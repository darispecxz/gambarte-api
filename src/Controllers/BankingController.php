<?php
/**
 * BankingController — Reportes de Bancos / Cuentas Bancarias.
 *
 * Endpoints:
 * - GET /api/banking/entities
 * - GET /api/banking/accounts
 * - GET /api/banking/movements
 * - GET /api/banking/conciliacion
 * - GET /api/banking/daily-summary
 * - GET /api/banking/transfers
 */

namespace CgrApi\Controllers;

use CgrApi\Backend;
use CgrApi\Validator;
use CgrApi\Http\ApiResponse;

class BankingController
{
    private $backend;
    public function __construct(Backend $backend) { $this->backend = $backend; }

    private static function fecha($q, $key = 'fecha', $default = true)
    {
        $v = isset($q[$key]) ? trim($q[$key]) : ($default ? date('Y-m-d') : null);
        if ($v !== null && !Validator::isDate($v)) {
            throw new \RuntimeException("__validation__Parámetro '{$key}' inválido (use AAAA-MM-DD).");
        }
        return $v;
    }

    private static function optionalInt($q, $key)
    {
        return isset($q[$key]) && $q[$key] !== '' ? (int)$q[$key] : null;
    }

    private static function optionalStr($q, $key)
    {
        return isset($q[$key]) && $q[$key] !== '' ? trim($q[$key]) : null;
    }

    /** GET /api/banking/entities */
    public function entities($params, $q)
    {
        ApiResponse::ok($this->backend->bankingEntities());
    }

    /** GET /api/banking/accounts */
    public function accounts($params, $q)
    {
        $agencia = self::optionalInt($q, 'agencia');
        ApiResponse::ok($this->backend->bankingAccounts($agencia));
    }

    /** GET /api/banking/movements */
    public function movements($params, $q)
    {
        $desde = self::fecha($q, 'desde');
        $hasta = self::fecha($q, 'hasta', false);
        $agencia = self::optionalInt($q, 'agencia');
        $moneda = self::optionalInt($q, 'moneda');
        $cuenta = self::optionalStr($q, 'cuenta');
        $conc = self::optionalStr($q, 'conciliacion');

        $result = $this->backend->bankingMovements($desde, $hasta, $agencia, $moneda, $cuenta, $conc);
        ApiResponse::ok(array(
            'desde' => $desde,
            'hasta' => $hasta ?: $desde,
            'total' => count($result),
            'movements' => $result,
        ));
    }

    /**
     * GET /api/banking/conciliacion — Conciliación contable OBEL vs Balance de Sumas y Saldos.
     *
     * Compara para cada cuenta bancaria:
     *   - Saldo OBEL (SUM movimiento_cuenta_bancaria)
     *   - Saldo contable (SUM co_partidas.debe - co_partidas.haber)
     * Identifica diferencias y sus posibles causas.
     */
    public function conciliacion($params, $q)
    {
        $fecha = self::fecha($q);
        $agencia = self::optionalInt($q, 'agencia');
        $result = $this->backend->bankingConciliacionContable($fecha, $agencia);
        ApiResponse::ok($result);
    }

    /** GET /api/banking/daily-summary */
    public function dailySummary($params, $q)
    {
        $fecha = self::fecha($q);
        $result = $this->backend->bankingDailySummary($fecha);
        ApiResponse::ok(array('fecha' => $fecha, 'report' => $result));
    }

    /** GET /api/banking/conciliacion-detalle — análisis de discrepancia por cuenta */
    public function conciliacionDetalle($params, $q)
    {
        $fecha   = self::fecha($q);
        $agencia = self::optionalInt($q, 'agencia');
        $entidad = self::optionalInt($q, 'entidad');
        $cuenta  = self::optionalStr($q, 'cuenta');
        $moneda  = self::optionalInt($q, 'moneda');

        if ($agencia === null || $entidad === null || $cuenta === null || $moneda === null) {
            throw new \RuntimeException('__validation__Faltan parámetros requeridos (agencia, entidad, cuenta, moneda).');
        }

        $result = $this->backend->bankingConciliacionDetalle($fecha, $agencia, $entidad, $cuenta, $moneda);
        ApiResponse::ok($result);
    }

    /** GET /api/banking/movement-detail */
    public function movementDetail($params, $q)
    {
        $agencia  = self::optionalInt($q, 'agencia');
        $entidad  = self::optionalInt($q, 'entidad');
        $cuenta   = self::optionalStr($q, 'cuenta');
        $moneda   = self::optionalInt($q, 'moneda');
        $fecha    = self::optionalStr($q, 'fecha');
        $conc     = self::optionalStr($q, 'conciliacion');
        $monto    = isset($q['monto']) && $q['monto'] !== '' ? (float)$q['monto'] : null;

        if ($agencia === null || $entidad === null || $cuenta === null ||
            $moneda === null || $fecha === null || $conc === null || $monto === null) {
            throw new \RuntimeException('__validation__Faltan parámetros requeridos (agencia, entidad, cuenta, moneda, fecha, conciliacion, monto).');
        }

        $result = $this->backend->bankingMovementDetail(
            $agencia, $entidad, $cuenta, $moneda, $fecha, $conc, $monto
        );

        if ($result === null) {
            throw new \RuntimeException('Movimiento no encontrado.');
        }

        ApiResponse::ok($result);
    }

    /** GET /api/banking/transfers */
    public function transfers($params, $q)
    {
        $desde = self::fecha($q, 'desde');
        $hasta = self::fecha($q, 'hasta', false);
        $estado = self::optionalStr($q, 'estado');

        $result = $this->backend->bankingTransfers($desde, $hasta, $estado);
        ApiResponse::ok(array(
            'desde' => $desde,
            'hasta' => $hasta ?: $desde,
            'total' => count($result),
            'transfers' => $result,
        ));
    }
}
