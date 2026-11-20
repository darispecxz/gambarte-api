<?php

namespace App\Modules\Recargas\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Isolated, persistent UAT fixtures. Never connects to Chile or to CGR databases.
 * All writes use a file-cache lock; the simulated state expires after 24 hours.
 */
class UatRecargasSimulator
{
    private const KEY = 'recargas:uat:simulation:v1';
    private const TTL = 86400;
    private const PATH = '/api/integracion/recargas.php';

    public function request(string $method, string $path, array $payload): array
    {
        if (!app()->environment('uat') || config('services.recargas.uat_simulation') !== true) {
            throw new UatSimulationException('Simulador deshabilitado');
        }

        $cache = Cache::store('file');
        return $cache->lock(self::KEY . ':lock', 10)->block(5, function () use ($cache, $method, $path, $payload): array {
            $state = $cache->get(self::KEY) ?? $this->fixtures();
            $route = substr($path, strlen(self::PATH));

            if ($method === 'GET' && $route === '/pendientes') {
                $pending = array_values(array_filter($state['recargas'], function (array $r): bool {
                    return $r['estado'] === 0 && (!$r['reserva'] || $r['reserva']['expires_at_unix'] <= time());
                }));
                // Never expose reservation secrets through the listing endpoint.
                return array_map(function (array $r): array {
                    unset($r['reserva']);
                    return $r;
                }, $pending);
            }
            if ($method === 'GET' && $route === '/lineas') {
                return array_values($state['lineas']);
            }
            if ($method !== 'POST' || !preg_match('~^/([0-9]+)/(reservar|confirmar|liberar)$~', $route, $match)) {
                throw new UatSimulationException('Operación de simulación no reconocida');
            }

            $id = (int) $match[1];
            $action = $match[2];
            if (!isset($state['recargas'][$id])) throw new UatSimulationException('Recarga de prueba inexistente');
            $recarga = &$state['recargas'][$id];
            $requestId = (string) ($payload['request_id'] ?? '');
            $operator = (string) ($payload['operator'] ?? '');
            if ($requestId === '' || $operator === '') throw new UatSimulationException('Faltan datos del operador');

            if ($action === 'reservar') {
                $result = $this->reservar($recarga, $requestId, $operator);
            } else {
                $result = $this->finalizar($state, $recarga, $action, $payload, $requestId, $operator);
            }

            $cache->put(self::KEY, $state, self::TTL);
            return $result;
        });
    }

    private function reservar(array &$recarga, string $requestId, string $operator): array
    {
        if ($recarga['estado'] !== 0) throw new UatSimulationException('Recarga ya procesada');
        $current = $recarga['reserva'];
        if ($current && $current['expires_at_unix'] > time()) {
            if ($current['request_id'] !== $requestId || $current['operator'] !== $operator) {
                throw new UatSimulationException('Recarga reservada por otro operador');
            }
            return ['token' => $current['token'], 'expires_at' => date('c', $current['expires_at_unix'])];
        }
        $expires = time() + 300;
        $token = bin2hex(random_bytes(32));
        $recarga['reserva'] = [
            'request_id' => $requestId,
            'operator' => $operator,
            'token' => $token,
            'expires_at_unix' => $expires,
            'estado' => 'RESERVADA',
        ];
        return ['token' => $token, 'expires_at' => date('c', $expires)];
    }

    private function finalizar(array &$state, array &$recarga, string $action, array $payload, string $requestId, string $operator): array
    {
        $reservation = $recarga['reserva'];
        $token = (string) ($payload['token'] ?? '');
        if (!$reservation || !hash_equals($reservation['token'], $token)
            || $reservation['request_id'] !== $requestId || $reservation['operator'] !== $operator) {
            throw new UatSimulationException('Reserva no válida');
        }

        if ($action === 'confirmar' && $reservation['estado'] === 'CONFIRMADA') {
            return ['estado' => 'CONFIRMADA', 'id_linea' => $reservation['id_linea']];
        }
        if ($reservation['estado'] !== 'RESERVADA' || $reservation['expires_at_unix'] <= time() || $recarga['estado'] !== 0) {
            throw new UatSimulationException('Reserva vencida o estado inválido');
        }
        if ($action === 'liberar') {
            $recarga['reserva'] = null;
            return ['estado' => 'LIBERADA'];
        }

        $lineId = (int) ($payload['id_linea'] ?? 0);
        $line = $state['lineas'][$lineId] ?? null;
        if (!$line || $line['id_proveedor'] !== $recarga['id_proveedor']) {
            throw new UatSimulationException('Línea incompatible con el proveedor');
        }
        $amount = (float) $recarga['monto_bob'];
        if ((float) $line['saldo_bob'] < $amount) throw new UatSimulationException('Saldo insuficiente en la línea de prueba');

        $state['lineas'][$lineId]['saldo_bob'] = number_format((float) $line['saldo_bob'] - $amount, 2, '.', '');
        $recarga['estado'] = 1;
        $recarga['reserva']['estado'] = 'CONFIRMADA';
        $recarga['reserva']['id_linea'] = $lineId;
        return ['estado' => 'CONFIRMADA', 'id_linea' => $lineId];
    }

    private function fixtures(): array
    {
        $orders = [
            [900001, 190001, 1, '71234567', '100.00'],
            [900002, 190002, 2, '76543210', '50.00'],
            [900003, 190003, 3, '69876543', '200.00'],
            [900004, 190004, 1, '78811223', '300.00'],
        ];
        $recargas = [];
        foreach ($orders as [$id, $pos, $provider, $phone, $amount]) {
            $recargas[$id] = [
                'id_detalle_recarga' => $id, 'id_recarga' => $pos, 'codigo' => 'UAT-' . $pos,
                'id_proveedor' => $provider, 'celular' => $phone, 'monto_bob' => $amount,
                'fec_crea_recarga' => '2026-11-20 09:00:00', 'estado' => 0, 'reserva' => null,
            ];
        }

        $lines = [
            [910001, 1, 'ENTEL UAT 01', '850.00'], [910002, 1, 'ENTEL UAT 02', '75.00'],
            [910003, 2, 'TIGO UAT 01', '500.00'], [910004, 3, 'VIVA UAT 01', '300.00'],
        ];
        $lineas = [];
        foreach ($lines as [$id, $provider, $phone, $balance]) {
            $lineas[$id] = ['id_linea' => $id, 'id_proveedor' => $provider, 'numero' => $phone, 'saldo_bob' => $balance];
        }
        return ['recargas' => $recargas, 'lineas' => $lineas];
    }
}
