<?php

namespace App\Modules\Facturacion;

class SiatClient
{
    private string $baseUrl;
    private int $timeout;

    public function __construct(?string $baseUrl = null, int $timeout = 10)
    {
        $this->baseUrl = rtrim($baseUrl ?? env('SIAT_BASE_URL', 'http://localhost:8080/api'), '/');
        $this->timeout = $timeout;
    }

    public function enviarFacturaDivisas(array $facturaPayload): array
    {
        return $this->post('/divisas/recepcion/factura', $facturaPayload);
    }

    public function verificarNit(array $payload): array
    {
        return $this->post('/codigos/verificarnit', $payload);
    }

    public function verificarComunicacion(): array
    {
        return $this->get('/divisas/verificar/comunicacion');
    }

    private function post(string $path, array $data): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl . $path,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout + 5,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'error'   => $error ?: 'No se pudo conectar al servicio SIAT',
                'data'    => null,
            ];
        }

        $decoded = json_decode($response, true);

        return [
            'success'   => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'error'     => null,
            'data'      => $decoded,
        ];
    }

    private function get(string $path): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl . $path,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout + 5,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'error' => $error, 'data' => null];
        }

        return [
            'success'   => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'error'     => null,
            'data'      => json_decode($response, true),
        ];
    }
}
