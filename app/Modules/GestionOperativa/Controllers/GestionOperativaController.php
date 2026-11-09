<?php

namespace App\Modules\GestionOperativa\Controllers;

use App\Modules\GestionOperativa\Services\GestionOperativaService;
use App\Modules\GestionOperativa\Services\GastosOperativoService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GestionOperativaController extends ApiController
{
    private GestionOperativaService $service;
    private GastosOperativoService $gastosSvc;

    public function __construct()
    {
        $this->service = new GestionOperativaService();
        $this->gastosSvc = new GastosOperativoService();
    }

    public function consolidado(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok($this->service->consolidado($fecha));
    }

    public function alertas(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok($this->service->alertas($fecha));
    }

    public function recomendaciones(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok([
            'porLimites' => $this->service->recomendacionesFondeo($fecha),
            'porRemesas' => $this->service->fondeosPorRemesas($fecha),
        ]);
    }

    public function transferencias(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        $idAgencia = $this->intParam($request, 'agencia');
        return $this->ok($this->service->transferencias($fecha, $idAgencia));
    }

    public function solicitudesPendientes(): JsonResponse
    {
        return $this->ok($this->service->solicitudesPendientes());
    }

    public function resumenEjecutivo(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok($this->service->resumenEjecutivo($fecha));
    }

    public function detalleAgencia(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        $idAgencia = $this->intParam($request, 'agencia');
        if (!$idAgencia) {
            return $this->error('Se requiere el parámetro agencia', 422);
        }
        return $this->ok($this->service->detalleAgencia($fecha, $idAgencia));
    }

    public function conciliacionRemesas(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok($this->service->conciliacionRemesas($fecha));
    }

    public function movimientosDia(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok($this->service->movimientosDia($fecha));
    }

    public function cotizacionesAgencia(): JsonResponse
    {
        return $this->ok($this->service->cotizacionesAgencia());
    }

    public function gastosOperativo(Request $request): JsonResponse
    {
        $mes = trim($request->query('mes', date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            return $this->error("Parámetro 'mes' inválido (use AAAA-MM).", 422);
        }
        return $this->ok($this->gastosSvc->resumenMensual($mes));
    }

    public function gastosDetalleAgencia(Request $request): JsonResponse
    {
        $mes = trim($request->query('mes', date('Y-m')));
        $agencia = $request->query('agencia');
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            return $this->error("Parámetro 'mes' inválido.", 422);
        }
        if (!is_numeric($agencia)) {
            return $this->error("Parámetro 'agencia' inválido.", 422);
        }
        return $this->ok($this->gastosSvc->detalleGastosAgencia($mes, (int) $agencia));
    }

    public function gastosDetalleSubcuenta(Request $request): JsonResponse
    {
        $mes = trim($request->query('mes', date('Y-m')));
        $cod = trim($request->query('codsubcuenta', ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            return $this->error("Parámetro 'mes' inválido.", 422);
        }
        if (!preg_match('/^\d{5,}$/', $cod)) {
            return $this->error("Parámetro 'codsubcuenta' inválido.", 422);
        }
        return $this->ok($this->gastosSvc->detalleSubcuenta($mes, $cod));
    }
}
