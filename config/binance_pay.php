<?php

return [
    'history' => [
        'base_url' => env('BINANCE_PAY_HISTORY_API_BASE', 'https://api.binance.com'),
        'api_key' => env('BINANCE_PAY_HISTORY_API_KEY'),
        'api_secret' => env('BINANCE_PAY_HISTORY_API_SECRET'),
        'receiver_id' => env('BINANCE_PAY_HISTORY_RECEIVER_ID'),
    ],
];
