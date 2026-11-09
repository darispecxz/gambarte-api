<?php
/**
 * index.php — Front controller de la API (CgrReportsApi).
 *
 * API REST sin dependencias (compatible con PHP 5.6, en paridad con el servidor
 * de producción). Reutiliza la lógica del proyecto PHP existente; no la duplica.
 * Estructura estilo Slim para una futura migración a Slim 4 (cuando haya PHP 8 +
 * Composer).
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');   // nunca exponer errores al cliente
set_time_limit(300);

define('CGRAPI_ROOT', dirname(__DIR__));

require_once CGRAPI_ROOT . '/src/Env.php';
use CgrApi\Env;
Env::load(CGRAPI_ROOT . '/.env');

// Autoloader simple PSR-4 para el namespace CgrApi\ -> src/
spl_autoload_register(function ($class) {
    $prefix = 'CgrApi\\';
    if (strpos($class, $prefix) !== 0) return;
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = CGRAPI_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) require_once $file;
});

use CgrApi\Backend;
use CgrApi\Http\Router;
use CgrApi\Http\ApiResponse;
use CgrApi\Controllers\RisksController;
use CgrApi\Controllers\LogsController;
use CgrApi\Controllers\OperationsController;
use CgrApi\Controllers\AccountingController;
use CgrApi\Controllers\BankingController;
use CgrApi\Controllers\DiscrepancyController;

$debug = Env::bool('APP_DEBUG', false);

try {
    $backend = new Backend();
} catch (\Throwable $e) {
    error_log('[CgrApi] boot: ' . $e->getMessage());
    ApiResponse::error('El servicio no está disponible en este momento.', 503);
    exit;
} catch (\Exception $e) {
    error_log('[CgrApi] boot: ' . $e->getMessage());
    ApiResponse::error('El servicio no está disponible en este momento.', 503);
    exit;
}

$risks = new RisksController($backend);
$logs  = new LogsController($backend);
$ops   = new OperationsController($backend);
$accounting = new AccountingController($backend);
$banking       = new BankingController($backend);
$discrepancies = new DiscrepancyController();

$router = new Router($debug);

// Salud
$router->get('/api/health', function ($p, $q) {
    ApiResponse::ok(array('status' => 'ok', 'time' => date('c')));
});

// Riesgos  (dashboard antes que {slug} para evitar colisión)
$router->get('/api/risks',                array($risks, 'index'));
$router->get('/api/risks/dashboard',      array($risks, 'dashboard'));
$router->get('/api/risks/{slug}',         array($risks, 'show'));
$router->get('/api/risks/{slug}/pdf',     array($risks, 'pdf'));

// Logs / bitácoras  (zip antes que {code})
$router->get('/api/logs',                 array($logs, 'index'));
$router->get('/api/logs/excel/zip',       array($logs, 'zip'));
$router->get('/api/logs/{code}',          array($logs, 'show'));
$router->get('/api/logs/{code}/excel',    array($logs, 'excel'));

// Operaciones
$router->get('/api/operations',           array($ops, 'index'));
$router->get('/api/operations/{action}',  array($ops, 'show'));

// Contabilidad — Arqueos de caja y reportes
$router->get('/api/accounting/daily-summary',      array($accounting, 'dailySummary'));
$router->get('/api/accounting/cashier-details',    array($accounting, 'cashierDetails'));
$router->get('/api/accounting/movement-summary',   array($accounting, 'movementSummary'));
$router->get('/api/accounting/agency-closings',    array($accounting, 'agencyClosings'));
$router->get('/api/accounting/agencies',          array($accounting, 'agencies'));
$router->get('/api/accounting/export',            array($accounting, 'export'));
$router->get('/api/accounting/ejercicios',       array($accounting, 'ejercicios'));
$router->get('/api/accounting/subcuentas',       array($accounting, 'subcuentas'));
$router->get('/api/accounting/balance',          array($accounting, 'balance'));
$router->get('/api/accounting/libro-mayor',      array($accounting, 'libroMayor'));
$router->get('/api/accounting/asiento-detalle',  array($accounting, 'asientoDetalle'));

// Bancos — Movimientos, conciliación contable y traspasos
$router->get('/api/banking/entities',            array($banking, 'entities'));
$router->get('/api/banking/accounts',            array($banking, 'accounts'));
$router->get('/api/banking/movements',           array($banking, 'movements'));
$router->get('/api/banking/conciliacion',        array($banking, 'conciliacion'));
$router->get('/api/banking/daily-summary',       array($banking, 'dailySummary'));
$router->get('/api/banking/conciliacion-detalle', array($banking, 'conciliacionDetalle'));
$router->get('/api/banking/movement-detail',     array($banking, 'movementDetail'));
$router->get('/api/banking/transfers',           array($banking, 'transfers'));

// Discrepancias — Auditoría e incidencias contables
$router->get('/api/discrepancies',              array($discrepancies, 'index'));
$router->get('/api/discrepancies/summary',      array($discrepancies, 'summary'));
$router->get('/api/discrepancies/types',        array($discrepancies, 'types'));
$router->post('/api/discrepancies/run',         array($discrepancies, 'run'));
$router->post('/api/discrepancies/{id}/resolve', array($discrepancies, 'resolve'));

// Documentación (Swagger UI + OpenAPI)
$router->get('/openapi.yaml', function ($p, $q) {
    header('Content-Type: application/yaml; charset=utf-8');
    readfile(CGRAPI_ROOT . '/docs/openapi.yaml');
});
$router->get('/docs', function ($p, $q) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(CGRAPI_ROOT . '/docs/swagger.html');
});

$router->run();
