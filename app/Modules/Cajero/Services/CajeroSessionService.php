<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;

class CajeroSessionService
{
    private CajeroQueryService $query;

    public function __construct(CajeroQueryService $query)
    {
        $this->query = $query;
    }

    public function apertura(int $codUsuario, int $idAgencia, int $idCaja, array $montos, string $ip): array
    {
        $fecha = date('Y-m-d');

        $caja = DB::selectOne(
            "SELECT * FROM caja WHERE id_caja = :id AND cod_usuario = :u AND estado = 'A' AND id_tipo_unidad = 2",
            [':id' => $idCaja, ':u' => $codUsuario]
        );
        if (!$caja) {
            return ['success' => false, 'message' => 'Caja no encontrada o no pertenece al usuario'];
        }

        $yaAbierta = DB::selectOne(
            "SELECT 1 FROM saldo_caja WHERE id_caja = :id AND conciliacion = 'A' AND DATE(fec_crea_saldo) = :f LIMIT 1",
            [':id' => $idCaja, ':f' => $fecha]
        );
        if ($yaAbierta) {
            return ['success' => false, 'message' => 'La caja ya fue abierta hoy'];
        }

        $saldoApertura = $this->calcularTotalMontos($montos);

        if ($saldoApertura > (float) $caja['maximo']) {
            return ['success' => false, 'message' => "El monto de apertura ($saldoApertura) excede el máximo permitido ({$caja['maximo']})"];
        }
        if ($saldoApertura > 0 && $saldoApertura < (float) $caja['minimo']) {
            return ['success' => false, 'message' => "El monto de apertura ($saldoApertura) es menor al mínimo permitido ({$caja['minimo']})"];
        }

        $boveda = DB::selectOne(
            "SELECT * FROM boveda WHERE id_agencia = :ag AND id_moneda = :mon",
            [':ag' => $caja['id_agencia'], ':mon' => $caja['id_moneda']]
        );

        if ($boveda && $saldoApertura > 0) {
            $saldoBoveda = (float) DB::scalar(
                "SELECT COALESCE(SUM(saldo), 0)
                 FROM saldo_boveda
                 WHERE id_boveda = :id AND DATE(fec_crea_saldo) <= :f",
                [':id' => $boveda['id_boveda'], ':f' => $fecha]
            );
            $disponibleBoveda = $saldoBoveda - (float) $boveda['minimo'];
            if ($saldoApertura > $disponibleBoveda) {
                return ['success' => false, 'message' => "Fondos insuficientes en bóveda (disponible: $disponibleBoveda)"];
            }
        }

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCaja, $caja, $boveda, $saldoApertura, $montos, $fecha, $idAgencia, $codUsuario, $ip, $uuid) {
            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                 VALUES (:id, :saldo, NOW(), 'A')",
                [':id' => $idCaja, ':saldo' => $saldoApertura]
            );

            if ($boveda && $saldoApertura > 0) {
                DB::execute(
                    "INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion)
                     VALUES (:id, :saldo, NOW(), :conc)",
                    [':id' => $boveda['id_boveda'], ':saldo' => -$saldoApertura, ':conc' => "SAC-{$idCaja}"]
                );
            }

            $this->insertarArqueo($montos, $idAgencia, $caja['num_caja'], 1);

            $this->insertarSesionCaja($idCaja, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $fecha, $saldoApertura, $ip);

            $this->registrarLog($uuid, 'apertura_caja', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $saldoApertura, $ip);

            return [
                'success' => true,
                'message' => 'Caja abierta correctamente',
                'data'    => [
                    'id_caja'        => $idCaja,
                    'saldo_apertura' => $saldoApertura,
                    'fecha'          => $fecha,
                    'uuid'           => $uuid,
                ],
            ];
        });
    }

    public function cierre(int $codUsuario, int $idAgencia, int $idCaja, array $montos, string $ip): array
    {
        $fecha = date('Y-m-d');

        $caja = DB::selectOne(
            "SELECT * FROM caja WHERE id_caja = :id AND cod_usuario = :u AND estado = 'A' AND id_tipo_unidad = 2",
            [':id' => $idCaja, ':u' => $codUsuario]
        );
        if (!$caja) {
            return ['success' => false, 'message' => 'Caja no encontrada o no pertenece al usuario'];
        }

        $apertura = DB::selectOne(
            "SELECT 1 FROM saldo_caja WHERE id_caja = :id AND conciliacion = 'A' AND DATE(fec_crea_saldo) = :f LIMIT 1",
            [':id' => $idCaja, ':f' => $fecha]
        );
        if (!$apertura) {
            return ['success' => false, 'message' => 'La caja no fue abierta hoy'];
        }

        $yaCerrada = DB::selectOne(
            "SELECT 1 FROM saldo_caja WHERE id_caja = :id AND conciliacion = 'C' AND DATE(fec_crea_saldo) = :f LIMIT 1",
            [':id' => $idCaja, ':f' => $fecha]
        );
        if ($yaCerrada) {
            return ['success' => false, 'message' => 'La caja ya fue cerrada hoy'];
        }

        $saldoCierre = $this->calcularTotalMontos($montos);

        $saldoEsperado = $this->query->saldoActual($idCaja, $fecha);
        $diferencia = round($saldoCierre - $saldoEsperado, 4);

        $boveda = DB::selectOne(
            "SELECT * FROM boveda WHERE id_agencia = :ag AND id_moneda = :mon",
            [':ag' => $caja['id_agencia'], ':mon' => $caja['id_moneda']]
        );

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCaja, $caja, $boveda, $saldoCierre, $saldoEsperado, $diferencia, $montos, $fecha, $idAgencia, $codUsuario, $ip, $uuid) {
            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                 VALUES (:id, :saldo, NOW(), 'C')",
                [':id' => $idCaja, ':saldo' => $saldoCierre]
            );

            if ($boveda && $saldoCierre > 0) {
                DB::execute(
                    "INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion)
                     VALUES (:id, :saldo, NOW(), :conc)",
                    [':id' => $boveda['id_boveda'], ':saldo' => $saldoCierre, ':conc' => "ECC-{$idCaja}"]
                );
            }

            $this->insertarArqueo($montos, $idAgencia, $caja['num_caja'], 2);

            $this->cerrarSesionCaja($idCaja, $fecha, $saldoCierre, $saldoEsperado, $diferencia, $ip);

            $this->registrarLog($uuid, 'cierre_caja', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $saldoCierre, $ip);

            return [
                'success' => true,
                'message' => 'Caja cerrada correctamente',
                'data'    => [
                    'id_caja'        => $idCaja,
                    'saldo_cierre'   => $saldoCierre,
                    'saldo_esperado' => $saldoEsperado,
                    'diferencia'     => $diferencia,
                    'fecha'          => $fecha,
                    'uuid'           => $uuid,
                ],
            ];
        });
    }

    public function cierreConApertura(int $codUsuario, int $idAgencia, int $idCaja, array $montos, string $ip): array
    {
        $fecha = date('Y-m-d');

        $caja = DB::selectOne(
            "SELECT * FROM caja WHERE id_caja = :id AND cod_usuario = :u AND estado = 'A' AND id_tipo_unidad = 2",
            [':id' => $idCaja, ':u' => $codUsuario]
        );
        if (!$caja) {
            return ['success' => false, 'message' => 'Caja no encontrada o no pertenece al usuario'];
        }

        $yaAbierta = DB::selectOne(
            "SELECT 1 FROM saldo_caja WHERE id_caja = :id AND conciliacion = 'A' AND DATE(fec_crea_saldo) = :f LIMIT 1",
            [':id' => $idCaja, ':f' => $fecha]
        );
        if ($yaAbierta) {
            return ['success' => false, 'message' => 'La caja ya fue abierta hoy — use cierre normal'];
        }

        $saldoCierre = $this->calcularTotalMontos($montos);

        $boveda = DB::selectOne(
            "SELECT * FROM boveda WHERE id_agencia = :ag AND id_moneda = :mon",
            [':ag' => $caja['id_agencia'], ':mon' => $caja['id_moneda']]
        );

        $uuid = $this->uuid();

        return DB::transaction(function () use ($idCaja, $caja, $boveda, $saldoCierre, $montos, $fecha, $idAgencia, $codUsuario, $ip, $uuid) {
            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, 0, NOW(), 'A')",
                [':id' => $idCaja]
            );

            $this->insertarSesionCaja($idCaja, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $fecha, 0, $ip);
            $this->registrarLog($uuid, 'apertura_caja', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], 0, $ip);

            DB::execute(
                "INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), 'C')",
                [':id' => $idCaja, ':saldo' => $saldoCierre]
            );

            if ($boveda && $saldoCierre > 0) {
                DB::execute(
                    "INSERT INTO saldo_boveda (id_boveda, saldo, fec_crea_saldo, conciliacion) VALUES (:id, :saldo, NOW(), :conc)",
                    [':id' => $boveda['id_boveda'], ':saldo' => $saldoCierre, ':conc' => "ECC-{$idCaja}"]
                );
            }

            if (!empty($montos)) {
                $this->insertarArqueo($montos, $idAgencia, $caja['num_caja'], 2);
            }

            // El saldo esperado es 0 porque la caja se abre con 0 y `saldoCierre`
            // es el arqueo contado. Por eso `diferencia == saldoCierre`.
            $this->cerrarSesionCaja($idCaja, $fecha, $saldoCierre, 0, $saldoCierre, $ip);

            $uuidCierre = $this->uuid();
            $this->registrarLog($uuidCierre, 'cierre_caja', $idCaja, null, $codUsuario, $idAgencia, (int) $caja['id_moneda'], $saldoCierre, $ip);

            return [
                'success' => true,
                'message' => 'Caja abierta y cerrada correctamente',
                'data'    => [
                    'id_caja'        => $idCaja,
                    'saldo_apertura' => 0,
                    'saldo_cierre'   => $saldoCierre,
                    'diferencia'     => $saldoCierre,
                    'fecha'          => $fecha,
                    'uuid'           => $uuid,
                ],
            ];
        });
    }

    private function calcularTotalMontos(array $montos): float
    {
        $total = 0.0;
        foreach ($montos as $item) {
            $cantidad = (int) ($item['cantidad'] ?? 0);
            $valor = (float) ($item['valor'] ?? 0);
            $multiplicador = (int) ($item['multiplicador'] ?? 1);
            $total += $valor * $multiplicador * $cantidad;
        }
        return round($total, 4);
    }

    private function insertarArqueo(array $montos, int $idAgencia, $numCaja, int $tipoArqueo): void
    {
        foreach ($montos as $item) {
            $cantidad = (int) ($item['cantidad'] ?? 0);
            if ($cantidad <= 0) continue;

            DB::execute(
                "INSERT INTO arqueo_caja (id_corte, id_agrupacion, id_tipo_corte, id_agencia, num_caja, fecha, cantidad, id_tipo_arqueo, fec_crea_arqueo_caja)
                 VALUES (:corte, :agrup, :tipo_corte, :ag, :nc, NOW(), :cant, :tipo, NOW())",
                [
                    ':corte'      => $item['id_corte'],
                    ':agrup'      => $item['id_agrupacion'],
                    ':tipo_corte' => $item['id_tipo_corte'],
                    ':ag'         => $idAgencia,
                    ':nc'         => $numCaja,
                    ':cant'       => $cantidad,
                    ':tipo'       => $tipoArqueo,
                ]
            );
        }
    }

    private function insertarSesionCaja(int $idCaja, int $codUsuario, int $idAgencia, int $idMoneda, string $fecha, float $saldoApertura, string $ip): void
    {
        DB::execute(
            "INSERT INTO pos_sesion_caja (id_caja, cod_usuario, id_agencia, id_moneda, fecha, estado, saldo_apertura, hora_apertura, ip_apertura)
             VALUES (:caja, :usr, :ag, :mon, :f, 'abierta', :saldo, NOW(), :ip)",
            [':caja' => $idCaja, ':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $idMoneda, ':f' => $fecha, ':saldo' => $saldoApertura, ':ip' => $ip]
        );
    }

    private function cerrarSesionCaja(int $idCaja, string $fecha, float $saldoCierre, float $saldoEsperado, float $diferencia, string $ip): void
    {
        DB::execute(
            "UPDATE pos_sesion_caja
             SET estado = 'cerrada', saldo_cierre = :cierre, saldo_esperado = :esperado, diferencia = :dif, hora_cierre = NOW(), ip_cierre = :ip
             WHERE id_caja = :caja AND fecha = :f AND estado = 'abierta'",
            [':cierre' => $saldoCierre, ':esperado' => $saldoEsperado, ':dif' => $diferencia, ':ip' => $ip, ':caja' => $idCaja, ':f' => $fecha]
        );
    }

    private function registrarLog(string $uuid, string $tipo, ?int $idCaja, ?int $idBoveda, int $codUsuario, int $idAgencia, ?int $idMoneda, ?float $monto, string $ip): void
    {
        DB::execute(
            "INSERT INTO pos_operacion_log (uuid, tipo_operacion, id_caja, id_boveda, cod_usuario, id_agencia, id_moneda, monto_total, ip_origen)
             VALUES (:uuid, :tipo, :caja, :bov, :usr, :ag, :mon, :monto, :ip)",
            [':uuid' => $uuid, ':tipo' => $tipo, ':caja' => $idCaja, ':bov' => $idBoveda, ':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $idMoneda, ':monto' => $monto, ':ip' => $ip]
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
