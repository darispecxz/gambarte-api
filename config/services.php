<?php

return [
    'recargas' => [
        'url' => env('RECARGAS_CHILE_URL', ''),
        'client_id' => env('RECARGAS_CHILE_CLIENT_ID', 'gambarte-bolivia'),
        'secret' => env('RECARGAS_CHILE_SECRET', ''),
        // Strictly gated by APP_ENV=uat in RecargasGateway.
        'uat_simulation' => env('RECARGAS_UAT_SIMULATION', false),
    ],
];
