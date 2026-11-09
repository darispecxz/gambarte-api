<?php

return [
    'secret'  => env('JWT_SECRET', ''),
    'ttl'     => (int) env('JWT_TTL', 3600),       // segundos (1 hora por defecto, igual que Legacy)
    'algo'    => env('JWT_ALGO', 'HS256'),
    'issuer'  => env('JWT_ISSUER', 'cgrapi'),
];
