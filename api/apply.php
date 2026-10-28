<?php
/**
 * POST /api/apply.php  (JSON body with the Bootcamp application fields)
 * Saves the application privately, then starts a $50 Stripe Checkout payment.
 * The application only counts as submitted once the webhook confirms payment.
 * Returns: { "url": "https://checkout.stripe.com/..." }
 */
require __DIR__ . '/_lib.php';

sgc_require_same_origin_post();
$in = sgc_read_json_body();

if (!empty($in['website'])) sgc_json(['error' => 'Unable to submit.'], 400); // honeypot

$fields = [
    // name => [label, required, max length]
    'FirstName'             => ['First name', true, 80],
    'LastName'              => ['Last name', true, 80],
    'Email'                 => ['Email', true, 160],
    'Phone'                 => ['Mobile number', true, 40],
    'Age'                   => ['Age', true, 3],
    'Gender'                => ['Gender', true, 10],
    'Country'               => ['Country of residence', true, 80],
    'ChristianDuration'     => ['Years as a Christian', true, 80],
    'Denomination'          => ['Denomination / tradition', true, 120],
    'PriorMentorship'       => ['Formal mentorship before', true, 3],
    'SpiritualRating'       => ['Self-rated spiritual growth (1-5)', true, 1],
    'ChurchMember'          => ['Part of a church', true, 3],
    'ChurchName'            => ['Church name', false, 160],
    'JoinReason'            => ['Primary reason for joining', true, 2000],
    'GrowthArea'            => ['Area needing most growth', true, 2000],
    'Expectations'          => ['Expectations', true, 2000],
    'Commitment90Day'       => ['Commits to 90 days', true, 3],
    'CommitmentJournal'     => ['Commits to journaling', true, 3],
    'CommitmentWeekly'      => ['Available for weekly meetings', true, 3],
    'AccountabilityPartner' => ['Comfortable with accountability partner', true, 3],
];

$app = [];
foreach ($fields as $name => [$label, $required, $max]) {
    $v = trim((string)($in[$name] ?? ''));
    if ($required && $v === '') sgc_json(['error' => "Please fill in: $label."], 400);
    $app[$name] = mb_substr($v, 0, $max);
}
if (!filter_var($app['Email'], FILTER_VALIDATE_EMAIL)) sgc_json(['error' => 'Please enter a valid email address.'], 400);
if (!ctype_digit($app['Age']) || (int)$app['Age'] < 18 || (int)$app['Age'] > 120) sgc_json(['error' => 'The Bootcamp is for adults aged 18 and over.'], 400);
if (empty($in['AgreeTerms'])) sgc_json(['error' => 'Please confirm you understand the payment and refund terms.'], 400);

// Hidden fields, set on the server so they cannot be altered in the browser.
$cohort = sgc_cohort();
$app['Cohort'] = $cohort['label'];          // e.g. "Cohort 1, January 2027"
$app['CohortStart'] = $cohort['start'];     // e.g. "2027-01-15"
$app['Timestamp'] = gmdate('Y-m-d');        // submission date (Sender date field)
$app['SubmittedAt'] = gmdate('c');

$appId = 'app_' . date('Ymd') . '_' . sgc_random_id(9);
$app['id'] = $appId;
$app['status'] = 'pending_payment';
$app['created_at'] = date('c');
$app['ip_country'] = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
sgc_save('applications', $appId, $app);

try {
    $item = sgc_item('bootcamp');
    $site = rtrim(sgc_config()['site_url'], '/');
    $session = stripe_request('POST', 'checkout/sessions', sgc_checkout_params([
        'mode' => 'payment',
        'customer_creation' => 'always',
        'customer_email' => $app['Email'],
        'client_reference_id' => $appId,
        'line_items' => [['price' => $item['price'], 'quantity' => 1]],
        'cancel_url' => $site . '/apply.html?canceled=1',
        'metadata' => ['flow' => 'bootcamp', 'application_id' => $appId, 'cohort' => $app['Cohort']],
        'payment_intent_data' => [
            'description' => 'Spiritual Growth Bootcamp application (' . $app['Cohort'] . ')',
            'metadata' => ['flow' => 'bootcamp', 'application_id' => $appId],
        ],
        'adaptive_pricing' => ['enabled' => true],
        'integration_identifier' => 'sgc_bootcamp_xhchzikx',
    ]), 'apply-' . $appId);

    $app['checkout_session_id'] = $session['id'];
    sgc_save('applications', $appId, $app);
    sgc_json(['url' => $session['url']]);
} catch (Throwable $e) {
    sgc_log('apply', 'Create session failed: ' . $e->getMessage(), ['application_id' => $appId]);
    sgc_json(['error' => 'Your answers were saved, but we could not start the payment. Please try again in a moment.'], 502);
}
