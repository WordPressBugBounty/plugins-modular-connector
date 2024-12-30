<?php

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
