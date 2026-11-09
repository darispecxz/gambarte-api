<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;

class CajeroMovimientosService
{
    private const TIPO_TRANSFERENCIA_ENVIO = 1;
    private const TIPO_TRANSFERENCIA_RECEPCION = 2;

    /** Cuenta de caja en contabilidad (codsubcuenta) => abreviatura de moneda. */
    private const CUENTAS_CAJA_MONEDA = [
        '11101101' => 'BOB',
        '11101201' => 'USD',
        '11101202' => 'EUR',
        '11101203' => 'BRL',
        '11101204' => 'PEN',
        '11101205' => 'ARS',
        '11101206' => 'CLP',
        '11101211' => 'CAD',
        '11101213' => 'CHF',
        '11101215' => 'AUD',
        '11101216' => 'PYG',
        '11101217' => 'GBP',
        '11101220' => 'NZD',
    ];

    private CajeroQueryService $query;
    private CajeroBalanceService $balance;

    public function __construct(CajeroQueryService $query, CajeroBalanceService $balance)
    {
        $this->query   = $query;
        $this->balance = $balance;
    }

    /**
     * Solicitar fondos de bóveda a caja.
     * Conciliacion: ET (caja recibe), boveda pierde.
     */
    public function solicitarFondos(
        int $codUsuario, int $idAgencia, int $idCaja,
        float $monto, string $concepto, array $cortes, string $ip
    ): array {
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0'];
        }

        $caja = $this->validarCajaAbierta($idCaja, $codUsuario);
        if (!$caja['success']) return $caja;
        $caja = $caja['caja'];
        if ($err = $this->errorCortes($cortes, (int) $caja['id_moneda'], $monto)) {
            return ['success' => false, 'message' => $err];
        }

        $boveda = $this->getBoveda($idAgencia, (int) $caja['id_moneda']);
        if (!$boveda) {
            return ['success' => false, 'message' => 'No se encontró bóveda para esta moneda'];
        }

        $fecha = date('Y-m-d');
        $saldoBoveda = $this->saldoBoveda((int) $boveda['id_boveda'], $fecha);
        $disponible = $saldoBoveda - (float) $boveda['minimo'];
        if ($monto > $disponible) {
            return ['success' => false, 'message' => "Fondos insuficientes en bóveda. Disponible: " . number_format($disponible, 2)];
        }

        $saldoActual = $this->query->saldoActual($idCaja, $fecha);
        $nuevoSaldo = $saldoActual + $monto;
        if ($nuevoSaldo > (float) $caja['maximo']) {
            return ['success' => false, 'message' => "El monto excedería el máximo de la caja (" . number_format((float) $caja['maximo'], 2) . "). Saldo actual: " . number_format($saldoActual, 2)];
        }

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCaja, $caja, $boveda, $monto, $concepto, $cortes, $fecha, $idAgencia, $codUsuario, $ip, $uuid, $saldoActual) {
            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'ET')",
                [':id' => $idCaja, ':saldo' => $monto]
            );

            DB::execute(
                "INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), :conc)",
                [':id' => $boveda['id_boveda'], ':saldo' => -$monto, ':conc' => "SF-{$idCaja}"]
            );

            DB::execute(
                "INSERT INTO transferencia_caja (id_tipo_transferencia, id_caja, id_boveda, monto_total, estado, fec_crea_transferencia_caja)
                 VALUES (:tipo, :caja, :bov, :monto, 'A', NOW())",
                [':tipo' => self::TIPO_TRANSFERENCIA_RECEPCION, ':caja' => $idCaja, ':bov' => $boveda['id_boveda'], ':monto' => $monto]
            );
            $idTransferencia = (int) DB::scalar("SELECT LAST_INSERT_ID()");
            $this->guardarCortes('detalle_transferencia_caja', $idTransferencia, $cortes);

            $this->registrarLog($uuid, 'solicitud_fondos', $idCaja, (int) $boveda['id_boveda'], $codUsuario, $idAgencia, (int) $caja['id_moneda'], $monto, $ip, ['concepto' => $concepto, 'cortes' => $cortes]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $caja['id_moneda']]);

            return [
                'success' => true,
                'message' => 'Fondos recibidos correctamente',
                'data'    => [
                    'id_caja'       => $idCaja,
                    'monto'         => $monto,
                    'moneda'        => $monAbr,
                    'saldo_anterior' => $saldoActual,
                    'saldo_nuevo'   => $saldoActual + $monto,
                    'fecha'         => date('Y-m-d H:i:s'),
                    'uuid'          => $uuid,
                ],
            ];
        });
    }

    /**
     * Devolver fondos de caja a bóveda.
     * Conciliacion: ST (caja pierde), boveda gana.
     */
    public function traspasoBoveda(
        int $codUsuario, int $idAgencia, int $idCaja,
        float $monto, string $concepto, array $cortes, string $ip
    ): array {
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0'];
        }

        $caja = $this->validarCajaAbierta($idCaja, $codUsuario);
        if (!$caja['success']) return $caja;
        $caja = $caja['caja'];
        if ($err = $this->errorCortes($cortes, (int) $caja['id_moneda'], $monto)) {
            return ['success' => false, 'message' => $err];
        }

        $fecha = date('Y-m-d');
        $saldoActual = $this->query->saldoActual($idCaja, $fecha);
        if ($monto > $saldoActual) {
            return ['success' => false, 'message' => "Saldo insuficiente en caja. Disponible: " . number_format($saldoActual, 2)];
        }

        $boveda = $this->getBoveda($idAgencia, (int) $caja['id_moneda']);
        if (!$boveda) {
            return ['success' => false, 'message' => 'No se encontró bóveda para esta moneda'];
        }

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCaja, $caja, $boveda, $monto, $concepto, $cortes, $fecha, $idAgencia, $codUsuario, $ip, $uuid, $saldoActual) {
            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'ST')",
                [':id' => $idCaja, ':saldo' => -$monto]
            );

            DB::execute(
                "INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), :conc)",
                [':id' => $boveda['id_boveda'], ':saldo' => $monto, ':conc' => "TB-{$idCaja}"]
            );

            $this->registrarLog($uuid, 'traspaso_boveda', $idCaja, (int) $boveda['id_boveda'], $codUsuario, $idAgencia, (int) $caja['id_moneda'], $monto, $ip, ['concepto' => $concepto, 'cortes' => $cortes]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $caja['id_moneda']]);

            return [
                'success' => true,
                'message' => 'Fondos devueltos a bóveda correctamente',
                'data'    => [
                    'id_caja'       => $idCaja,
                    'monto'         => $monto,
                    'moneda'        => $monAbr,
                    'saldo_anterior' => $saldoActual,
                    'saldo_nuevo'   => $saldoActual - $monto,
                    'fecha'         => date('Y-m-d H:i:s'),
                    'uuid'          => $uuid,
                ],
            ];
        });
    }

    /**
     * Traspaso entre cajeros (misma agencia, misma moneda).
     * Conciliacion: SO (origen pierde), EO (destino recibe).
     */
    public function traspasoCajero(
        int $codUsuario, int $idAgencia, int $idCajaOrigen, int $idCajaDestino,
        float $monto, string $concepto, array $cortes, string $ip
    ): array {
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0'];
        }
        if ($idCajaOrigen === $idCajaDestino) {
            return ['success' => false, 'message' => 'La caja origen y destino no pueden ser la misma'];
        }

        $cajaOrigen = $this->validarCajaAbierta($idCajaOrigen, $codUsuario);
        if (!$cajaOrigen['success']) return ['success' => false, 'message' => 'Caja origen: ' . $cajaOrigen['message']];
        $cajaOrigen = $cajaOrigen['caja'];
        if ($err = $this->errorCortes($cortes, (int) $cajaOrigen['id_moneda'], $monto)) {
            return ['success' => false, 'message' => $err];
        }

        $cajaDestino = DB::selectOne(
            "SELECT * FROM caja WHERE id_caja = :id AND id_agencia = :ag AND estado = 'A' AND id_tipo_unidad = 2",
            [':id' => $idCajaDestino, ':ag' => $idAgencia]
        );
        if (!$cajaDestino) {
            return ['success' => false, 'message' => 'Caja destino no encontrada en esta agencia'];
        }

        if ((int) $cajaOrigen['id_moneda'] !== (int) $cajaDestino['id_moneda']) {
            return ['success' => false, 'message' => 'Las cajas deben ser de la misma moneda'];
        }

        $destinoAbierta = DB::selectOne(
            "SELECT 1 FROM saldo_caja WHERE id_caja = :id AND conciliacion = 'A' AND DATE(fec_crea_saldo) = CURDATE() LIMIT 1",
            [':id' => $idCajaDestino]
        );
        $destinoCerrada = DB::selectOne(
            "SELECT 1 FROM saldo_caja WHERE id_caja = :id AND conciliacion = 'C' AND DATE(fec_crea_saldo) = CURDATE() LIMIT 1",
            [':id' => $idCajaDestino]
        );
        if (!$destinoAbierta || $destinoCerrada) {
            return ['success' => false, 'message' => 'La caja destino no está abierta hoy'];
        }

        $fecha = date('Y-m-d');
        $saldoOrigen = $this->query->saldoActual($idCajaOrigen, $fecha);
        if ($monto > $saldoOrigen) {
            return ['success' => false, 'message' => "Saldo insuficiente en caja origen. Disponible: " . number_format($saldoOrigen, 2)];
        }

        $saldoDestino = $this->query->saldoActual($idCajaDestino, $fecha);
        if (($saldoDestino + $monto) > (float) $cajaDestino['maximo']) {
            return ['success' => false, 'message' => "El monto excedería el máximo de la caja destino"];
        }

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCajaOrigen, $idCajaDestino, $cajaOrigen, $cajaDestino, $monto, $concepto, $cortes, $fecha, $idAgencia, $codUsuario, $ip, $uuid, $saldoOrigen, $saldoDestino) {
            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'SO')",
                [':id' => $idCajaOrigen, ':saldo' => -$monto]
            );

            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'EO')",
                [':id' => $idCajaDestino, ':saldo' => $monto]
            );

            $this->registrarLog($uuid, 'traspaso_cajero', $idCajaOrigen, null, $codUsuario, $idAgencia, (int) $cajaOrigen['id_moneda'], $monto, $ip, ['concepto' => $concepto, 'id_caja_destino' => $idCajaDestino, 'cortes' => $cortes]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $cajaOrigen['id_moneda']]);
            $cajeroDestino = DB::scalar("SELECT CONCAT(nombre, ' ', apellido) FROM usuario WHERE cod_usuario = :u", [':u' => $cajaDestino['cod_usuario']]);

            return [
                'success' => true,
                'message' => 'Traspaso entre cajeros realizado correctamente',
                'data'    => [
                    'id_caja_origen'  => $idCajaOrigen,
                    'id_caja_destino' => $idCajaDestino,
                    'cajero_destino'  => $cajeroDestino,
                    'monto'           => $monto,
                    'moneda'          => $monAbr,
                    'saldo_origen_anterior'  => $saldoOrigen,
                    'saldo_origen_nuevo'     => $saldoOrigen - $monto,
                    'fecha' => date('Y-m-d H:i:s'),
                    'uuid'  => $uuid,
                ],
            ];
        });
    }

    /**
     * Transferencia entre bóvedas (inter-agencia o misma agencia, distinta moneda).
     * Registra en transferencia_boveda y ajusta saldos de ambas bóvedas.
     */
    public function transferenciaBoveda(
        int $codUsuario, int $idAgencia, int $idBovedaOrigen, int $idBovedaDestino,
        float $monto, string $concepto, array $cortes, string $ip
    ): array {
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0'];
        }
        if ($idBovedaOrigen === $idBovedaDestino) {
            return ['success' => false, 'message' => 'La bóveda origen y destino no pueden ser la misma'];
        }

        $bovedaOrigen = DB::selectOne(
            "SELECT b.*, m.abreviatura AS moneda FROM boveda b JOIN moneda m ON m.id_moneda = b.id_moneda WHERE b.id_boveda = :id",
            [':id' => $idBovedaOrigen]
        );
        if (!$bovedaOrigen) {
            return ['success' => false, 'message' => 'Bóveda origen no encontrada'];
        }

        $bovedaDestino = DB::selectOne(
            "SELECT b.*, m.abreviatura AS moneda FROM boveda b JOIN moneda m ON m.id_moneda = b.id_moneda WHERE b.id_boveda = :id",
            [':id' => $idBovedaDestino]
        );
        if (!$bovedaDestino) {
            return ['success' => false, 'message' => 'Bóveda destino no encontrada'];
        }
        if ($err = $this->errorCortes($cortes, (int) $bovedaOrigen['id_moneda'], $monto)) {
            return ['success' => false, 'message' => $err];
        }

        $fecha = date('Y-m-d');
        $saldoOrigen = $this->saldoBoveda($idBovedaOrigen, $fecha);
        $disponible = $saldoOrigen - (float) $bovedaOrigen['minimo'];
        if ($monto > $disponible) {
            return ['success' => false, 'message' => "Fondos insuficientes en bóveda origen. Disponible: " . number_format($disponible, 2)];
        }

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idBovedaOrigen, $idBovedaDestino, $bovedaOrigen, $bovedaDestino, $monto, $concepto, $cortes, $idAgencia, $codUsuario, $ip, $uuid, $saldoOrigen) {
            DB::execute(
                "INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'STB')",
                [':id' => $idBovedaOrigen, ':saldo' => -$monto]
            );

            DB::execute(
                "INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'ETB')",
                [':id' => $idBovedaDestino, ':saldo' => $monto]
            );

            DB::execute(
                "INSERT INTO transferencia_boveda (id_tipo_transferencia, boveda_origen, boveda_destino, monto_total, estado, fec_crea_transferencia_boveda)
                 VALUES (1, :orig, :dest, :monto, 'A', NOW())",
                [':orig' => $idBovedaOrigen, ':dest' => $idBovedaDestino, ':monto' => $monto]
            );
            $idTB = (int) DB::scalar("SELECT LAST_INSERT_ID()");
            $this->guardarCortes('detalle_transferencia_boveda', $idTB, $cortes);

            $this->registrarLog($uuid, 'transferencia_boveda', null, $idBovedaOrigen, $codUsuario, $idAgencia, (int) $bovedaOrigen['id_moneda'], $monto, $ip, ['concepto' => $concepto, 'id_boveda_destino' => $idBovedaDestino, 'cortes' => $cortes]);

            $agOrigen = DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $bovedaOrigen['id_agencia']]);
            $agDestino = DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $bovedaDestino['id_agencia']]);

            return [
                'success' => true,
                'message' => 'Transferencia entre bóvedas realizada',
                'data'    => [
                    'boveda_origen'   => $agOrigen . ' (' . $bovedaOrigen['moneda'] . ')',
                    'boveda_destino'  => $agDestino . ' (' . $bovedaDestino['moneda'] . ')',
                    'monto'           => $monto,
                    'fecha' => date('Y-m-d H:i:s'),
                    'uuid'  => $uuid,
                ],
            ];
        });
    }

    /**
     * Transferencia de una caja a la bóveda de una agencia (la propia u otra).
     *
     * Flujo inmediato (como el legacy `registra_transferencia_caja.php`): la caja
     * se debita y deposita en su propia bóveda; si la agencia destino es distinta,
     * esa bóveda reenvía a la bóveda de la agencia destino. El detalle de billetes
     * se guarda en `detalle_transferencia_caja` y, en el tramo entre bóvedas,
     * también en `detalle_transferencia_boveda`.
     */
    public function transferenciaCajaAgencia(
        int $codUsuario, int $idAgencia, int $idCaja, int $idAgenciaDestino,
        float $monto, string $concepto, array $cortes, string $ip, ?int $idBovedaDestino = null
    ): array {
        if ($monto <= 0) return ['success' => false, 'message' => 'El monto debe ser mayor a 0'];

        $caja = $this->validarCajaAbierta($idCaja, $codUsuario);
        if (!$caja['success']) return $caja;
        $caja = $caja['caja'];
        $idMoneda = (int) $caja['id_moneda'];

        $fecha = date('Y-m-d');
        $saldoActual = $this->query->saldoActual($idCaja, $fecha);
        if ($monto > $saldoActual) {
            return ['success' => false, 'message' => 'Saldo insuficiente en caja. Disponible: ' . number_format($saldoActual, 2)];
        }

        $bovedaOrigen = $this->getBoveda($idAgencia, $idMoneda);
        if (!$bovedaOrigen) return ['success' => false, 'message' => 'No se encontró bóveda de tu agencia para esta moneda'];

        $bovedaDestino = $idBovedaDestino
            ? DB::selectOne(
                "SELECT b.*, m.abreviatura AS moneda FROM boveda b JOIN moneda m ON m.id_moneda = b.id_moneda WHERE b.id_boveda = :id AND b.id_agencia = :ag AND b.estado = 'A'",
                [':id' => $idBovedaDestino, ':ag' => $idAgenciaDestino]
            )
            : $this->getBoveda($idAgenciaDestino, $idMoneda);
        if (!$bovedaDestino) return ['success' => false, 'message' => 'La agencia destino no tiene bóveda para esta moneda'];
        if ((int) $bovedaDestino['id_moneda'] !== $idMoneda) {
            return ['success' => false, 'message' => 'La bóveda destino debe usar la misma moneda que la caja'];
        }

        if ($err = $this->errorCortes($cortes, $idMoneda, $monto)) {
            return ['success' => false, 'message' => $err];
        }

        $uuid = $this->uuid();
        $cruzada = (int) $bovedaDestino['id_boveda'] !== (int) $bovedaOrigen['id_boveda'];

        return DB::transaction(function () use ($idCaja, $idMoneda, $bovedaOrigen, $bovedaDestino, $monto, $concepto, $cortes, $cruzada, $idAgencia, $idAgenciaDestino, $codUsuario, $ip, $uuid, $saldoActual) {
            // 1. Caja → su propia bóveda
            DB::execute("INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :s, NOW(), 'ST')", [':id' => $idCaja, ':s' => -$monto]);
            DB::execute("INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :s, NOW(), :c)", [':id' => $bovedaOrigen['id_boveda'], ':s' => $monto, ':c' => 'ETC-' . $idCaja]);

            DB::execute(
                "INSERT INTO transferencia_caja (id_tipo_transferencia, id_caja, id_boveda, monto_total, motivo, estado, fec_crea_transferencia_caja)
                 VALUES (:tipo, :caja, :bov, :monto, :mot, 'A', NOW())",
                [':tipo' => self::TIPO_TRANSFERENCIA_ENVIO, ':caja' => $idCaja, ':bov' => $bovedaOrigen['id_boveda'], ':monto' => $monto, ':mot' => $concepto ?: null]
            );
            $idTC = (int) DB::scalar("SELECT LAST_INSERT_ID()");
            $this->guardarCortes('detalle_transferencia_caja', $idTC, $cortes);

            // 2. Si la bóveda destino es de otra agencia: reenvío bóveda → bóveda
            if ($cruzada) {
                DB::execute("INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :s, NOW(), :c)", [':id' => $bovedaOrigen['id_boveda'], ':s' => -$monto, ':c' => 'STB-' . $idAgenciaDestino]);
                DB::execute("INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :s, NOW(), :c)", [':id' => $bovedaDestino['id_boveda'], ':s' => $monto, ':c' => 'ETB-' . $idAgencia]);

                DB::execute(
                    "INSERT INTO transferencia_boveda (id_tipo_transferencia, boveda_origen, boveda_destino, monto_total, estado, fec_crea_transferencia_boveda)
                     VALUES (1, :o, :d, :monto, 'A', NOW())",
                    [':o' => $bovedaOrigen['id_boveda'], ':d' => $bovedaDestino['id_boveda'], ':monto' => $monto]
                );
                $idTB = (int) DB::scalar("SELECT LAST_INSERT_ID()");
                $this->guardarCortes('detalle_transferencia_boveda', $idTB, $cortes);
            }

            $this->registrarLog($uuid, 'transferencia_caja_agencia', $idCaja, (int) $bovedaDestino['id_boveda'], $codUsuario, $idAgencia, $idMoneda, $monto, $ip, ['concepto' => $concepto, 'id_agencia_destino' => $idAgenciaDestino, 'id_boveda_destino' => (int) $bovedaDestino['id_boveda'], 'cortes' => $cortes]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $idMoneda]);
            $agDest = DB::scalar("SELECT descripcion FROM agencia WHERE id_agencia = :a", [':a' => $idAgenciaDestino]);

            return [
                'success' => true,
                'message' => 'Transferencia a bóveda registrada correctamente',
                'data' => [
                    'id_caja'        => $idCaja,
                    'monto'          => $monto,
                    'moneda'         => $monAbr,
                    'boveda_destino' => $agDest,
                    'saldo_anterior' => $saldoActual,
                    'saldo_nuevo'    => $saldoActual - $monto,
                    'fecha'          => date('Y-m-d H:i:s'),
                    'uuid'           => $uuid,
                ],
            ];
        });
    }

    /**
     * Egreso con flujo de aprobación.
     * El dinero sale inmediatamente (EA) pero queda pendiente de aprobación del contador.
     * Origen: 'caja' (descuenta de caja) o 'banca' (solo registro, no afecta saldo_caja).
     */
    public function egreso(
        int $codUsuario, int $idAgencia, int $idCaja,
        float $monto, string $concepto, string $ip,
        string $origen = 'caja', string $beneficiario = '', string $categoria = 'otros'
    ): array {
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0'];
        }
        if (!$concepto) {
            return ['success' => false, 'message' => 'El concepto/motivo es requerido'];
        }

        $categoriasValidas = ['gastos_operativos', 'sueldos', 'servicios', 'impuestos', 'alquileres', 'mantenimiento', 'compras', 'otros'];
        if (!in_array($categoria, $categoriasValidas)) {
            $categoria = 'otros';
        }

        $caja = $this->validarCajaAbierta($idCaja, $codUsuario);
        if (!$caja['success']) return $caja;
        $caja = $caja['caja'];

        $fecha = date('Y-m-d');
        $saldoActual = $this->query->saldoActual($idCaja, $fecha);

        if ($origen === 'caja' && $monto > $saldoActual) {
            return ['success' => false, 'message' => "Saldo insuficiente en caja. Disponible: " . number_format($saldoActual, 2)];
        }

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCaja, $caja, $monto, $concepto, $fecha, $idAgencia, $codUsuario, $ip, $uuid, $saldoActual, $origen, $beneficiario, $categoria) {
            $idSaldoCaja = null;
            $nuevoSaldo = $saldoActual;

            if ($origen === 'caja') {
                DB::execute(
                    "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'EA')",
                    [':id' => $idCaja, ':saldo' => -$monto]
                );
                $idSaldoCaja = (int) DB::scalar("SELECT LAST_INSERT_ID()");
                $nuevoSaldo = $saldoActual - $monto;
            }

            $this->registrarLog($uuid, 'egreso', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $monto, $ip, [
                'concepto' => $concepto,
                'origen' => $origen,
                'beneficiario' => $beneficiario,
                'categoria' => $categoria,
            ]);

            $idLog = (int) DB::scalar("SELECT id FROM pos_operacion_log WHERE uuid = :u", [':u' => $uuid]);

            DB::execute(
                "INSERT INTO pos_egreso (uuid, origen, id_caja, id_saldo_caja, id_agencia, id_moneda, monto, concepto, beneficiario, categoria, estado, cod_usuario_cajero, ip_cajero, id_operacion_log)
                 VALUES (:uuid, :origen, :caja, :sc, :ag, :mon, :monto, :concepto, :benef, :cat, 'pendiente', :usr, :ip, :log)",
                [
                    ':uuid' => $uuid, ':origen' => $origen, ':caja' => $idCaja,
                    ':sc' => $idSaldoCaja, ':ag' => $idAgencia, ':mon' => (int) $caja['id_moneda'],
                    ':monto' => $monto, ':concepto' => $concepto, ':benef' => $beneficiario ?: null,
                    ':cat' => $categoria, ':usr' => $codUsuario, ':ip' => $ip, ':log' => $idLog ?: null,
                ]
            );

            $idEgreso = (int) DB::scalar("SELECT LAST_INSERT_ID()");
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $caja['id_moneda']]);

            return [
                'success' => true,
                'message' => $origen === 'caja'
                    ? 'Egreso registrado. El dinero fue descontado de la caja. Pendiente de aprobación del contador.'
                    : 'Egreso bancario registrado. Pendiente de aprobación del contador.',
                'data'    => [
                    'id_egreso'     => $idEgreso,
                    'id_caja'       => $idCaja,
                    'monto'         => $monto,
                    'moneda'        => $monAbr,
                    'concepto'      => $concepto,
                    'origen'        => $origen,
                    'estado'        => 'pendiente',
                    'beneficiario'  => $beneficiario,
                    'categoria'     => $categoria,
                    'saldo_anterior' => $saldoActual,
                    'saldo_nuevo'   => $nuevoSaldo,
                    'fecha'         => date('Y-m-d H:i:s'),
                    'uuid'          => $uuid,
                ],
            ];
        });
    }

    /**
     * Subir comprobante a un egreso (cajero o contador).
     */
    public function subirComprobanteEgreso(int $idEgreso, string $filePath, string $fileName, int $codUsuario, string $rol): array
    {
        $egreso = DB::selectOne("SELECT * FROM pos_egreso WHERE id = :id", [':id' => $idEgreso]);
        if (!$egreso) {
            return ['success' => false, 'message' => 'Egreso no encontrado'];
        }

        if ($rol === 'cajero') {
            DB::execute(
                "UPDATE pos_egreso SET comprobante_path = :path, comprobante_nombre = :name WHERE id = :id",
                [':path' => $filePath, ':name' => $fileName, ':id' => $idEgreso]
            );
        } else {
            DB::execute(
                "UPDATE pos_egreso SET comprobante_aprobacion_path = :path, comprobante_aprobacion_nombre = :name WHERE id = :id",
                [':path' => $filePath, ':name' => $fileName, ':id' => $idEgreso]
            );
        }

        return ['success' => true, 'message' => 'Comprobante subido correctamente'];
    }

    /**
     * Listar egresos pendientes de aprobación (para el contador).
     */
    public function egresosPendientes(int $idAgencia, ?string $desde = null, ?string $hasta = null): array
    {
        $desde = $desde ?: date('Y-m-d');
        $hasta = $hasta ?: $desde;

        return DB::select("
            SELECT e.*, m.abreviatura AS moneda, m.descripcion AS moneda_desc,
                   CONCAT(u.nombre, ' ', u.apellido) AS cajero_nombre,
                   c.num_caja,
                   CONCAT(ua.nombre, ' ', ua.apellido) AS aprobador_nombre
            FROM pos_egreso e
            JOIN moneda m ON m.id_moneda = e.id_moneda
            JOIN usuario u ON u.cod_usuario = e.cod_usuario_cajero
            LEFT JOIN caja c ON c.id_caja = e.id_caja
            LEFT JOIN usuario ua ON ua.cod_usuario = e.cod_usuario_aprobador
            WHERE e.id_agencia = :ag
              AND DATE(e.created_at) BETWEEN :desde AND :hasta
            ORDER BY
              CASE e.estado WHEN 'pendiente' THEN 0 WHEN 'aprobado' THEN 1 WHEN 'rechazado' THEN 2 ELSE 3 END,
              e.created_at DESC
        ", [':ag' => $idAgencia, ':desde' => $desde, ':hasta' => $hasta]);
    }

    /**
     * Aprobar un egreso (contador).
     */
    public function aprobarEgreso(int $idEgreso, int $codUsuario, int $idAgencia, string $observaciones, string $ip): array
    {
        $egreso = DB::selectOne("SELECT * FROM pos_egreso WHERE id = :id", [':id' => $idEgreso]);
        if (!$egreso) {
            return ['success' => false, 'message' => 'Egreso no encontrado'];
        }
        if ($egreso['estado'] !== 'pendiente') {
            return ['success' => false, 'message' => 'Este egreso ya fue ' . $egreso['estado']];
        }

        $sod = $this->validarAprobacion($egreso, $codUsuario, $idAgencia, 'aprobar');
        if ($sod !== null) return $sod;

        return DB::transaction(function () use ($idEgreso, $egreso, $codUsuario, $observaciones) {
            DB::execute(
                "UPDATE pos_egreso SET estado = 'aprobado', cod_usuario_aprobador = :usr, fecha_aprobacion = NOW(), observaciones_aprobacion = :obs WHERE id = :id",
                [':usr' => $codUsuario, ':obs' => $observaciones ?: null, ':id' => $idEgreso]
            );

            return [
                'success' => true,
                'message' => 'Egreso aprobado correctamente',
                'data'    => ['id_egreso' => $idEgreso, 'estado' => 'aprobado'],
            ];
        });
    }

    /**
     * Rechazar un egreso (contador). Revierte el saldo si fue de caja.
     */
    public function rechazarEgreso(int $idEgreso, int $codUsuario, int $idAgencia, string $observaciones, string $ip): array
    {
        $egreso = DB::selectOne("SELECT * FROM pos_egreso WHERE id = :id", [':id' => $idEgreso]);
        if (!$egreso) {
            return ['success' => false, 'message' => 'Egreso no encontrado'];
        }
        if ($egreso['estado'] !== 'pendiente') {
            return ['success' => false, 'message' => 'Este egreso ya fue ' . $egreso['estado']];
        }
        if (!$observaciones) {
            return ['success' => false, 'message' => 'Debe indicar el motivo del rechazo'];
        }

        $sod = $this->validarAprobacion($egreso, $codUsuario, $idAgencia, 'rechazar');
        if ($sod !== null) return $sod;

        return DB::transaction(function () use ($idEgreso, $egreso, $codUsuario, $idAgencia, $observaciones, $ip) {
            $idSaldoReversion = null;

            if ($egreso['origen'] === 'caja' && $egreso['id_caja']) {
                DB::execute(
                    "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'RA')",
                    [':id' => $egreso['id_caja'], ':saldo' => (float) $egreso['monto']]
                );
                $idSaldoReversion = (int) DB::scalar("SELECT LAST_INSERT_ID()");
            }

            DB::execute(
                "UPDATE pos_egreso SET estado = 'rechazado', cod_usuario_aprobador = :usr, fecha_aprobacion = NOW(), observaciones_aprobacion = :obs, id_saldo_caja_reversion = :rev WHERE id = :id",
                [':usr' => $codUsuario, ':obs' => $observaciones, ':rev' => $idSaldoReversion, ':id' => $idEgreso]
            );

            $uuid = $this->uuid();
            $this->registrarLog($uuid, 'egreso', (int) $egreso['id_caja'] ?: null, null, $codUsuario, $idAgencia, (int) $egreso['id_moneda'], (float) $egreso['monto'], $ip, [
                'accion' => 'rechazo',
                'id_egreso' => $idEgreso,
                'motivo_rechazo' => $observaciones,
            ]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $egreso['id_moneda']]);

            return [
                'success' => true,
                'message' => $egreso['origen'] === 'caja'
                    ? 'Egreso rechazado. El monto fue devuelto a la caja.'
                    : 'Egreso bancario rechazado.',
                'data'    => [
                    'id_egreso' => $idEgreso,
                    'estado'    => 'rechazado',
                    'monto_revertido' => (float) $egreso['monto'],
                    'moneda'    => $monAbr,
                ],
            ];
        });
    }

    /**
     * Controles de autorización para aprobar o rechazar un egreso.
     *
     * Dos comprobaciones que antes no existían:
     *
     *  1. SEGREGACIÓN DE FUNCIONES — un egreso de caja se descuenta del saldo en
     *     el momento de registrarlo (ver `egreso()`) y queda `pendiente` hasta que
     *     un tercero lo autoriza. Si quien lo registró puede además aprobarlo, el
     *     control no existe: el cajero se autoriza a sí mismo y el flujo de
     *     aprobación queda decorativo. La política de la empresa marca como
     *     incompatibles los pares Cajero vs Supervisor/Responsable
     *     (`Seguridad::paresIncompatibles()`: (6,12), (6,4)), y la ruta que expone
     *     estas acciones incluye el rol 6 (CAJERO); la separación debe imponerse en
     *     el servidor, no solo en la UI. La app de cajeros, de hecho, nunca llama a
     *     estos endpoints: la aprobación se hace desde el back-office.
     *
     *  2. ALCANCE POR AGENCIA — el egreso se aprueba/rechaza dentro de la agencia
     *     del token; sin esto, cualquier usuario autorizado de otra agencia podía
     *     operar sobre el egreso con solo conocer su id.
     *
     * @return array|null  Respuesta de error si falla algún control, o null si es válido.
     */
    private function validarAprobacion(array $egreso, int $codUsuario, int $idAgencia, string $accion): ?array
    {
        if ((int) ($egreso['cod_usuario_cajero'] ?? 0) === $codUsuario) {
            return [
                'success' => false,
                'message' => "No puede {$accion} un egreso que usted mismo registró. "
                           . 'La aprobación debe realizarla otro usuario (segregación de funciones).',
            ];
        }

        if ($idAgencia > 0 && (int) $egreso['id_agencia'] !== $idAgencia) {
            return [
                'success' => false,
                'message' => "No puede {$accion} un egreso de otra agencia.",
            ];
        }

        return null;
    }

    /**
     * Ingreso autorizado (entrada de efectivo a caja).
     * Conciliacion: IA.
     */
    public function ingreso(
        int $codUsuario, int $idAgencia, int $idCaja,
        float $monto, string $concepto, string $ip,
        string $origenIngreso = 'efectivo', string $entregadoPor = '', string $categoria = 'otros'
    ): array {
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0'];
        }
        if (!$concepto) {
            return ['success' => false, 'message' => 'El concepto/motivo es requerido'];
        }

        $caja = $this->validarCajaAbierta($idCaja, $codUsuario);
        if (!$caja['success']) return $caja;
        $caja = $caja['caja'];

        $fecha = date('Y-m-d');
        $saldoActual = $this->query->saldoActual($idCaja, $fecha);
        $nuevoSaldo = $saldoActual + $monto;
        if ($nuevoSaldo > (float) $caja['maximo']) {
            return ['success' => false, 'message' => "El ingreso excedería el máximo de la caja (" . number_format((float) $caja['maximo'], 2) . ")"];
        }

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCaja, $caja, $monto, $concepto, $fecha, $idAgencia, $codUsuario, $ip, $uuid, $saldoActual, $origenIngreso, $entregadoPor, $categoria) {
            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'IA')",
                [':id' => $idCaja, ':saldo' => $monto]
            );

            $this->registrarLog($uuid, 'ingreso', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $monto, $ip, [
                'concepto' => $concepto,
                'origen_ingreso' => $origenIngreso,
                'entregado_por' => $entregadoPor ?: null,
                'categoria' => $categoria,
            ]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $caja['id_moneda']]);

            return [
                'success' => true,
                'message' => 'Ingreso registrado correctamente',
                'data'    => [
                    'id_caja'       => $idCaja,
                    'monto'         => $monto,
                    'moneda'        => $monAbr,
                    'concepto'      => $concepto,
                    'origen_ingreso' => $origenIngreso,
                    'entregado_por' => $entregadoPor ?: null,
                    'categoria'     => $categoria,
                    'saldo_anterior' => $saldoActual,
                    'saldo_nuevo'   => $saldoActual + $monto,
                    'fecha'         => date('Y-m-d H:i:s'),
                    'uuid'          => $uuid,
                ],
            ];
        });
    }

    /** Adjunta comprobante a un ingreso directo propio de la agencia. */
    public function subirComprobanteIngreso(string $uuid, string $filePath, string $fileName, int $codUsuario, int $idAgencia): array
    {
        $log = DB::selectOne(
            "SELECT id, metadata FROM pos_operacion_log
             WHERE uuid = :uuid AND tipo_operacion = 'ingreso' AND cod_usuario = :usr AND id_agencia = :ag",
            [':uuid' => $uuid, ':usr' => $codUsuario, ':ag' => $idAgencia]
        );
        if (!$log) return ['success' => false, 'message' => 'Ingreso no encontrado o no pertenece a este usuario/agencia'];

        $metadata = $log['metadata'] ? (json_decode($log['metadata'], true) ?: []) : [];
        if (!empty($metadata['autorizado'])) {
            return ['success' => false, 'message' => 'No se puede adjuntar un comprobante a un ingreso contable autorizado'];
        }
        $metadata['comprobante_path'] = $filePath;
        $metadata['comprobante_nombre'] = $fileName;

        DB::execute(
            "UPDATE pos_operacion_log SET metadata = :metadata WHERE id = :id",
            [':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE), ':id' => (int) $log['id']]
        );

        return ['success' => true, 'message' => 'Comprobante de ingreso subido correctamente'];
    }

    /** Adjunta un comprobante a una transacción bancaria propia del cajero. */
    public function subirComprobanteTransaccionBancaria(string $uuid, string $filePath, string $fileName, int $codUsuario, int $idAgencia): array
    {
        $log = DB::selectOne(
            "SELECT id, metadata FROM pos_operacion_log
             WHERE uuid = :uuid AND tipo_operacion = 'transaccion_bancaria' AND cod_usuario = :usr AND id_agencia = :ag",
            [':uuid' => $uuid, ':usr' => $codUsuario, ':ag' => $idAgencia]
        );
        if (!$log) return ['success' => false, 'message' => 'Transacción bancaria no encontrada o no pertenece a este usuario/agencia'];

        $metadata = $log['metadata'] ? (json_decode($log['metadata'], true) ?: []) : [];
        $metadata['comprobante_path'] = $filePath;
        $metadata['comprobante_nombre'] = $fileName;

        DB::execute(
            "UPDATE pos_operacion_log SET metadata = :metadata WHERE id = :id",
            [':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE), ':id' => (int) $log['id']]
        );

        return ['success' => true, 'message' => 'Comprobante de transacción bancaria subido correctamente'];
    }

    /**
     * Transacción bancaria (depósito, retiro, ITF, intereses).
     * Conciliacion: DB (depósito), RB (retiro/reversión), SE (ITF), EE (intereses).
     */
    public function transaccionBancaria(
        int $codUsuario, int $idAgencia, int $idCaja,
        string $tipo, float $monto, string $referencia, string $concepto, string $ip
    ): array {
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0'];
        }

        $tiposValidos = [
            'deposito'  => ['conciliacion' => 'DB', 'signo' => -1, 'label' => 'Depósito bancario'],
            'retiro'    => ['conciliacion' => 'RB', 'signo' => 1,  'label' => 'Retiro bancario'],
            'itf'       => ['conciliacion' => 'SE', 'signo' => -1, 'label' => 'Cobro de ITF'],
            'intereses' => ['conciliacion' => 'EE', 'signo' => 1,  'label' => 'Intereses bancarios'],
        ];

        if (!isset($tiposValidos[$tipo])) {
            return ['success' => false, 'message' => 'Tipo de transacción no válido. Use: deposito, retiro, itf, intereses'];
        }

        $config = $tiposValidos[$tipo];

        $caja = $this->validarCajaAbierta($idCaja, $codUsuario);
        if (!$caja['success']) return $caja;
        $caja = $caja['caja'];

        $moneda = strtoupper((string) DB::scalar(
            "SELECT abreviatura FROM moneda WHERE id_moneda = :id",
            [':id' => (int) $caja['id_moneda']]
        ));
        if (!in_array($moneda, ['BOB', 'USD'], true)) {
            return ['success' => false, 'message' => 'Las transacciones bancarias solo están habilitadas para cajas en BOB o USD'];
        }

        $fecha = date('Y-m-d');
        $saldoActual = $this->query->saldoActual($idCaja, $fecha);
        $movimiento = $monto * $config['signo'];

        if ($config['signo'] < 0 && $monto > $saldoActual) {
            return ['success' => false, 'message' => "Saldo insuficiente en caja. Disponible: " . number_format($saldoActual, 2)];
        }

        if ($config['signo'] > 0) {
            $nuevoSaldo = $saldoActual + $monto;
            if ($nuevoSaldo > (float) $caja['maximo']) {
                return ['success' => false, 'message' => "El ingreso excedería el máximo de la caja"];
            }
        }

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCaja, $caja, $monto, $movimiento, $config, $tipo, $referencia, $concepto, $fecha, $idAgencia, $codUsuario, $ip, $uuid, $saldoActual) {
            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), :conc)",
                [':id' => $idCaja, ':saldo' => $movimiento, ':conc' => $config['conciliacion']]
            );

            $this->registrarLog($uuid, 'transaccion_bancaria', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $monto, $ip, [
                'tipo_transaccion' => $tipo,
                'referencia' => $referencia,
                'concepto' => $concepto,
            ]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $caja['id_moneda']]);

            return [
                'success' => true,
                'message' => $config['label'] . ' registrado correctamente',
                'data'    => [
                    'id_caja'       => $idCaja,
                    'tipo'          => $tipo,
                    'tipo_label'    => $config['label'],
                    'monto'         => $monto,
                    'moneda'        => $monAbr,
                    'referencia'    => $referencia,
                    'concepto'      => $concepto,
                    'saldo_anterior' => $saldoActual,
                    'saldo_nuevo'   => $saldoActual + $movimiento,
                    'fecha'         => date('Y-m-d H:i:s'),
                    'uuid'          => $uuid,
                ],
            ];
        });
    }

    /**
     * Lista las cajas abiertas de otros cajeros en la misma agencia y moneda (para traspaso).
     */
    public function cajasDestinoTraspaso(int $codUsuario, int $idAgencia, int $idMoneda): array
    {
        return DB::select("
            SELECT c.id_caja, c.num_caja, c.id_moneda, m.abreviatura AS moneda,
                   u.nombre, u.apellido, CONCAT(u.nombre, ' ', u.apellido) AS cajero
            FROM caja c
            JOIN moneda m ON m.id_moneda = c.id_moneda
            JOIN usuario u ON u.cod_usuario = c.cod_usuario
            WHERE c.id_agencia = :ag AND c.id_moneda = :mon AND c.estado = 'A'
              AND c.id_tipo_unidad = 2 AND c.cod_usuario != :usr
              AND EXISTS (
                  SELECT 1 FROM saldo_caja sc
                  WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'A'
                    AND DATE(sc.fec_crea_saldo) = CURDATE()
              )
              AND NOT EXISTS (
                  SELECT 1 FROM saldo_caja sc2
                  WHERE sc2.id_caja = c.id_caja AND sc2.conciliacion = 'C'
                    AND DATE(sc2.fec_crea_saldo) = CURDATE()
              )
            ORDER BY c.num_caja
        ", [':ag' => $idAgencia, ':mon' => $idMoneda, ':usr' => $codUsuario]);
    }

    /**
     * Lista bóvedas disponibles para transferencias, con su saldo real.
     *
     * El saldo se calcula con `CajeroBalanceService` (apertura + movimientos del
     * día); antes se usaba `SUM(saldo)` acumulado del histórico, que sumaba las
     * filas de arqueo 'A'/'C' y no tenía significado.
     */
    public function bovedasDisponibles(int $idAgencia): array
    {
        $bovedas = DB::select("
            SELECT b.id_boveda, b.num_boveda, b.id_agencia, b.id_moneda,
                   m.abreviatura AS moneda, a.descripcion AS agencia
            FROM boveda b
            JOIN moneda m ON m.id_moneda = b.id_moneda
            JOIN agencia a ON a.id_agencia = b.id_agencia
            WHERE b.estado = 'A'
            ORDER BY a.descripcion, m.abreviatura
        ", []);

        $hoy = date('Y-m-d');
        foreach ($bovedas as &$b) {
            $b['saldo_actual'] = $this->saldoBoveda((int) $b['id_boveda'], $hoy);
        }
        unset($b);

        return $bovedas;
    }

    /**
     * Historial de movimientos de una caja en el rango indicado.
     */
    public function movimientosCaja(int $idCaja, int $codUsuario, int $idAgencia, string $desde, string $hasta): array
    {
        $caja = DB::selectOne(
            "SELECT id_caja FROM caja WHERE id_caja = :id AND cod_usuario = :usr AND id_agencia = :ag AND id_tipo_unidad = 2 AND estado = 'A'",
            [':id' => $idCaja, ':usr' => $codUsuario, ':ag' => $idAgencia]
        );
        if (!$caja) return ['success' => false, 'message' => 'Caja no encontrada o no pertenece al usuario'];

        $movimientos = DB::select(" 
            SELECT pol.id, pol.uuid,
                   CASE WHEN pol.tipo_operacion = '' AND JSON_CONTAINS_PATH(pol.metadata, 'one', '$.id_boveda_destino') = 1
                        THEN 'transferencia_caja_agencia' ELSE pol.tipo_operacion END AS tipo_operacion,
                   pol.id_moneda, m.abreviatura AS moneda,
                   pol.monto_total AS monto, pol.estado, pol.metadata, pol.created_at,
                   caja_destino.num_caja AS num_caja_destino,
                   TRIM(CONCAT_WS(' ', trabajador_destino.nom_trab, trabajador_destino.ape_pat_trab, trabajador_destino.ape_mat_trab)) AS cajero_destino,
                   caja_origen.num_caja AS num_caja_origen,
                   TRIM(CONCAT_WS(' ', trabajador_origen.nom_trab, trabajador_origen.ape_pat_trab, trabajador_origen.ape_mat_trab)) AS cajero_origen,
                   boveda_origen.num_boveda AS num_boveda_origen,
                   agencia_origen.descripcion AS agencia_boveda_origen,
                   moneda_origen.abreviatura AS moneda_boveda_origen,
                   boveda_destino.num_boveda AS num_boveda_destino,
                   agencia_destino.descripcion AS agencia_boveda_destino,
                   moneda_destino.abreviatura AS moneda_boveda_destino,
                   CASE WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(pol.metadata, '$.id_caja_destino')) AS UNSIGNED) = :caja_destino
                        THEN 1 ELSE 0 END AS es_destino,
                   pe.estado AS estado_egreso, pe.origen, pe.beneficiario, pe.categoria,
                   pe.comprobante_nombre, pe.observaciones_aprobacion,
                   (SELECT sc.saldo FROM saldo_caja sc
                    WHERE sc.id_caja = pol.id_caja
                      AND DATE(sc.fec_crea_saldo) = DATE(pol.created_at)
                      AND ABS(TIMESTAMPDIFF(SECOND, sc.fec_crea_saldo, pol.created_at)) < 5
                      AND ABS(ABS(sc.saldo) - pol.monto_total) < 0.01
                      AND NOT (pol.tipo_operacion = 'egreso' AND JSON_UNQUOTE(JSON_EXTRACT(pol.metadata, '$.origen')) = 'banca')
                    ORDER BY ABS(TIMESTAMPDIFF(MICROSECOND, sc.fec_crea_saldo, pol.created_at))
                    LIMIT 1) AS impacto_saldo
            FROM pos_operacion_log pol
            JOIN moneda m ON m.id_moneda = pol.id_moneda
            LEFT JOIN pos_egreso pe ON pe.uuid = pol.uuid
            LEFT JOIN caja caja_destino
              ON caja_destino.id_caja = CAST(JSON_UNQUOTE(JSON_EXTRACT(pol.metadata, '$.id_caja_destino')) AS UNSIGNED)
            LEFT JOIN usuario usuario_destino ON usuario_destino.cod_usuario = caja_destino.cod_usuario
            LEFT JOIN trabajador trabajador_destino ON trabajador_destino.cod_trab = usuario_destino.cod_trab
            LEFT JOIN caja caja_origen ON caja_origen.id_caja = pol.id_caja
            LEFT JOIN usuario usuario_origen ON usuario_origen.cod_usuario = caja_origen.cod_usuario
            LEFT JOIN trabajador trabajador_origen ON trabajador_origen.cod_trab = usuario_origen.cod_trab
            LEFT JOIN boveda boveda_origen ON boveda_origen.id_boveda = pol.id_boveda
            LEFT JOIN agencia agencia_origen ON agencia_origen.id_agencia = boveda_origen.id_agencia
            LEFT JOIN moneda moneda_origen ON moneda_origen.id_moneda = boveda_origen.id_moneda
            LEFT JOIN boveda boveda_destino
              ON boveda_destino.id_boveda = CAST(JSON_UNQUOTE(JSON_EXTRACT(pol.metadata, '$.id_boveda_destino')) AS UNSIGNED)
            LEFT JOIN agencia agencia_destino ON agencia_destino.id_agencia = boveda_destino.id_agencia
            LEFT JOIN moneda moneda_destino ON moneda_destino.id_moneda = boveda_destino.id_moneda
            WHERE pol.id_caja = :id AND pol.id_agencia = :ag
              AND DATE(pol.created_at) BETWEEN :desde_caja AND :hasta_caja
              AND (pol.tipo_operacion IN ('egreso', 'ingreso', 'transaccion_bancaria', 'solicitud_fondos', 'traspaso_boveda', 'traspaso_cajero', 'transferencia_boveda', 'transferencia_caja_agencia')
                   OR (pol.tipo_operacion = '' AND JSON_CONTAINS_PATH(pol.metadata, 'one', '$.id_boveda_destino') = 1))
            UNION
            SELECT pol.id, pol.uuid, pol.tipo_operacion, pol.id_moneda, m.abreviatura AS moneda,
                   pol.monto_total AS monto, pol.estado, pol.metadata, pol.created_at,
                   caja_destino.num_caja AS num_caja_destino,
                   TRIM(CONCAT_WS(' ', trabajador_destino.nom_trab, trabajador_destino.ape_pat_trab, trabajador_destino.ape_mat_trab)) AS cajero_destino,
                   caja_origen.num_caja AS num_caja_origen,
                   TRIM(CONCAT_WS(' ', trabajador_origen.nom_trab, trabajador_origen.ape_pat_trab, trabajador_origen.ape_mat_trab)) AS cajero_origen,
                   boveda_origen.num_boveda AS num_boveda_origen,
                   agencia_origen.descripcion AS agencia_boveda_origen,
                   moneda_origen.abreviatura AS moneda_boveda_origen,
                   boveda_destino.num_boveda AS num_boveda_destino,
                   agencia_destino.descripcion AS agencia_boveda_destino,
                   moneda_destino.abreviatura AS moneda_boveda_destino,
                   1 AS es_destino,
                   pe.estado AS estado_egreso, pe.origen, pe.beneficiario, pe.categoria,
                   pe.comprobante_nombre, pe.observaciones_aprobacion,
                   NULL AS impacto_saldo
            FROM pos_operacion_log pol
            JOIN moneda m ON m.id_moneda = pol.id_moneda
            LEFT JOIN pos_egreso pe ON pe.uuid = pol.uuid
            LEFT JOIN caja caja_destino
              ON caja_destino.id_caja = CAST(JSON_UNQUOTE(JSON_EXTRACT(pol.metadata, '$.id_caja_destino')) AS UNSIGNED)
            LEFT JOIN usuario usuario_destino ON usuario_destino.cod_usuario = caja_destino.cod_usuario
            LEFT JOIN caja caja_origen ON caja_origen.id_caja = pol.id_caja
            LEFT JOIN usuario usuario_origen ON usuario_origen.cod_usuario = caja_origen.cod_usuario
            LEFT JOIN trabajador trabajador_destino ON trabajador_destino.cod_trab = usuario_destino.cod_trab
            LEFT JOIN trabajador trabajador_origen ON trabajador_origen.cod_trab = usuario_origen.cod_trab
            LEFT JOIN boveda boveda_origen ON boveda_origen.id_boveda = pol.id_boveda
            LEFT JOIN agencia agencia_origen ON agencia_origen.id_agencia = boveda_origen.id_agencia
            LEFT JOIN moneda moneda_origen ON moneda_origen.id_moneda = boveda_origen.id_moneda
            LEFT JOIN boveda boveda_destino
              ON boveda_destino.id_boveda = CAST(JSON_UNQUOTE(JSON_EXTRACT(pol.metadata, '$.id_boveda_destino')) AS UNSIGNED)
            LEFT JOIN agencia agencia_destino ON agencia_destino.id_agencia = boveda_destino.id_agencia
            LEFT JOIN moneda moneda_destino ON moneda_destino.id_moneda = boveda_destino.id_moneda
            WHERE pol.id_agencia = :ag_destino
              AND pol.tipo_operacion = 'traspaso_cajero'
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(pol.metadata, '$.id_caja_destino')) AS UNSIGNED) = :id_destino
              AND DATE(pol.created_at) BETWEEN :desde_destino AND :hasta_destino
            UNION
            SELECT pol.id, pol.uuid, pol.tipo_operacion, pol.id_moneda, m.abreviatura AS moneda,
                   pol.monto_total AS monto, pol.estado, pol.metadata, pol.created_at,
                   NULL AS num_caja_destino, NULL AS cajero_destino,
                   NULL AS num_caja_origen, NULL AS cajero_origen,
                   boveda_origen.num_boveda AS num_boveda_origen,
                   agencia_origen.descripcion AS agencia_boveda_origen,
                   moneda_origen.abreviatura AS moneda_boveda_origen,
                   boveda_destino.num_boveda AS num_boveda_destino,
                   agencia_destino.descripcion AS agencia_boveda_destino,
                   moneda_destino.abreviatura AS moneda_boveda_destino,
                   CASE WHEN boveda_destino.id_agencia = :agencia_boveda_destino THEN 1 ELSE 0 END AS es_destino,
                   pe.estado AS estado_egreso, pe.origen, pe.beneficiario, pe.categoria,
                   pe.comprobante_nombre, pe.observaciones_aprobacion,
                   NULL AS impacto_saldo
            FROM pos_operacion_log pol
            JOIN moneda m ON m.id_moneda = pol.id_moneda
            LEFT JOIN pos_egreso pe ON pe.uuid = pol.uuid
            LEFT JOIN boveda boveda_origen ON boveda_origen.id_boveda = pol.id_boveda
            LEFT JOIN agencia agencia_origen ON agencia_origen.id_agencia = boveda_origen.id_agencia
            LEFT JOIN moneda moneda_origen ON moneda_origen.id_moneda = boveda_origen.id_moneda
            LEFT JOIN boveda boveda_destino
              ON boveda_destino.id_boveda = CAST(JSON_UNQUOTE(JSON_EXTRACT(pol.metadata, '$.id_boveda_destino')) AS UNSIGNED)
            LEFT JOIN agencia agencia_destino ON agencia_destino.id_agencia = boveda_destino.id_agencia
            LEFT JOIN moneda moneda_destino ON moneda_destino.id_moneda = boveda_destino.id_moneda
            WHERE pol.tipo_operacion = 'transferencia_boveda'
              AND (pol.id_agencia = :ag_boveda OR boveda_destino.id_agencia = :agencia_boveda_filtro)
              AND DATE(pol.created_at) BETWEEN :desde_boveda AND :hasta_boveda
            ORDER BY created_at DESC, id DESC
        ", [':id' => $idCaja, ':ag' => $idAgencia, ':caja_destino' => $idCaja,
            ':ag_destino' => $idAgencia, ':id_destino' => $idCaja, ':ag_boveda' => $idAgencia,
            ':agencia_boveda_destino' => $idAgencia, ':agencia_boveda_filtro' => $idAgencia,
            ':desde_caja' => $desde, ':hasta_caja' => $hasta,
            ':desde_destino' => $desde, ':hasta_destino' => $hasta,
            ':desde_boveda' => $desde, ':hasta_boveda' => $hasta]);

        foreach ($movimientos as &$movimiento) {
            $movimiento['metadata'] = $movimiento['metadata'] ? (json_decode($movimiento['metadata'], true) ?: []) : [];
            $movimiento['metadata']['num_caja_destino'] = $movimiento['num_caja_destino'] ?? null;
            $movimiento['metadata']['cajero_destino'] = $movimiento['cajero_destino'] ?? null;
            $movimiento['metadata']['num_caja_origen'] = $movimiento['num_caja_origen'] ?? null;
            $movimiento['metadata']['cajero_origen'] = $movimiento['cajero_origen'] ?? null;
            $movimiento['metadata']['es_destino'] = (bool) ($movimiento['es_destino'] ?? false);
            $movimiento['metadata']['boveda_origen'] = isset($movimiento['num_boveda_origen'])
                ? trim(($movimiento['agencia_boveda_origen'] ?? 'Bóveda') . ' · ' . ($movimiento['moneda_boveda_origen'] ?? ''))
                : null;
            $movimiento['metadata']['boveda_destino'] = isset($movimiento['num_boveda_destino'])
                ? trim(($movimiento['agencia_boveda_destino'] ?? 'Bóveda') . ' · ' . ($movimiento['moneda_boveda_destino'] ?? ''))
                : null;
        }
        unset($movimiento);

        return ['success' => true, 'data' => $movimientos];
    }

    /**
     * Cuentas bancarias activas de la agencia para cobros QR/transferencia.
     */
    public function cuentasBancariasCobro(int $idAgencia): array
    {
        return DB::select("
            SELECT cb.num_cuenta_bancaria, cb.id_entidad_financiera, cb.id_moneda,
                   cb.codsubcuenta, cb.id_agencia, cb.id_tipo_cuenta_bancaria,
                   ef.abreviatura AS entidad, ef.descripcion AS entidad_desc,
                   m.abreviatura AS moneda, tcb.descripcion AS tipo_cuenta
            FROM cuenta_bancaria cb
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = cb.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            INNER JOIN tipo_cuenta_bancaria tcb ON tcb.id_tipo_cuenta_bancaria = cb.id_tipo_cuenta_bancaria
            WHERE cb.estado = 'A' AND cb.id_agencia = :ag
            ORDER BY m.abreviatura, ef.descripcion
        ", [':ag' => $idAgencia]);
    }

    /**
     * Buscar asiento contable autorizado por el contador.
     * Tipo 'E' = egreso, 'I' = ingreso.
     */
    public function buscarAsientoAutorizado(string $tipo, int $numero): array
    {
        if (!in_array($tipo, ['E', 'I'])) {
            return ['success' => false, 'message' => 'Tipo debe ser E (egreso) o I (ingreso)'];
        }
        if ($numero <= 0) {
            return ['success' => false, 'message' => 'El número de comprobante debe ser mayor a 0'];
        }

        $codejercicio = date('Y');

        $asiento = DB::selectOne("
            SELECT idasiento, numero, concepto, fecha, importe, tipodocumento
            FROM co_asientos
            WHERE numero = :num AND tipodocumento = :tipo AND idconcepto = '000' AND codejercicio = :ej
            LIMIT 1
        ", [':num' => $numero, ':tipo' => $tipo, ':ej' => $codejercicio], 'conta');

        if (!$asiento) {
            $label = $tipo === 'E' ? 'egreso' : 'ingreso';
            return ['success' => false, 'message' => "No se encontró comprobante de {$label} con número {$numero} para el ejercicio {$codejercicio}"];
        }

        $prefijo = $tipo === 'E' ? 'E' : 'I';
        $tabla = $tipo === 'E' ? 'egreso' : 'ingreso';
        $existente = DB::selectOne(
            "SELECT id_caja FROM {$tabla} WHERE glosa LIKE :pat LIMIT 1",
            [':pat' => "{$prefijo}-{$numero}: %"]
        );
        if ($existente) {
            $label = $tipo === 'E' ? 'egreso' : 'ingreso';
            return ['success' => false, 'message' => "Este comprobante de {$label} ya fue ejecutado anteriormente"];
        }

        $idasiento = (int) $asiento['idasiento'];
        $campo = $tipo === 'E' ? 'haber' : 'debe';
        $campoMe = $tipo === 'E' ? 'haberme' : 'debeme';
        $partida = DB::selectOne("
            SELECT codsubcuenta, coddivisa, {$campo} AS importe_base,
                   {$campoMe} AS importe_me, tasaconv
            FROM co_partidas
            WHERE idasiento = :id AND codsubcuenta LIKE '11101%'
              AND ({$campo} > 0 OR {$campoMe} > 0)
            ORDER BY idpartida
            LIMIT 1
        ", [':id' => $idasiento], 'conta');

        if (!$partida) {
            return ['success' => false, 'message' => 'No se encontró la partida contable de caja para este asiento'];
        }

        $codsubcuenta = (string) $partida['codsubcuenta'];
        $monedaAbr = self::CUENTAS_CAJA_MONEDA[$codsubcuenta] ?? null;
        if (!$monedaAbr) {
            return ['success' => false, 'message' => "La subcuenta de caja {$codsubcuenta} no tiene una moneda configurada en el POS"];
        }

        $moneda = DB::selectOne(
            "SELECT id_moneda, abreviatura FROM moneda WHERE UPPER(abreviatura) = :abr LIMIT 1",
            [':abr' => $monedaAbr]
        );
        if (!$moneda) {
            return ['success' => false, 'message' => "No existe la moneda {$monedaAbr} asociada a la subcuenta {$codsubcuenta}"];
        }
        $idMoneda = (int) $moneda['id_moneda'];

        // La partida conserva el importe en moneda local (debe/haber) y, cuando
        // aplica, su importe en moneda extranjera (debeme/haberme). Usamos este
        // último para no convertir divisas distintas con el tipo de cambio USD.
        $importeBase = (float) $partida['importe_base'];
        $importeMe = (float) ($partida['importe_me'] ?? 0);
        if ($monedaAbr === 'BOB') {
            $importe = $importeBase;
        } elseif ($importeMe > 0) {
            $importe = $importeMe;
        } else {
            $tasa = (float) ($partida['tasaconv'] ?? 0);
            if ($tasa <= 0) {
                return ['success' => false, 'message' => "El asiento no contiene importe en {$monedaAbr} ni una tasa de conversión válida"];
            }
            $importe = round($importeBase / $tasa, 2);
        }
        $importe = round($importe, 2);

        return [
            'success' => true,
            'data' => [
                'idasiento'     => $idasiento,
                'numero'        => (int) $asiento['numero'],
                'glosa'         => $asiento['concepto'],
                'fecha_asiento' => $asiento['fecha'],
                'monto'         => $importe,
                'id_moneda'     => $idMoneda,
                'moneda'        => $monedaAbr,
                'codsubcuenta'  => $codsubcuenta,
                'tipo'          => $tipo,
            ],
        ];
    }

    /**
     * Completar egreso autorizado: descuenta de caja y registra en tabla egreso.
     * Conciliacion: EA (negativo).
     */
    public function completarEgresoAutorizado(
        int $codUsuario, int $idAgencia, int $idCaja,
        int $numero, string $glosa, float $monto, int $idMoneda, string $ip
    ): array {
        // El importe y la moneda se re-derivan del asiento contable; lo que envíe el
        // navegador solo se usa para cotejar. Ver `resolverAsientoAutorizado()`.
        $resuelto = $this->resolverAsientoAutorizado('E', $numero, $monto, $idMoneda);
        if (isset($resuelto['error'])) return $resuelto['error'];
        $monto    = $resuelto['importe'];
        $idMoneda = $resuelto['idMoneda'];
        $codsubcuenta = $resuelto['codsubcuenta'];
        $idasiento = $resuelto['idasiento'];

        $caja = $this->validarCajaAbierta($idCaja, $codUsuario);
        if (!$caja['success']) return $caja;
        $caja = $caja['caja'];

        if ((int) $caja['id_moneda'] !== $idMoneda) {
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $idMoneda]);
            return ['success' => false, 'message' => "La caja seleccionada no opera en {$monAbr}"];
        }

        $fecha = date('Y-m-d');
        $saldoActual = $this->query->saldoActual($idCaja, $fecha);

        if ($monto > $saldoActual) {
            return ['success' => false, 'message' => "Saldo insuficiente en caja. Disponible: " . number_format($saldoActual, 2)];
        }

        $existente = DB::selectOne(
            "SELECT id_caja FROM egreso WHERE glosa LIKE :pat LIMIT 1",
            [':pat' => "E-{$numero}: %"]
        );
        if ($existente) {
            return ['success' => false, 'message' => 'Este egreso autorizado ya fue ejecutado'];
        }

        $uuid = $this->uuid();
        $glosaCombinada = "E-{$numero}: {$glosa}";

        return DB::transaction(function () use ($idCaja, $caja, $monto, $glosaCombinada, $fecha, $idAgencia, $codUsuario, $ip, $uuid, $saldoActual, $numero, $glosa, $codsubcuenta, $idasiento) {
            DB::execute(
                "INSERT INTO egreso (id_caja, glosa, fecha, comprobante_path, comprobante_nombre, estado)
                 VALUES (:caja, :glosa, :fecha, NULL, NULL, 1)",
                [':caja' => $idCaja, ':glosa' => $glosaCombinada, ':fecha' => $fecha]
            );

            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'EA')",
                [':id' => $idCaja, ':saldo' => -$monto]
            );

            $this->registrarLog($uuid, 'egreso', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $monto, $ip, [
                'autorizado' => true,
                'numero_comprobante' => $numero,
                'idasiento' => $idasiento,
                'codsubcuenta' => $codsubcuenta,
                'glosa' => $glosa,
            ]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $caja['id_moneda']]);

            return [
                'success' => true,
                'message' => 'Egreso autorizado ejecutado correctamente',
                'data'    => [
                    'id_caja'        => $idCaja,
                    'monto'          => $monto,
                    'moneda'         => $monAbr,
                    'concepto'       => $glosaCombinada,
                    'saldo_anterior' => $saldoActual,
                    'saldo_nuevo'    => $saldoActual - $monto,
                    'fecha'          => date('Y-m-d H:i:s'),
                    'uuid'           => $uuid,
                ],
            ];
        });
    }

    /**
     * Completar ingreso autorizado: ingresa a caja y registra en tabla ingreso.
     * Conciliacion: IA (positivo).
     */
    public function completarIngresoAutorizado(
        int $codUsuario, int $idAgencia, int $idCaja,
        int $numero, string $glosa, float $monto, int $idMoneda, string $ip
    ): array {
        // Igual que en el egreso: el importe autoritativo sale del asiento, no del POS.
        $resuelto = $this->resolverAsientoAutorizado('I', $numero, $monto, $idMoneda);
        if (isset($resuelto['error'])) return $resuelto['error'];
        $monto    = $resuelto['importe'];
        $idMoneda = $resuelto['idMoneda'];
        $codsubcuenta = $resuelto['codsubcuenta'];
        $idasiento = $resuelto['idasiento'];

        $caja = $this->validarCajaAbierta($idCaja, $codUsuario);
        if (!$caja['success']) return $caja;
        $caja = $caja['caja'];

        if ((int) $caja['id_moneda'] !== $idMoneda) {
            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $idMoneda]);
            return ['success' => false, 'message' => "La caja seleccionada no opera en {$monAbr}"];
        }

        $fecha = date('Y-m-d');
        $saldoActual = $this->query->saldoActual($idCaja, $fecha);
        $nuevoSaldo = $saldoActual + $monto;

        if ($nuevoSaldo > (float) $caja['maximo']) {
            return ['success' => false, 'message' => "El ingreso excedería el máximo de la caja (" . number_format((float) $caja['maximo'], 2) . ")"];
        }

        $existente = DB::selectOne(
            "SELECT id_caja FROM ingreso WHERE glosa LIKE :pat LIMIT 1",
            [':pat' => "I-{$numero}: %"]
        );
        if ($existente) {
            return ['success' => false, 'message' => 'Este ingreso autorizado ya fue ejecutado'];
        }

        $uuid = $this->uuid();
        $glosaCombinada = "I-{$numero}: {$glosa}";

        return DB::transaction(function () use ($idCaja, $caja, $monto, $glosaCombinada, $fecha, $idAgencia, $codUsuario, $ip, $uuid, $saldoActual, $numero, $glosa, $codsubcuenta, $idasiento) {
            DB::execute(
                "INSERT INTO ingreso (id_caja, glosa, fecha, comprobante_path, comprobante_nombre, estado)
                 VALUES (:caja, :glosa, :fecha, NULL, NULL, 1)",
                [':caja' => $idCaja, ':glosa' => $glosaCombinada, ':fecha' => $fecha]
            );

            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'IA')",
                [':id' => $idCaja, ':saldo' => $monto]
            );

            $this->registrarLog($uuid, 'ingreso', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $monto, $ip, [
                'autorizado' => true,
                'numero_comprobante' => $numero,
                'idasiento' => $idasiento,
                'codsubcuenta' => $codsubcuenta,
                'glosa' => $glosa,
            ]);

            $monAbr = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $caja['id_moneda']]);

            return [
                'success' => true,
                'message' => 'Ingreso autorizado ejecutado correctamente',
                'data'    => [
                    'id_caja'        => $idCaja,
                    'monto'          => $monto,
                    'moneda'         => $monAbr,
                    'concepto'       => $glosaCombinada,
                    'saldo_anterior' => $saldoActual,
                    'saldo_nuevo'    => $saldoActual + $monto,
                    'fecha'          => date('Y-m-d H:i:s'),
                    'uuid'           => $uuid,
                ],
            ];
        });
    }

    /**
     * Resuelve el asiento autorizado y devuelve el importe/moneda AUTORITATIVOS.
     *
     * Antes, `completarEgresoAutorizado` / `completarIngresoAutorizado` confiaban en
     * el `monto` y el `id_moneda` que enviaba el navegador: el cajero tecleaba el
     * importe y el backend lo aplicaba tal cual contra `saldo_caja`. El asiento se
     * consultaba solo para pintarlo en pantalla, así que un cliente manipulado podía
     * mover de caja cualquier cifra mientras la glosa citara un comprobante real.
     *
     * Ahora el valor se re-deriva de `co_partidas` (la única fuente válida) y se
     * exige que coincida con lo recibido.
     *
     * @return array{error?: array, importe?: float, idMoneda?: int, idasiento?: int, codsubcuenta?: string}
     */
    private function resolverAsientoAutorizado(string $tipo, int $numero, float $montoEnviado, int $idMonedaEnviado): array
    {
        $asiento = $this->buscarAsientoAutorizado($tipo, $numero);
        if (empty($asiento['success'])) {
            return ['error' => $asiento];
        }

        $importeAutorizado = (float) $asiento['data']['monto'];
        $idMonedaAutorizado = (int) $asiento['data']['id_moneda'];

        if (abs($importeAutorizado - $montoEnviado) > 0.01) {
            return ['error' => [
                'success' => false,
                'message' => 'El importe no coincide con el comprobante autorizado '
                           . '(enviado: ' . number_format($montoEnviado, 2)
                           . ', autorizado: ' . number_format($importeAutorizado, 2) . ').',
            ]];
        }

        if ($idMonedaAutorizado !== $idMonedaEnviado) {
            $monEnviada = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $idMonedaEnviado]);
            $monReal    = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $idMonedaAutorizado]);
            return ['error' => [
                'success' => false,
                'message' => "La moneda no coincide con el comprobante autorizado "
                           . "(enviada: {$monEnviada}, autorizada: {$monReal}).",
            ]];
        }

        return [
            'importe' => $importeAutorizado,
            'idMoneda' => $idMonedaAutorizado,
            'idasiento' => (int) $asiento['data']['idasiento'],
            'codsubcuenta' => (string) $asiento['data']['codsubcuenta'],
        ];
    }

    private function getCotizacionOficialVenta(): float
    {
        return (float) DB::scalar("
            SELECT valor FROM cotizacion_oficial
            WHERE id_moneda = 1 AND id_operacion = 2
              AND DATE(fec_crea_cotizacion_oficial) <= CURDATE()
            ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1
        ") ?: 1.0;
    }

    // ── helpers ──

    private function validarCajaAbierta(int $idCaja, int $codUsuario): array
    {
        $caja = DB::selectOne(
            "SELECT * FROM caja WHERE id_caja = :id AND cod_usuario = :u AND estado = 'A' AND id_tipo_unidad = 2",
            [':id' => $idCaja, ':u' => $codUsuario]
        );
        if (!$caja) {
            return ['success' => false, 'message' => 'Caja no encontrada o no pertenece al usuario'];
        }

        $fecha = date('Y-m-d');
        $apertura = DB::selectOne(
            "SELECT 1 FROM saldo_caja WHERE id_caja = :id AND conciliacion = 'A' AND DATE(fec_crea_saldo) = :f LIMIT 1",
            [':id' => $idCaja, ':f' => $fecha]
        );
        if (!$apertura) {
            return ['success' => false, 'message' => 'La caja no está abierta hoy'];
        }

        $cierre = DB::selectOne(
            "SELECT 1 FROM saldo_caja WHERE id_caja = :id AND conciliacion = 'C' AND DATE(fec_crea_saldo) = :f LIMIT 1",
            [':id' => $idCaja, ':f' => $fecha]
        );
        if ($cierre) {
            return ['success' => false, 'message' => 'La caja ya fue cerrada hoy'];
        }

        return ['success' => true, 'caja' => $caja];
    }

    private function getBoveda(int $idAgencia, int $idMoneda): ?array
    {
        return DB::selectOne(
            "SELECT * FROM boveda WHERE id_agencia = :ag AND id_moneda = :mon AND estado = 'A'",
            [':ag' => $idAgencia, ':mon' => $idMoneda]
        );
    }

    /**
     * Suma el detalle de billetes (cortes) usando el catálogo de denominaciones:
     * cada fila aporta valor × multiplicador (de la agrupación) × cantidad.
     * Cada corte recibido: {id_corte, id_agrupacion, id_tipo_corte, cantidad}.
     */
    private function sumaCortes(array $cortes, int $idMoneda): float
    {
        if (!$cortes) return 0.0;
        $cat = $this->query->denominaciones($idMoneda);
        $valorUnidad = [];
        foreach ($cat as $r) {
            $valorUnidad[$r['id_corte'] . '-' . $r['id_agrupacion']] = (float) $r['valor'] * (float) $r['multiplicador'];
        }
        $total = 0.0;
        foreach ($cortes as $d) {
            $k = (int) ($d['id_corte'] ?? 0) . '-' . (int) ($d['id_agrupacion'] ?? 0);
            $total += ($valorUnidad[$k] ?? 0) * (int) ($d['cantidad'] ?? 0);
        }
        return round($total, 2);
    }

    /** Error si el detalle de billetes no cuadra con el monto (solo si se envió). */
    private function errorCortes(array $cortes, int $idMoneda, float $monto): ?string
    {
        if (!$cortes) return null;
        if (abs($this->sumaCortes($cortes, $idMoneda) - $monto) > 0.01) {
            return 'El detalle de billetes no coincide con el monto.';
        }
        return null;
    }

    /** Guarda el detalle de billetes (positional, como el legacy). */
    private function guardarCortes(string $tabla, int $idHeader, array $cortes): void
    {
        foreach ($cortes as $d) {
            $cant = (int) ($d['cantidad'] ?? 0);
            if ($cant <= 0) continue;
            DB::execute(
                "INSERT INTO {$tabla} VALUES (:h, :corte, :agr, :tipo, :cant, NOW())",
                [':h' => $idHeader, ':corte' => (int) $d['id_corte'], ':agr' => (int) $d['id_agrupacion'], ':tipo' => (int) $d['id_tipo_corte'], ':cant' => $cant]
            );
        }
    }

    /**
     * Saldo acumulado de una bóveda hasta la fecha inclusive.
     * Excluye las filas de control 'A'/'C' (ver `CajeroBalanceService`).
     */
    private function saldoBoveda(int $idBoveda, string $fecha): float
    {
        return $this->balance->saldoBoveda($idBoveda, $fecha);
    }

    private function registrarLog(string $uuid, string $tipo, ?int $idCaja, ?int $idBoveda, int $codUsuario, int $idAgencia, ?int $idMoneda, ?float $monto, string $ip, array $extraMeta = []): void
    {
        $metadata = !empty($extraMeta) ? json_encode($extraMeta, JSON_UNESCAPED_UNICODE) : null;

        DB::execute(
            "INSERT INTO pos_operacion_log (uuid, tipo_operacion, id_caja, id_boveda, cod_usuario, id_agencia, id_moneda, monto_total, ip_origen, metadata)
             VALUES (:uuid, :tipo, :caja, :bov, :usr, :ag, :mon, :monto, :ip, :meta)",
            [':uuid' => $uuid, ':tipo' => $tipo, ':caja' => $idCaja, ':bov' => $idBoveda, ':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $idMoneda, ':monto' => $monto, ':ip' => $ip, ':meta' => $metadata]
        );
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
