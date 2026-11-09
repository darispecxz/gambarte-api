<?php

namespace App\Modules\Cripto\Controllers;

use App\Modules\Cripto\Services\CriptoService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CriptoController extends ApiController
{
    public function __construct(private CriptoService $service) {}

    private function claims(Request $request): array
    {
        return (array) $request->attributes->get('jwt_claims', []);
    }

    private function scope(Request $request): array
    {
        $c = $this->claims($request);
        return [
            (int) ($c['user']['cod_usuario'] ?? 0),
            (int) ($c['agencia']['id_agencia'] ?? 0),
            (int) ($c['rol']['id_rol'] ?? 0),
        ];
    }

    private function respond(array $r): JsonResponse
    {
        return ($r['success'] ?? false)
            ? $this->ok($r['data'] ?? null, $r['message'] ?? null)
            : $this->error($r['message'] ?? 'No se pudo completar la operación', $r['status'] ?? 422);
    }

    public function catalogo(Request $request): JsonResponse
    {
        [, $agency, $role] = $this->scope($request);
        return $this->ok($this->service->catalogo($agency, $role === 2));
    }

    public function binancePayQr(): JsonResponse
    {
        return $this->ok(['qr_data_url' => $this->service->binancePayQrDataUrl()]);
    }

    public function binancePayHistory(Request $request): JsonResponse
    {
        [$user, $agency, $role] = $this->scope($request);
        return $this->ok($this->service->binancePayHistory($user, $agency, $role));
    }

    public function binancePaySyncStatus(): JsonResponse
    {
        return $this->ok($this->service->binancePaySyncStatus());
    }

    public function binancePayPendingOperations(Request $request): JsonResponse
    {
        [, $agency] = $this->scope($request);
        return $this->ok($this->service->binancePayPendingOperations($agency));
    }

    public function vincularBinancePayManual(Request $request): JsonResponse
    {
        [$user, $agency] = $this->scope($request);
        $input = $request->validate(['transaction_id' => 'required|string|max:64', 'id_operacion' => 'required|integer|min:1']);
        return $this->respond($this->service->vincularBinancePayManual($input['transaction_id'], (int) $input['id_operacion'], $user, $agency));
    }

    public function guardarBinancePayQr(Request $request): JsonResponse
    {
        [$user] = $this->scope($request);
        $request->validate([
            'qr' => 'required|file|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);
        $file = $request->file('qr');
        $result = $this->service->guardarBinancePayQr($file->get(), $file->getMimeType(), $user);
        return $this->respond($result);
    }

    public function cajas(Request $request): JsonResponse
    {
        [$user, $agency] = $this->scope($request);
        return $this->ok($this->service->cajas($user, $agency));
    }

    public function listar(Request $request): JsonResponse
    {
        [$user, $agency, $role] = $this->scope($request);
        return $this->ok($this->service->listar($user, $agency, $role, [
            'desde' => $request->query('desde'), 'hasta' => $request->query('hasta'),
            'estado' => $request->query('estado'), 'tipo' => $request->query('tipo'),
        ]));
    }

    public function detalle(Request $request, int $id): JsonResponse
    {
        [$user, $agency, $role] = $this->scope($request);
        $r = $this->service->detalle($id, $user, $agency, $role);
        return $r ? $this->ok($r) : $this->error('Operación no encontrada', 404);
    }

    public function crear(Request $request): JsonResponse
    {
        [$user, $agency] = $this->scope($request);
        $input = $request->validate([
            'uuid' => 'required|uuid', 'tipo' => 'required|in:COMPRA,VENTA',
            'id_activo' => 'required|integer|min:1', 'id_caja' => 'required|integer|min:1',
            'cantidad_cripto' => 'required|numeric|gt:0', 'direccion_cliente' => 'nullable|string|max:180',
            'concepto' => 'nullable|string|max:240',
            'codigo_referencia' => ['nullable', 'string', 'regex:/^GB-[0-9]{5}[A-HJ-NP-Z]$/'],
        ]);
        return $this->respond($this->service->crear($input, $user, $agency, $request->ip()));
    }

    public function guardarActivo(Request $request): JsonResponse
    {
        [$user] = $this->scope($request);
        $input = $request->validate([
            'id' => 'nullable|integer|min:1', 'simbolo' => 'required|string|max:16',
            'nombre' => 'required|string|max:80', 'red' => 'required|string|max:48',
            'contrato' => 'nullable|string|max:128', 'decimales' => 'required|integer|min:0|max:18',
            'tasa_compra_bob' => 'required|numeric|gt:0', 'tasa_venta_bob' => 'required|numeric|gt:0',
            'comision_porcentaje' => 'required|numeric|min:0|max:100',
            'direccion_recepcion' => 'nullable|string|max:180', 'activo' => 'required|boolean',
        ]);
        return $this->respond($this->service->guardarActivo($input, $user));
    }

    public function ajustarInventario(Request $request, int $id): JsonResponse
    {
        [$user] = $this->scope($request);
        $input = $request->validate([
            'cantidad' => 'required|numeric|not_in:0', 'motivo' => 'required|string|min:5|max:500',
        ]);
        return $this->respond($this->service->ajustarInventario($id, (float) $input['cantidad'], $input['motivo'], $user));
    }

    public function confirmarRecepcion(Request $request, int $id): JsonResponse
    {
        [$user, $agency, $role] = $this->scope($request);
        $input = $request->validate(['referencia' => 'nullable|string|min:4|max:180', 'transaction_id' => 'nullable|string|min:8|max:64']);
        if (!empty($input['transaction_id'])) {
            return $this->respond($this->service->confirmarRecepcionBinancePay($id, $input['transaction_id'], $user, $agency));
        }
        if ($role !== 2 || empty($input['referencia'])) return $this->error('Para confirmar Binance Pay vincula el transaction_id; la recepción manual on-chain requiere rol ADMIN y referencia', 403);
        return $this->respond($this->service->confirmarRecepcion($id, $input['referencia'], $user));
    }

    public function confirmarCobro(Request $request, int $id): JsonResponse
    {
        [$user, $agency] = $this->scope($request);
        return $this->respond($this->service->confirmarCobro($id, $user, $agency));
    }

    public function pagarBob(Request $request, int $id): JsonResponse
    {
        [$user, $agency] = $this->scope($request);
        return $this->respond($this->service->pagarBob($id, $user, $agency));
    }

    public function confirmarEnvio(Request $request, int $id): JsonResponse
    {
        [$user] = $this->scope($request);
        $input = $request->validate(['referencia' => 'required|string|min:4|max:180']);
        return $this->respond($this->service->confirmarEnvio($id, $input['referencia'], $user));
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        [$user] = $this->scope($request);
        $input = $request->validate(['motivo' => 'required|string|min:5|max:500']);
        return $this->respond($this->service->rechazar($id, $input['motivo'], $user));
    }

    public function cancelar(Request $request, int $id): JsonResponse
    {
        [$user, $agency] = $this->scope($request);
        $input = $request->validate(['motivo' => 'required|string|min:5|max:500']);
        return $this->respond($this->service->cancelar($id, $input['motivo'], $user, $agency));
    }
}
