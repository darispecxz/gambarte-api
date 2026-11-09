<?php

namespace App\Modules\Cripto\Services;

use App\Modules\Shared\Database\RawConnection as DB;

class BinancePayHistoryService
{
    private const ENDPOINT = '/sapi/v1/pay/transactions';
    private const PAGE_LIMIT = 100;

    public function sync(?int $fromMs = null, ?int $toMs = null): array
    {
        $key = trim((string) config('binance_pay.history.api_key', ''));
        $secret = trim((string) config('binance_pay.history.api_secret', ''));
        $receiverId = trim((string) config('binance_pay.history.receiver_id', ''));
        if ($key === '' || $secret === '' || $receiverId === '') return ['success' => false, 'message' => 'Binance Pay history credentials or receiver account ID are not configured'];

        try {
            $base = rtrim((string) config('binance_pay.history.base_url', 'https://api.binance.com'), '/');
            $serverOffsetMs = $this->serverTimeOffset($base);
            $now = (int) floor(microtime(true) * 1000) + ($serverOffsetMs ?? 0);
            if ($fromMs === null) {
                $syncState = DB::selectOne('SELECT last_success_at FROM Pos_CriptoPaySync WHERE id=1');
                $lastSuccess = !empty($syncState['last_success_at']) ? strtotime($syncState['last_success_at'] . ' UTC') : false;
                // On first run, import a useful history window. Later runs resume from the
                // last success with overlap so a delayed scheduler cannot silently skip payments.
                $fromMs = $lastSuccess
                    ? max(1, ($lastSuccess - 5 * 60) * 1000)
                    : $now - 30 * 24 * 60 * 60 * 1000;
            }
            $toMs = $toMs ?? $now;
            if ($fromMs <= 0 || $toMs <= $fromMs || ($toMs - $fromMs) > 90 * 24 * 60 * 60 * 1000) {
                throw new \RuntimeException('Invalid Binance Pay query interval (maximum 90 days)');
            }

            $records = $this->fetchWindow($key, $secret, $fromMs, $toMs, 0, $serverOffsetMs);
            $inserted = 0;
            foreach ($records as $row) if ($this->store($row)) $inserted++;
            DB::execute('UPDATE Pos_CriptoPaySync SET last_success_at=NOW(),last_error_at=NULL,last_error=NULL,last_records=:n WHERE id=1', [':n' => $inserted]);
            return ['success' => true, 'message' => 'Binance Pay history synchronized', 'data' => ['received' => count($records), 'inserted' => $inserted]];
        } catch (\Throwable $e) {
            // Sanitized: never persist response bodies, request URLs, signatures or credentials.
            DB::execute('UPDATE Pos_CriptoPaySync SET last_error_at=NOW(),last_error=:e WHERE id=1', [':e' => mb_substr($this->safeError($e), 0, 240)]);
            return ['success' => false, 'message' => $this->safeError($e)];
        }
    }

    private function serverTimeOffset(string $base): ?int
    {
        // Use alternate official Binance hosts if the configured host's public time route stalls.
        $hosts = array_unique([$base, 'https://api-gcp.binance.com', 'https://api1.binance.com']);
        foreach ($hosts as $host) {
            $before = (int) floor(microtime(true) * 1000);
            $handle = curl_init(rtrim($host, '/') . '/api/v3/time');
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            $responseBody = curl_exec($handle);
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);
            if ($responseBody === false || $httpStatus < 200 || $httpStatus >= 300) continue;
            $body = json_decode($responseBody, true);
            if (!is_array($body) || !isset($body['serverTime']) || !is_numeric($body['serverTime'])) continue;
            $after = (int) floor(microtime(true) * 1000);
            return (int) $body['serverTime'] - (int) floor(($before + $after) / 2);
        }
        // Fallback to the machine clock; recvWindow is widened to Binance's documented limit.
        return null;
    }

    private function fetchWindow(string $key, string $secret, int $start, int $end, int $depth, int $serverOffsetMs): array
    {
        if ($depth > 12) throw new \RuntimeException('Pay history window is too dense to fetch safely');
        $base = rtrim((string) config('binance_pay.history.base_url', 'https://api.binance.com'), '/');
        $params = ['startTime' => $start, 'endTime' => $end, 'limit' => self::PAGE_LIMIT,
            'recvWindow' => $serverOffsetMs === null ? 60000 : 5000,
            'timestamp' => (int) floor(microtime(true) * 1000) + ($serverOffsetMs ?? 0)];
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $query, $secret);
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL extension is required to query Binance Pay history');
        $handle = curl_init($base . self::ENDPOINT . '?' . $query . '&signature=' . $signature);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['X-MBX-APIKEY: ' . $key, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        $responseBody = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);
        if ($responseBody === false) throw new \RuntimeException('Could not connect to Binance Pay history service (cURL ' . $curlError . ')');
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $errorBody = json_decode($responseBody, true);
            $errorCode = is_array($errorBody) && isset($errorBody['code']) ? (string) $errorBody['code'] : '';
            $errorMessage = is_array($errorBody) && isset($errorBody['msg']) ? trim((string) $errorBody['msg']) : '';
            $detail = $errorCode !== '' ? ' (Binance ' . $errorCode . ')' : '';
            if ($errorMessage !== '') $detail .= ': ' . substr(strip_tags($errorMessage), 0, 180);
            throw new \RuntimeException('Binance Pay history request failed with HTTP ' . $httpStatus . $detail);
        }
        $body = json_decode($responseBody, true);
        if (!is_array($body) || (string) ($body['code'] ?? '') !== '000000' || !is_array($body['data'] ?? null)) {
            throw new \RuntimeException('Binance Pay returned an unsuccessful history response');
        }
        $rows = $body['data'];
        if (count($rows) < self::PAGE_LIMIT) return $rows;
        if (($end - $start) <= 1000) throw new \RuntimeException('Binance Pay history reached the page limit for a one-second interval');
        $middle = (int) floor(($start + $end) / 2);
        return array_merge($this->fetchWindow($key, $secret, $start, $middle, $depth + 1, $serverOffsetMs), $this->fetchWindow($key, $secret, $middle + 1, $end, $depth + 1, $serverOffsetMs));
    }

    private function store(array $row): bool
    {
        $transactionId = trim((string) ($row['transactionId'] ?? ''));
        $time = (int) ($row['transactionTime'] ?? 0);
        $amount = (string) ($row['amount'] ?? '');
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if ($transactionId === '' || $time <= 0 || !is_numeric($amount) || $currency === '') return false;
        $receiverId = isset($row['receiverInfo']['binanceId']) ? (string) $row['receiverInfo']['binanceId'] : null;
        $expectedReceiver = trim((string) config('binance_pay.history.receiver_id', ''));
        $verified = $expectedReceiver !== '' && $receiverId !== null && hash_equals($expectedReceiver, $receiverId);
        $walletTypes = $row['walletTypes'] ?? [];
        $noteText = trim((string) ($row['note'] ?? ''));
        $noteText = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $noteText) ?? '';
        $noteText = mb_substr($noteText, 0, 128);
        $note = strtoupper($noteText);
        $noteCode = preg_match('/^GB-[0-9]{5}[A-HJ-NP-Z]$/', $note) ? $note : null;
        $stored = DB::execute(
            'INSERT INTO Pos_CriptoPayTransaccion
             (transaction_id,order_id,order_type,transaction_time,amount,currency,wallet_type,payer_binance_id,receiver_binance_id,receptor_verificado,note_code,nota_binance)
             VALUES (:tx,:order,:type,:time,:amount,:currency,:wallet,:payer,:receiver,:verified,:note,:note_text)
             ON DUPLICATE KEY UPDATE note_code=VALUES(note_code),nota_binance=VALUES(nota_binance)',
            [':tx' => $transactionId, ':order' => isset($row['orderId']) ? (string) $row['orderId'] : null,
             ':type' => substr((string) ($row['orderType'] ?? 'UNKNOWN'), 0, 40),
             ':time' => date('Y-m-d H:i:s', (int) floor($time / 1000)), ':amount' => $amount, ':currency' => $currency,
             ':wallet' => substr(implode(',', array_map('strval', is_array($walletTypes) ? $walletTypes : [])), 0, 24),
             ':payer' => isset($row['payerInfo']['binanceId']) ? (string) $row['payerInfo']['binanceId'] : null,
             ':receiver' => $receiverId, ':verified' => $verified ? 1 : 0, ':note' => $noteCode,
             ':note_text' => $noteText !== '' ? $noteText : null]
        );
        if ($stored > 0 && $noteCode !== null && $verified && strtoupper((string) ($row['orderType'] ?? '')) === 'C2C' && (float) $amount > 0) {
            $this->autoLinkByCode($transactionId, $noteCode, $currency, $amount, date('Y-m-d H:i:s', (int) floor($time / 1000)));
        }
        return $stored === 1;
    }

    private function autoLinkByCode(string $transactionId, string $noteCode, string $currency, string $amount, string $transactionTime): void
    {
        DB::transaction(function () use ($transactionId, $noteCode, $currency, $amount, $transactionTime) {
            $payment = DB::selectOne('SELECT id,id_operacion FROM Pos_CriptoPayTransaccion WHERE transaction_id=:tx FOR UPDATE', [':tx' => $transactionId]);
            if (!$payment || !empty($payment['id_operacion'])) return;
            $operations = DB::select(
                "SELECT o.id FROM Pos_CriptoOperacion o JOIN Pos_CriptoActivo a ON a.id=o.id_activo
                 WHERE o.codigo_referencia=:code AND o.tipo='COMPRA' AND o.estado='PENDIENTE_RECEPCION'
                   AND a.simbolo=:currency AND o.cantidad_cripto=:amount
                   AND :paid_at BETWEEN DATE_SUB(o.created_at,INTERVAL 5 MINUTE) AND DATE_ADD(o.created_at,INTERVAL 24 HOUR)
                 FOR UPDATE",
                [':code' => $noteCode, ':currency' => $currency, ':amount' => $amount, ':paid_at' => $transactionTime]
            );
            if (count($operations) !== 1) return;
            $linked = DB::execute(
                "UPDATE Pos_CriptoPayTransaccion SET id_operacion=:op,conciliacion_metodo='AUTOMATICA'
                 WHERE id=:id AND id_operacion IS NULL",
                [':op' => (int) $operations[0]['id'], ':id' => (int) $payment['id']]
            );
            if ($linked > 0) {
                DB::execute(
                    "INSERT INTO Pos_CriptoEvento (id_operacion,accion,estado_anterior,estado_nuevo,cod_usuario,referencia,motivo,metadata)
                     VALUES (:op,'RECEPCION_PAY_AUTOCONCILIADA','PENDIENTE_RECEPCION','PENDIENTE_RECEPCION',0,:tx,NULL,:meta)",
                    [':op' => (int) $operations[0]['id'], ':tx' => $transactionId,
                     ':meta' => json_encode(['source' => 'BINANCE_PAY', 'note_code' => $noteCode], JSON_UNESCAPED_UNICODE)]
                );
            }
        });
    }

    private function safeError(\Throwable $e): string
    {
        if ($e instanceof \RuntimeException) return $e->getMessage();
        if ($e instanceof \Illuminate\Http\Client\ConnectionException) return 'No se pudo conectar con Binance Pay. Verifica la conexión del servidor.';
        if ($e instanceof \PDOException || $e instanceof \Illuminate\Database\QueryException) return 'No se pudo guardar el historial de Binance Pay en la base de datos.';
        if ($e instanceof \Error) {
            $detail = $e->getMessage();
            foreach ([config('binance_pay.history.api_key'), config('binance_pay.history.api_secret')] as $credential) {
                if (is_string($credential) && $credential !== '') $detail = str_replace($credential, '[oculto]', $detail);
            }
            $detail = preg_replace('/signature=[^&\s]+/i', 'signature=[oculta]', $detail) ?? $detail;
            return 'Error interno al sincronizar (' . get_class($e) . '): ' . substr($detail, 0, 160);
        }
        return 'Error interno al sincronizar (' . get_class($e) . ').';
    }
}
