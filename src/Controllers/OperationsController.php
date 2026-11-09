<?php
/**
 * OperationsController — datos operativos/comerciales (reutiliza api_dashboard.php).
 *
 * Angular podrá reorganizar estos reportes por categorías; la lógica de consulta
 * sigue viviendo en api_dashboard.php (única fuente de verdad).
 */

namespace CgrApi\Controllers;

use CgrApi\Backend;
use CgrApi\Validator;
use CgrApi\Http\ApiResponse;

class OperationsController
{
    private $backend;
    public function __construct(Backend $backend) { $this->backend = $backend; }

    /** GET /api/operations — lista de vistas operativas disponibles (acciones). */
    public function index($params, $q)
    {
        ApiResponse::ok(array('actions' => $this->backend->operationActions()));
    }

    /** GET /api/operations/{action} — datos de una vista operativa. */
    public function show($params, $q)
    {
        $accion = Validator::inList($params['action'], $this->backend->operationActions(), 'action');
        list($desde, $hasta) = Validator::resolveRango($q);
        $data = $this->backend->operations($accion, $desde, $hasta);
        ApiResponse::ok(array('action' => $accion, 'dateFrom' => $desde, 'dateTo' => $hasta, 'result' => $data));
    }
}
