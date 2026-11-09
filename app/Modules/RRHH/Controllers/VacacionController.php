<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\VacacionService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VacacionController extends ApiController
{
    private VacacionService $vacaciones;

    public function __construct(VacacionService $vacaciones)
    {
        $this->vacaciones = $vacaciones;
    }

    public function resumen(Request $request): JsonResponse
    {
        $gestion = (int) $request->query('ejercicio', date('Y'));
        $agencia = $request->query('agencia') ? (int) $request->query('agencia') : null;
        return $this->ok($this->vacaciones->resumen($gestion, $agencia));
    }

    public function cronograma(Request $request): JsonResponse
    {
        $gestion = (int) $request->query('ejercicio', date('Y'));
        return $this->ok($this->vacaciones->cronograma($gestion));
    }

    public function solicitar(Request $request): JsonResponse
    {
        $gestion = (int) $request->query('ejercicio', date('Y'));

        $data = [
            'id_empleado'   => $request->input('id_empleado'),
            'fecha_inicio'  => $request->input('fecha_inicio'),
            'fecha_fin'     => $request->input('fecha_fin'),
            'tipo'          => $request->input('tipo', 'VACACION'),
            'observaciones' => $request->input('observaciones'),
        ];

        $result = $this->vacaciones->solicitar($data, $gestion);

        if (isset($result['error'])) {
            return $this->error($result['error'], 422);
        }

        return $this->ok($result, 'Solicitud registrada correctamente.');
    }

    public function aprobar(int $id): JsonResponse
    {
        $result = $this->vacaciones->aprobar($id);
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result, 'Vacación aprobada.');
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $motivo = $request->input('motivo');
        $result = $this->vacaciones->rechazar($id, $motivo);
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result, 'Vacación rechazada.');
    }
}
