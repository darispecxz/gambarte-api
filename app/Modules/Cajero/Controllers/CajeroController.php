<?php

namespace App\Modules\Cajero\Controllers;

use App\Modules\Cajero\Services\CajeroSessionService;
use App\Modules\Cajero\Services\CajeroQueryService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CajeroController extends ApiController
{
    private CajeroQueryService $query;
    private CajeroSessionService $session;

    public function __construct(CajeroQueryService $query, CajeroSessionService $session)
    {
        $this->query   = $query;
        $this->session = $session;
    }

    private function claims(Request $request): array
    {
        return $request->attributes->get('jwt_claims');
    }

    public function misCajas(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];
        return $this->ok($this->query->misCajas($codUsuario, $idAgencia));
    }

    public function denominaciones(Request $request): JsonResponse
    {
        $idMoneda = $this->intParam($request, 'id_moneda');
        return $this->ok($this->query->denominaciones($idMoneda));
    }

    public function disponibilidad(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idCaja     = (int) $request->query('id_caja');
        $fecha      = $this->requireFecha($request);

        if (!$idCaja) {
            return $this->error('Parámetro id_caja es requerido', 400);
        }

        return $this->ok($this->query->disponibilidad($codUsuario, $idCaja, $fecha));
    }

    public function apertura(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja = (int) $request->input('id_caja');
        $montos = $request->input('montos', []);

        if (!$idCaja) {
            return $this->error('Parámetro id_caja es requerido', 400);
        }
        if (!is_array($montos)) {
            return $this->error('Parámetro montos debe ser un array', 400);
        }

        $result = $this->session->apertura(
            $codUsuario,
            $idAgencia,
            $idCaja,
            $montos,
            $request->ip()
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function cierre(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja = (int) $request->input('id_caja');
        $montos = $request->input('montos', []);

        if (!$idCaja) {
            return $this->error('Parámetro id_caja es requerido', 400);
        }
        if (!is_array($montos)) {
            return $this->error('Parámetro montos debe ser un array', 400);
        }

        $result = $this->session->cierre(
            $codUsuario,
            $idAgencia,
            $idCaja,
            $montos,
            $request->ip()
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }

    public function cierreConApertura(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja = (int) $request->input('id_caja');
        $montos = $request->input('montos', []);

        if (!$idCaja) {
            return $this->error('Parámetro id_caja es requerido', 400);
        }
        if (!is_array($montos)) {
            return $this->error('Parámetro montos debe ser un array', 400);
        }

        $result = $this->session->cierreConApertura(
            $codUsuario,
            $idAgencia,
            $idCaja,
            $montos,
            $request->ip()
        );

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok($result['data'], $result['message']);
    }
}
