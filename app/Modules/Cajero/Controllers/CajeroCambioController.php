<?php

namespace App\Modules\Cajero\Controllers;

use App\Modules\Cajero\Services\CajeroCambioService;
use App\Modules\Facturacion\SiatClient;
use App\Modules\Operaciones\Services\OperationsReportService;
use App\Modules\Shared\Database\RawConnection as DB;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CajeroCambioController extends ApiController
{
    private CajeroCambioService $service;
    private OperationsReportService $reportService;

    public function __construct(CajeroCambioService $service, OperationsReportService $reportService)
    {
        $this->service = $service;
        $this->reportService = $reportService;
    }

    private function claims(Request $request): array
    {
        return $request->attributes->get('jwt_claims');
    }

    public function cotizaciones(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];
        return $this->ok($this->service->cotizaciones($idAgencia));
    }

    public function buscarCliente(Request $request): JsonResponse
    {
        $documento  = trim($request->query('documento', ''));
        $tipoDoc    = (int) $request->query('tipo_doc', 1);
        $expedicion = (int) $request->query('expedicion', 0);

        if (!$documento) {
            return $this->error('Parámetro documento es requerido', 400);
        }
        // La expedición sólo es obligatoria para Cédula de Identidad (tipo 1).
        if ($tipoDoc === 1 && !$expedicion) {
            return $this->error('Parámetro expedicion es requerido', 400);
        }

        $cliente = $this->service->buscarCliente($documento, $tipoDoc, $expedicion);
        return $this->ok($cliente);
    }

    public function actualizarCliente(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $cliente = $data['cliente'] ?? [];

        if (empty($cliente['num_doc_identidad'])) {
            return $this->error('Datos de cliente incompletos', 400);
        }

        $id = $this->service->actualizarCliente($cliente);
        return $this->ok(['id' => $id]);
    }

    public function catalogos(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'] ?? 0;
        return $this->ok($this->service->catalogos($idAgencia));
    }

    public function registrarCambio(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario  = $claims['user']['cod_usuario'];
        $loginUsuario = $claims['user']['login'];
        $idAgencia   = $claims['agencia']['id_agencia'];

        $idOperacion = (int) $request->input('id_operacion');
        $cliente     = $request->input('cliente', []);
        $operacion   = $request->input('operacion', []);

        $uuid = trim($request->input('uuid', ''));

        if (!in_array($idOperacion, [1, 2], true)) {
            return $this->error('id_operacion debe ser 1 (compra) o 2 (venta)', 400);
        }
        if (empty($cliente['num_doc_identidad']) || empty($cliente['nombres'])) {
            return $this->error('Datos del cliente incompletos', 400);
        }
        if (empty($operacion['monto']) || empty($operacion['tipo_cambio'])) {
            return $this->error('Datos de la operación incompletos', 400);
        }
        if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->error('UUID de operación es requerido', 400);
        }

        $metodosPago = $request->input('metodos_pago', []);
        if (!is_array($metodosPago) || empty($metodosPago)) {
            $metodosPago = [['id_forma_pago' => 1, 'monto' => 0, 'referencia' => '']];
        }
        $complemento = $request->input('complemento', []);

        $result = $this->service->registrarCambio(
            $codUsuario,
            $loginUsuario,
            $idAgencia,
            $idOperacion,
            $cliente,
            $operacion,
            $request->ip(),
            $uuid,
            $metodosPago,
            is_array($complemento) ? $complemento : []
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function estadoSiat(Request $request): JsonResponse
    {
        $claims    = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];
        $login     = $claims['user']['login'];

        // 1. Verificar facturador electrónico (Java service)
        $siat = new SiatClient();
        $comunicacion = $siat->verificarComunicacion();
        $facturadorOnline = $comunicacion['success'];
        $sinOnline = $comunicacion['success'] && (($comunicacion['data']['return']['transaccion'] ?? false) === true);

        // 2. Punto de venta + CUFD vigente
        $puntoVenta = DB::selectOne("
            SELECT fpv.codigo_punto_venta, fs.sucursal, fc2.codigo AS cuis,
                   fc.codigo AS cufd, fc.fecha_vigencia,
                   fc.codigo_control, a.descripcion AS agencia
            FROM fe_cufd fc
            INNER JOIN fe_cuis fc2 ON fc.id_cuis = fc2.id_cuis
            INNER JOIN fe_punto_venta fpv ON fpv.id_punto_venta = fc2.id_punto_venta
            INNER JOIN fe_sucursal fs ON fs.id_sucursal = fpv.id_sucursal
            INNER JOIN agencia a ON a.id_agencia = fs.id_agencia AND a.id_agencia = :ag
            WHERE (CURRENT_TIMESTAMP(3) <= SUBTIME(fc.fecha_vigencia, '04:00:00')
                OR TIMESTAMPDIFF(HOUR, fc.created_at, CURRENT_TIMESTAMP(3)) <= 70)
                AND fpv.activo = TRUE
            ORDER BY fc.created_at DESC LIMIT 1
        ", [':ag' => $idAgencia]);

        // 3. Facturación activa
        $facturacionActiva = DB::selectOne("
            SELECT factura, autorizacion FROM facturacion
            WHERE estado = 'A' AND id_agencia = :ag AND LENGTH(llave) > 0 AND cf = 0
        ", [':ag' => $idAgencia]);

        // 4. Facturas fuera de línea pendientes de sincronización.
        // Se cuentan también las 'CONTINGENCIA' históricas: son facturas emitidas
        // fuera de línea que quedaron sin enviar al SIN porque el servicio de
        // sincronización busca 'POR_PROCESAR' (ver FacturacionService).
        $contingencias = (int) DB::scalar("
            SELECT COUNT(*) FROM fe_factura ff
            INNER JOIN cambio c ON c.id_cambio = ff.id_operacion
            WHERE ff.estado IN ('POR_PROCESAR', 'CONTINGENCIA') AND c.id_agencia = :ag
        ", [':ag' => $idAgencia]) ?: 0;

        $puedeFacturar = $puntoVenta !== null && $facturacionActiva !== null;
        $estadoGeneral = ($facturadorOnline && $sinOnline && $puedeFacturar) ? 'EN_LINEA'
            : ($puedeFacturar ? 'CONTINGENCIA' : 'INACTIVO');

        return $this->ok([
            'conexiones' => [
                'facturador'  => $facturadorOnline,
                'sin'         => $sinOnline,
                'estado'      => $estadoGeneral,
            ],
            'punto_venta' => $puntoVenta ? [
                'agencia'        => $puntoVenta['agencia'],
                'sucursal'       => (int) $puntoVenta['sucursal'],
                'punto_venta'    => (int) $puntoVenta['codigo_punto_venta'],
                'cuis'           => $puntoVenta['cuis'],
                'cufd'           => $puntoVenta['cufd'],
                'codigo_control' => $puntoVenta['codigo_control'],
                'cufd_vigente'   => true,
                'cufd_vencimiento' => $puntoVenta['fecha_vigencia'],
            ] : null,
            'sincronizacion' => [
                'cambios_pendientes' => $contingencias,
            ],
            'puede_facturar' => $puedeFacturar,
        ]);
    }

    public function calcularCambio(Request $request): JsonResponse
    {
        $idOperacion = (int) $request->input('id_operacion');
        $idMoneda    = (int) $request->input('id_moneda');
        $monto       = (float) $request->input('monto');
        $documento   = trim($request->input('documento', ''));
        $tipoDoc     = (int) $request->input('tipo_doc', 1);
        $expedicion  = (int) $request->input('expedicion', 0);

        if (!in_array($idOperacion, [1, 2], true)) {
            return $this->error('id_operacion debe ser 1 o 2', 400);
        }
        if ($monto <= 0) {
            return $this->error('El monto debe ser mayor a 0', 400);
        }
        if (!$documento) {
            return $this->error('Documento es requerido', 400);
        }

        $result = $this->service->calcularCambioUif(
            $idOperacion, $idMoneda, $monto, $documento, $tipoDoc, $expedicion
        );

        return $this->ok($result);
    }

    public function comprobante(int $id): JsonResponse
    {
        $data = $this->service->obtenerComprobante($id);
        if (!$data) {
            return $this->error('Operación no encontrada', 404);
        }
        return $this->ok($data);
    }

    public function historial(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $idAgencia  = $claims['agencia']['id_agencia'];
        $codUsuario = $claims['user']['cod_usuario'];

        $desde     = $this->optionalFecha($request, 'desde') ?: date('Y-m-d');
        $hasta     = $this->optionalFecha($request, 'hasta');
        $operacion = $this->intParam($request, 'operacion');

        $records = $this->reportService->cambios($desde, $hasta, $idAgencia, $operacion, $codUsuario);

        $totals = $this->buildTotals($records);

        return $this->ok([
            'desde'   => $desde,
            'hasta'   => $hasta ?: $desde,
            'total'   => count($records),
            'totals'  => $totals,
            'records' => $records,
        ]);
    }

    public function historialDetalle(int $id): JsonResponse
    {
        $detail = $this->reportService->cambioDetail($id);
        if (!$detail) {
            return $this->error('Operación no encontrada', 404);
        }
        return $this->ok($detail);
    }

    public function facturaPdf(int $id)
    {
        $result = $this->service->facturaPdf($id);

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }

    private function buildTotals(array $records): array
    {
        $compras = ['count' => 0, 'totalBob' => 0.0, 'byMoneda' => []];
        $ventas  = ['count' => 0, 'totalBob' => 0.0, 'byMoneda' => []];

        foreach ($records as $r) {
            $bucket = &$compras;
            if ($r['idOperacion'] === 2) $bucket = &$ventas;

            $bucket['count']++;
            $bucket['totalBob'] += $r['totalOperacionBob'] ?: $r['totalOperacion'];

            $mon = $r['moneda'] ?? 'N/A';
            if (!isset($bucket['byMoneda'][$mon])) {
                $bucket['byMoneda'][$mon] = ['count' => 0, 'monto' => 0.0, 'totalBob' => 0.0];
            }
            $bucket['byMoneda'][$mon]['count']++;
            $bucket['byMoneda'][$mon]['monto'] += $r['monto'];
            $bucket['byMoneda'][$mon]['totalBob'] += $r['totalOperacionBob'] ?: $r['totalOperacion'];
        }

        $compras['totalBob'] = round($compras['totalBob'], 2);
        $ventas['totalBob']  = round($ventas['totalBob'], 2);
        foreach ($compras['byMoneda'] as &$m) { $m['monto'] = round($m['monto'], 2); $m['totalBob'] = round($m['totalBob'], 2); }
        foreach ($ventas['byMoneda'] as &$m)  { $m['monto'] = round($m['monto'], 2); $m['totalBob'] = round($m['totalBob'], 2); }

        return ['compras' => $compras, 'ventas' => $ventas];
    }
}
