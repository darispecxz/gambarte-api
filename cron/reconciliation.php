<?php
/**
 * Cron: Reconciliation Engine
 *
 * Ejecutar desde crontab de Hostinger cada 6 horas:
 *   0 */6 * * * /opt/alt/php83/usr/bin/php ~/domains/api-platorma-gambarte.owl-tech.net/public_html/../cron/reconciliation.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(300);

define('CGRAPI_ROOT', dirname(__DIR__));

require_once CGRAPI_ROOT . '/src/Env.php';
\CgrApi\Env::load(CGRAPI_ROOT . '/.env');

spl_autoload_register(function ($class) {
    $prefix = 'CgrApi\\';
    if (strpos($class, $prefix) !== 0) return;
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = CGRAPI_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) require_once $file;
});

require_once CGRAPI_ROOT . '/src/legacy/contabilidad/lib/Db.php';
\Contabilidad\Db::config();

use CgrApi\Discrepancy\ReconciliationEngine;

$start   = microtime(true);
$created = ReconciliationEngine::run();
$elapsed = round(microtime(true) - $start, 2);

$msg = date('Y-m-d H:i:s') . " — Reconciliación completada en {$elapsed}s. Nuevas discrepancias: {$created}";
echo $msg . "\n";
error_log('[CgrApi:Cron] ' . $msg);
