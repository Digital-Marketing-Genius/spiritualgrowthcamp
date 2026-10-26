<?php
/**
 * TEMPLATE ONLY. Do not put real keys in this file (this repository is public).
 *
 * Copy this into the PRIVATE folder that sits next to public_html, NOT inside it:
 *
 *     domains/spiritualgrowthcamp.com/sgc-private/config.php
 *
 * then fill in the values. The website reads it from there, and Git deploys
 * never touch it.
 */
return [
    // Restricted API key (starts with rk_test_ in test mode, rk_live_ in live mode).
    // Permissions: Checkout Sessions = Write, Products = Read, Prices = Read.
    'stripe_key'     => 'rk_test_REPLACE_ME',

    // Signing secret of the webhook endpoint https://spiritualgrowthcamp.com/api/webhook.php
    'webhook_secret' => 'whsec_REPLACE_ME',

    'site_url'       => 'https://spiritualgrowthcamp.com',
    'mail_from'      => 'hello@spiritualgrowthcamp.com',
    'mail_from_name' => 'School of Spiritual Growth',
    'admin_email'    => 'hello@spiritualgrowthcamp.com',

    // Leave false until Stripe Tax has a head office address AND a registration.
    // With no registration, Stripe collects no tax even when this is true.
    'automatic_tax'  => false,

    // Optional: your Google Apps Script URL, to copy paid Bootcamp applications into a Sheet.
    'sheet_webhook_url' => '',

    'download_days'  => 30,   // how long ebook links work
    'download_limit' => 10,   // downloads per order link
];
