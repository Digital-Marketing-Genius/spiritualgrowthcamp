<?php
/**
 * GET /api/session.php?session_id=cs_...
 * Small, non-sensitive summary for the thank-you page. Display only:
 * fulfilment never depends on this endpoint (the webhook does that).
 */
require __DIR__ . '/_lib.php';

$id = (string)($_GET['session_id'] ?? '');
if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $id)) sgc_json(['error' => 'Invalid session'], 400);

try {
    $s = stripe_request('GET', "checkout/sessions/$id", []);
} catch (Throwable $e) {
    sgc_json(['error' => 'Not found'], 404);
}

$email = $s['customer_details']['email'] ?? '';
if ($email && strpos($email, '@') !== false) {
    [$u, $d] = explode('@', $email, 2);
    $email = mb_substr($u, 0, 2) . str_repeat('*', max(1, mb_strlen($u) - 2)) . '@' . $d;
}

sgc_json([
    'flow' => $s['metadata']['flow'] ?? '',
    'status' => $s['status'] ?? '',
    'payment_status' => $s['payment_status'] ?? '',
    'email' => $email,
    'first_name' => strtok((string)($s['customer_details']['name'] ?? ''), ' ') ?: '',
]);
