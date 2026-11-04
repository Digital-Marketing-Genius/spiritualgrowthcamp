<?php
/**
 * POST /api/waitlist.php
 * Waitlist sign-ups (School of Doctrine, School of Prayer, Missions & Apologetics, Prophetic School)
 * and partnership requests (School of the Spirit).
 *
 * Each submission is saved in sgc-private/data/waitlist/, emailed to admin_email, confirmed to the
 * person, and added to the Sender group named in api/_programs.php (found by its title, or set
 * explicitly with config key sender_group_<programme>, e.g. 'sender_group_doctrine' => 'abc123').
 */
require __DIR__ . '/_lib.php';
sgc_require_same_origin_post();
$in = sgc_read_json_body();
if (!empty($in['website'])) sgc_json(['ok' => true]); // honeypot

$programs = require __DIR__ . '/_programs.php';
$key = (string)($in['program'] ?? '');
$prog = $programs[$key] ?? null;
if (!$prog) sgc_json(['error' => 'Unknown programme.'], 400);
$partner = $prog['partner'];

$fields = [
    'firstname' => ['First name', true, 80], 'lastname' => ['Last name', true, 80],
    'email' => ['Email', true, 160], 'phone' => ['Mobile number', $partner, 40],
    'country' => ['Country', true, 80],
];
if ($partner) {
    $fields += ['organisation' => ['Church, ministry or organisation', true, 160], 'role' => ['Your role', true, 120],
                'city' => ['City', true, 80], 'attendance' => ['Expected attendance', true, 40],
                'venue' => ['Venue', true, 60], 'dates' => ['Preferred season or dates', false, 120],
                'message' => ['Vision for the camp', false, 3000]];
} else {
    $fields += ['hope' => ['Hoping to gain', false, 2000]];
}
$rec = [];
foreach ($fields as $k => [$label, $req, $max]) {
    $v = trim((string)($in[$k] ?? ''));
    if ($req && $v === '') sgc_json(['error' => "Please fill in: $label."], 400);
    $rec[$k] = mb_substr($v, 0, $max);
}
if (!filter_var($rec['email'], FILTER_VALIDATE_EMAIL)) sgc_json(['error' => 'Please enter a valid email address.'], 400);

// Light abuse limit: 8 submissions an hour per address.
$rid = 'rate_' . substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'x') . date('YmdH')), 0, 32);
$rate = sgc_load('waitlist', $rid) ?? ['n' => 0];
if ($rate['n'] >= 8) sgc_json(['error' => 'Too many submissions. Please try again in an hour.'], 429);
$rate['n']++; sgc_save('waitlist', $rid, $rate);

$id = ($partner ? 'partner_' : 'wait_') . $key . '_' . date('Ymd') . '_' . sgc_random_id(6);
$rec = ['id' => $id, 'program' => $key, 'program_name' => $prog['name'], 'submitted_at' => gmdate('c')] + $rec;
sgc_save('waitlist', $id, $rec);

$cfg = sgc_config();
$site = rtrim($cfg['site_url'], '/');

// Sender (best effort)
$inSender = false;
if (!empty($cfg['sender_api_token'])) {
    $group = $cfg['sender_group_' . $key] ?? sgc_sender_group_by_title($prog['sender_group']);
    if ($group) {
        $f = ['{$country}' => $rec['country']];
        if (!empty($rec['hope'])) $f['{$expectations}'] = $rec['hope'];
        if (!empty($rec['city'])) $f['{$city}'] = $rec['city'];
        if (!empty($rec['organisation'])) $f['{$churchname}'] = $rec['organisation'];
        $body = ['email' => $rec['email'], 'firstname' => $rec['firstname'], 'lastname' => $rec['lastname'], 'groups' => [$group], 'fields' => $f, 'trigger_automation' => true];
        $ph = preg_replace('/[^\d+]/', '', $rec['phone']);
        if (preg_match('/^(\+|00)\d{7,15}$/', $ph)) $body['phone'] = $ph;
        $r = sgc_http_json('POST', 'https://api.sender.net/v2/subscribers', $cfg['sender_api_token'], $body);
        if (!$r['ok']) $r = sgc_http_json('POST', 'https://api.sender.net/v2/subscribers/groups/' . rawurlencode($group), $cfg['sender_api_token'], ['subscribers' => [$rec['email']], 'trigger_automation' => true]);
        $inSender = $r['ok'];
        if (!$inSender) sgc_log('sender', 'Waitlist subscriber not added', ['program' => $key, 'status' => $r['status']]);
    } else {
        sgc_log('sender', 'Sender group not found', ['title' => $prog['sender_group']]);
    }
}
$rec['sender_synced'] = $inSender;
sgc_save('waitlist', $id, $rec);

// Confirmation to the person
if ($partner) {
    sgc_mail($rec['email'], 'We received your School of the Spirit partnership request',
        '<h1 style="font-family:Georgia,serif;font-weight:normal">Thank you, ' . h($rec['firstname']) . '.</h1>'
        . '<p>We have received your request to host the School of the Spirit in ' . h($rec['city']) . ', ' . h($rec['country']) . '.</p>'
        . '<p>Our team will contact you to talk about your vision, dates, venue and next steps. Sending a request does not commit you to anything.</p>'
        . '<p style="font-style:italic;color:#555">"But ye shall receive power, after that the Holy Ghost is come upon you." Acts 1:8</p>');
} else {
    sgc_mail($rec['email'], 'You are on the ' . $prog['name'] . ' waitlist',
        '<h1 style="font-family:Georgia,serif;font-weight:normal">You are on the list, ' . h($rec['firstname']) . '.</h1>'
        . '<p>Thank you for joining the waitlist for the <b>' . h($prog['name']) . '</b>. We will email you as soon as enrolment opens, with the start date and everything you need to prepare.</p>'
        . '<p>In the meantime, explore the <a href="' . h($site) . '/bookstore.html" style="color:#C21B1B">bookstore</a> or the <a href="' . h($site) . '/bootcamp.html" style="color:#C21B1B">Spiritual Growth Bootcamp</a>.</p>');
}

// Alert to admin
$rows = '';
foreach ($rec as $k => $v) {
    if (is_bool($v)) $v = $v ? 'yes' : 'no';
    $rows .= '<tr><td style="padding:4px 8px;color:#666;vertical-align:top">' . h($k) . '</td><td style="padding:4px 8px">' . nl2br(h($v)) . '</td></tr>';
}
sgc_mail($cfg['admin_email'], ($partner ? 'New School of the Spirit partnership request: ' : 'New ' . $prog['name'] . ' waitlist sign-up: ') . $rec['firstname'] . ' ' . $rec['lastname'],
    '<table style="border-collapse:collapse;font-size:14px">' . $rows . '</table>'
    . ($inSender ? '' : '<p><b>Not added to Sender.</b> Create a Sender group named "' . h($prog['sender_group']) . '" and add this person manually.</p>'));

sgc_json(['ok' => true]);
