<?php

namespace CgrApi\Controllers;

use CgrApi\Http\ApiResponse;
use CgrApi\Discrepancy\DiscrepancyService;
use CgrApi\Discrepancy\ReconciliationEngine;

class DiscrepancyController
{
    /** GET /api/discrepancies — listar discrepancias con filtros */
    public function index($params, $q)
    {
        $filters = array();
        foreach (array('type', 'severity', 'status', 'operation_type', 'desde', 'hasta') as $k) {
            if (isset($q[$k]) && $q[$k] !== '') {
                $filters[$k] = trim($q[$k]);
            }
        }

        $rows = DiscrepancyService::list($filters);
        ApiResponse::ok(array(
            'total' => count($rows),
            'discrepancies' => $rows,
        ));
    }

    /** GET /api/discrepancies/summary — resumen por severidad/tipo/estado */
    public function summary($params, $q)
    {
        $filters = array();
        if (isset($q['desde']) && $q['desde'] !== '') $filters['desde'] = $q['desde'];
        if (isset($q['hasta']) && $q['hasta'] !== '') $filters['hasta'] = $q['hasta'];

        ApiResponse::ok(DiscrepancyService::summary($filters));
    }

    /** POST /api/discrepancies/run — ejecutar reconciliación manualmente */
    public function run($params, $q)
    {
        $created = ReconciliationEngine::run();
        ApiResponse::ok(array(
            'created' => $created,
            'message' => "Reconciliación completada. {$created} discrepancias nuevas detectadas.",
        ));
    }

    /** POST /api/discrepancies/{id}/resolve — resolver una discrepancia */
    public function resolve($params, $q)
    {
        $id = (int) $params['id'];

        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body || empty($body['resolution_type']) || !isset($body['notes'])) {
            throw new \RuntimeException('__validation__Se requiere resolution_type y notes.');
        }

        $userId = isset($body['user_id']) ? (int) $body['user_id'] : 0;
        $ok = DiscrepancyService::resolve($id, $body['resolution_type'], $body['notes'], $userId);

        if (!$ok) {
            ApiResponse::error('Discrepancia no encontrada o ya resuelta.', 404);
            return;
        }

        ApiResponse::ok(null, 'Discrepancia resuelta.');
    }

    /** GET /api/discrepancies/types — catálogo de tipos */
    public function types($params, $q)
    {
        ApiResponse::ok(array(
            'data_integrity' => array(
                array('code' => 'DUPLICATE_SAME_SYSTEM', 'label' => 'Duplicado en mismo sistema'),
                array('code' => 'ORPHAN_OPERATION', 'label' => 'Operación huérfana'),
                array('code' => 'GHOST_OPERATION', 'label' => 'Operación fantasma'),
            ),
            'accounting' => array(
                array('code' => 'ACCOUNTING_AMOUNT', 'label' => 'Monto contable diferente'),
                array('code' => 'ACCOUNTING_MISSING', 'label' => 'Asiento contable faltante'),
                array('code' => 'DEBIT_CREDIT_MISMATCH', 'label' => 'Debe ≠ Haber'),
            ),
            'exchange_rate' => array(
                array('code' => 'RATE_DIFFERENT', 'label' => 'Tasa diferente a cotización'),
                array('code' => 'ROUNDING_INCONSISTENT', 'label' => 'Redondeo inconsistente'),
            ),
            'workflow' => array(
                array('code' => 'VOID_WITHOUT_REVERSAL', 'label' => 'Anulado sin reverso contable'),
            ),
        ));
    }
}
