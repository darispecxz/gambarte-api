<?php

namespace App\Modules\Notificaciones\Controllers;

use App\Modules\Notificaciones\Services\NotificacionService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacionController extends ApiController
{
    private NotificacionService $service;

    public function __construct()
    {
        $this->service = new NotificacionService();
    }

    public function listar(Request $request): JsonResponse
    {
        $filtros = [
            'tipo'     => $request->query('tipo'),
            'urgencia' => $request->query('urgencia'),
            'leida'    => $request->query('leida'),
            'agencia'  => $request->query('agencia'),
            'limit'    => $request->query('limit', 50),
            'offset'   => $request->query('offset', 0),
        ];
        return $this->ok($this->service->listar($filtros));
    }

    public function contarNoLeidas(Request $request): JsonResponse
    {
        $agencia = $this->intParam($request, 'agencia');
        return $this->ok($this->service->contarNoLeidas($agencia));
    }

    public function marcarLeida(Request $request): JsonResponse
    {
        $id = (int)$request->input('id');
        if (!$id) {
            return $this->error('Se requiere el id de la notificación', 422);
        }
        $usuario = $request->input('usuario', 'sistema');
        $this->service->marcarLeida($id, $usuario);
        return $this->ok(null, 'Notificación marcada como leída');
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $usuario = $request->input('usuario', 'sistema');
        $tipo = $request->input('tipo');
        $this->service->marcarTodasLeidas($usuario, $tipo);
        return $this->ok(null, 'Notificaciones marcadas como leídas');
    }

    public function generar(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        $codigo = $request->query('codigo');

        $results = [];
        $generadores = [
            'fondeo_remesas'         => fn() => $this->service->generarFondeoRemesas($fecha),
            'resumen_matutino'       => fn() => $this->service->generarResumenMatutino($fecha),
            'cierre_operativo'       => fn() => $this->service->generarCierreOperativo($fecha),
            'utilidad_diaria'        => fn() => $this->service->generarUtilidadDiaria($fecha),
            'cotizacion_mercado'     => fn() => $this->service->generarCotizacionFueraMercado(),
            'concentracion_efectivo' => fn() => $this->service->generarConcentracionEfectivo($fecha),
            'fondeo_sin_atender'     => fn() => $this->service->generarFondeoSinAtender(),
            'transferencia_inusual'  => fn() => $this->service->generarTransferenciaInusual($fecha),
            'cierre_tardio'          => fn() => $this->service->generarCierreTardio($fecha),
            'operacion_elevada'      => fn() => $this->service->generarOperacionElevada($fecha),
            'consumo_efectivo'       => fn() => $this->service->generarConsumoEfectivo($fecha),
            'brecha_cotizacion'      => fn() => $this->service->generarBrechaCotizacion(),
        ];

        if ($codigo && isset($generadores[$codigo])) {
            $results[$codigo] = $generadores[$codigo]();
        } else {
            foreach ($generadores as $key => $fn) {
                try {
                    $results[$key] = $fn();
                } catch (\Throwable $e) {
                    $results[$key] = 'error: ' . $e->getMessage();
                }
            }
        }

        return $this->ok($results, 'Notificaciones generadas');
    }
}
