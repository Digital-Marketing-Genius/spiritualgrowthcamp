<?php
/**
 * /api/app/unsubscribe.php?user=<uuid>&token=<hmac>
 * GET shows a confirmation button; POST turns daily reading emails off.
 */
require __DIR__ . '/_ttb.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$user = (string)($_GET['user'] ?? '');
$token = (string)($_GET['token'] ?? '');
$secret = (string)ttb_cfg()['ttb_unsubscribe_secret'];
if (!$secret || !preg_match('/^[a-f0-9-]{36}$/', $user) || !preg_match('/^[a-f0-9]{64}$/', $token) || !hash_equals(ttb_unsubscribe_token($user), $token)) {
    http_response_code(400); exit('Invalid unsubscribe link.');
}
$page = function (string $body) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><meta name="viewport" content="width=device-width"><title>Unsubscribe · Through the Bible</title><body style="font:18px system-ui;max-width:600px;margin:60px auto;padding:24px">' . $body . '</body></html>';
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $page('<h1>Daily reading emails</h1><p>You can keep reading in the app without receiving daily emails.</p><form method="post"><button style="padding:14px">Unsubscribe</button></form>');
}
$r = ttb_sb('PATCH', '/rest/v1/email_preferences?user_id=eq.' . rawurlencode($user), ['enabled' => false, 'updated_at' => gmdate('c')], ['Prefer: return=minimal']);
if (!$r['ok']) { http_response_code(500); $page('<p>Could not update your preference. Please try again.</p>'); }
$page('<h1>You are unsubscribed.</h1><p>You will no longer receive Through the Bible daily reading emails. <a href="/app/">Back to the app</a></p>');
