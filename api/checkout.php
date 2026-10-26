<?php
/**
 * POST /api/checkout.php
 * Body (JSON):
 *   { "items": [ {"sku": "ebook-gods-men", "quantity": 1}, ... ] }   ebook cart
 *   { "sku": "school-of-prayer" }                                     single programme
 *   { "sku": "through-the-bible" }                                    annual subscription
 * Returns: { "url": "https://checkout.stripe.com/..." }
 */
require __DIR__ . '/_lib.php';

sgc_require_same_origin_post();
$body = sgc_read_json_body();
$site = rtrim(sgc_config()['site_url'], '/');

try {
    /* ---------- annual subscription ---------- */
    if (($body['sku'] ?? '') === 'through-the-bible') {
        $item = sgc_item('through-the-bible');
        $session = stripe_request('POST', 'checkout/sessions', sgc_checkout_params([
            'mode' => 'subscription',
            'line_items' => [['price' => $item['price'], 'quantity' => 1]],
            'cancel_url' => $site . '/through-the-bible.html?canceled=1',
            'metadata' => ['flow' => 'through-the-bible'],
            'subscription_data' => ['metadata' => ['flow' => 'through-the-bible']],
            'integration_identifier' => 'sgc_bible_app_gqhnszlt',
        ]));
        sgc_json(['url' => $session['url']]);
    }

    /* ---------- single programme (School of Prayer) ---------- */
    if (($body['sku'] ?? '') === 'school-of-prayer') {
        $item = sgc_item('school-of-prayer');
        $session = stripe_request('POST', 'checkout/sessions', sgc_checkout_params([
            'mode' => 'payment',
            'customer_creation' => 'always',
            'line_items' => [['price' => $item['price'], 'quantity' => 1]],
            'cancel_url' => $site . '/?canceled=1',
            'metadata' => ['flow' => 'school-of-prayer'],
            'adaptive_pricing' => ['enabled' => true],
            'integration_identifier' => 'sgc_prayer_akrxcfvs',
        ]));
        sgc_json(['url' => $session['url']]);
    }

    /* ---------- ebook cart ---------- */
    $items = $body['items'] ?? null;
    if (!is_array($items) || !$items) sgc_json(['error' => 'Your cart is empty.'], 400);
    if (count($items) > 30) sgc_json(['error' => 'Too many items in one order.'], 400);

    $lineItems = []; $skus = [];
    foreach ($items as $row) {
        $sku = is_array($row) ? (string)($row['sku'] ?? '') : '';
        $item = sgc_item($sku);
        if (!$item || $item['kind'] !== 'ebook') sgc_json(['error' => 'One of the books in your cart is no longer available. Please refresh the page.'], 400);
        if (isset($skus[$sku])) continue;        // an ebook only needs to be bought once
        $skus[$sku] = true;
        $lineItems[] = ['price' => $item['price'], 'quantity' => 1];
    }

    $session = stripe_request('POST', 'checkout/sessions', sgc_checkout_params([
        'mode' => 'payment',
        'customer_creation' => 'always',
        'line_items' => $lineItems,
        'cancel_url' => $site . '/bookstore.html?canceled=1',
        'metadata' => ['flow' => 'ebooks'],
        'adaptive_pricing' => ['enabled' => true],
        'integration_identifier' => 'sgc_ebooks_xtbaguty',
    ]));
    sgc_json(['url' => $session['url']]);

} catch (Throwable $e) {
    sgc_log('checkout', 'Create session failed: ' . $e->getMessage());
    sgc_json(['error' => 'We could not start checkout. Please try again in a moment.'], 502);
}
