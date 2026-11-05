<?php
/**
 * Learning platform access (spiritualgrowthcamp.com/learning/...).
 *
 * 1. Verifies the student's Firebase sign-in (ID token, RS256, signed by Google).
 * 2. Requires a verified email address.
 * 3. Allows only Bootcamp students: members of the Sender Bootcamp groups, paid applicants,
 *    the admin, or emails listed in config 'learning_allow'.
 *
 * Optional config keys (sgc-private/config.php):
 *   'firebase_project_id' => 'spiritual-growth-bootcamp',
 *   'learning_groups'     => ['dLGzxp','dPGEBy','dJAZYJ','azE627'],  // Sender group ids
 *   'learning_allow'      => ['someone@example.com'],                // extra emails
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/_lib.php';

function lp_cfg(): array {
    return sgc_config() + [
        'firebase_project_id' => 'spiritual-growth-bootcamp',
        'learning_groups' => ['dLGzxp', 'dPGEBy', 'dJAZYJ', 'azE627'],
        'learning_allow' => [],
    ];
}

function lp_b64url(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Google's current public certificates for Firebase ID tokens (cached per their max-age). */
function lp_google_certs(bool $refresh = false): array {
    $cache = sgc_load('learning', 'firebase_certs');
    if (!$refresh && $cache && ($cache['exp'] ?? 0) > time()) return $cache['certs'];
    $ch = curl_init('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10]);
    $raw = curl_exec($ch);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return $cache['certs'] ?? [];
    $headers = substr($raw, 0, $hsize);
    $certs = json_decode(substr($raw, $hsize), true) ?: [];
    $age = preg_match('/max-age=(\d+)/i', $headers, $m) ? (int)$m[1] : 3600;
    sgc_save('learning', 'firebase_certs', ['exp' => time() + min($age, 21600), 'certs' => $certs]);
    return $certs;
}

/** Returns the verified token claims, or null. */
function lp_verify_firebase_token(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h64, $p64, $s64] = $parts;
    $header = json_decode(lp_b64url($h64), true);
    $claims = json_decode(lp_b64url($p64), true);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) return null;

    $certs = lp_google_certs();
    if (!isset($certs[$header['kid']])) $certs = lp_google_certs(true);
    $cert = $certs[$header['kid']] ?? null;
    if (!$cert) return null;
    $key = openssl_pkey_get_public($cert);
    if (!$key || openssl_verify("$h64.$p64", lp_b64url($s64), $key, OPENSSL_ALGO_SHA256) !== 1) return null;

    $pid = lp_cfg()['firebase_project_id'];
    $now = time();
    if (($claims['aud'] ?? '') !== $pid) return null;
    if (($claims['iss'] ?? '') !== "https://securetoken.google.com/$pid") return null;
    if (empty($claims['sub']) || !is_string($claims['sub'])) return null;
    if (($claims['exp'] ?? 0) < $now - 60 || ($claims['iat'] ?? PHP_INT_MAX) > $now + 300) return null;
    return $claims;
}

function lp_bearer(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$h && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v;
    return trim(preg_replace('/^Bearer\s+/i', '', (string)$h));
}

/** Is this email a Bootcamp student? Cached: 30 min if yes, 5 min if no. */
function lp_is_student(string $email): bool {
    $cfg = lp_cfg();
    $email = strtolower(trim($email));
    if ($email === '') return false;
    if ($email === strtolower($cfg['admin_email'] ?? '') || in_array($email, array_map('strtolower', (array)$cfg['learning_allow']), true)) return true;

    $cid = 'student_' . substr(hash('sha256', $email), 0, 40);
    $c = sgc_load('learning', $cid);
    if ($c && ($c['until'] ?? 0) > time()) return (bool)$c['ok'];

    $ok = false;
    // Sender: member of a Bootcamp group?
    if (!empty($cfg['sender_api_token'])) {
        $r = sgc_http_json('GET', 'https://api.sender.net/v2/subscribers/' . rawurlencode($email), $cfg['sender_api_token']);
        if ($r['ok']) {
            $d = $r['data']['data'] ?? $r['data'] ?? [];
            $groups = array_merge((array)($d['subscriber_tags'] ?? []), (array)($d['groups'] ?? []));
            foreach ($groups as $g) {
                $gid = is_array($g) ? (string)($g['id'] ?? '') : (string)$g;
                if (in_array($gid, (array)$cfg['learning_groups'], true)) { $ok = true; break; }
            }
        }
    }
    // Paid Bootcamp applications on this site
    if (!$ok) {
        foreach ((array)glob(sgc_private_dir() . '/data/applications/app_*.json') as $f) {
            $a = json_decode((string)@file_get_contents($f), true);
            if (is_array($a) && strtolower($a['Email'] ?? '') === $email && in_array($a['status'] ?? '', ['paid_pending_review', 'accepted'], true)) { $ok = true; break; }
        }
    }
    sgc_save('learning', $cid, ['ok' => $ok, 'until' => time() + ($ok ? 1800 : 300)]);
    return $ok;
}
