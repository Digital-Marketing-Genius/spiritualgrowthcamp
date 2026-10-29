<?php
/**
 * Through the Bible app (spiritualgrowthcamp.com/app/): server helpers.
 * Uses the site's shared library (Stripe client, Resend mailer, private config).
 *
 * Extra keys in sgc-private/config.php:
 *   'supabase_url'               => 'https://hpyntlrumfzslpvlswoq.supabase.co',
 *   'supabase_service_role_key'  => '...',   // Supabase > Project Settings > API keys (secret)
 *   'ttb_unsubscribe_secret'     => '...',   // any long random string (reuse the Netlify UNSUBSCRIBE_SECRET to keep old links working)
 *   'ttb_mail_from'              => 'Through the Bible <readings@spiritualgrowthcamp.com>',
 *   'ttb_daily_email'            => true,
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/_lib.php';

const TTB_FREE_DAYS = 7;
const TTB_ACTIVE = ['active', 'trialing', 'past_due'];

function ttb_cfg(): array {
    $c = sgc_config();
    return $c + [
        'supabase_url' => 'https://hpyntlrumfzslpvlswoq.supabase.co',
        'supabase_service_role_key' => '',
        'ttb_unsubscribe_secret' => '',
        'ttb_mail_from' => 'Through the Bible <readings@spiritualgrowthcamp.com>',
        'ttb_daily_email' => false,
        'ttb_price' => sgc_item('through-the-bible')['price'] ?? '',
    ];
}

function ttb_app_url(): string { return rtrim(sgc_config()['site_url'], '/') . '/app/'; }

/** Supabase REST/Auth call with the service role key. Returns [ok, status, data]. */
function ttb_sb(string $method, string $path, ?array $body = null, array $extraHeaders = [], ?string $bearer = null): array {
    $c = ttb_cfg();
    if (empty($c['supabase_service_role_key'])) throw new RuntimeException('supabase_service_role_key is not configured');
    $key = $c['supabase_service_role_key'];
    $ch = curl_init(rtrim($c['supabase_url'], '/') . $path);
    $headers = array_merge(['apikey: ' . $key, 'Authorization: Bearer ' . ($bearer ?? $key), 'Content-Type: application/json', 'Accept: application/json'], $extraHeaders);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'status' => 0, 'data' => null];
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => json_decode((string)$raw, true)];
}

/** The signed-in reader, from "Authorization: Bearer <Supabase access token>". */
function ttb_reader(): ?array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v; }
    $token = preg_replace('/^Bearer\s+/i', '', (string)$h);
    if (strlen($token) < 20) return null;
    $r = ttb_sb('GET', '/auth/v1/user', null, [], $token);
    return ($r['ok'] && !empty($r['data']['id'])) ? $r['data'] : null;
}

function ttb_membership(string $userId): ?array {
    $r = ttb_sb('GET', '/rest/v1/memberships?select=*&user_id=eq.' . rawurlencode($userId));
    return ($r['ok'] && !empty($r['data'][0])) ? $r['data'][0] : null;
}

function ttb_is_active(?array $row): bool {
    if (!$row || !in_array($row['status'] ?? '', TTB_ACTIVE, true)) return false;
    if (empty($row['current_period_end'])) return true;
    return strtotime($row['current_period_end']) > time() - 3 * 86400;
}

/** Map a Stripe Subscription to a memberships row. */
function ttb_row_from_subscription(array $sub): array {
    $end = $sub['items']['data'][0]['current_period_end'] ?? ($sub['current_period_end'] ?? null);
    $cust = is_array($sub['customer'] ?? null) ? ($sub['customer']['id'] ?? null) : ($sub['customer'] ?? null);
    return [
        'stripe_customer_id' => $cust,
        'stripe_subscription_id' => $sub['id'],
        'status' => $sub['status'] ?? 'incomplete',
        'current_period_end' => $end ? gmdate('c', (int)$end) : null,
        'cancel_at_period_end' => !empty($sub['cancel_at_period_end']) || !empty($sub['cancel_at']),
        'livemode' => !empty($sub['livemode']),
        'updated_at' => gmdate('c'),
    ];
}

/**
 * Called by the site webhook (api/webhook.php) for Through the Bible subscriptions
 * (metadata.flow = "ttb-app"). Writes public.memberships in Supabase.
 */
function ttb_sync_subscription(array $sub, ?string $userId = null): void {
    $userId = $userId ?: ($sub['metadata']['user_id'] ?? '');
    if (!$userId) { sgc_log('ttb', 'Subscription without user_id', ['sub' => $sub['id'] ?? '']); return; }
    // Prefer the current state from Stripe (events can arrive out of order).
    try { $sub = stripe_request('GET', 'subscriptions/' . $sub['id'], []); } catch (Throwable $e) { /* use the event snapshot */ }
    $row = ['user_id' => $userId] + ttb_row_from_subscription($sub);

    // A late event for an older, ended subscription must not replace a newer active one.
    $current = ttb_membership($userId);
    if ($current && !empty($current['stripe_subscription_id']) && $current['stripe_subscription_id'] !== $row['stripe_subscription_id']
        && in_array($current['status'], TTB_ACTIVE, true) && !in_array($row['status'], TTB_ACTIVE, true)) return;

    $r = ttb_sb('POST', '/rest/v1/memberships?on_conflict=user_id', $row, ['Prefer: resolution=merge-duplicates,return=minimal']);
    if (!$r['ok']) throw new RuntimeException('Supabase membership write failed (' . $r['status'] . ')');
}

/* ---------------------------------------------------------- daily emails */

function ttb_first_unfinished(array $completed): ?int {
    for ($i = 1; $i <= 1189; $i++) if (empty($completed[$i]) && empty($completed[(string)$i])) return $i;
    return null;
}

/** Local date (Y-m-d) when the reader's reminder time fell within the last 15 minutes, else null. */
function ttb_due_now(DateTimeImmutable $now, string $tz, string $time): ?string {
    try { $local = $now->setTimezone(new DateTimeZone($tz)); } catch (Throwable $e) { return null; }
    [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, 0));
    $delta = (int)$local->format('G') * 60 + (int)$local->format('i') - ($h * 60 + $m);
    return ($delta >= 0 && $delta < 15) ? $local->format('Y-m-d') : null;
}

function ttb_unsubscribe_token(string $userId): string {
    return hash_hmac('sha256', $userId, (string)ttb_cfg()['ttb_unsubscribe_secret']);
}

function ttb_email_html(array $c, string $unsubscribe): string {
    $reading = ttb_app_url() . '#today/' . (int)$c['day'] . '/read';
    $paras = array_slice(preg_split('/\n\n+/', (string)($c['commentary'] ?? '')), 0, 2);
    $comm = implode('', array_map(fn($p) => '<p style="line-height:1.8">' . h($p) . '</p>', $paras));
    return '<!doctype html><html><body style="background:#f7f6f2;color:#272c2b;font-family:Arial,sans-serif"><main style="max-width:600px;margin:auto;padding:32px">'
        . '<p style="letter-spacing:2px;color:#9d332a">THROUGH THE BIBLE · MUSTARD SEED</p>'
        . '<h1 style="font-family:Georgia,serif">' . h($c['book'] ?? '') . ' ' . (int)($c['chapter'] ?? 0) . ': ' . h($c['title'] ?? '') . '</h1>'
        . '<p>' . h($c['introduction'] ?? '') . '</p><p><a href="' . h($reading) . '">Read or listen to today\'s BSB chapter →</a></p>'
        . '<h2>Understanding the chapter</h2>' . $comm
        . '<h2>Today\'s takeaway</h2><p>' . h($c['takeaway'] ?? '') . '</p><h2>Reflect</h2><p>' . h($c['reflection'] ?? '') . '</p><h2>Pray</h2><p>' . h($c['prayer'] ?? '') . '</p>'
        . '<p><a href="' . h($reading) . '">Continue your journey →</a></p><hr><p style="font-size:12px">You opted in to daily reading emails. Your journey follows your progress, not a calendar deadline.</p>'
        . '<a href="' . h($unsubscribe) . '">Unsubscribe from daily readings</a></main></body></html>';
}

function ttb_json_in(): array { return sgc_read_json_body(); }
