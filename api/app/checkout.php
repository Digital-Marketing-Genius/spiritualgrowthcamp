<?php
/**
 * POST /api/app/checkout.php  (Authorization: Bearer <Supabase access token>)
 * Starts Stripe Checkout for the Through the Bible membership ($12 USD a year),
 * linked to the signed-in reader's account.
 */
require __DIR__ . '/_ttb.php';
sgc_require_same_origin_post();

$cfg = ttb_cfg();
$user = ttb_reader();
if (!$user) sgc_json(['error' => 'Please sign in first.'], 401);

$existing = ttb_membership($user['id']);
if (ttb_is_active($existing)) sgc_json(['error' => 'You already have an active membership.', 'active' => true], 409);

$app = ttb_app_url();
$params = sgc_checkout_params([
    'mode' => 'subscription',
    'line_items' => [['price' => $cfg['ttb_price'], 'quantity' => 1]],
    'client_reference_id' => $user['id'],
    'metadata' => ['flow' => 'ttb-app', 'user_id' => $user['id']],
    'subscription_data' => ['metadata' => ['flow' => 'ttb-app', 'user_id' => $user['id']]],
    'success_url' => $app . '?membership=success#profile',
    'cancel_url' => $app . '?membership=canceled#profile',
    'integration_identifier' => 'sgc_bible_app_gqhnszlt',
]);
if (!empty($existing['stripe_customer_id'])) $params['customer'] = $existing['stripe_customer_id'];
elseif (!empty($user['email'])) $params['customer_email'] = $user['email'];

try {
    $s = stripe_request('POST', 'checkout/sessions', $params);
    sgc_json(['url' => $s['url']]);
} catch (Throwable $e) {
    sgc_log('ttb', 'Checkout failed: ' . $e->getMessage(), ['user' => $user['id']]);
    sgc_json(['error' => 'We could not start checkout. Please try again in a moment.'], 502);
}
