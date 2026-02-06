<?php

if (!defined('ABSPATH')) {
    exit;
}

return [
    'defaults' => [
        'guard' => 'modular',
    ],

    'guards' => [
        'modular' => [
            'driver' => 'modular',
            'provider' => 'sites',
            'hash' => false,
        ],
    ],
];
