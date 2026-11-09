<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;

/**
 * Operación triple CLP para cambios que involucran USD.
 *
 * Cuando USD (id_moneda=1) participa en un cambio, se registran 2 patas
 * intermedias CLP además del cambio principal USD↔BOB:
 *
 *   COMPRA USD: (1) Compra CLP→BOB  (2) Venta BOB→CLP
 *   VENTA  USD: (1) Venta  BOB→CLP  (2) Compra CLP→BOB
 *
 * Este servicio está desacoplado y puede deshabilitarse con ENABLED = false
 * o eliminarse sin afectar el flujo principal de CajeroCambioService.
 */
class TripleOperacionService
{
    const ENABLED = false;

    const CHILE_API_URL_DEFAULT = 'https://cgr.gambarte.cl';
    const ID_CLP = 3;
    const ID_BOB = 2;
    const ID_USD = 1;

    public static function isRequired(int $monRec, int $monEnt): bool
    {
        if (!self::ENABLED) return false;
        return $monRec === self::ID_USD || $monEnt === self::ID_USD;
    }

    public function registrarPatasClp(
        int    $idOperacion,
        int    $idAgencia,
        float  $totalBob,
        float  $montoUsd,
        string $fecRegOpe,
        int    $codUsuario,
        int    $idUsuarioFinanciero,
        array  $clienteIdentidad,
        array  $pcc06
    ): array {
        $tcClpUsd = $this->fetchTcClpUsd();
        if ($tcClpUsd <= 0) {
            return ['success' => false, 'error' => 'No se pudo obtener TC CLP/USD desde Chile.'];
        }

        $oficialCompra = $this->getOficialUsd(1);
        $oficialVenta  = $this->getOficialUsd(2);

        $montoClp = round($montoUsd * $tcClpUsd, 2);

        $commonParams = [
            ':tipo_doc' => $clienteIdentidad['id_tipo_doc_identidad'],
            ':doc'      => $clienteIdentidad['num_doc_identidad'],
            ':exp'      => $clienteIdentidad['id_expedicion'],
            ':ag'       => $idAgencia,
            ':fecha'    => $fecRegOpe,
            ':usr'      => $codUsuario,
            ':origen'   => $pcc06['origen'],
            ':destino'  => $pcc06['destino'],
            ':dinero_si'  => $pcc06['dinero_si'],
            ':dinero_no'  => $pcc06['dinero_no'],
            ':select_per' => $pcc06['select_per'],
            ':nombres_dd' => $pcc06['nombres_dd'],
            ':doc_nit_dd' => $pcc06['doc_nit_dd'],
            ':act_eco_dd' => $pcc06['act_eco_dd'],
            ':domicilio_dd'  => $pcc06['domicilio_dd'],
            ':id_dpto_dd'    => $pcc06['id_departamento_dd'],
            ':id_ciudad_pcc' => $pcc06['id_ciudad_pcc'],
            ':telefono_dd'   => $pcc06['telefono_dd'],
            ':zona_dd'       => $pcc06['zona_dd'],
            ':uf_id'         => $idUsuarioFinanciero,
        ];

        if ($idOperacion === 1) {
            $this->compraUsd($commonParams, $totalBob, $montoClp, $tcClpUsd, $oficialCompra);
        } else {
            $this->ventaUsd($commonParams, $totalBob, $montoClp, $tcClpUsd, $oficialVenta);
        }

        return [
            'success'    => true,
            'tc_clp_usd' => $tcClpUsd,
            'monto_clp'  => $montoClp,
        ];
    }

    private function compraUsd(array $common, float $totalBob, float $montoClp, float $tcClpUsd, float $oficialCompraUsd): void
    {
        // Pata 1: Compra CLP con BOB
        $tcCompraClp = $montoClp > 0 ? round($totalBob / $montoClp, 5) : 0;

        $this->insertCambio(array_merge($common, [
            ':op'           => 1,
            ':mon_rec'      => self::ID_CLP,
            ':mon_ent'      => self::ID_BOB,
            ':tc'           => $tcCompraClp,
            ':monto'        => $montoClp,
            ':total'        => $totalBob,
            ':recibido'     => $montoClp,
            ':entregado'    => $totalBob,
            ':cambio'       => 0,
            ':total_op'     => $totalBob,
            ':tc_bob'       => $tcCompraClp,
            ':total_op_bob' => $totalBob,
            ':cambio_bob'   => 0,
            ':imp_pcc06'    => 'N',
        ]));

        // Pata 2: Venta CLP por BOB (con TC oficial)
        $tcVentaClp = $tcClpUsd > 0 ? round($oficialCompraUsd / $tcClpUsd, 5) : 0;
        $segundaMontoBob = round($montoClp * $tcVentaClp, 2);
        $segundoTotalClp = $tcVentaClp > 0 ? round($segundaMontoBob / $tcVentaClp, 2) : 0;

        $this->insertCambio(array_merge($common, [
            ':op'           => 2,
            ':mon_rec'      => self::ID_BOB,
            ':mon_ent'      => self::ID_CLP,
            ':tc'           => $tcVentaClp,
            ':monto'        => $segundaMontoBob,
            ':total'        => $segundoTotalClp,
            ':recibido'     => $segundaMontoBob,
            ':entregado'    => $segundoTotalClp,
            ':cambio'       => 0,
            ':total_op'     => $segundoTotalClp,
            ':tc_bob'       => $tcVentaClp,
            ':total_op_bob' => $segundaMontoBob,
            ':cambio_bob'   => 0,
            ':imp_pcc06'    => 'N',
        ]));
    }

    private function ventaUsd(array $common, float $totalBob, float $montoClp, float $tcClpUsd, float $oficialVentaUsd): void
    {
        // Pata 1: Venta BOB por CLP
        $tcVentaClp = $montoClp > 0 ? round($totalBob / $montoClp, 5) : 0;

        $this->insertCambio(array_merge($common, [
            ':op'           => 2,
            ':mon_rec'      => self::ID_BOB,
            ':mon_ent'      => self::ID_CLP,
            ':tc'           => $tcVentaClp,
            ':monto'        => $totalBob,
            ':total'        => $montoClp,
            ':recibido'     => $totalBob,
            ':entregado'    => $montoClp,
            ':cambio'       => 0,
            ':total_op'     => $montoClp,
            ':tc_bob'       => $tcVentaClp,
            ':total_op_bob' => $totalBob,
            ':cambio_bob'   => 0,
            ':imp_pcc06'    => 'N',
        ]));

        // Pata 2: Compra CLP con BOB (con TC oficial)
        $tcCompraClp = $tcClpUsd > 0 ? round($oficialVentaUsd / $tcClpUsd, 5) : 0;
        $segundoTotalClp = round($montoClp * $tcCompraClp, 2);
        $segundaMontoBob = $tcCompraClp > 0 ? round($segundoTotalClp / $tcCompraClp, 2) : 0;

        $this->insertCambio(array_merge($common, [
            ':op'           => 1,
            ':mon_rec'      => self::ID_CLP,
            ':mon_ent'      => self::ID_BOB,
            ':tc'           => $tcCompraClp,
            ':monto'        => $segundaMontoBob,
            ':total'        => $segundoTotalClp,
            ':recibido'     => $segundaMontoBob,
            ':entregado'    => $segundoTotalClp,
            ':cambio'       => 0,
            ':total_op'     => $segundoTotalClp,
            ':tc_bob'       => $tcCompraClp,
            ':total_op_bob' => $segundaMontoBob,
            ':cambio_bob'   => 0,
            ':imp_pcc06'    => 'N',
        ]));
    }

    private function insertCambio(array $params): int
    {
        DB::execute("
            INSERT INTO cambio (
                id_operacion, id_tipo_doc_identidad, num_doc_identidad, id_expedicion,
                id_agencia, mon_rec, mon_ent, tipo_cambio,
                monto, total_cambio, recibido, entregado,
                cambio, total_operacion, tipo_cambio_bob, total_operacion_bob,
                cambio_bob, fec_crea_cambio, facturado, cod_usuario,
                origen, destino, dinero_si, dinero_no,
                select_per, nombres_dd, doc_nit_dd, act_eco_dd,
                domicilio_dd, id_departamento_dd, id_ciudad_pcc, telefono_dd,
                zona_dd, impresion_pcc06, usuario_financiero_id, persona_factura_id
            ) VALUES (
                :op, :tipo_doc, :doc, :exp,
                :ag, :mon_rec, :mon_ent, :tc,
                :monto, :total, :recibido, :entregado,
                :cambio, :total_op, :tc_bob, :total_op_bob,
                :cambio_bob, :fecha, 0, :usr,
                :origen, :destino, :dinero_si, :dinero_no,
                :select_per, :nombres_dd, :doc_nit_dd, :act_eco_dd,
                :domicilio_dd, :id_dpto_dd, :id_ciudad_pcc, :telefono_dd,
                :zona_dd, :imp_pcc06, :uf_id, 0
            )
        ", $params);

        return DB::lastInsertId();
    }

    private function fetchTcClpUsd(): float
    {
        $comando = base64_encode('1|1');
        $chileUrl = env('CHILE_API_URL', self::CHILE_API_URL_DEFAULT);
        $url = $chileUrl . '/ws/ws.php?t=' . $comando;

        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) return 0.0;

        $array = json_decode($json, true);
        if (!is_array($array) || empty($array)) return 0.0;

        $valor = $array[0]['valor'] ?? '';
        return (float) base64_decode($valor);
    }

    private function getOficialUsd(int $idOperacion): float
    {
        return (float) DB::scalar("
            SELECT co.valor FROM cotizacion_oficial co
            WHERE co.id_moneda = :mon AND co.id_operacion = :op
            ORDER BY co.fec_crea_cotizacion_oficial DESC LIMIT 1
        ", [':mon' => self::ID_USD, ':op' => $idOperacion]) ?: 0.0;
    }
}
