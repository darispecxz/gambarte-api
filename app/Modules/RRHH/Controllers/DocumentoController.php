<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\DocumentoService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentoController extends ApiController
{
    private DocumentoService $documentos;

    public function __construct(DocumentoService $documentos)
    {
        $this->documentos = $documentos;
    }

    public function plantillas(): JsonResponse
    {
        return $this->ok($this->documentos->listarPlantillas());
    }

    public function generados(): JsonResponse
    {
        return $this->ok($this->documentos->listarGenerados());
    }

    public function generar(Request $request): JsonResponse
    {
        $plantilla = $request->input('plantilla');
        if (!$plantilla) {
            return $this->error('El campo "plantilla" es obligatorio');
        }

        try {
            $resultado = $this->documentos->generar($plantilla, $request->all());
            return $this->ok($resultado);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
    }
}
