<?php
/**
 * POST /api/free-book.php  { "sku": "ebook-gods-men", "email": "...", "firstname": "..." }
 * Emails a download link for a free ebook and adds the reader to the Sender group
 * "Complete Active Database" (sender_group_free_books in config).
 */
require __DIR__ . '/_lib.php';
sgc_require_same_origin_post();
$in = sgc_read_json_body();
if (!empty($in['website'])) sgc_json(['ok' => true]); // honeypot: pretend success

$sku = (string)($in['sku'] ?? '');
$email = trim((string)($in['email'] ?? ''));
$first = mb_substr(trim((string)($in['firstname'] ?? '')), 0, 80);
$item = sgc_item($sku);
if (!$item || $item['kind'] !== 'ebook' || (int)$item['amount'] !== 0) sgc_json(['error' => 'This book is not free.'], 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sgc_json(['error' => 'Please enter a valid email address.'], 400);

// Light abuse limits: 6 requests an hour per address and per email.
foreach (['ip-' . ($_SERVER['REMOTE_ADDR'] ?? 'x'), 'em-' . strtolower($email)] as $k) {
    $id = 'rate_' . substr(hash('sha256', $k . date('YmdH')), 0, 32);
    $r = sgc_load('free', $id) ?? ['n' => 0];
    if ($r['n'] >= 6) sgc_json(['error' => 'Too many requests. Please try again in an hour.'], 429);
    $r['n']++; sgc_save('free', $id, $r);
}

if (!sgc_send_ebook_links($email, [$sku], 'free-' . date('Ymd'), true)) {
    sgc_json(['error' => 'We could not send the email just now. Please try again in a few minutes.'], 502);
}
sgc_log('free', 'Free ebook sent', ['sku' => $sku]);

// Add to the mailing list (best effort; never blocks the download).
$cfg = sgc_config();
if (!empty($cfg['sender_api_token']) && !empty($cfg['sender_group_free_books'])) {
    $body = ['email' => $email, 'groups' => [$cfg['sender_group_free_books']], 'trigger_automation' => true];
    if ($first !== '') $body['firstname'] = $first;
    $res = sgc_http_json('POST', 'https://api.sender.net/v2/subscribers', $cfg['sender_api_token'], $body);
    if (!$res['ok']) {
        $res = sgc_http_json('POST', 'https://api.sender.net/v2/subscribers/groups/' . rawurlencode($cfg['sender_group_free_books']), $cfg['sender_api_token'], ['subscribers' => [$email]]);
        if (!$res['ok']) sgc_log('sender', 'Free book subscriber not added', ['status' => $res['status']]);
    }
}
sgc_json(['ok' => true]);
