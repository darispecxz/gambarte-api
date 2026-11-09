<?php

namespace App\Modules\Cripto\Services;

use App\Modules\Cajero\Services\CajeroBalanceService;
use App\Modules\Shared\Database\RawConnection as DB;

class CriptoService
{
    public function __construct(private CajeroBalanceService $balance) {}

    public function binancePayQrDataUrl(): ?string
    {
        $saved = DB::selectOne(
            "SELECT qr_data, mime_type FROM Pos_CriptoConfiguracion WHERE clave='BINANCE_PAY_QR'"
        );
        if ($saved) {
            return 'data:' . $saved['mime_type'] . ';base64,' . base64_encode($saved['qr_data']);
        }

        $defaultPath = base_path('app/Modules/Cripto/Resources/binance-pay-qr.png');
        if (!is_file($defaultPath)) return null;
        return 'data:image/png;base64,' . base64_encode(file_get_contents($defaultPath));
    }

    public function guardarBinancePayQr(string $image, string $mimeType, int $codUsuario): array
    {
        DB::execute(
            "INSERT INTO Pos_CriptoConfiguracion (clave, qr_data, mime_type, cod_usuario_actualiza)
             VALUES ('BINANCE_PAY_QR', :image, :mime, :usr)
             ON DUPLICATE KEY UPDATE qr_data=VALUES(qr_data), mime_type=VALUES(mime_type),
                 cod_usuario_actualiza=VALUES(cod_usuario_actualiza), updated_at=CURRENT_TIMESTAMP",
            [':image' => $image, ':mime' => $mimeType, ':usr' => $codUsuario]
        );
        return ['success' => true, 'message' => 'QR de Binance Pay actualizado', 'data' => null];
    }

    public function binancePayHistory(int $user, int $agency, int $role): array
    {
        $historyDays = in_array($role, [2, 4], true) ? 550 : 30;
        $params = [];
        $scopeCount = '';
        $scopeMin = '';
        $scopeCode = '';
        $linkedScope = '';
        $visibleScope = '';
        $visibilityCondition = '1=1';
        if (!in_array($role, [2, 4], true)) {
            $scopeCount = ' AND o.id_agencia=:ag_count AND o.cod_usuario_cajero=:usr_count';
            $scopeMin = ' AND o.id_agencia=:ag_min AND o.cod_usuario_cajero=:usr_min';
            $scopeCode = ' AND code_o.id_agencia=:ag_code AND code_o.cod_usuario_cajero=:usr_code';
            $linkedScope = ' AND linked.id_agencia=:ag_linked AND linked.cod_usuario_cajero=:usr_linked';
            $visibleScope = ' AND visible_o.id_agencia=:ag_visible AND visible_o.cod_usuario_cajero=:usr_visible';
            $params = [':ag_count' => $agency, ':usr_count' => $user, ':ag_min' => $agency, ':usr_min' => $user,
                ':ag_code' => $agency, ':usr_code' => $user, ':ag_linked' => $agency, ':usr_linked' => $user,
                ':ag_visible' => $agency, ':usr_visible' => $user];
            $visibilityCondition = "(t.id_operacion IS NOT NULL OR code_a.id IS NOT NULL OR EXISTS (SELECT 1 FROM Pos_CriptoOperacion visible_o
                    JOIN Pos_CriptoActivo visible_a ON visible_a.id=visible_o.id_activo
                    WHERE visible_o.tipo='COMPRA' AND visible_o.estado='PENDIENTE_RECEPCION'
                      AND visible_a.simbolo=t.currency AND visible_o.cantidad_cripto=t.amount
                      AND t.transaction_time BETWEEN DATE_SUB(visible_o.created_at,INTERVAL 5 MINUTE) AND DATE_ADD(visible_o.created_at,INTERVAL 24 HOUR) {$visibleScope}))";
        }
        $rows = DB::select(
            "SELECT t.id,t.transaction_id,t.order_id,t.order_type,t.transaction_time,t.amount,t.currency,
                    t.wallet_type,t.payer_binance_id,t.receptor_verificado,t.id_operacion,t.confirmado_at,
                    t.note_code,t.nota_binance,t.conciliacion_metodo,
                    IF(code_a.id IS NOT NULL,1,(SELECT COUNT(*) FROM Pos_CriptoOperacion o JOIN Pos_CriptoActivo a ON a.id=o.id_activo
                     WHERE o.tipo='COMPRA' AND o.estado='PENDIENTE_RECEPCION' AND a.simbolo=t.currency
                       AND o.cantidad_cripto=t.amount AND t.transaction_time BETWEEN DATE_SUB(o.created_at,INTERVAL 5 MINUTE) AND DATE_ADD(o.created_at,INTERVAL 24 HOUR) {$scopeCount})) AS candidate_count,
                    COALESCE(IF(code_a.id IS NOT NULL,code_o.id,NULL),(SELECT MIN(o.id) FROM Pos_CriptoOperacion o JOIN Pos_CriptoActivo a ON a.id=o.id_activo
                     WHERE o.tipo='COMPRA' AND o.estado='PENDIENTE_RECEPCION' AND a.simbolo=t.currency
                       AND o.cantidad_cripto=t.amount AND t.transaction_time BETWEEN DATE_SUB(o.created_at,INTERVAL 5 MINUTE) AND DATE_ADD(o.created_at,INTERVAL 24 HOUR) {$scopeMin})) AS candidate_operation_id
             FROM Pos_CriptoPayTransaccion t
             LEFT JOIN Pos_CriptoOperacion code_o ON code_o.codigo_referencia=t.note_code
               AND code_o.tipo='COMPRA' AND code_o.estado='PENDIENTE_RECEPCION' {$scopeCode}
             LEFT JOIN Pos_CriptoActivo code_a ON code_a.id=code_o.id_activo AND code_a.simbolo=t.currency
             WHERE t.order_type='C2C' AND t.amount>0 AND t.receptor_verificado=1
               AND t.transaction_time>=DATE_SUB(NOW(), INTERVAL {$historyDays} DAY)
               AND (t.id_operacion IS NULL OR EXISTS (SELECT 1 FROM Pos_CriptoOperacion linked
                    WHERE linked.id=t.id_operacion {$linkedScope}))
               AND {$visibilityCondition}
             ORDER BY t.transaction_time DESC LIMIT 200",
            $params
        );
        foreach ($rows as &$row) {
            if (!empty($row['candidate_operation_id'])) {
                $row['candidate_operation'] = DB::selectOne(
                    'SELECT o.id,o.cantidad_cripto,o.monto_bob,o.cod_usuario_cajero,o.created_at,c.num_caja FROM Pos_CriptoOperacion o JOIN caja c ON c.id_caja=o.id_caja WHERE o.id=:id',
                    [':id' => $row['candidate_operation_id']]
                );
            } else $row['candidate_operation'] = null;
            $row['candidate_count'] = (int) $row['candidate_count'];
            $row['receptor_verificado'] = (bool) $row['receptor_verificado'];
            $row['payer_binance_id'] = empty($row['payer_binance_id']) ? null : '••••' . substr((string) $row['payer_binance_id'], -4);
        }
        return $rows;
    }

    public function binancePaySyncStatus(): ?array
    {
        $status = DB::selectOne('SELECT last_success_at,last_error_at,last_error,last_records,updated_at FROM Pos_CriptoPaySync WHERE id=1');
        if (!$status) return null;
        $status['api_configured'] = trim((string) config('binance_pay.history.api_key', '')) !== '' && trim((string) config('binance_pay.history.api_secret', '')) !== '';
        $status['receiver_configured'] = trim((string) config('binance_pay.history.receiver_id', '')) !== '';
        return $status;
    }

    public function binancePayPendingOperations(int $agency): array
    {
        return DB::select(
            "SELECT o.id,o.codigo_referencia,o.cantidad_cripto,o.monto_bob,o.created_at,a.simbolo,a.red,c.num_caja
             FROM Pos_CriptoOperacion o JOIN Pos_CriptoActivo a ON a.id=o.id_activo JOIN caja c ON c.id_caja=o.id_caja
             WHERE o.id_agencia=:agency AND o.tipo='COMPRA' AND o.estado='PENDIENTE_RECEPCION'
             ORDER BY o.created_at DESC LIMIT 300",
            [':agency' => $agency]
        );
    }

    public function vincularBinancePayManual(string $transactionId, int $operationId, int $user, int $agency): array
    {
        return DB::transaction(function () use ($transactionId, $operationId, $user, $agency) {
            $payment = DB::selectOne('SELECT * FROM Pos_CriptoPayTransaccion WHERE transaction_id=:tx FOR UPDATE', [':tx' => trim($transactionId)]);
            if (!$payment) return $this->fail('El pago no está en el historial sincronizado', 404);
            if (!empty($payment['id_operacion'])) return $this->fail('El pago ya está vinculado a una operación', 409);
            if ($payment['order_type'] !== 'C2C' || (float) $payment['amount'] <= 0 || !(int) $payment['receptor_verificado']) return $this->fail('Solo se pueden vincular ingresos C2C recibidos en la cuenta verificada');
            $op = $this->locked($operationId, $agency);
            if (!$op || $op['tipo'] !== 'COMPRA' || $op['estado'] !== 'PENDIENTE_RECEPCION') return $this->fail('La compra no está pendiente en esta agencia', 404);
            $asset = DB::selectOne('SELECT simbolo FROM Pos_CriptoActivo WHERE id=:id', [':id' => $op['id_activo']]);
            if (!$asset || strtoupper((string) $asset['simbolo']) !== strtoupper((string) $payment['currency']) || !$this->sameDecimal($op['cantidad_cripto'], $payment['amount'])) {
                return $this->fail('El activo y la cantidad deben coincidir exactamente incluso en una vinculación manual');
            }
            DB::execute("UPDATE Pos_CriptoPayTransaccion SET id_operacion=:op,conciliacion_metodo='MANUAL' WHERE id=:id AND id_operacion IS NULL", [':op' => $operationId, ':id' => $payment['id']]);
            $this->event($operationId, 'RECEPCION_PAY_VINCULO_MANUAL', $op['estado'], $op['estado'], $user, $transactionId, null,
                ['source' => 'BINANCE_PAY', 'transaction_id' => $transactionId, 'note_code' => $payment['note_code']]);
            return ['success' => true, 'message' => 'Pago vinculado para revisión del cajero', 'data' => ['id_operacion' => $operationId, 'transaction_id' => $transactionId]];
        });
    }

    public function catalogo(int $idAgencia, bool $admin): array
    {
        $activos = DB::select(
            "SELECT id, simbolo, nombre, red, contrato, decimales, tasa_compra_bob,
                    tasa_venta_bob, comision_porcentaje, direccion_recepcion, activo
             FROM Pos_CriptoActivo WHERE (:admin = 1 OR activo = 1) ORDER BY simbolo, red",
            [':admin' => $admin ? 1 : 0]
        );
        foreach ($activos as &$asset) {
            $asset['saldo_tesoreria'] = $this->saldoActivo((int) $asset['id']);
            $asset['reservado'] = $this->reservadoActivo((int) $asset['id']);
            $asset['disponible'] = round($asset['saldo_tesoreria'] - $asset['reservado'], 10);
        }
        return $activos;
    }

    public function cajas(int $codUsuario, int $idAgencia): array
    {
        return DB::select(
            "SELECT c.id_caja, c.num_caja, c.id_moneda, c.maximo, c.minimo,
                    m.abreviatura AS moneda, a.descripcion AS agencia
             FROM caja c JOIN moneda m ON m.id_moneda = c.id_moneda
             JOIN agencia a ON a.id_agencia = c.id_agencia
             WHERE c.cod_usuario = :usr AND c.id_agencia = :ag AND c.estado = 'A'
               AND c.id_tipo_unidad = 2 AND m.abreviatura = 'BOB'
               AND EXISTS (SELECT 1 FROM pos_sesion_caja s WHERE s.id_caja=c.id_caja AND s.fecha=CURDATE() AND s.estado='abierta')
             ORDER BY c.num_caja",
            [':usr' => $codUsuario, ':ag' => $idAgencia]
        );
    }

    public function listar(int $codUsuario, int $idAgencia, int $rol, array $filters): array
    {
        $where = ['o.id_agencia = :ag'];
        $params = [':ag' => $idAgencia];
        if (!in_array($rol, [2, 4], true)) {
            $where[] = 'o.cod_usuario_cajero = :usr';
            $params[':usr'] = $codUsuario;
        }
        foreach (['desde' => 'DATE(o.created_at) >= :desde', 'hasta' => 'DATE(o.created_at) <= :hasta'] as $key => $condition) {
            if (!empty($filters[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters[$key])) {
                $where[] = $condition;
                $params[':' . $key] = $filters[$key];
            }
        }
        if (!empty($filters['estado'])) { $where[] = 'o.estado = :estado'; $params[':estado'] = $filters['estado']; }
        if (!empty($filters['tipo']) && in_array($filters['tipo'], ['COMPRA', 'VENTA'], true)) {
            $where[] = 'o.tipo = :tipo'; $params[':tipo'] = $filters['tipo'];
        }
        return DB::select(
            "SELECT o.id, o.uuid, o.tipo, o.cod_usuario_cajero, o.cantidad_cripto, o.tasa_bob, o.comision_bob,
                    o.monto_bob, o.direccion_cliente, o.direccion_tesoreria, o.hash_transaccion,
                    o.estado, o.concepto, o.codigo_referencia, o.created_at, o.fecha_confirmacion,
                    a.simbolo, a.nombre AS activo_nombre, a.red, c.num_caja,
                    u.login_usu AS usuario
             FROM Pos_CriptoOperacion o
             JOIN Pos_CriptoActivo a ON a.id = o.id_activo
             JOIN caja c ON c.id_caja = o.id_caja
             LEFT JOIN usuario u ON u.cod_usuario = o.cod_usuario_cajero
             WHERE " . implode(' AND ', $where) . " ORDER BY o.id DESC LIMIT 300",
            $params
        );
    }

    public function detalle(int $id, int $codUsuario, int $idAgencia, int $rol): ?array
    {
        $where = 'o.id = :id AND o.id_agencia = :ag';
        $params = [':id' => $id, ':ag' => $idAgencia];
        if (!in_array($rol, [2, 4], true)) { $where .= ' AND o.cod_usuario_cajero = :usr'; $params[':usr'] = $codUsuario; }
        $op = DB::selectOne(
            "SELECT o.*, a.simbolo, a.nombre AS activo_nombre, a.red, c.num_caja,
                    ag.descripcion AS agencia, u.login_usu AS usuario
             FROM Pos_CriptoOperacion o JOIN Pos_CriptoActivo a ON a.id = o.id_activo
             JOIN caja c ON c.id_caja = o.id_caja JOIN agencia ag ON ag.id_agencia = o.id_agencia
             LEFT JOIN usuario u ON u.cod_usuario = o.cod_usuario_cajero WHERE {$where}", $params
        );
        if (!$op) return null;
        $op['eventos'] = DB::select(
            "SELECT accion, estado_anterior, estado_nuevo, cod_usuario, referencia, motivo, created_at
             FROM Pos_CriptoEvento WHERE id_operacion = :id ORDER BY id",
            [':id' => $id]
        );
        return $op;
    }

    public function guardarActivo(array $in, int $codUsuario): array
    {
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            $changed = DB::execute(
                "UPDATE Pos_CriptoActivo SET simbolo=:s,nombre=:n,red=:r,contrato=:ct,decimales=:d,
                 tasa_compra_bob=:tc,tasa_venta_bob=:tv,comision_porcentaje=:cp,
                 direccion_recepcion=:dr,activo=:a,cod_usuario_actualiza=:u WHERE id=:id",
                $this->assetParams($in, $codUsuario) + [':id' => $id]
            );
            if (!$changed && !DB::scalar("SELECT id FROM Pos_CriptoActivo WHERE id=:id", [':id' => $id])) {
                return $this->fail('Activo no encontrado', 404);
            }
        } else {
            DB::execute(
                "INSERT INTO Pos_CriptoActivo (simbolo,nombre,red,contrato,decimales,tasa_compra_bob,
                 tasa_venta_bob,comision_porcentaje,direccion_recepcion,activo,cod_usuario_actualiza)
                 VALUES (:s,:n,:r,:ct,:d,:tc,:tv,:cp,:dr,:a,:u)",
                $this->assetParams($in, $codUsuario)
            );
            $id = DB::lastInsertId();
        }
        return ['success' => true, 'message' => 'Configuración guardada', 'data' => ['id' => $id]];
    }

    private function assetParams(array $in, int $user): array
    {
        return [
            ':s' => strtoupper(trim($in['simbolo'])), ':n' => trim($in['nombre']),
            ':r' => trim($in['red']), ':ct' => $in['contrato'] ?: null,
            ':d' => (int) $in['decimales'], ':tc' => (float) $in['tasa_compra_bob'],
            ':tv' => (float) $in['tasa_venta_bob'], ':cp' => (float) $in['comision_porcentaje'],
            ':dr' => trim($in['direccion_recepcion'] ?? '') ?: null,
            ':a' => !empty($in['activo']) ? 1 : 0, ':u' => $user,
        ];
    }

    public function crear(array $in, int $user, int $agency, string $ip): array
    {
        $existing = DB::selectOne('SELECT id, uuid, estado, cod_usuario_cajero, id_agencia, codigo_referencia FROM Pos_CriptoOperacion WHERE uuid=:uuid', [':uuid' => $in['uuid']]);
        if ($existing) {
            if ((int) $existing['cod_usuario_cajero'] !== $user || (int) $existing['id_agencia'] !== $agency) return $this->fail('UUID de operación ya utilizado', 409);
            return ['success' => true, 'message' => 'Operación ya registrada', 'data' => $existing];
        }
        $asset = DB::selectOne('SELECT * FROM Pos_CriptoActivo WHERE id=:id AND activo=1', [':id' => $in['id_activo']]);
        if (!$asset) return $this->fail('Activo o red no habilitados');
        $caja = DB::selectOne(
            "SELECT c.id_caja FROM caja c JOIN moneda m ON m.id_moneda=c.id_moneda
             WHERE c.id_caja=:id AND c.cod_usuario=:usr AND c.id_agencia=:ag
               AND c.estado='A' AND c.id_tipo_unidad=2 AND m.abreviatura='BOB'
               AND EXISTS (SELECT 1 FROM pos_sesion_caja s WHERE s.id_caja=c.id_caja AND s.fecha=CURDATE() AND s.estado='abierta')",
            [':id' => $in['id_caja'], ':usr' => $user, ':ag' => $agency]
        );
        if (!$caja) return $this->fail('Selecciona una caja BOB abierta que te pertenezca');
        if ($in['tipo'] === 'VENTA' && trim($in['direccion_cliente'] ?? '') === '') return $this->fail('La dirección del cliente es obligatoria para vender cripto');
        if ($in['tipo'] === 'COMPRA' && !$this->binancePayQrDataUrl()) return $this->fail('Tesorería aún no configuró el QR de Binance Pay');

        $qty = round((float) $in['cantidad_cripto'], min(10, (int) $asset['decimales']));
        $rate = (float) ($in['tipo'] === 'COMPRA' ? $asset['tasa_compra_bob'] : $asset['tasa_venta_bob']);
        $gross = round($qty * $rate, 2);
        $fee = round($gross * (float) $asset['comision_porcentaje'] / 100, 2);
        $bob = round($in['tipo'] === 'COMPRA' ? $gross - $fee : $gross + $fee, 2);
        if ($qty <= 0 || $bob <= 0) return $this->fail('El monto calculado debe ser mayor a cero');

        try {
            return DB::transaction(function () use ($in, $asset, $user, $agency, $ip, $qty, $rate, $gross, $fee, $bob) {
                $state = $in['tipo'] === 'COMPRA' ? 'PENDIENTE_RECEPCION' : 'PENDIENTE_COBRO_BOB';
                $codigoReferencia = $in['tipo'] === 'COMPRA'
                    ? (trim((string) ($in['codigo_referencia'] ?? '')) ?: $this->newReferenceCode())
                    : null;
                if ($codigoReferencia && DB::scalar('SELECT id FROM Pos_CriptoOperacion WHERE codigo_referencia=:code', [':code' => $codigoReferencia])) {
                    return $this->fail('El código de nota ya está en uso. Cierra y vuelve a abrir la operación para generar otro.', 409);
                }
                $lockedAsset = DB::selectOne('SELECT id FROM Pos_CriptoActivo WHERE id=:id FOR UPDATE', [':id' => $asset['id']]);
                if (!$lockedAsset) return $this->fail('Activo no encontrado');
                if ($in['tipo'] === 'VENTA') {
                    $available = $this->saldoActivo((int) $asset['id']) - $this->reservadoActivo((int) $asset['id']);
                    if ($available < $qty) return $this->fail('Tesorería no tiene inventario cripto disponible para esta operación');
                }
                DB::execute(
                    "INSERT INTO Pos_CriptoOperacion
                     (uuid,tipo,id_activo,id_agencia,id_caja,cod_usuario_cajero,cantidad_cripto,tasa_bob,
                      porcentaje_comision,monto_bruto_bob,comision_bob,monto_bob,direccion_cliente,
                      direccion_tesoreria,estado,concepto,codigo_referencia,ip_creacion)
                     VALUES (:uuid,:tipo,:asset,:ag,:caja,:usr,:qty,:rate,:pct,:gross,:fee,:bob,:addr,:wallet,:state,:concept,:code,:ip)",
                    [':uuid' => $in['uuid'], ':tipo' => $in['tipo'], ':asset' => $asset['id'], ':ag' => $agency,
                     ':caja' => $in['id_caja'], ':usr' => $user, ':qty' => $qty, ':rate' => $rate,
                     ':pct' => $asset['comision_porcentaje'], ':gross' => $gross, ':fee' => $fee, ':bob' => $bob,
                     ':addr' => trim($in['direccion_cliente'] ?? '') ?: null,
                     ':wallet' => $asset['direccion_recepcion'], ':state' => $state,
                     ':concept' => trim($in['concepto'] ?? '') ?: null, ':code' => $codigoReferencia, ':ip' => $ip ?: '0.0.0.0']
                );
                $id = DB::lastInsertId();
                $this->event($id, 'CREADA', null, $state, $user, null, null);
                return ['success' => true, 'message' => 'Operación registrada', 'data' => ['id' => $id, 'uuid' => $in['uuid'], 'estado' => $state, 'monto_bob' => $bob, 'cantidad_cripto' => $qty, 'tasa_bob' => $rate, 'comision_bob' => $fee, 'codigo_referencia' => $codigoReferencia]];
            });
        } catch (\Throwable $e) {
            $existing = DB::selectOne('SELECT id, uuid, estado, cod_usuario_cajero, id_agencia, codigo_referencia FROM Pos_CriptoOperacion WHERE uuid=:uuid', [':uuid' => $in['uuid']]);
            if ($existing && (int) $existing['cod_usuario_cajero'] === $user && (int) $existing['id_agencia'] === $agency) return ['success' => true, 'message' => 'Operación ya registrada', 'data' => $existing];
            if ($existing) return $this->fail('UUID de operación ya utilizado', 409);
            if (!empty($in['codigo_referencia']) && (string) $e->getCode() === '23000') return $this->fail('El código de nota ya está en uso. Cierra y vuelve a abrir la operación para generar otro.', 409);
            throw $e;
        }
    }

    public function confirmarRecepcion(int $id, string $ref, int $user): array
    {
        return DB::transaction(function () use ($id, $ref, $user) {
            $op = $this->locked($id);
            if (!$op) return $this->fail('Operación no encontrada', 404);
            if ($op['tipo'] !== 'COMPRA' || $op['estado'] !== 'PENDIENTE_RECEPCION') return $this->fail('La operación ya cambió de estado');
            DB::execute("INSERT INTO Pos_CriptoMovimientoTesoreria (id_activo,id_operacion,tipo,cantidad,referencia,cod_usuario) VALUES (:asset,:op,'RECEPCION',:qty,:ref,:usr)", [':asset' => $op['id_activo'], ':op' => $id, ':qty' => $op['cantidad_cripto'], ':ref' => trim($ref), ':usr' => $user]);
            DB::execute("UPDATE Pos_CriptoOperacion SET estado='PENDIENTE_PAGO_BOB',hash_transaccion=:ref,cod_usuario_tesoreria=:usr,fecha_confirmacion=NOW() WHERE id=:id", [':ref' => trim($ref), ':usr' => $user, ':id' => $id]);
            $this->event($id, 'RECEPCION_CONFIRMADA', $op['estado'], 'PENDIENTE_PAGO_BOB', $user, $ref, null);
            return $this->result($id, 'Recepción confirmada; queda pendiente el pago en BOB');
        });
    }

    public function confirmarRecepcionBinancePay(int $id, string $transactionId, int $user, int $agency): array
    {
        return DB::transaction(function () use ($id, $transactionId, $user, $agency) {
            $op = $this->locked($id, $agency);
            if (!$op || (int) $op['cod_usuario_cajero'] !== $user) return $this->fail('Operación no encontrada en tu caja', 404);
            if ($op['tipo'] !== 'COMPRA' || $op['estado'] !== 'PENDIENTE_RECEPCION') return $this->fail('La operación ya cambió de estado');
            if (!$this->validateCashbox((int) $op['id_caja'], $user, $agency)) return $this->fail('La caja debe seguir abierta y asignada a tu usuario');
            $payment = DB::selectOne('SELECT * FROM Pos_CriptoPayTransaccion WHERE transaction_id=:tx FOR UPDATE', [':tx' => trim($transactionId)]);
            if (!$payment) return $this->fail('El movimiento no está en el historial sincronizado de Binance Pay', 404);
            if (!empty($payment['id_operacion']) && (int) $payment['id_operacion'] !== $id) return $this->fail('Este movimiento Binance Pay ya está vinculado a otra operación', 409);
            if ($payment['order_type'] !== 'C2C' || (float) $payment['amount'] <= 0 || !(int) $payment['receptor_verificado']) return $this->fail('El movimiento no es una recepción válida de la cuenta Binance Pay configurada');
            $paymentTime = strtotime((string) $payment['transaction_time']);
            $operationTime = strtotime((string) $op['created_at']);
            $manualLink = ($payment['conciliacion_metodo'] ?? null) === 'MANUAL' && (int) ($payment['id_operacion'] ?? 0) === $id;
            if (!$paymentTime || !$operationTime || (!$manualLink && ($paymentTime < $operationTime - 300 || $paymentTime > $operationTime + 86400))) return $this->fail('El movimiento Binance Pay está fuera del periodo de esta operación');
            $asset = DB::selectOne('SELECT simbolo FROM Pos_CriptoActivo WHERE id=:id', [':id' => $op['id_activo']]);
            if (!$asset || strtoupper($asset['simbolo']) !== strtoupper($payment['currency']) || !$this->sameDecimal($payment['amount'], $op['cantidad_cripto'])) {
                return $this->fail('El activo o importe recibido no coincide exactamente con la operación');
            }
            $codeIdentifiesOperation = !empty($payment['note_code']) && hash_equals((string) ($op['codigo_referencia'] ?? ''), (string) $payment['note_code']);
            $possibleOperations = $manualLink ? [['id' => $id]] : ($codeIdentifiesOperation ? DB::select(
                "SELECT o.id FROM Pos_CriptoOperacion o
                 WHERE o.codigo_referencia=:code AND o.tipo='COMPRA' AND o.estado='PENDIENTE_RECEPCION'
                   AND o.id_agencia=:agency AND o.cod_usuario_cajero=:user FOR UPDATE",
                [':code' => $payment['note_code'], ':agency' => $agency, ':user' => $user]
            ) : DB::select(
                "SELECT o.id FROM Pos_CriptoOperacion o
                 JOIN Pos_CriptoActivo a ON a.id=o.id_activo
                 WHERE o.id_agencia=:agency AND o.cod_usuario_cajero=:user
                   AND o.tipo='COMPRA' AND o.estado='PENDIENTE_RECEPCION'
                   AND a.simbolo=:currency AND o.cantidad_cripto=:amount
                   AND :paid_at BETWEEN DATE_SUB(o.created_at,INTERVAL 5 MINUTE) AND DATE_ADD(o.created_at,INTERVAL 24 HOUR)
                 ORDER BY o.id FOR UPDATE",
                [':agency' => $agency, ':user' => $user, ':currency' => $payment['currency'],
                 ':amount' => $payment['amount'], ':paid_at' => $payment['transaction_time']]
            ));
            if (count($possibleOperations) !== 1 || (int) $possibleOperations[0]['id'] !== $id) {
                return $this->fail('Este pago coincide con varias operaciones pendientes o la coincidencia cambió. No se vinculó para evitar una conciliación incorrecta.', 409);
            }
            DB::execute("INSERT INTO Pos_CriptoMovimientoTesoreria (id_activo,id_operacion,tipo,cantidad,referencia,cod_usuario) VALUES (:asset,:op,'RECEPCION',:qty,:ref,:usr)", [':asset' => $op['id_activo'], ':op' => $id, ':qty' => $op['cantidad_cripto'], ':ref' => $transactionId, ':usr' => $user]);
            DB::execute("UPDATE Pos_CriptoPayTransaccion SET id_operacion=:op,cod_usuario_confirma=:usr,confirmado_at=NOW(),conciliacion_metodo=COALESCE(conciliacion_metodo,'MANUAL') WHERE id=:id AND (id_operacion IS NULL OR id_operacion=:op)", [':op' => $id, ':usr' => $user, ':id' => $payment['id']]);
            DB::execute("UPDATE Pos_CriptoOperacion SET estado='PENDIENTE_PAGO_BOB',hash_transaccion=:ref,fecha_confirmacion=NOW() WHERE id=:id", [':ref' => $transactionId, ':id' => $id]);
            $this->event($id, 'RECEPCION_PAY_CONFIRMADA', $op['estado'], 'PENDIENTE_PAGO_BOB', $user, $transactionId, null, ['source' => 'BINANCE_PAY', 'transaction_id' => $transactionId]);
            return $this->result($id, 'Recepción Binance Pay confirmada desde caja; queda pendiente el pago en BOB');
        });
    }

    private function sameDecimal($left, $right): bool
    {
        $normalize = static function ($value): string {
            $value = trim((string) $value);
            if (!preg_match('/^\+?(\d+)(?:\.(\d+))?$/', $value, $m)) return '';
            $whole = ltrim($m[1], '0');
            $fraction = rtrim($m[2] ?? '', '0');
            return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
        };
        $a = $normalize($left); $b = $normalize($right);
        return $a !== '' && $a === $b;
    }

    private function newReferenceCode(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $digits = '';
            for ($i = 0; $i < 5; $i++) $digits .= (string) random_int(0, 9);
            $code = 'GB-' . $digits . $letters[random_int(0, strlen($letters) - 1)];
            if (!DB::scalar('SELECT id FROM Pos_CriptoOperacion WHERE codigo_referencia=:code', [':code' => $code])) return $code;
        }
        throw new \RuntimeException('No se pudo generar un código de referencia único');
    }

    public function confirmarCobro(int $id, int $user, int $agency): array
    {
        return DB::transaction(function () use ($id, $user, $agency) {
            $op = $this->locked($id, $agency);
            if (!$op) return $this->fail('Operación no encontrada', 404);
            if ((int) $op['cod_usuario_cajero'] !== $user || $op['tipo'] !== 'VENTA' || $op['estado'] !== 'PENDIENTE_COBRO_BOB') return $this->fail('No puedes confirmar el cobro de esta operación');
            $caja = $this->validateCashbox((int) $op['id_caja'], $user, $agency);
            if (!$caja) return $this->fail('La caja ya no está abierta o no te pertenece');
            DB::execute("INSERT INTO saldo_caja (id_caja,saldo,fec_crea_saldo,conciliacion) VALUES (:caja,:monto,NOW(),'IA')", [':caja' => $op['id_caja'], ':monto' => $op['monto_bob']]);
            $idSaldo = DB::lastInsertId();
            DB::execute("UPDATE Pos_CriptoOperacion SET estado='PENDIENTE_ENVIO',id_saldo_caja=:sc WHERE id=:id", [':sc' => $idSaldo, ':id' => $id]);
            $this->event($id, 'COBRO_BOB_CONFIRMADO', $op['estado'], 'PENDIENTE_ENVIO', $user, null, null, ['id_saldo_caja' => $idSaldo]);
            return $this->result($id, 'Cobro registrado; Tesorería debe enviar el criptoactivo');
        });
    }

    public function pagarBob(int $id, int $user, int $agency): array
    {
        return DB::transaction(function () use ($id, $user, $agency) {
            $op = $this->locked($id, $agency);
            if (!$op) return $this->fail('Operación no encontrada', 404);
            if ((int) $op['cod_usuario_cajero'] !== $user || $op['tipo'] !== 'COMPRA' || $op['estado'] !== 'PENDIENTE_PAGO_BOB') return $this->fail('No puedes pagar esta operación o ya fue procesada');
            if (!$this->validateCashbox((int) $op['id_caja'], $user, $agency)) return $this->fail('La caja ya no está abierta o no te pertenece');
            if ($this->balance->saldoCaja((int) $op['id_caja'], date('Y-m-d')) < (float) $op['monto_bob']) return $this->fail('Saldo BOB insuficiente en caja');
            DB::execute("INSERT INTO saldo_caja (id_caja,saldo,fec_crea_saldo,conciliacion) VALUES (:caja,:monto,NOW(),'SC')", [':caja' => $op['id_caja'], ':monto' => -(float) $op['monto_bob']]);
            $idSaldo = DB::lastInsertId();
            DB::execute("UPDATE Pos_CriptoOperacion SET estado='COMPLETADA',id_saldo_caja=:sc WHERE id=:id", [':sc' => $idSaldo, ':id' => $id]);
            $this->event($id, 'PAGO_BOB_COMPLETADO', $op['estado'], 'COMPLETADA', $user, null, null, ['id_saldo_caja' => $idSaldo]);
            return $this->result($id, 'Pago en BOB registrado; operación completada');
        });
    }

    public function confirmarEnvio(int $id, string $ref, int $user): array
    {
        return DB::transaction(function () use ($id, $ref, $user) {
            $op = $this->locked($id);
            if (!$op) return $this->fail('Operación no encontrada', 404);
            if ($op['tipo'] !== 'VENTA' || $op['estado'] !== 'PENDIENTE_ENVIO') return $this->fail('La operación no está pendiente de envío');
            DB::selectOne('SELECT id FROM Pos_CriptoActivo WHERE id=:id FOR UPDATE', [':id' => $op['id_activo']]);
            $available = $this->saldoActivo((int) $op['id_activo']);
            if ($available < (float) $op['cantidad_cripto']) return $this->fail('El inventario ya no cubre este envío');
            DB::execute("INSERT INTO Pos_CriptoMovimientoTesoreria (id_activo,id_operacion,tipo,cantidad,referencia,cod_usuario) VALUES (:asset,:op,'ENVIO',:qty,:ref,:usr)", [':asset' => $op['id_activo'], ':op' => $id, ':qty' => -(float) $op['cantidad_cripto'], ':ref' => trim($ref), ':usr' => $user]);
            DB::execute("UPDATE Pos_CriptoOperacion SET estado='COMPLETADA',hash_transaccion=:ref,cod_usuario_tesoreria=:usr,fecha_confirmacion=NOW() WHERE id=:id", [':ref' => trim($ref), ':usr' => $user, ':id' => $id]);
            $this->event($id, 'ENVIO_CONFIRMADO', $op['estado'], 'COMPLETADA', $user, $ref, null);
            return $this->result($id, 'Envío confirmado; operación completada');
        });
    }

    public function ajustarInventario(int $idAsset, float $qty, string $reason, int $user): array
    {
        return DB::transaction(function () use ($idAsset, $qty, $reason, $user) {
            $asset = DB::selectOne('SELECT id FROM Pos_CriptoActivo WHERE id=:id FOR UPDATE', [':id' => $idAsset]);
            if (!$asset) return $this->fail('Activo no encontrado', 404);
            if ($qty < 0 && $this->saldoActivo($idAsset) + $qty < $this->reservadoActivo($idAsset)) return $this->fail('El ajuste no puede reducir el inventario por debajo de lo reservado');
            DB::execute("INSERT INTO Pos_CriptoMovimientoTesoreria (id_activo,tipo,cantidad,cod_usuario,motivo) VALUES (:id,'AJUSTE',:qty,:usr,:reason)", [':id' => $idAsset, ':qty' => $qty, ':usr' => $user, ':reason' => trim($reason)]);
            return ['success' => true, 'message' => 'Ajuste registrado en la bitácora de Tesorería', 'data' => ['saldo' => $this->saldoActivo($idAsset)]];
        });
    }

    public function rechazar(int $id, string $reason, int $user): array
    {
        return $this->closeBeforeCash($id, 'RECHAZADA', 'RECHAZADA', $reason, $user);
    }

    public function cancelar(int $id, string $reason, int $user, int $agency): array
    {
        $op = DB::selectOne('SELECT cod_usuario_cajero, id_agencia FROM Pos_CriptoOperacion WHERE id=:id', [':id' => $id]);
        if (!$op || (int) $op['id_agencia'] !== $agency || (int) $op['cod_usuario_cajero'] !== $user) return $this->fail('Operación no encontrada', 404);
        return $this->closeBeforeCash($id, 'CANCELADA', 'CANCELADA', $reason, $user);
    }

    private function closeBeforeCash(int $id, string $target, string $action, string $reason, int $user): array
    {
        return DB::transaction(function () use ($id, $target, $action, $reason, $user) {
            $op = $this->locked($id);
            if (!$op) return $this->fail('Operación no encontrada', 404);
            $allowed = $target === 'RECHAZADA' ? ['PENDIENTE_RECEPCION'] : ['PENDIENTE_RECEPCION', 'PENDIENTE_COBRO_BOB'];
            if (!in_array($op['estado'], $allowed, true)) return $this->fail('No se puede cerrar la operación después de afectar caja o confirmar fondos');
            DB::execute("UPDATE Pos_CriptoOperacion SET estado=:state,motivo_cierre=:reason WHERE id=:id", [':state' => $target, ':reason' => trim($reason), ':id' => $id]);
            $this->event($id, $action, $op['estado'], $target, $user, null, $reason);
            return $this->result($id, $target === 'RECHAZADA' ? 'Operación rechazada' : 'Operación cancelada');
        });
    }

    private function validateCashbox(int $idCaja, int $user, int $agency): ?array
    {
        return DB::selectOne("SELECT id_caja FROM caja WHERE id_caja=:id AND cod_usuario=:usr AND id_agencia=:ag AND estado='A' AND id_tipo_unidad=2 AND EXISTS (SELECT 1 FROM pos_sesion_caja s WHERE s.id_caja=caja.id_caja AND s.fecha=CURDATE() AND s.estado='abierta') FOR UPDATE", [':id' => $idCaja, ':usr' => $user, ':ag' => $agency]);
    }

    private function locked(int $id, ?int $agency = null): ?array
    {
        $where = 'id=:id' . ($agency ? ' AND id_agencia=:ag' : '');
        return DB::selectOne("SELECT * FROM Pos_CriptoOperacion WHERE {$where} FOR UPDATE", $agency ? [':id' => $id, ':ag' => $agency] : [':id' => $id]);
    }

    private function saldoActivo(int $id): float
    {
        return (float) DB::scalar('SELECT COALESCE(SUM(cantidad),0) FROM Pos_CriptoMovimientoTesoreria WHERE id_activo=:id', [':id' => $id]);
    }

    private function reservadoActivo(int $id): float
    {
        return (float) DB::scalar("SELECT COALESCE(SUM(cantidad_cripto),0) FROM Pos_CriptoOperacion WHERE id_activo=:id AND tipo='VENTA' AND estado IN ('PENDIENTE_COBRO_BOB','PENDIENTE_ENVIO')", [':id' => $id]);
    }

    private function event(int $id, string $action, ?string $from, string $to, int $user, ?string $reference, ?string $reason, array $metadata = []): void
    {
        DB::execute("INSERT INTO Pos_CriptoEvento (id_operacion,accion,estado_anterior,estado_nuevo,cod_usuario,referencia,motivo,metadata) VALUES (:id,:action,:from,:to,:usr,:ref,:reason,:meta)", [
            ':id' => $id, ':action' => $action, ':from' => $from, ':to' => $to, ':usr' => $user,
            ':ref' => $reference, ':reason' => $reason,
            ':meta' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function result(int $id, string $message): array
    {
        return ['success' => true, 'message' => $message, 'data' => DB::selectOne('SELECT id,uuid,estado,monto_bob,cantidad_cripto FROM Pos_CriptoOperacion WHERE id=:id', [':id' => $id])];
    }

    private function fail(string $message, int $status = 422): array
    {
        return ['success' => false, 'message' => $message, 'status' => $status];
    }
}
