<?php

namespace App\Modules\Cajero\Controllers;

use App\Modules\Cajero\Services\CajeroRemesaService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CajeroRemesaController extends ApiController
{
    private CajeroRemesaService $service;

    public function __construct(CajeroRemesaService $service)
    {
        $this->service = $service;
    }

    private function claims(Request $request): array
    {
        return $request->attributes->get('jwt_claims');
    }

    public function destinos(): JsonResponse
    {
        return $this->ok($this->service->destinos());
    }

    public function comisionesPreferenciales(): JsonResponse
    {
        return $this->ok($this->service->listarComisionesPreferenciales());
    }

    public function buscarDestinatario(Request $request): JsonResponse
    {
        $documento  = trim($request->query('documento', ''));
        $tipoDoc    = (int) $request->query('tipo_doc', 1);
        $expedicion = (int) $request->query('expedicion', 0);

        if (!$documento) {
            return $this->error('Parámetro documento es requerido', 400);
        }

        $destinatario = $this->service->buscarDestinatario($documento, $tipoDoc, $expedicion);
        return $this->ok($destinatario);
    }

    public function destinatariosPorRemitente(Request $request): JsonResponse
    {
        $documento  = trim($request->query('documento', ''));
        $tipoDoc    = (int) $request->query('tipo_doc', 1);
        $expedicion = (int) $request->query('expedicion', 0);

        if (!$documento) {
            return $this->error('Parámetro documento es requerido', 400);
        }

        $lista = $this->service->destinatariosPorRemitente($documento, $tipoDoc, $expedicion);
        return $this->ok($lista);
    }

    public function verificarUif(Request $request): JsonResponse
    {
        $idMoneda   = (int) $request->input('id_moneda');
        $monto      = (float) $request->input('monto');
        $documento  = trim($request->input('documento', ''));
        $tipoDoc    = (int) $request->input('tipo_doc', 1);
        $expedicion = (int) $request->input('expedicion', 0);

        if ($monto <= 0 || !$idMoneda || !$documento) {
            return $this->error('Parámetros incompletos para verificación UIF', 400);
        }

        $result = $this->service->verificarUifRemesa($idMoneda, $monto, $documento, $tipoDoc, $expedicion);

        return $this->ok($result);
    }

    public function tcOficialVenta(): JsonResponse
    {
        return $this->ok($this->service->obtenerTcOficialVenta());
    }

    public function bancosChile(): JsonResponse
    {
        return $this->ok($this->service->bancosChile());
    }

    public function formasPagoChile(): JsonResponse
    {
        return $this->ok($this->service->formasPagoChile());
    }

    public function formasPagoCobro(): JsonResponse
    {
        return $this->ok($this->service->formasPagoCobro());
    }

    public function calcularComision(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];

        $tipoComision    = (int) $request->input('tipo_comision', 0);
        $idMoneda        = (int) $request->input('id_moneda');
        $idOperacion     = (int) $request->input('id_operacion', 4);
        $monto           = (float) $request->input('monto');
        $idDestino       = (int) $request->input('id_destino', 1);
        $incluirComision = (int) $request->input('incluir_comision', 0);

        if ($monto <= 0) {
            return $this->error('El monto debe ser mayor a 0', 400);
        }
        if (!$idMoneda) {
            return $this->error('id_moneda es requerido', 400);
        }

        $result = $this->service->calcularComision(
            $tipoComision, $idAgencia, $idMoneda, $idOperacion, $monto, $idDestino, $incluirComision
        );

        if (isset($result['error'])) {
            return $this->error($result['error'], 422);
        }

        return $this->ok($result);
    }

    public function cotizacionChile(Request $request): JsonResponse
    {
        $idDestino = (int) $request->query('id_destino', 1);
        $result = $this->service->obtenerCotizacionChile($idDestino);

        if (!$result['success']) {
            return $this->error('No se pudo obtener la cotización de Chile. Intente nuevamente.', 503);
        }

        return $this->ok($result);
    }

    public function crearCotizacion(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idMoneda        = (int) $request->input('id_moneda');
        $idDestino       = (int) $request->input('id_destino', 1);
        $monto           = (float) $request->input('monto');
        $tipoComision    = (int) $request->input('tipo_comision', 0);
        $incluirComision = (int) $request->input('incluir_comision', 0);
        $snapshot        = $request->input('snapshot', []);

        if ($monto <= 0 || !$idMoneda) {
            return $this->error('Datos incompletos para cotización', 400);
        }

        $result = $this->service->crearCotizacionRemesa(
            $codUsuario, $idAgencia, $idMoneda, $idDestino,
            $monto, $tipoComision, $incluirComision,
            is_array($snapshot) ? $snapshot : []
        );

        return $this->ok($result, 'Cotización creada.');
    }

    public function buscarCotizacion(Request $request): JsonResponse
    {
        $codigo = trim($request->query('codigo', ''));
        if (!$codigo) {
            return $this->error('Código de cotización requerido', 400);
        }

        $result = $this->service->buscarCotizacionRemesa($codigo);
        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['cotizacion']);
    }

    public function registrarRemesa(Request $request): JsonResponse
    {
        $claims       = $this->claims($request);
        $codUsuario   = $claims['user']['cod_usuario'];
        $loginUsuario = $claims['user']['login'];
        $idAgencia    = $claims['agencia']['id_agencia'];

        $idOperacion  = (int) $request->input('id_operacion', 4);
        $cliente      = $request->input('cliente', []);
        $destinatario = $request->input('destinatario', []);
        $operacion    = $request->input('operacion', []);
        $comisionData   = $request->input('comision', []);
        $complemento    = $request->input('complemento', []);
        $idCotizacion   = $request->input('id_cotizacion') ? (int) $request->input('id_cotizacion') : null;
        $uuid           = trim($request->input('uuid', ''));

        if (!in_array($idOperacion, [4, 16], true)) {
            return $this->error('id_operacion debe ser 4 (remesa) o 16 (giro internacional)', 400);
        }
        if (empty($cliente['num_doc_identidad']) || empty($cliente['nombres'])) {
            return $this->error('Datos del cliente incompletos', 400);
        }
        if (empty($cliente['fecha_nacimiento'])) {
            return $this->error('La fecha de nacimiento del cliente es requerida', 400);
        }
        if (empty(trim($cliente['domicilio'] ?? ''))) {
            return $this->error('El domicilio del cliente es requerido', 400);
        }
        if (empty($destinatario['nombres'])) {
            return $this->error('Datos del destinatario incompletos', 400);
        }
        if (empty($operacion['monto']) || empty($operacion['mon_ent'])) {
            return $this->error('Datos de la operación incompletos', 400);
        }
        $monto = (float) $operacion['monto'];
        if ($monto < 1) {
            return $this->error('El monto mínimo de envío es 1', 400);
        }
        if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->error('UUID de operación es requerido', 400);
        }

        $result = $this->service->registrarRemesa(
            $codUsuario,
            $loginUsuario,
            $idAgencia,
            $idOperacion,
            is_array($cliente) ? $cliente : [],
            is_array($destinatario) ? $destinatario : [],
            is_array($operacion) ? $operacion : [],
            is_array($comisionData) ? $comisionData : [],
            $request->ip(),
            $uuid,
            is_array($complemento) ? $complemento : []
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        if ($idCotizacion && isset($result['data']['id_remesa'])) {
            $this->service->marcarCotizacionAplicada($idCotizacion, (int) $result['data']['id_remesa']);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function comprobanteRemesa(int $id): JsonResponse
    {
        $data = $this->service->obtenerComprobante($id);
        if (!$data) {
            return $this->error('Remesa no encontrada', 404);
        }
        return $this->ok($data);
    }

    public function comprobantePdf(int $id)
    {
        $result = $this->service->comprobantePdf($id);

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function comprobanteEnvioChilePdf(int $codigo)
    {
        $result = $this->service->comprobanteEnvioChilePdf($codigo);

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
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

    public function historialRemesa(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $desde  = $request->query('desde', date('Y-m-d'));
        $hasta  = $request->query('hasta', $desde);
        $estado = $request->query('estado') !== null && $request->query('estado') !== ''
                  ? (int) $request->query('estado') : null;
        $codigo = $request->query('codigo') !== null && $request->query('codigo') !== ''
                  ? (int) $request->query('codigo') : null;

        $records = $this->service->historialRemesas($codUsuario, $idAgencia, $desde, $hasta, $estado, $codigo);
        return $this->ok($records);
    }

    public function listarCotizaciones(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $estado = $request->query('estado');

        $records = $this->service->listarCotizaciones($codUsuario, $idAgencia, $estado);
        return $this->ok($records);
    }

    // ─── Pago de Remesas ──────────────────────────────────────────────

    public function pendientesPago(Request $request): JsonResponse
    {
        $claims    = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];
        $tipoPago = $request->query('tipo_pago') !== null && $request->query('tipo_pago') !== ''
            ? (int) $request->query('tipo_pago') : null;

        return $this->ok($this->service->remesasPendientesPago($idAgencia, $tipoPago));
    }

    public function buscarRemesa(int $codigo): JsonResponse
    {
        if ($codigo <= 0) {
            return $this->error('Código de remesa inválido', 400);
        }

        $result = $this->service->buscarRemesaChile($codigo);
        if (!$result['success']) {
            return $this->error($result['message'], 404);
        }

        return $this->ok($result['remesa']);
    }

    public function preprocesarRemesas(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $codUsuario = (int) $claims['user']['cod_usuario'];
        $idAgencia = (int) $claims['agencia']['id_agencia'];
        $codigos = $request->input('codigos', []);
        $uuid = trim((string) $request->input('uuid', ''));

        if (!is_array($codigos) || empty($codigos)) {
            return $this->error('Seleccione al menos una remesa', 400);
        }
        if (count($codigos) > 100) {
            return $this->error('No puede preparar más de 100 remesas por lote', 400);
        }
        if (!$uuid) {
            return $this->error('UUID es requerido', 400);
        }

        $codigos = array_values(array_unique(array_filter(array_map('intval', $codigos), fn ($c) => $c > 0)));
        if (empty($codigos)) {
            return $this->error('Los códigos de remesa no son válidos', 400);
        }

        $result = $this->service->preprocesarRemesas($codUsuario, $idAgencia, $codigos, $request->ip(), $uuid);
        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function pagarRemesa(Request $request): JsonResponse
    {
        $claims      = $this->claims($request);
        $codUsuario  = $claims['user']['cod_usuario'];
        $loginUsuario = $claims['user']['login'];
        $idAgencia   = $claims['agencia']['id_agencia'];

        $codigo          = (int) $request->input('codigo');
        $destinatarioDoc = $request->input('destinatario_doc', []);
        $tipoPago        = (int) $request->input('tipo_pago', 0);
        $datosBancarios  = $request->input('datos_bancarios', []);
        $uuid            = trim($request->input('uuid', ''));
        $ip              = $request->ip();

        if ($codigo <= 0) {
            return $this->error('Código de remesa es requerido', 400);
        }
        if (!$uuid) {
            return $this->error('UUID es requerido', 400);
        }
        if ($tipoPago === 1) {
            if (empty($datosBancarios['num_cuenta_bancaria']) || empty($datosBancarios['id_entidad_financiera'])) {
                return $this->error('Datos bancarios incompletos para depósito', 400);
            }
        }

        $result = $this->service->pagarRemesa(
            $codUsuario, $loginUsuario, $idAgencia,
            $codigo, is_array($destinatarioDoc) ? $destinatarioDoc : [],
            $tipoPago, is_array($datosBancarios) ? $datosBancarios : [],
            $ip, $uuid
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function comprobantePagoRemesa(int $id): JsonResponse
    {
        $data = $this->service->obtenerComprobantePago($id);
        if (!$data) {
            return $this->error('Pago no encontrado', 404);
        }
        return $this->ok($data);
    }

    public function comprobantePagoRemesaPdf(int $id)
    {
        $result = $this->service->comprobantePagoPdf($id);

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function enviarComprobantePagoEmail(Request $request): JsonResponse
    {
        $idPago = (int) $request->input('id_pago');
        $email  = trim($request->input('email', ''));

        if ($idPago <= 0) {
            return $this->error('id_pago es requerido', 400);
        }
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Email valido es requerido', 400);
        }

        $result = $this->service->enviarComprobantePagoEmail($idPago, $email);

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok(null, $result['message']);
    }

    public function subirBoletaPagoRemesa(Request $request): JsonResponse
    {
        $codigo = (int) $request->input('codigo');
        if (!$codigo) {
            return $this->error('Codigo de remesa es requerido', 400);
        }

        $file = $request->file('comprobante');
        if (!$file || !$file->isValid()) {
            return $this->error('Archivo de comprobante es requerido', 400);
        }

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExt)) {
            return $this->error('Formato no permitido. Use: ' . implode(', ', $allowedExt), 400);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->error('El archivo no debe superar 5MB', 400);
        }

        $result = $this->service->subirBoletaPagoRemesa($codigo, $file);

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function reporteRemesas(Request $request): JsonResponse
    {
        $claims    = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];

        $desde  = $request->query('desde', date('Y-m-d'));
        $hasta  = $request->query('hasta', $desde);
        $estado = $request->query('estado') !== null && $request->query('estado') !== ''
                  ? (int) $request->query('estado') : null;
        $codigo = $request->query('codigo') !== null && $request->query('codigo') !== ''
                  ? (int) $request->query('codigo') : null;
        $tipoPago = $request->query('tipo_pago') !== null && $request->query('tipo_pago') !== ''
                  ? (int) $request->query('tipo_pago') : null;

        $records = $this->service->reporteRemesas($idAgencia, $desde, $hasta, $estado, $codigo, $tipoPago);
        return $this->ok($records);
    }

    public function confirmarPreproceso(Request $request): JsonResponse
    {
        $claims      = $this->claims($request);
        $codUsuario  = $claims['user']['cod_usuario'];
        $loginUsuario = $claims['user']['login'] ?? $claims['user']['login_usu'] ?? '';
        $idAgencia   = $claims['agencia']['id_agencia'];

        $codigo = (int) $request->input('codigo');
        $uuid   = $request->input('uuid');
        $ip     = $request->ip();

        if (!$codigo) {
            return $this->error('Codigo de remesa es requerido', 400);
        }
        if (!$uuid) {
            return $this->error('UUID es requerido', 400);
        }

        $result = $this->service->confirmarPreproceso(
            $codUsuario, $loginUsuario, $idAgencia,
            $codigo, $ip, $uuid
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function preprocesosActivos(Request $request): JsonResponse
    {
        $claims    = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];

        $records = $this->service->preprocesosActivos($idAgencia);
        return $this->ok($records);
    }

    public function syncChileRetry(): JsonResponse
    {
        $result = $this->service->reintentarSyncChile();
        $message = !empty($result['pausado'])
            ? $result['mensaje']
            : "Sync completado: {$result['completados']}/{$result['total']}";
        return $this->ok($result, $message);
    }
}
