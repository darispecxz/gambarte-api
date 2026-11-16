<?php

return [
    'secret'  => env('JWT_SECRET', ''),
    'ttl'     => (int) env('JWT_TTL', 3600),       // segundos (1 hora por defecto, igual que Legacy)
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2592000), // 30 días
    'algo'    => env('JWT_ALGO', 'HS256'),
    'issuer'  => env('JWT_ISSUER', 'cgrapi'),
];
