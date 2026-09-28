<?php
// Easiest: open Calage in a browser, the setup wizard writes config.php.
// By hand: copy this file to config.php, then edit it; the password hash is generated with
// "php bin/hash-password.php". Afterwards, the Settings page edits this file.
return [
    // Public URL of the application, without a trailing slash. May include a subfolder.
    'base_url' => 'https://your-domain.com/calage',

    // Interface language: en | fr. The share page follows the visitor's browser.
    'locale' => 'en',

    'admin' => [
        'username' => 'admin',
        'password_hash' => '', // password_hash(), never the password in plain text
    ],

    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls', // tls | ssl | none
        'username' => '',
        'password' => '',
        'from_email' => 'newsletters@your-domain.com',
        'from_name' => 'My agency',
    ],

    'paths' => [
        'database' => __DIR__ . '/data/app.sqlite',
        'tmp'      => __DIR__ . '/data/tmp', // sessions, upload chunks, pending imports
    ],

    // Time zone of the displayed dates (dates are stored in UTC).
    'timezone' => 'Europe/Paris',

    // true to display PHP errors (development only).
    'debug' => false,
];
