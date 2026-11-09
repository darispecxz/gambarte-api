<?php
/**
 * LogsController — reportes de logs / bitácoras (reutiliza Auditoria\Reportes / Zip).
 * Mantiene el formato de Excel y ZIP mensual del sistema actual.
 */

namespace CgrApi\Controllers;

use CgrApi\Backend;
use CgrApi\Validator;
use CgrApi\Http\ApiResponse;

class LogsController
{
    private $backend;
    public function __construct(Backend $backend) { $this->backend = $backend; }

    /** GET /api/logs — catálogo de los 7 reportes. */
    public function index($params, $q)
    {
        ApiResponse::ok($this->backend->logsCatalog());
    }

    /** GET /api/logs/{code} — datos del reporte (hojas/columnas/filas). */
    public function show($params, $q)
    {
        $code    = Validator::inList($params['code'], $this->backend->logCodes(), 'code');
        $periodo = Validator::period($q);
        ApiResponse::ok($this->backend->logData($code, $periodo));
    }

    /** GET /api/logs/{code}/excel — .xlsx del reporte. */
    public function excel($params, $q)
    {
        $code    = Validator::inList($params['code'], $this->backend->logCodes(), 'code');
        $periodo = Validator::period($q);
        $bin  = $this->backend->logExcel($code, $periodo);
        $name = $this->backend->logExcelName($code, $periodo);
        $disp = (isset($q['disposition']) && $q['disposition'] === 'inline') ? 'inline' : 'attachment';
        ApiResponse::binary($bin,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name, $disp);
    }

    /** GET /api/logs/excel/zip — ZIP mensual con los 7 reportes. */
    public function zip($params, $q)
    {
        $periodo = Validator::period($q);
        $bin  = $this->backend->logsZip($periodo);
        $name = $this->backend->logsZipName($periodo);
        ApiResponse::binary($bin, 'application/zip', $name, 'attachment');
    }
}
