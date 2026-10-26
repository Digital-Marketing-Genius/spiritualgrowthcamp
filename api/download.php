<?php
/**
 * GET /api/download.php?t=<token>&b=<ebook sku>
 * Streams a purchased ebook from the private folder (never from public_html).
 * Files live in:  <domain folder>/sgc-private/ebooks/<sku>.pdf  (or .epub / .zip)
 */
require __DIR__ . '/_lib.php';

function fail(string $msg, int $code = 404): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Download</title>'
       . '<body style="margin:0;background:#050A14;color:#F2EFE9;font-family:Arial,Helvetica,sans-serif;display:grid;place-items:center;min-height:100vh;padding:24px">'
       . '<div style="max-width:440px;text-align:center"><p style="letter-spacing:2px;text-transform:uppercase;font-size:12px;color:#E33A2C">School of Spiritual Growth</p>'
       . '<h1 style="font-weight:300">' . h($msg) . '</h1><p style="color:rgba(242,239,233,.65)">Reply to your order email or write to hello@spiritualgrowthcamp.com and we will help.</p>'
       . '<p><a href="/bookstore.html" style="color:#F2EFE9">Back to the bookstore</a></p></div></body>';
    exit;
}

$token = (string)($_GET['t'] ?? '');
$sku   = (string)($_GET['b'] ?? '');
if (strlen($token) < 20 || !preg_match('/^[a-z0-9\-]+$/', $sku)) fail('This download link is not valid.', 400);

$key = hash('sha256', $token);
$grant = sgc_load('downloads', $key);
if (!$grant || !in_array($sku, $grant['skus'] ?? [], true)) fail('This download link is not valid.', 404);
if (time() > (int)$grant['expires_at']) fail('This download link has expired.', 410);
if ((int)$grant['downloads'] >= (int)$grant['limit']) fail('This link has reached its download limit.', 429);

$file = null;
foreach (['pdf', 'epub', 'zip'] as $ext) {
    $candidate = sgc_private_dir() . "/ebooks/$sku.$ext";
    if (is_file($candidate)) { $file = $candidate; break; }
}
if (!$file) {
    sgc_log('download', 'Ebook file missing', ['sku' => $sku]);
    fail('This book is being prepared. We have been notified and will email it to you shortly.', 503);
}

$grant['downloads'] = (int)$grant['downloads'] + 1;
$grant['last_download_at'] = date('c');
sgc_save('downloads', $key, $grant);

$item = sgc_item($sku);
$niceName = preg_replace('/[^A-Za-z0-9 \-]/', '', $item['name'] ?? $sku) . '.' . pathinfo($file, PATHINFO_EXTENSION);
$types = ['pdf' => 'application/pdf', 'epub' => 'application/epub+zip', 'zip' => 'application/zip'];

header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . $niceName . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($file);
