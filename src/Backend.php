<?php
/**
 * Backend — ÚNICO punto de reutilización de la lógica existente.
 *
 * Carga las clases legacy (src/legacy/) y las expone a los controladores.
 * La API NO reimplementa lógica de negocio: toda consulta, cálculo y
 * generación de documentos vive en los módulos legacy, que siguen siendo
 * la única fuente de verdad.
 */

namespace CgrApi;

class Backend
{
    /** @var string ruta absoluta a la carpeta legacy */
    private $root;

    public function __construct()
    {
        $this->root = __DIR__ . '/legacy';
        $this->boot();
    }

    private function reportes($sub) { return $this->root . '/' . $sub; }

    private function boot()
    {
        // Riesgos (Panel + Dashboard) — cargan a su vez Db/Fmt y los motores.
        require_once $this->reportes('riesgo_cambiario/lib/Panel.php');
        require_once $this->reportes('riesgo_cambiario/lib/Dashboard.php');
        // Logs / bitácoras (auditoría) — Reportes carga Db/Xlsx/Zip.
        require_once $this->reportes('auditoria/lib/Reportes.php');
        // Contabilidad — Arqueos de caja y reportes.
        require_once $this->reportes('contabilidad/lib/Service.php');
        require_once $this->reportes('contabilidad/lib/Exporter.php');
        // Reportes contables (Balance, Libro Mayor, etc.)
        require_once $this->reportes('contabilidad/lib/AccountingReportsService.php');
        // Bancos — Movimientos, conciliaciones y saldos bancarios.
        require_once $this->reportes('contabilidad/lib/BankService.php');
        // Reportes de operaciones — Cambios, Giros, Remesas.
        require_once $this->reportes('contabilidad/lib/OperationsReportService.php');
        // Fija zona horaria una sola vez (usa la config del propio proyecto).
        \RiesgoCambiario\Db::config();
        \Contabilidad\Db::config();
    }

    // ------------------------------------------------------------------ //
    //  RIESGOS  (reutiliza Panel / Dashboard)                            //
    // ------------------------------------------------------------------ //

    /** Catálogo de los informes de riesgo (slug, título, icono). */
    public function risksCatalog()
    {
        $out = array();
        foreach (\RiesgoCambiario\Panel::risks() as $slug => $r) {
            $out[] = array('slug' => $slug, 'title' => $r['title'], 'icon' => $r['icon']);
        }
        return $out;
    }

    public function riskSlugs()
    {
        return array_keys(\RiesgoCambiario\Panel::risks());
    }

    /** Datos estructurados de un informe (idénticos a los del sistema PHP). */
    public function riskData($slug, $ini, $fin)
    {
        $res = \RiesgoCambiario\Panel::generar($slug, $ini, $fin);
        return $res['data'];
    }

    /** PDF de un informe (mismo mPDF que descargar.php). Devuelve binario. */
    public function riskPdf($slug, $ini, $fin)
    {
        return \RiesgoCambiario\Panel::pdf($slug, $ini, $fin);
    }

    public function riskPdfName($slug, $ini, $fin)
    {
        return \RiesgoCambiario\Panel::pdfFilename($slug, $ini, $fin);
    }

    /** Resumen consolidado del tablero (con caché, igual que dashboard.php). */
    public function dashboard($ini, $fin, $forzar = false)
    {
        return \RiesgoCambiario\Dashboard::resumenCache($ini, $fin, $forzar);
    }

    // ------------------------------------------------------------------ //
    //  LOGS / BITÁCORAS  (reutiliza Auditoria\Reportes / Zip)            //
    // ------------------------------------------------------------------ //

    /** Catálogo de los 7 reportes de auditoría. */
    public function logsCatalog()
    {
        $out = array();
        foreach (\Auditoria\Reportes::catalog() as $key => $c) {
            $out[] = array('code' => $c['code'], 'title' => $c['title'], 'db' => $c['db']);
        }
        return $out;
    }

    public function logCodes()
    {
        return array_keys(\Auditoria\Reportes::catalog());
    }

    /** Datos de un reporte de auditoría (hojas/columnas/filas), igual que datos.php. */
    public function logData($code, $periodo)
    {
        return \Auditoria\Reportes::data($code, $periodo);
    }

    /** Excel .xlsx de un reporte (mismo formato que descargar.php). Devuelve binario. */
    public function logExcel($code, $periodo)
    {
        $xlsx = \Auditoria\Reportes::build($code, $periodo);
        return $xlsx->toString();
    }

    public function logExcelName($code, $periodo)
    {
        return \Auditoria\Reportes::fileName($code, $periodo);
    }

    /** ZIP mensual con los 7 reportes (misma lógica que descargar.php?reporte=todos). */
    public function logsZip($periodo)
    {
        $files = array();
        foreach (\Auditoria\Reportes::catalog() as $key => $meta) {
            $xlsx = \Auditoria\Reportes::build($key, $periodo);
            $files[\Auditoria\Reportes::fileName($key, $periodo)] = $xlsx->toString();
        }
        return \Auditoria\Zip::build($files);
    }

    public function logsZipName($periodo)
    {
        return 'REPORTES_AUDITORIA_' . str_replace('-', '_', $periodo) . '.zip';
    }

    // ------------------------------------------------------------------ //
    //  OPERACIONES  (reutiliza api_dashboard.php por include-and-capture)//
    // ------------------------------------------------------------------ //

    /**
     * Ejecuta una acción del dashboard operativo existente (api_dashboard.php)
     * sin duplicar su lógica: fija los parámetros, captura su salida JSON y la
     * decodifica. api_dashboard.php es la única fuente de esta lógica.
     */
    public function operations($accion, $desde, $hasta)
    {
        $script = $this->reportes('api_dashboard.php');
        if (!is_file($script)) {
            throw new \RuntimeException('No se encontró api_dashboard.php.');
        }
        $prevGet = $_GET;
        $_GET = array('accion' => $accion, 'desde' => $desde, 'hasta' => $hasta);
        ob_start();
        include $script;                 // echoea JSON (no hace exit en el flujo normal)
        $raw = ob_get_clean();
        $_GET = $prevGet;

        $json = json_decode($raw, true);
        if ($json === null && trim($raw) !== 'null') {
            throw new \RuntimeException('Respuesta no válida del dashboard operativo.');
        }
        return $json;
    }

    /** Acciones válidas del dashboard operativo (según api_dashboard.php). */
    public function operationActions()
    {
        return array('operativo', 'utilidades', 'agencias', 'live', 'cajeros', 'precios',
                     'cotizaciones', 'plazas', 'saldos', 'horas', 'margen', 'tendencias', 'comparativo');
    }

    // ------------------------------------------------------------------ //
    //  CONTABILIDAD  (arqueos de caja y reportes)                        //
    // ------------------------------------------------------------------ //

    /** Resumen diario: totales de cajas + bóveda. */
    public function accountingDailySummary($fecha)
    {
        return \Contabilidad\Service::getDailySummary($fecha);
    }

    /** Arqueos completos por cajero/caja. */
    public function accountingCashierDetails($fecha, $agencia = null)
    {
        return \Contabilidad\Service::getReconciliation($fecha, $agencia);
    }

    /** Resumen consolidado de movimientos por moneda. */
    public function accountingMovementSummary($fecha)
    {
        return \Contabilidad\Service::getMovementSummary($fecha);
    }

    /** Cierres de agencias (matriz agencia × moneda). */
    public function accountingAgencyClosings($fecha)
    {
        return \Contabilidad\Service::getAgencyClosings($fecha);
    }

    /** Lista de agencias activas. */
    public function accountingAgencies()
    {
        return \Contabilidad\Service::getAgencies();
    }

    // ------------------------------------------------------------------ //
    //  REPORTES CONTABLES  (Balance, Libro Mayor, etc.)                   //
    // ------------------------------------------------------------------ //

    public function accountingEjercicios()
    {
        return \Contabilidad\AccountingReportsService::getEjercicios();
    }

    public function accountingSubcuentas($ejercicio)
    {
        return \Contabilidad\AccountingReportsService::getSubcuentas($ejercicio);
    }

    public function accountingBalance($ejercicio, $fechaDesde, $fechaHasta)
    {
        return \Contabilidad\AccountingReportsService::getBalanceSumasYSaldos($ejercicio, $fechaDesde, $fechaHasta);
    }

    public function accountingLibroMayor($codsubcuenta, $ejercicio, $fechaDesde, $fechaHasta, $page = 1, $pageSize = 500)
    {
        return \Contabilidad\AccountingReportsService::getLibroMayor($codsubcuenta, $ejercicio, $fechaDesde, $fechaHasta, $page, $pageSize);
    }

    public function accountingAsientoDetalle($idasiento)
    {
        return \Contabilidad\AccountingReportsService::getAsientoDetalle($idasiento);
    }

    // ------------------------------------------------------------------ //
    //  BANCOS  (movimientos, conciliaciones y saldos bancarios)           //
    // ------------------------------------------------------------------ //

    public function bankingEntities()
    {
        return \Contabilidad\BankService::getFinancialEntities();
    }

    public function bankingAccounts($idAgencia = null)
    {
        return \Contabilidad\BankService::getBankAccounts($idAgencia);
    }

    public function bankingMovements($desde, $hasta = null, $idAgencia = null,
                                     $idMoneda = null, $numCuenta = null, $conciliacion = null)
    {
        return \Contabilidad\BankService::getBankMovements($desde, $hasta, $idAgencia, $idMoneda, $numCuenta, $conciliacion);
    }

    public function bankingConciliacionContable($fecha, $idAgencia = null)
    {
        return \Contabilidad\BankService::getConciliacionContable($fecha, $idAgencia);
    }

    public function bankingDailySummary($fecha)
    {
        return \Contabilidad\BankService::getBankDailySummary($fecha);
    }

    public function bankingTransfers($desde, $hasta = null, $estado = null)
    {
        return \Contabilidad\BankService::getBankTransfers($desde, $hasta, $estado);
    }

    public function bankingConciliacionDetalle($fecha, $idAgencia, $idEntidad, $numCuenta, $idMoneda)
    {
        return \Contabilidad\BankService::getConciliacionDetalle(
            $fecha, $idAgencia, $idEntidad, $numCuenta, $idMoneda
        );
    }

    public function bankingMovementDetail($idAgencia, $idEntidad, $numCuenta, $idMoneda,
                                           $fecha, $conciliacion, $monto)
    {
        return \Contabilidad\BankService::getMovementDetail(
            $idAgencia, $idEntidad, $numCuenta, $idMoneda, $fecha, $conciliacion, $monto
        );
    }
}
