<?php
/**
 * datos.php — Devuelve los datos de un reporte en JSON para el visor de la UI.
 *
 *   datos.php?periodo=2026-01&reporte=06
 *
 * Respuesta: { report, title, db, periodo, label, sheets:[ {name, columns, rows} ] }
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(300);
ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/lib/Reportes.php';
use Auditoria\Reportes;

function jexit($arr, $code = 200)
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$periodo = isset($_GET['periodo']) ? trim($_GET['periodo']) : '';
$reporte = isset($_GET['reporte']) ? trim($_GET['reporte']) : '';

if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) {
    jexit(array('error' => 'Período inválido (use AAAA-MM).'), 400);
}
$catalog = Reportes::catalog();
if (!isset($catalog[$reporte])) {
    jexit(array('error' => 'Reporte desconocido.'), 400);
}

try {
    $t0   = microtime(true);
    $data = Reportes::data($reporte, $periodo);
    $data['ms'] = round((microtime(true) - $t0) * 1000);
    jexit($data);
} catch (\Exception $e) {
    jexit(array('error' => $e->getMessage()), 500);
}
