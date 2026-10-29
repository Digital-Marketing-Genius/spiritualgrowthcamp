<?php
/**
 * POST /api/webhook.php: Stripe webhook endpoint.
 *
 * Subscribe this endpoint (Stripe Dashboard > Developers > Webhooks) to:
 *   checkout.session.completed
 *   checkout.session.async_payment_succeeded
 *   checkout.session.async_payment_failed
 *   customer.subscription.created / .updated / .deleted
 *   invoice.paid
 *   invoice.payment_failed
 *   charge.refunded
 *   charge.dispute.created
 *   radar.early_fraud_warning.created
 *
 * All fulfilment happens here, never on the thank-you page.
 */
require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }

$cfg = sgc_config();
$payload = (string)file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripe_verify_webhook($payload, $sig, $cfg['webhook_secret'])) {
    sgc_log('webhook', 'Signature verification failed');
    http_response_code(400);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id'])) { http_response_code(400); exit; }

// Stripe can deliver the same event more than once; handle each event id once.
if (!sgc_claim_once('events', $event['id'])) { http_response_code(200); echo 'duplicate'; exit; }

$type = $event['type'] ?? '';
$obj  = $event['data']['object'] ?? [];

try {
    switch ($type) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            // Delayed payment methods complete as "unpaid"; fulfil only when paid.
            if (in_array($obj['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
                fulfil_checkout_session($obj['id']);
            } else {
                sgc_log('webhook', 'Session completed but not yet paid', ['session' => $obj['id']]);
            }
            break;

        case 'checkout.session.async_payment_failed':
            on_async_payment_failed($obj);
            break;

        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            $sub = sgc_load('subscriptions', $obj['id']) ?? [];
            $sub = array_merge($sub, [
                'id' => $obj['id'],
                'customer' => $obj['customer'] ?? null,
                'status' => $obj['status'] ?? null,
                'cancel_at_period_end' => $obj['cancel_at_period_end'] ?? false,
                'updated_at' => date('c'),
            ]);
            sgc_save('subscriptions', $obj['id'], $sub);
            if (($obj['metadata']['flow'] ?? '') === 'ttb-app') {
                require_once __DIR__ . '/app/_ttb.php';
                ttb_sync_subscription($obj); // throws on failure so Stripe retries
            }
            if ($type === 'customer.subscription.deleted') {
                sgc_mail($cfg['admin_email'], 'Through the Bible App subscription ended', '<p>Subscription <b>' . h($obj['id']) . '</b> has ended.</p>');
            }
            break;

        case 'invoice.paid':
            // Renewals of the annual subscription arrive here.
            sgc_log('billing', 'Invoice paid', ['invoice' => $obj['id'], 'customer' => $obj['customer'] ?? null, 'amount' => $obj['amount_paid'] ?? null, 'reason' => $obj['billing_reason'] ?? null]);
            break;

        case 'invoice.payment_failed':
            sgc_log('billing', 'Invoice payment failed', ['invoice' => $obj['id'], 'customer' => $obj['customer'] ?? null]);
            sgc_mail($cfg['admin_email'], 'A subscription payment failed',
                '<p>Stripe could not collect payment for invoice <b>' . h($obj['id']) . '</b> (' . h($obj['customer_email'] ?? '') . ').</p><p>Stripe will retry automatically. No action is needed unless retries keep failing.</p>');
            break;

        case 'charge.refunded':
            on_refund($obj);
            break;

        case 'charge.dispute.created':
            sgc_log('risk', 'Dispute opened', ['dispute' => $obj['id'], 'charge' => $obj['charge'] ?? null, 'amount' => $obj['amount'] ?? null]);
            sgc_mail($cfg['admin_email'], 'Action needed: a payment was disputed',
                '<p>A customer disputed a payment (dispute <b>' . h($obj['id']) . '</b>, reason: ' . h($obj['reason'] ?? 'unknown') . ').</p><p>Respond in the Stripe Dashboard under Payments &gt; Disputes before the deadline.</p>');
            break;

        case 'radar.early_fraud_warning.created':
            sgc_log('risk', 'Early fraud warning', ['warning' => $obj['id'], 'charge' => $obj['charge'] ?? null]);
            sgc_mail($cfg['admin_email'], 'Fraud warning on a payment',
                '<p>The card issuer flagged charge <b>' . h($obj['charge'] ?? '') . '</b> as possibly fraudulent. Consider refunding it in the Stripe Dashboard to avoid a dispute.</p>');
            break;

        default:
            // Not subscribed / not needed.
            break;
    }
} catch (Throwable $e) {
    // Let Stripe retry: release the claim and answer 500.
    sgc_release_claim('events', $event['id']);
    sgc_log('webhook', "Handler error for $type: " . $e->getMessage(), ['event' => $event['id']]);
    http_response_code(500);
    exit;
}

http_response_code(200);
echo 'ok';

/* ======================================================================= */

function fulfil_checkout_session(string $sessionId): void {
    if (!sgc_claim_once('fulfilled', $sessionId)) return; // already fulfilled

    try {
        $session = stripe_request('GET', "checkout/sessions/$sessionId", []);
        $lines = stripe_request('GET', "checkout/sessions/$sessionId/line_items", ['limit' => 100, 'expand' => ['data.price.product']]);
    } catch (Throwable $e) {
        sgc_release_claim('fulfilled', $sessionId);
        throw $e;
    }

    $cfg = sgc_config();
    $email = $session['customer_details']['email'] ?? ($session['customer_email'] ?? '');
    $name  = $session['customer_details']['name'] ?? '';
    $flow  = $session['metadata']['flow'] ?? '';
    if ($flow === 'ttb-app') {
        // Through the Bible app membership: record it in Supabase.
        try {
            require_once __DIR__ . '/app/_ttb.php';
            if (!empty($session['subscription'])) ttb_sync_subscription(['id' => $session['subscription'], 'metadata' => $session['metadata'] ?? []], $session['client_reference_id'] ?? null);
        } catch (Throwable $e) {
            sgc_release_claim('fulfilled', $sessionId);
            throw $e;
        }
        return;
    }

    $skus = [];
    foreach ($lines['data'] ?? [] as $li) {
        $sku = $li['price']['product']['metadata']['sku'] ?? '';
        if ($sku) $skus[] = $sku;
    }

    $order = [
        'session_id' => $sessionId,
        'flow' => $flow,
        'email' => $email,
        'name' => $name,
        'skus' => $skus,
        'amount_total' => $session['amount_total'] ?? null,
        'currency' => $session['currency'] ?? null,
        'customer' => $session['customer'] ?? null,
        'payment_intent' => $session['payment_intent'] ?? null,
        'subscription' => $session['subscription'] ?? null,
        'paid_at' => date('c'),
    ];
    sgc_save('orders', $sessionId, $order);

    if ($flow === 'bootcamp') {
        fulfil_bootcamp($session, $order);
    } elseif ($flow === 'ebooks') {
        fulfil_ebooks($order);
    } elseif ($flow === 'school-of-prayer') {
        sgc_mail($email, 'Welcome to the School of Prayer',
            '<h1 style="font-family:Georgia,serif;font-weight:normal">You are enrolled.</h1><p>Thank you for joining the School of Prayer. We will email your access details before the programme begins.</p>');
        sgc_mail($cfg['admin_email'], 'New School of Prayer enrolment', '<p>' . h($name) . ' (' . h($email) . ') paid for the School of Prayer.</p>');
    } elseif ($flow === 'through-the-bible') {
        if (!empty($session['subscription'])) {
            $sub = sgc_load('subscriptions', $session['subscription']) ?? ['id' => $session['subscription']];
            $sub['email'] = $email;
            $sub['customer'] = $session['customer'] ?? null;
            sgc_save('subscriptions', $session['subscription'], $sub);
        }
        sgc_mail($email, 'Your Through the Bible App subscription',
            '<h1 style="font-family:Georgia,serif;font-weight:normal">Welcome aboard.</h1><p>Your annual subscription to the Through the Bible App is active. It renews automatically each year; you can cancel any time from the "Manage subscription" link on spiritualgrowthcamp.com.</p>');
        sgc_mail($cfg['admin_email'], 'New Through the Bible App subscriber', '<p>' . h($name) . ' (' . h($email) . ') subscribed.</p>');
    } else {
        sgc_log('webhook', 'Paid session with unknown flow', ['session' => $sessionId]);
    }
}

function fulfil_bootcamp(array $session, array $order): void {
    $cfg = sgc_config();
    $appId = $session['client_reference_id'] ?? ($session['metadata']['application_id'] ?? '');
    $app = $appId ? sgc_load('applications', $appId) : null;
    if (!$app) {
        sgc_log('bootcamp', 'Paid session without a stored application', ['session' => $session['id'], 'application_id' => $appId]);
        sgc_mail($cfg['admin_email'], 'Bootcamp payment received without an application',
            '<p>Payment ' . h($session['id']) . ' from ' . h($order['email']) . ' has no stored application. Please contact the applicant.</p>');
        return;
    }
    $app['status'] = 'paid_pending_review';
    $app['paid_at'] = $order['paid_at'];
    $app['payment_intent'] = $order['payment_intent'];
    $app['checkout_session_id'] = $session['id'];
    sgc_save('applications', $appId, $app);
    if (!empty($order['payment_intent'])) sgc_save('pi_index', $order['payment_intent'], ['application_id' => $appId]);

    // Optional: forward to the existing Google Sheet (Apps Script web app URL in config).
    if (!empty($cfg['sheet_webhook_url'])) {
        $post = $app; unset($post['ip_country']);
        $ch = curl_init($cfg['sheet_webhook_url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
        curl_exec($ch);
        curl_close($ch);
    }

    // Record the applicant in Sender (group "Spiritual Growth Bootcamp"). Joining the
    // group starts the Sender confirmation email. If Sender is not configured or
    // fails, send the confirmation through our own mailer instead.
    $inSender = false;
    try { $inSender = sgc_sender_add_applicant($app); } catch (Throwable $e) { sgc_log('sender', $e->getMessage()); }
    $app['sender_synced'] = $inSender;
    sgc_save('applications', $appId, $app);

    if (!$inSender) {
        $first = $app['FirstName'];
        sgc_mail($app['Email'], 'We received your Bootcamp application',
            '<h1 style="font-family:Georgia,serif;font-weight:normal">Thank you, ' . h($first) . '.</h1>'
            . '<p>Your application to the Spiritual Growth Bootcamp (' . h($app['Cohort']) . ') and your $50 payment have been received.</p>'
            . '<p>We review every application personally and will email you with a decision. If you are not accepted into this cohort, your $50 will be refunded in full.</p>'
            . '<p style="font-style:italic;color:#555">"The things that thou hast heard of me among many witnesses, the same commit thou to faithful men, who shall be able to teach others also." 2 Timothy 2:2</p>');
    }

    $rows = '';
    foreach ($app as $k => $v) {
        if (in_array($k, ['ip_country'], true)) continue;
        if (is_bool($v)) $v = $v ? 'yes' : 'no';
        $rows .= '<tr><td style="padding:4px 8px;color:#666;vertical-align:top">' . h($k) . '</td><td style="padding:4px 8px">' . nl2br(h($v)) . '</td></tr>';
    }
    sgc_mail($cfg['admin_email'], 'New paid Bootcamp application: ' . $app['FirstName'] . ' ' . $app['LastName'] . ' (' . $app['Cohort'] . ')',
        '<p>A new application has been paid and is ready for review.' . ($inSender ? ' It has been added to the Sender group "Spiritual Growth Bootcamp".' : ' <b>It could not be added to Sender</b>; please add it manually.') . '</p><table style="border-collapse:collapse;font-size:14px">' . $rows . '</table>'
        . '<p>To decline an applicant, refund the payment in the Stripe Dashboard (Payments, open the payment, Refund).</p>');
}

function fulfil_ebooks(array $order): void {
    $cfg = sgc_config();
    $ebookSkus = array_values(array_filter($order['skus'], function ($s) { $i = sgc_item($s); return $i && $i['kind'] === 'ebook'; }));
    if (!$ebookSkus) return;

    $token = sgc_random_id(24);
    sgc_save('downloads', hash('sha256', $token), [
        'session_id' => $order['session_id'],
        'email' => $order['email'],
        'skus' => $ebookSkus,
        'expires_at' => time() + 86400 * (int)$cfg['download_days'],
        'downloads' => 0,
        'limit' => (int)$cfg['download_limit'],
    ]);

    $base = rtrim($cfg['site_url'], '/') . '/api/download.php?t=' . rawurlencode($token);
    $list = '';
    foreach ($ebookSkus as $sku) {
        $item = sgc_item($sku);
        $list .= '<li style="margin:8px 0"><a href="' . h($base . '&b=' . rawurlencode($sku)) . '" style="color:#C21B1B">' . h($item['name']) . '</a></li>';
    }
    sgc_mail($order['email'], 'Your ebooks from the School of Spiritual Growth',
        '<h1 style="font-family:Georgia,serif;font-weight:normal">Your books are ready.</h1>'
        . '<p>Thank you for your order. Download your ebooks below:</p><ul style="padding-left:18px">' . $list . '</ul>'
        . '<p style="font-size:13px;color:#666">These links work for ' . (int)$cfg['download_days'] . ' days. Please save the files to your device.</p>');
    sgc_mail($cfg['admin_email'], 'New ebook order', '<p>' . h($order['email']) . ' bought: ' . h(implode(', ', $ebookSkus)) . '</p>');
}

function on_async_payment_failed(array $session): void {
    $cfg = sgc_config();
    $email = $session['customer_details']['email'] ?? ($session['customer_email'] ?? '');
    sgc_log('webhook', 'Async payment failed', ['session' => $session['id']]);
    if ($email) {
        sgc_mail($email, 'Your payment did not go through',
            '<p>Unfortunately your payment to the School of Spiritual Growth did not complete, so your order has not been processed.</p><p>Please try again on spiritualgrowthcamp.com, or reply to this email if you need help.</p>');
    }
}

function on_refund(array $charge): void {
    $pi = $charge['payment_intent'] ?? '';
    sgc_log('risk', 'Charge refunded', ['charge' => $charge['id'], 'payment_intent' => $pi, 'amount_refunded' => $charge['amount_refunded'] ?? null]);
    $idx = $pi ? sgc_load('pi_index', $pi) : null;
    $appId = $idx['application_id'] ?? '';
    if ($appId && ($app = sgc_load('applications', $appId))) {
        $app['status'] = 'refunded';
        $app['refunded_at'] = date('c');
        sgc_save('applications', $appId, $app);
    }
}
