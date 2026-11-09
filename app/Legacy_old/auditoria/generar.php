<?php
/**
 * generar.php — Generación por línea de comandos (independiente de la UI).
 *
 *   php generar.php --periodo 2026-01
 *   php generar.php --periodo 2026-01 --salida "D:\Gambarte\...\auditoria"
 *   php generar.php --periodo 2026-01 --reporte 05
 *
 * Sin --salida crea:  ./salida/<AAAA - Mes>/
 * Con  --salida crea:  <salida>/<AAAA - Mes>/   (igual convención de carpetas)
 */
if (PHP_SAPI !== 'cli') {
    die("Este script es solo para línea de comandos.\n");
}
require_once __DIR__ . '/lib/Reportes.php';
use Auditoria\Reportes;

/* ── parseo simple de argumentos --clave valor ── */
$args = array();
for ($i = 1; $i < $argc; $i++) {
    if (substr($argv[$i], 0, 2) === '--') {
        $k = substr($argv[$i], 2);
        $v = (isset($argv[$i+1]) && substr($argv[$i+1],0,2) !== '--') ? $argv[++$i] : true;
        $args[$k] = $v;
    }
}

$periodo = isset($args['periodo']) ? $args['periodo'] : date('Y-m', strtotime('first day of last month'));
$reporte = isset($args['reporte']) ? $args['reporte'] : 'todos';

try {
    $p = Reportes::period($periodo);
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$base = isset($args['salida']) ? rtrim($args['salida'], "/\\") : __DIR__ . DIRECTORY_SEPARATOR . 'salida';
$dir  = $base . DIRECTORY_SEPARATOR . $p['folder'];
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    fwrite(STDERR, "No se pudo crear la carpeta: $dir\n");
    exit(1);
}

$catalog = Reportes::catalog();
$keys = ($reporte === 'todos') ? array_keys($catalog) : array($reporte);

echo "Período: {$p['label']}  ({$p['ini']} → {$p['fin']})\n";
echo "Salida : $dir\n\n";

foreach ($keys as $key) {
    if (!isset($catalog[$key])) { echo "  [!] Reporte desconocido: $key\n"; continue; }
    $t0 = microtime(true);
    try {
        $xlsx = Reportes::build($key, $periodo);
        $file = $dir . DIRECTORY_SEPARATOR . Reportes::fileName($key, $periodo);
        $xlsx->save($file);
        printf("  [OK] %-34s %6.1fs  %s\n", $catalog[$key]['title'],
               microtime(true) - $t0, basename($file));
    } catch (Exception $e) {
        printf("  [ERROR] %s: %s\n", $key, $e->getMessage());
    }
}
echo "\nListo.\n";
