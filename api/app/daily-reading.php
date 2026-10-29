<?php
/**
 * Daily reading emails for Through the Bible. Run every 15 minutes by a Hostinger cron job:
 *   php /home/<user>/domains/spiritualgrowthcamp.com/public_html/api/app/daily-reading.php
 * It refuses to run from the web.
 *
 * Sends the reader's next unfinished, published chapter at their chosen local time.
 * Days after the free preview are sent only to members. Each reader gets at most one email
 * per local day (email_deliveries has a unique user/day key).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($_SERVER['DOCUMENT_ROOT'] === '') $_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require __DIR__ . '/_ttb.php';

$cfg = ttb_cfg();
if (empty($cfg['ttb_daily_email'])) { echo "Daily email is not enabled.\n"; exit; }
if (empty($cfg['resend_api_key']) || empty($cfg['ttb_unsubscribe_secret'])) { echo "Missing resend_api_key or ttb_unsubscribe_secret.\n"; exit(1); }

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$sent = 0; $failed = 0; $offset = 0;
do {
    $prefs = ttb_sb('GET', '/rest/v1/email_preferences?select=user_id,timezone,reminder_time&enabled=eq.true&order=user_id&limit=500&offset=' . $offset);
    if (!$prefs['ok']) { echo "Could not load preferences.\n"; exit(1); }
    $batch = $prefs['data'] ?? [];
    foreach ($batch as $p) {
        $due = ttb_due_now($now, (string)$p['timezone'], (string)$p['reminder_time']);
        if (!$due) continue;
        $uid = $p['user_id'];

        $st = ttb_sb('GET', '/rest/v1/reader_state?select=state&user_id=eq.' . rawurlencode($uid));
        if (!$st['ok']) continue;
        $day = ttb_first_unfinished((array)($st['data'][0]['state']['completed'] ?? []));
        if ($day === null) continue;
        if ($day > TTB_FREE_DAYS && !ttb_is_active(ttb_membership($uid))) continue;

        $ch = ttb_sb('GET', '/rest/v1/devotionals?select=published&day=eq.' . $day);
        $chapter = $ch['data'][0]['published'] ?? null;
        if (!$ch['ok'] || !$chapter) continue;
        $chapter['day'] = $day;

        $u = ttb_sb('GET', '/auth/v1/admin/users/' . rawurlencode($uid));
        $email = $u['data']['email'] ?? '';
        if (!$u['ok'] || !$email || empty($u['data']['email_confirmed_at'])) continue;

        // Claim this user/day first so a second run never double-sends.
        $claim = ttb_sb('POST', '/rest/v1/email_deliveries', ['user_id' => $uid, 'local_day' => $due, 'status' => 'sending'], ['Prefer: return=minimal']);
        if (!$claim['ok']) continue;

        $unsub = rtrim($cfg['site_url'], '/') . '/api/app/unsubscribe.php?user=' . rawurlencode($uid) . '&token=' . ttb_unsubscribe_token($uid);
        $res = sgc_http_json('POST', 'https://api.resend.com/emails', $cfg['resend_api_key'], [
            'from' => $cfg['ttb_mail_from'],
            'to' => [$email],
            'subject' => 'Day ' . $day . ': ' . ($chapter['book'] ?? '') . ' ' . ($chapter['chapter'] ?? '') . ' | ' . ($chapter['title'] ?? ''),
            'html' => ttb_email_html($chapter, $unsub),
            'headers' => ['List-Unsubscribe' => "<$unsub>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
        ]);
        $patch = ['status' => $res['ok'] ? 'sent' : 'failed', 'provider_id' => $res['data']['id'] ?? null, 'updated_at' => gmdate('c')];
        ttb_sb('PATCH', '/rest/v1/email_deliveries?user_id=eq.' . rawurlencode($uid) . '&local_day=eq.' . $due, $patch, ['Prefer: return=minimal']);
        $res['ok'] ? $sent++ : $failed++;
    }
    $offset += 500;
} while (count($batch) === 500);

echo json_encode(['sent' => $sent, 'failed' => $failed]) . "\n";
