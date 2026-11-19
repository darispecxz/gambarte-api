<?php

namespace App\Modules\Recargas\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChileRecargasClient
{
    public function request(string $method, string $path, array $payload = []): array
    {
        $url = rtrim((string) config('services.recargas.url'), '/');
        $secret = (string) config('services.recargas.secret');
        $client = (string) config('services.recargas.client_id');
        if (!str_starts_with($url, 'https://') || strlen($secret) < 32 || !$client) {
            throw new RuntimeException('Integración de recargas sin configuración HTTPS/HMAC válida');
        }

        $body = $method === 'GET' ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        // Contract v1: method, exact path, SHA256 raw body, timestamp, nonce.
        $canonical = implode("\n", [$method, $path, hash('sha256', $body), $timestamp, $nonce]);
        $headers = [
            'X-Client-ID' => $client,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => hash_hmac('sha256', $canonical, $secret),
            'Content-Type' => 'application/json',
        ];
        $response = Http::withHeaders($headers)->timeout(15)->withBody($body, 'application/json')
            ->send($method, $url . $path);
        if (!$response->successful()) {
            throw new RuntimeException('Chile respondió HTTP ' . $response->status());
        }
        $json = $response->json();
        if (!is_array($json) || !($json['success'] ?? false)) {
            throw new RuntimeException('Respuesta inválida del servicio Chile');
        }
        return (array) ($json['data'] ?? []);
    }
}
