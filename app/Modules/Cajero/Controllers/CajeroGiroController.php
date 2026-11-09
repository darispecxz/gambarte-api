<?php

namespace App\Modules\Cajero\Controllers;

use App\Modules\Cajero\Services\CajeroGiroService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CajeroGiroController extends ApiController
{
    private CajeroGiroService $service;

    public function __construct(CajeroGiroService $service)
    {
        $this->service = $service;
    }

    private function claims(Request $request): array
    {
        return $request->attributes->get('jwt_claims');
    }

    public function agenciasDestino(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];
        return $this->ok($this->service->agenciasDestino($idAgencia));
    }

    public function entidadesFinancieras(): JsonResponse
    {
        return $this->ok($this->service->entidadesFinancieras());
    }

    public function destinatariosPorRemitente(Request $request): JsonResponse
    {
        $documento  = trim($request->query('documento', ''));
        $tipoDoc    = (int) $request->query('tipo_doc', 1);
        $expedicion = (int) $request->query('expedicion', 0);

        if (!$documento) {
            return $this->error('Parámetro documento es requerido', 400);
        }

        return $this->ok($this->service->destinatariosPorRemitente($documento, $tipoDoc, $expedicion));
    }

    public function buscarBeneficiarioFinal(Request $request): JsonResponse
    {
        $documento = trim($request->query('documento', ''));
        if (!$documento) return $this->error('El documento es requerido', 400);
        return $this->ok($this->service->buscarBeneficiarioFinal($documento));
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

        return $this->ok($this->service->verificarUifGiro($idMoneda, $monto, $documento, $tipoDoc, $expedicion));
    }

    public function calcularComision(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];

        $idMoneda        = (int) $request->input('id_moneda');
        $idMonedaEntrega = (int) $request->input('id_moneda_entrega', $idMoneda);
        $monto           = (float) $request->input('monto');
        $incluirComision = (int) $request->input('incluir_comision', 0);

        if ($monto <= 0) {
            return $this->error('El monto debe ser mayor a 0', 400);
        }
        if (!$idMoneda) {
            return $this->error('id_moneda es requerido', 400);
        }

        $result = $this->service->calcularComisionGiro($idAgencia, $idMoneda, $monto, $incluirComision, $idMonedaEntrega);
        return $this->ok($result);
    }

    public function registrar(Request $request): JsonResponse
    {
        $claims       = $this->claims($request);
        $codUsuario   = $claims['user']['cod_usuario'];
        $loginUsuario = $claims['user']['login'];
        $idAgencia    = $claims['agencia']['id_agencia'];

        $cliente      = $request->input('cliente', []);
        $destinatario = $request->input('destinatario', []);
        $operacion    = $request->input('operacion', []);
        $comisionData = $request->input('comision', []);
        $uuid         = trim($request->input('uuid', ''));

        if (empty($cliente['num_doc_identidad']) || empty($cliente['nombres'])) {
            return $this->error('Datos del cliente incompletos', 400);
        }
        if (empty($cliente['fecha_nacimiento'])) {
            return $this->error('La fecha de nacimiento del cliente es requerida', 400);
        }
        if (empty($destinatario['nombres'])) {
            return $this->error('Datos del destinatario incompletos', 400);
        }
        if (empty($operacion['monto']) || empty($operacion['mon_rec'])) {
            return $this->error('Datos de la operación incompletos', 400);
        }
        $monto = (float) $operacion['monto'];
        if ($monto < 1) {
            return $this->error('El monto mínimo es 1', 400);
        }
        if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->error('UUID de operación es requerido', 400);
        }

        $result = $this->service->registrarGiro(
            $codUsuario,
            $loginUsuario,
            $idAgencia,
            is_array($cliente) ? $cliente : [],
            is_array($destinatario) ? $destinatario : [],
            is_array($operacion) ? $operacion : [],
            is_array($comisionData) ? $comisionData : [],
            $request->ip(),
            $uuid
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function pagar(Request $request): JsonResponse
    {
        $claims       = $this->claims($request);
        $codUsuario   = $claims['user']['cod_usuario'];
        $loginUsuario = $claims['user']['login'];
        $idAgencia    = $claims['agencia']['id_agencia'];

        $codigo         = (int) $request->input('codigo');
        $destinatarioDoc = $request->input('destinatario_doc', []);
        $uuid           = trim($request->input('uuid', ''));

        if ($codigo <= 0) {
            return $this->error('Código de giro es requerido', 400);
        }
        if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->error('UUID de operación es requerido', 400);
        }

        $result = $this->service->pagarGiro(
            $codUsuario, $loginUsuario, $idAgencia,
            $codigo,
            is_array($destinatarioDoc) ? $destinatarioDoc : [],
            $request->ip(), $uuid
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function devolver(Request $request): JsonResponse
    {
        $claims       = $this->claims($request);
        $codUsuario   = $claims['user']['cod_usuario'];
        $loginUsuario = $claims['user']['login'];
        $idAgencia    = $claims['agencia']['id_agencia'];

        $codigo = (int) $request->input('codigo');
        $motivo = trim($request->input('motivo', ''));
        $uuid   = trim($request->input('uuid', ''));

        if ($codigo <= 0) {
            return $this->error('Código de giro es requerido', 400);
        }
        if (!$motivo) {
            return $this->error('El motivo de devolución es requerido', 400);
        }
        if (!$uuid || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->error('UUID de operación es requerido', 400);
        }

        $result = $this->service->devolverGiro(
            $codUsuario, $loginUsuario, $idAgencia,
            $codigo, $motivo,
            $request->ip(), $uuid
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function buscar(int $codigo): JsonResponse
    {
        $giro = $this->service->buscarGiroPorCodigo($codigo);
        if (!$giro) {
            return $this->error('Giro no encontrado', 404);
        }
        return $this->ok($giro);
    }

    public function pendientes(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];
        return $this->ok($this->service->girosPendientes($idAgencia));
    }

    public function historial(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $desde  = $request->query('desde', date('Y-m-d'));
        $hasta  = $request->query('hasta', $desde);
        $estado = $request->query('estado') !== null && $request->query('estado') !== ''
                  ? (int) $request->query('estado') : null;
        $codigo = $request->query('codigo') !== null && $request->query('codigo') !== ''
                  ? $request->query('codigo') : null;

        $records = $this->service->historialGiros($codUsuario, $idAgencia, $desde, $hasta, $estado, $codigo);
        return $this->ok($records);
    }

    public function comprobantePdf(int $id)
    {
        $data = $this->service->obtenerComprobante($id);
        if (!$data) {
            return $this->error('Giro no encontrado', 404);
        }

        // TODO: generar PDF con DOMPDF igual que remesa
        return $this->ok($data);
    }

    public function facturaPdf(int $id)
    {
        // TODO: generar factura PDF
        return $this->ok(['id' => $id]);
    }
}
