<?php
return [
    'enabled' => env('WHATSAPP_ENABLED', false),
    'provider' => env('WHATSAPP_PROVIDER', 'evolution'),
    'allow_production' => env('WHATSAPP_ALLOW_PRODUCTION', false),
    'test_recipient' => env('WHATSAPP_TEST_RECIPIENT', ''),
    'url' => env('EVOLUTION_API_URL', 'http://localhost:8080'),
    'key' => env('EVOLUTION_API_KEY', ''),
    'instance' => env('EVOLUTION_API_INSTANCE', ''),
    'agency_instances' => json_decode(env('EVOLUTION_AGENCY_INSTANCES', '{}'), true) ?: [],
];
