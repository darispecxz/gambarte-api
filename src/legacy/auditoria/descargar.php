<?php
/**
 * descargar.php — Genera y envía un reporte (.xlsx) o el paquete completo (.zip).
 *
 *   descargar.php?periodo=2026-01&reporte=00     -> un .xlsx
 *   descargar.php?periodo=2026-01&reporte=todos   -> un .zip con los 7
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/lib/Reportes.php';

use Auditoria\Reportes;
use Auditoria\Zip;

function fail($msg, $code = 400)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $msg;
    exit;
}

$periodo = isset($_GET['periodo']) ? trim($_GET['periodo']) : '';
$reporte = isset($_GET['reporte']) ? trim($_GET['reporte']) : '';

if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) {
    fail('Período inválido (use AAAA-MM).');
}

try {
    $catalog = Reportes::catalog();

    if ($reporte === 'todos') {
        // Paquete ZIP con los 7 reportes
        $files = array();
        foreach ($catalog as $key => $meta) {
            $xlsx = Reportes::build($key, $periodo);
            $files[Reportes::fileName($key, $periodo)] = $xlsx->toString();
        }
        $zip  = Zip::build($files);
        $name = 'REPORTES_AUDITORIA_' . str_replace('-', '_', $periodo) . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($zip));
        echo $zip;
        exit;
    }

    if (!isset($catalog[$reporte])) {
        fail('Reporte desconocido.');
    }

    $xlsx = Reportes::build($reporte, $periodo);
    $bin  = $xlsx->toString();
    $name = Reportes::fileName($reporte, $periodo);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit;

} catch (\Exception $e) {
    fail($e->getMessage(), 500);
}
