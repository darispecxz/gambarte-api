<?php

namespace App\Modules\Recargas\Controllers;

use App\Modules\Recargas\Services\RecargasGateway;
use App\Modules\Recargas\Services\UatSimulationException;
use App\Modules\Recargas\Services\UatRecargasSimulator;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class RecargasController extends ApiController
{
    public function __construct(private RecargasGateway $gateway) {}

    private function execute(string $method, string $path, array $payload = []): JsonResponse
    {
        try {
            return $this->ok($this->gateway->request($method, $path, $payload));
        } catch (UatSimulationException $e) {
            return $this->error($e->getMessage(), 409);
        } catch (Throwable $e) {
            report($e);
            return $this->error('El servicio de recargas de Chile no está disponible o rechazó la operación. Verifique el estado antes de reintentar.', 502);
        }
    }

    private function operator(Request $request): array
    {
        $claims = (array) $request->attributes->get('jwt_claims', []);
        return [
            'operator' => (string) ($claims['user']['cod_usuario'] ?? ''),
            'agency' => (string) ($claims['agencia']['id_agencia'] ?? ''),
        ];
    }

    public function pendientes(): JsonResponse
    {
        return $this->execute('GET', '/api/integracion/recargas.php/pendientes');
    }

    public function modo(): JsonResponse
    {
        return $this->ok(['simulated' => $this->gateway->isSimulated()]);
    }

    public function reiniciarUat(UatRecargasSimulator $simulator): JsonResponse
    {
        try {
            $simulator->reset();
            return $this->ok(['reset' => true], 'Datos simulados reiniciados');
        } catch (UatSimulationException $e) {
            return $this->error($e->getMessage(), 403);
        }
    }

    public function lineas(): JsonResponse
    {
        return $this->execute('GET', '/api/integracion/recargas.php/lineas');
    }

    public function reservar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['request_id' => 'required|uuid']);
        return $this->execute('POST', "/api/integracion/recargas.php/{$id}/reservar", $data + $this->operator($request));
    }

    public function confirmar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'request_id' => 'required|uuid', 'token' => 'required|string|size:64',
            'id_linea' => 'required|integer|min:1', 'glosa' => 'nullable|string|max:240',
        ]);
        return $this->execute('POST', "/api/integracion/recargas.php/{$id}/confirmar", $data + $this->operator($request));
    }

    public function liberar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'token' => 'required|string|size:64']);
        return $this->execute('POST', "/api/integracion/recargas.php/{$id}/liberar", $data + $this->operator($request));
    }
}
