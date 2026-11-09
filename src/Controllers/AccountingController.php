<?php
/**
 * AccountingController — Reportes de contabilidad/arqueos de caja.
 *
 * Endpoints:
 * - GET /api/accounting/daily-summary
 * - GET /api/accounting/cashier-details
 * - GET /api/accounting/movement-summary
 * - GET /api/accounting/agency-closings
 */

namespace CgrApi\Controllers;

use CgrApi\Backend;
use CgrApi\Validator;
use CgrApi\Http\ApiResponse;

class AccountingController
{
    private $backend;
    public function __construct(Backend $backend) { $this->backend = $backend; }

    private static function fecha($q)
    {
        $v = isset($q['fecha']) ? trim($q['fecha']) : date('Y-m-d');
        if (!Validator::isDate($v)) {
            throw new \RuntimeException("__validation__Parámetro 'fecha' inválido (use AAAA-MM-DD).");
        }
        return $v;
    }

    /** GET /api/accounting/daily-summary — Total diario (cajas + bóveda). */
    public function dailySummary($params, $q)
    {
        $fecha = self::fecha($q);
        $result = $this->backend->accountingDailySummary($fecha);
        ApiResponse::ok(array('fecha' => $fecha, 'report' => $result));
    }

    /** GET /api/accounting/cashier-details — Arqueos por cajero/caja. */
    public function cashierDetails($params, $q)
    {
        $fecha = self::fecha($q);
        $agencia = isset($q['agencia']) ? (int)$q['agencia'] : null;
        $result = $this->backend->accountingCashierDetails($fecha, $agencia);
        ApiResponse::ok(array('fecha' => $fecha, 'agencia' => $agencia, 'report' => $result));
    }

    /** GET /api/accounting/movement-summary — Resumen de movimientos por moneda. */
    public function movementSummary($params, $q)
    {
        $fecha = self::fecha($q);
        $result = $this->backend->accountingMovementSummary($fecha);
        ApiResponse::ok(array('fecha' => $fecha, 'report' => $result));
    }

    /** GET /api/accounting/agency-closings — Cierres por agencia × moneda. */
    public function agencyClosings($params, $q)
    {
        $fecha = self::fecha($q);
        $result = $this->backend->accountingAgencyClosings($fecha);
        ApiResponse::ok(array('fecha' => $fecha, 'report' => $result));
    }

    /** GET /api/accounting/agencies — Lista de agencias. */
    public function agencies($params, $q)
    {
        $result = $this->backend->accountingAgencies();
        ApiResponse::ok($result);
    }

    // ── Helpers para reportes contables ─────────────────────────────

    private static function ejercicio($q)
    {
        $v = isset($q['ejercicio']) ? trim($q['ejercicio']) : date('Y');
        if (!preg_match('/^\d{4}$/', $v)) {
            throw new \RuntimeException("__validation__Parámetro 'ejercicio' inválido.");
        }
        return $v;
    }

    private static function fechaRango($q)
    {
        $ej = self::ejercicio($q);
        $desde = isset($q['desde']) ? trim($q['desde']) : "$ej-01-01";
        $hasta = isset($q['hasta']) ? trim($q['hasta']) : date('Y-m-d');
        if (!Validator::isDate($desde) || !Validator::isDate($hasta)) {
            throw new \RuntimeException("__validation__Parámetros 'desde'/'hasta' inválidos (use AAAA-MM-DD).");
        }
        return array($ej, $desde, $hasta);
    }

    /** GET /api/accounting/ejercicios */
    public function ejercicios($params, $q)
    {
        $result = $this->backend->accountingEjercicios();
        ApiResponse::ok($result);
    }

    /** GET /api/accounting/subcuentas?ejercicio=2026 */
    public function subcuentas($params, $q)
    {
        $ej = self::ejercicio($q);
        $result = $this->backend->accountingSubcuentas($ej);
        ApiResponse::ok($result);
    }

    /** GET /api/accounting/balance?ejercicio=2026&desde=2026-01-01&hasta=2026-12-31 */
    public function balance($params, $q)
    {
        list($ej, $desde, $hasta) = self::fechaRango($q);
        $result = $this->backend->accountingBalance($ej, $desde, $hasta);
        ApiResponse::ok(array('report' => $result));
    }

    /** GET /api/accounting/libro-mayor?codsubcuenta=11301001&ejercicio=2026&desde=2026-01-01&hasta=2026-12-31&page=1 */
    public function libroMayor($params, $q)
    {
        $cod = isset($q['codsubcuenta']) ? trim($q['codsubcuenta']) : '';
        if ($cod === '') {
            throw new \RuntimeException("__validation__Parámetro 'codsubcuenta' es requerido.");
        }
        list($ej, $desde, $hasta) = self::fechaRango($q);
        $page = isset($q['page']) ? max(1, (int)$q['page']) : 1;
        $result = $this->backend->accountingLibroMayor($cod, $ej, $desde, $hasta, $page);
        ApiResponse::ok(array('report' => $result));
    }

    /** GET /api/accounting/asiento-detalle?id=12345 */
    public function asientoDetalle($params, $q)
    {
        $id = isset($q['id']) ? (int)$q['id'] : 0;
        if ($id <= 0) {
            throw new \RuntimeException("__validation__Parámetro 'id' es requerido.");
        }
        $result = $this->backend->accountingAsientoDetalle($id);
        if ($result === null) {
            throw new \RuntimeException("__validation__Asiento no encontrado.");
        }
        ApiResponse::ok($result);
    }

    /**
     * GET /api/accounting/export?report=daily|cashier|movement|agency&fecha=YYYY-MM-DD&format=xlsx|pdf
     */
    public function export($params, $q)
    {
        $fecha  = self::fecha($q);
        $report = isset($q['report']) ? $q['report'] : '';
        $format = isset($q['format']) ? $q['format'] : 'xlsx';
        $agencia = isset($q['agencia']) ? (int)$q['agencia'] : null;

        $validReports = array('daily', 'cashier', 'movement', 'agency');
        if (!in_array($report, $validReports)) {
            throw new \RuntimeException("__validation__Parámetro 'report' inválido. Use: " . implode(', ', $validReports));
        }
        if ($format !== 'xlsx' && $format !== 'pdf') {
            throw new \RuntimeException("__validation__Parámetro 'format' inválido. Use: xlsx o pdf.");
        }

        switch ($report) {
            case 'daily':
                $data = $this->backend->accountingDailySummary($fecha);
                $bin  = ($format === 'xlsx')
                    ? \Contabilidad\Exporter::dailySummaryXlsx($data)
                    : \Contabilidad\Exporter::dailySummaryPdf($data);
                $name = 'Resumen_Diario_' . $fecha;
                break;
            case 'cashier':
                $data = $this->backend->accountingCashierDetails($fecha, $agencia);
                $bin  = ($format === 'xlsx')
                    ? \Contabilidad\Exporter::cashierDetailsXlsx($data)
                    : \Contabilidad\Exporter::cashierDetailsPdf($data);
                $name = 'Arqueos_Cajero_' . $fecha;
                break;
            case 'movement':
                $data = $this->backend->accountingMovementSummary($fecha);
                $bin  = ($format === 'xlsx')
                    ? \Contabilidad\Exporter::movementSummaryXlsx($data)
                    : \Contabilidad\Exporter::movementSummaryPdf($data);
                $name = 'Resumen_Movimientos_' . $fecha;
                break;
            case 'agency':
                $data = $this->backend->accountingAgencyClosings($fecha);
                $bin  = ($format === 'xlsx')
                    ? \Contabilidad\Exporter::agencyClosingsXlsx($data)
                    : \Contabilidad\Exporter::agencyClosingsPdf($data);
                $name = 'Cierres_Agencia_' . $fecha;
                break;
        }

        if ($format === 'xlsx') {
            ApiResponse::binary($bin,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $name . '.xlsx');
        } else {
            ApiResponse::binary($bin, 'application/pdf', $name . '.pdf');
        }
    }
}
