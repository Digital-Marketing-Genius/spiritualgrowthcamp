<?php
/**
 * POST /api/app/portal.php  (Authorization: Bearer <Supabase access token>)
 * Opens the Stripe Customer Portal so a member can update their card or cancel.
 * The restricted Stripe key needs "Customer portal: Write".
 */
require __DIR__ . '/_ttb.php';
sgc_require_same_origin_post();

$user = ttb_reader();
if (!$user) sgc_json(['error' => 'Please sign in first.'], 401);
$row = ttb_membership($user['id']);
if (empty($row['stripe_customer_id'])) sgc_json(['error' => 'No membership found for this account.'], 404);

try {
    $p = stripe_request('POST', 'billing_portal/sessions', ['customer' => $row['stripe_customer_id'], 'return_url' => ttb_app_url() . '#profile']);
    sgc_json(['url' => $p['url']]);
} catch (Throwable $e) {
    sgc_log('ttb', 'Portal failed: ' . $e->getMessage(), ['user' => $user['id']]);
    sgc_json(['error' => 'We could not open your billing page. Please email hello@spiritualgrowthcamp.com and we will help.'], 502);
}
