<?php
/**
 * descargar.php — Genera el PDF de cualquier informe del panel (mPDF).
 *
 *   descargar.php?riesgo=cambiario&preset=mes&fin=2025-12-30
 *   descargar.php?riesgo=liquidez&preset=dia&fecha=2026-08-12
 *   descargar.php?riesgo=operativo&preset=rango&ini=2026-07-01&fin=2026-07-31
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_time_limit(300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/lib/Panel.php';

use RiesgoCambiario\Panel;

function valid_date($s) { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s); }
function fail($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $msg;
    exit;
}

\RiesgoCambiario\Db::config();

$risks  = Panel::risks();
$riesgo = isset($_GET['riesgo']) && isset($risks[$_GET['riesgo']]) ? $_GET['riesgo'] : 'cambiario';

$hoy = date('Y-m-d');
$preset = isset($_GET['preset']) ? $_GET['preset'] : 'mes';
if ($preset === 'dia') {
    $fin = isset($_GET['fecha']) && valid_date($_GET['fecha']) ? $_GET['fecha'] : $hoy;
    $ini = $fin;
} elseif ($preset === 'rango') {
    $ini = isset($_GET['ini']) && valid_date($_GET['ini']) ? $_GET['ini'] : date('Y-m-01');
    $fin = isset($_GET['fin']) && valid_date($_GET['fin']) ? $_GET['fin'] : $hoy;
    if ($ini > $fin) { $t = $ini; $ini = $fin; $fin = $t; }
} else {
    $fin = isset($_GET['fin']) && valid_date($_GET['fin']) ? $_GET['fin'] : $hoy;
    $ini = date('Y-m-01', strtotime($fin));
}

try {
    // Única fuente de la generación de PDF (reutilizada también por la API).
    $bin    = Panel::pdf($riesgo, $ini, $fin);
    $nombre = Panel::pdfFilename($riesgo, $ini, $fin);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit;
} catch (Exception $e) {
    fail('No se pudo generar el PDF: ' . $e->getMessage(), 500);
}
