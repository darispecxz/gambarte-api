<?php

namespace App\Modules\Cajero\Controllers;

use App\Modules\Cajero\Services\PosUsuarioPreferenciaService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosCuentaController extends ApiController
{
    public function __construct(private PosUsuarioPreferenciaService $preferencias) {}
    private function usuario(Request $request): int { return (int) ($request->attributes->get('jwt_claims')['user']['cod_usuario'] ?? 0); }
    public function mostrar(Request $request): JsonResponse { return $this->ok($this->preferencias->obtener($this->usuario($request))); }
    public function guardar(Request $request): JsonResponse
    {
        $config = $request->input('configuracion', []);
        if (!is_array($config)) return $this->error('La configuración debe ser un objeto.', 422);
        return $this->ok($this->preferencias->guardar($this->usuario($request), $config), 'Preferencias guardadas.');
    }
    public function subirFirma(Request $request): JsonResponse
    {
        $file = $request->file('firma');
        if (!$file) return $this->error('Seleccione una firma.', 422);
        try { return $this->ok($this->preferencias->subirFirma($this->usuario($request), $file), 'Firma digital actualizada.'); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }
}
