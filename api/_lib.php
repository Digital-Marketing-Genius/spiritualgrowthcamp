<?php
/**
 * School of Spiritual Growth: shared helpers for the Stripe integration.
 *
 * Secrets are NOT in this repository. They live in a private config file
 * outside public_html:  <domain folder>/sgc-private/config.php
 * (see api/_config.sample.php for the template).
 *
 * This is a deliberately small, dependency-free client for the Stripe REST API
 * (form-encoded requests over cURL, pinned API version) so the whole backend can
 * be deployed from GitHub to shared hosting without Composer.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }

const SGC_STRIPE_API_VERSION = '2026-09-30.endive';

/* ------------------------------------------------------------------ config */

function sgc_private_dir(): string {
    static $dir = null;
    if ($dir === null) {
        $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__), '/');
        $dir = dirname($docroot) . '/sgc-private';
    }
    return $dir;
}

function sgc_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = sgc_private_dir() . '/config.php';
    if (!is_file($file)) {
        sgc_log('config', 'Missing private config file');
        sgc_json(['error' => 'Payments are not configured yet. Please try again later.'], 503);
    }
    $cfg = require $file;
    $cfg += [
        'site_url'        => 'https://spiritualgrowthcamp.com',
        'mail_from'       => 'hello@spiritualgrowthcamp.com',
        'mail_from_name'  => 'School of Spiritual Growth',
        'admin_email'     => 'hello@spiritualgrowthcamp.com',
        'automatic_tax'   => false,
        'sheet_webhook_url' => '',
        'download_days'   => 30,
        'download_limit'  => 10,
        'resend_api_key'  => '',        // Resend API key (re_...). When set, all email goes through Resend.
        'sender_api_token' => '',       // Sender.net API access token. When set, paid applications go to Sender.
        'sender_group_bootcamp' => 'azE627', // Sender group "Spiritual Growth Bootcamp"
    ];
    if (empty($cfg['stripe_key']) || empty($cfg['webhook_secret'])) {
        sgc_log('config', 'stripe_key or webhook_secret missing');
        sgc_json(['error' => 'Payments are not configured yet. Please try again later.'], 503);
    }
    return $cfg;
}

function sgc_livemode(): bool {
    return strpos(sgc_config()['stripe_key'], '_live_') !== false;
}

/* ----------------------------------------------------------------- catalog */

/**
 * The only things customers can buy. The browser sends a SKU; the server maps it
 * to a Stripe Price ID here, so nobody can change a price from their browser.
 * Fill in the "live" IDs when you switch to live mode.
 */
function sgc_catalog(): array {
    $test = [
        'bootcamp'          => ['price' => 'price_1UUvgi4UP8TTKz5rpxKvmxz2', 'kind' => 'bootcamp',     'name' => 'Spiritual Growth Bootcamp'],
        'school-of-prayer'  => ['price' => 'price_1UUvgi4UP8TTKz5r0MS3vzlf', 'kind' => 'programme',    'name' => 'School of Prayer'],
        'through-the-bible' => ['price' => 'price_1UUvgj4UP8TTKz5rwLX7Jnmo', 'kind' => 'subscription', 'name' => 'Through the Bible App'],
        'ebook-bootcamp-collections'           => ['price' => 'price_1UUvhC4UP8TTKz5rIABIGfy5', 'kind' => 'ebook', 'name' => 'Spiritual Growth Bootcamp Collections'],
        'ebook-school-of-spiritual-growth'     => ['price' => 'price_1UUvhD4UP8TTKz5rzcb6suRZ', 'kind' => 'ebook', 'name' => 'School of Spiritual Growth'],
        'ebook-rethinking-spiritual-warfare'   => ['price' => 'price_1UUvhD4UP8TTKz5rGgdEEg6L', 'kind' => 'ebook', 'name' => 'Rethinking Spiritual Warfare'],
        'ebook-365-days-scripture-adventure'   => ['price' => 'price_1UUvhE4UP8TTKz5rMGbTXb7w', 'kind' => 'ebook', 'name' => '365 Days of Scripture Adventure'],
        'ebook-a-tongue-on-fire'               => ['price' => 'price_1UUvhF4UP8TTKz5r1WhaXZSD', 'kind' => 'ebook', 'name' => 'A Tongue on Fire'],
        'ebook-sweet-fellowship-of-the-spirit' => ['price' => 'price_1UUvhF4UP8TTKz5rhPpnuS2z', 'kind' => 'ebook', 'name' => 'The Sweet Fellowship of the Spirit'],
        'ebook-i-write-to-you'                 => ['price' => 'price_1UUvhG4UP8TTKz5rsURWwgiZ', 'kind' => 'ebook', 'name' => 'I Write to You'],
        'ebook-running-with-the-horses'        => ['price' => 'price_1UUvhH4UP8TTKz5r0MqZuZWG', 'kind' => 'ebook', 'name' => 'Running With the Horses'],
        'ebook-priesthood-of-the-believer'     => ['price' => 'price_1UUvhI4UP8TTKz5r0Ch2A4B2', 'kind' => 'ebook', 'name' => 'Priesthood of the Believer'],
        'ebook-by-faith-conquering-kingdoms'   => ['price' => 'price_1UUvhI4UP8TTKz5rVnyqaIWt', 'kind' => 'ebook', 'name' => 'By Faith Conquering Kingdoms'],
        'ebook-listen'                         => ['price' => 'price_1UUvhK4UP8TTKz5rBoVQsSwE', 'kind' => 'ebook', 'name' => 'Listen: How to Hear God in a Noisy World'],
        'ebook-a-heart-on-fire'                => ['price' => 'price_1UUvhL4UP8TTKz5rxxEoXKOx', 'kind' => 'ebook', 'name' => 'A Heart on Fire'],
        'ebook-gods-men'                       => ['price' => 'price_1UUvhL4UP8TTKz5rJ72qA096', 'kind' => 'ebook', 'name' => "God's Men"],
        'ebook-pray-until-something-happens'   => ['price' => 'price_1UVdup4UP8TTKz5rMZiEFrT6', 'kind' => 'ebook', 'name' => 'Pray Until Something Happens'],
        'ebook-saved'                          => ['price' => 'price_1UVduq4UP8TTKz5rqx22fxgH', 'kind' => 'ebook', 'name' => "Saved: Understanding God's Greatest Gift"],
    ];
    $live = [
        // 'bootcamp' => ['price' => 'price_LIVE_...', 'kind' => 'bootcamp', 'name' => 'Spiritual Growth Bootcamp'],
    ];
    return sgc_livemode() ? $live : $test;
}

function sgc_item(string $sku): ?array {
    $c = sgc_catalog();
    return isset($c[$sku]) ? $c[$sku] + ['sku' => $sku] : null;
}

/* ------------------------------------------------------------- stripe api */

function sgc_form_encode(array $params, string $prefix = ''): array {
    $out = [];
    foreach ($params as $k => $v) {
        $key = $prefix === '' ? (string)$k : $prefix . '[' . $k . ']';
        if (is_array($v)) {
            $out = array_merge($out, sgc_form_encode($v, $key));
        } elseif (is_bool($v)) {
            $out[] = rawurlencode($key) . '=' . ($v ? 'true' : 'false');
        } elseif ($v !== null) {
            $out[] = rawurlencode($key) . '=' . rawurlencode((string)$v);
        }
    }
    return $out;
}

/** Call the Stripe API. Throws RuntimeException with Stripe's message on failure. */
function stripe_request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array {
    $cfg = sgc_config();
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $body = implode('&', sgc_form_encode($params));
    $headers = [
        'Authorization: Bearer ' . $cfg['stripe_key'],
        'Stripe-Version: ' . SGC_STRIPE_API_VERSION,
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: SGC-PHP/1.0',
    ];
    if ($idempotencyKey) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;

    $ch = curl_init();
    if ($method === 'GET') {
        if ($body !== '') $url .= '?' . $body;
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('Could not reach Stripe: ' . $err);
    $data = json_decode($raw, true);
    if ($status >= 400 || !is_array($data)) {
        $msg = $data['error']['message'] ?? ('Stripe error ' . $status);
        throw new RuntimeException($msg, $status);
    }
    return $data;
}

/** Verify a webhook payload using the Stripe-Signature header (t=..., v1=...). */
function stripe_verify_webhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool {
    $timestamp = null; $sigs = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $timestamp = (int)$kv[1];
        if ($kv[0] === 'v1') $sigs[] = $kv[1];
    }
    if (!$timestamp || !$sigs) return false;
    if (abs(time() - $timestamp) > $tolerance) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($sigs as $s) { if (hash_equals($expected, $s)) return true; }
    return false;
}

/* ----------------------------------------------------------------- storage */

function sgc_store_dir(string $sub): string {
    $d = sgc_private_dir() . '/data/' . $sub;
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

function sgc_save(string $sub, string $id, array $data): void {
    $id = preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
    file_put_contents(sgc_store_dir($sub) . "/$id.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function sgc_load(string $sub, string $id): ?array {
    $id = preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
    $f = sgc_store_dir($sub) . "/$id.json";
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/** Returns true the first time a key is claimed, false if it was already claimed. */
function sgc_claim_once(string $sub, string $id): bool {
    $id = preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
    $f = sgc_store_dir($sub) . "/$id.lock";
    $h = @fopen($f, 'x');
    if ($h === false) return false;
    fwrite($h, date('c'));
    fclose($h);
    return true;
}

function sgc_release_claim(string $sub, string $id): void {
    $id = preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
    @unlink(sgc_store_dir($sub) . "/$id.lock");
}

function sgc_log(string $channel, string $message, array $context = []): void {
    $dir = sgc_private_dir() . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $line = date('c') . " [$channel] $message" . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '') . "\n";
    @file_put_contents($dir . '/stripe-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/* ------------------------------------------------------------------- http */

function sgc_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Only accept POSTs that come from our own pages. */
function sgc_require_same_origin_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sgc_json(['error' => 'Method not allowed'], 405);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $host = parse_url(sgc_config()['site_url'], PHP_URL_HOST);
    $oh = parse_url($origin, PHP_URL_HOST);
    if (!$oh || ($oh !== $host && $oh !== 'www.' . $host)) sgc_json(['error' => 'Forbidden'], 403);
}

function sgc_read_json_body(): array {
    $raw = file_get_contents('php://input', false, null, 0, 64 * 1024);
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

function sgc_random_id(int $bytes = 16): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/* ------------------------------------------------------------------- email */

function sgc_mail(string $to, string $subject, string $html): bool {
    $cfg = sgc_config();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = $cfg['mail_from'];
    $body = '<!doctype html><html><body style="margin:0;background:#F2EFE9;font-family:Arial,Helvetica,sans-serif;color:#050A14">'
          . '<div style="max-width:560px;margin:0 auto;padding:32px 24px">'
          . '<p style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#C21B1B;margin:0 0 16px">School of Spiritual Growth</p>'
          . $html
          . '<p style="font-size:12px;color:#666;margin-top:32px;border-top:1px solid #ddd;padding-top:16px">School of Spiritual Growth &middot; spiritualgrowthcamp.com<br>Questions? Reply to this email.</p>'
          . '</div></body></html>';

    // Preferred: Resend (spiritualgrowthcamp.com is a verified sending domain there).
    if (!empty($cfg['resend_api_key'])) {
        $res = sgc_http_json('POST', 'https://api.resend.com/emails', $cfg['resend_api_key'], [
            'from' => $cfg['mail_from_name'] . " <$from>",
            'to' => [$to],
            'reply_to' => $cfg['reply_to'] ?? $from,
            'subject' => $subject,
            'html' => $body,
        ]);
        if ($res['ok']) return true;
        sgc_log('mail', 'Resend failed, falling back to mail()', ['to' => $to, 'subject' => $subject, 'status' => $res['status'], 'error' => mb_substr($res['raw'], 0, 300)]);
    }

    // Fallback: the host's PHP mailer (often filtered as spam).
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . mb_encode_mimeheader($cfg['mail_from_name']) . " <$from>",
        "Reply-To: $from",
    ]);
    $ok = @mail($to, mb_encode_mimeheader($subject), $body, $headers, '-f' . $from);
    if (!$ok) sgc_log('mail', 'mail() failed', ['to' => $to, 'subject' => $subject]);
    return $ok;
}

/** Small JSON HTTP helper for Resend and Sender. Returns [ok, status, data, raw]. */
function sgc_http_json(string $method, string $url, string $bearer, ?array $body = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer, 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: SGC-PHP/1.0'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => $err];
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => json_decode($raw, true), 'raw' => (string)$raw];
}

/* ------------------------------------------------------------------ cohorts */

/**
 * The Bootcamp cohort currently open for applications.
 * Cohorts start on January 15 (Cohort 1), May 15 (Cohort 2) and September 15 (Cohort 3).
 * From a cohort's start date onwards, applications are for the next one.
 * Must match SGC_nextCohort() in assets/sgc.js.
 */
function sgc_cohort(?DateTimeImmutable $now = null): array {
    $tz = new DateTimeZone('America/Edmonton');
    $now = $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $y = (int)$now->format('Y');
    $starts = [1 => '01-15', 2 => '05-15', 3 => '09-15'];
    foreach ([$y, $y + 1] as $year) {
        foreach ($starts as $n => $md) {
            $d = "$year-$md";
            if ($today < $d) {
                $month = (new DateTimeImmutable($d, $tz))->format('F');
                return ['number' => $n, 'year' => $year, 'start' => $d, 'label' => "Cohort $n, $month $year"];
            }
        }
    }
    throw new LogicException('unreachable');
}

/* ------------------------------------------------------------------- sender */

/**
 * Add (or update) a paid Bootcamp applicant in Sender.net, in the
 * "Spiritual Growth Bootcamp" group, with every answer as a custom field.
 * Joining the group starts the Sender confirmation automation.
 * Returns true when Sender accepted the subscriber.
 */
function sgc_sender_add_applicant(array $app): bool {
    $cfg = sgc_config();
    if (empty($cfg['sender_api_token'])) return false;
    $token = $cfg['sender_api_token'];
    $group = $cfg['sender_group_bootcamp'];

    $map = [
        'cohort' => 'Cohort', 'age' => 'Age', 'gender' => 'Gender', 'country' => 'Country',
        'christianduration' => 'ChristianDuration', 'denomination' => 'Denomination',
        'priormentorship' => 'PriorMentorship', 'spiritualrating' => 'SpiritualRating',
        'joinreason' => 'JoinReason', 'growtharea' => 'GrowthArea', 'expectations' => 'Expectations',
        'churchmember' => 'ChurchMember', 'churchname' => 'ChurchName',
        'commitment90day' => 'Commitment90Day', 'commitmentjournal' => 'CommitmentJournal',
        'commitmentweekly' => 'CommitmentWeekly', 'accountabilitypartner' => 'AccountabilityPartner',
        'timestamp' => 'Timestamp',
    ];
    $numeric = ['age', 'spiritualrating'];
    $fields = [];
    foreach ($map as $senderName => $appKey) {
        $v = $app[$appKey] ?? '';
        if ($v === '' || $v === null) continue;
        $fields['{$' . $senderName . '}'] = in_array($senderName, $numeric, true) ? (int)$v : (string)$v;
    }

    $body = [
        'email' => $app['Email'],
        'firstname' => $app['FirstName'],
        'lastname' => $app['LastName'],
        'groups' => [$group],
        'fields' => $fields,
        'trigger_automation' => true,
    ];
    $phone = preg_replace('/[^\d+]/', '', (string)($app['Phone'] ?? ''));
    if (preg_match('/^(\+|00)\d{7,15}$/', $phone)) $body['phone'] = $phone;

    $res = sgc_http_json('POST', 'https://api.sender.net/v2/subscribers', $token, $body);
    if (!$res['ok'] && isset($body['phone'])) {
        // A phone number Sender rejects should not lose the whole record.
        unset($body['phone']);
        $res = sgc_http_json('POST', 'https://api.sender.net/v2/subscribers', $token, $body);
    }
    if (!$res['ok']) {
        // Existing subscriber: update their fields and add them to the group.
        $upd = sgc_http_json('PATCH', 'https://api.sender.net/v2/subscribers/' . rawurlencode($app['Email']), $token,
            ['firstname' => $app['FirstName'], 'lastname' => $app['LastName'], 'fields' => $fields]);
        $grp = sgc_http_json('POST', 'https://api.sender.net/v2/subscribers/groups/' . rawurlencode($group), $token,
            ['subscribers' => [$app['Email']], 'trigger_automation' => true]);
        if ($upd['ok'] && $grp['ok']) return true;
        sgc_log('sender', 'Could not add applicant to Sender', [
            'application_id' => $app['id'] ?? '', 'create' => $res['status'] . ' ' . mb_substr($res['raw'], 0, 300),
            'update' => $upd['status'], 'group' => $grp['status'] . ' ' . mb_substr($grp['raw'], 0, 200),
        ]);
        return false;
    }
    return true;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ------------------------------------------------------- checkout sessions */

/** Shared Checkout Session defaults for every flow. */
function sgc_checkout_params(array $extra): array {
    $cfg = sgc_config();
    $p = [
        'success_url' => rtrim($cfg['site_url'], '/') . '/thank-you.html?session_id={CHECKOUT_SESSION_ID}',
        'allow_promotion_codes' => true,
        'billing_address_collection' => 'auto',
    ];
    if (!empty($cfg['automatic_tax'])) {
        // Only switch this on once a head office address and at least one tax
        // registration are set up in Stripe Tax; otherwise no tax is collected.
        $p['automatic_tax'] = ['enabled' => true];
    }
    return array_replace_recursive($p, $extra);
}
