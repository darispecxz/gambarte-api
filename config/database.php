<?php

return [

    'default' => env('DB_CONNECTION', 'cgr'),

    'connections' => [

        'cgr' => [
            'driver' => 'mysql',
            'host' => env('DB_CGR_HOST', '127.0.0.1'),
            'port' => env('DB_CGR_PORT', '3306'),
            'database' => env('DB_CGR_DATABASE', 'u752765750_cgr'),
            'username' => env('DB_CGR_USERNAME', ''),
            'password' => env('DB_CGR_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? [
                PDO::ATTR_EMULATE_PREPARES => true,
            ] : [],
        ],

        'conta' => [
            'driver' => 'mysql',
            'host' => env('DB_CONTA_HOST', '127.0.0.1'),
            'port' => env('DB_CONTA_PORT', '3306'),
            'database' => env('DB_CONTA_DATABASE', 'u752765750_conta'),
            'username' => env('DB_CONTA_USERNAME', ''),
            'password' => env('DB_CONTA_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? [
                PDO::ATTR_EMULATE_PREPARES => true,
            ] : [],
        ],

        'chile' => [
            'driver' => 'mysql',
            'host' => env('DB_CHILE_HOST', '127.0.0.1'),
            'port' => env('DB_CHILE_PORT', '3306'),
            'database' => env('DB_CHILE_DATABASE', ''),
            'username' => env('DB_CHILE_USERNAME', ''),
            'password' => env('DB_CHILE_PASSWORD', ''),
            'unix_socket' => '',
            'charset' => 'utf8',
            'collation' => 'utf8_general_ci',
            'prefix' => '',
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? [
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_TIMEOUT => 30,
            ] : [],
        ],
    ],

    'migrations' => 'migrations',

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
    ],
];
