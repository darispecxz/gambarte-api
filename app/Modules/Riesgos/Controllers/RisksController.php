<?php

namespace App\Modules\Riesgos\Controllers;

use App\Modules\Riesgos\Services\RiskPanelService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RisksController extends ApiController
{
    private RiskPanelService $service;

    public function __construct(RiskPanelService $service)
    {
        $this->service = $service;
    }

    public function index(): JsonResponse
    {
        return $this->ok($this->service->catalog());
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $this->validateSlug($slug);
        [$ini, $fin] = $this->resolvePeriodo($request);

        return $this->ok([
            'slug' => $slug, 'ini' => $ini, 'fin' => $fin,
            'informe' => $this->service->generate($slug, $ini, $fin)['data'],
        ]);
    }

    public function pdf(Request $request, string $slug)
    {
        $this->validateSlug($slug);
        [$ini, $fin] = $this->resolvePeriodo($request);

        $bin  = $this->service->pdf($slug, $ini, $fin);
        $name = $this->service->pdfFilename($slug, $ini, $fin);
        $disp = $request->query('disposition') === 'inline' ? 'inline' : 'attachment';

        return response($bin, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "{$disp}; filename=\"{$name}\"",
            'Content-Length'      => strlen($bin),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        [$ini, $fin] = $this->resolvePeriodo($request);
        $forceRefresh = $request->has('recalcular');

        return $this->ok($this->service->dashboard($ini, $fin, $forceRefresh));
    }

    private function validateSlug(string $slug): void
    {
        if (!in_array($slug, $this->service->slugs(), true)) {
            abort(422, "Valor inválido para 'slug'. Permitidos: " . implode(', ', $this->service->slugs()) . '.');
        }
    }

    private function resolvePeriodo(Request $request): array
    {
        $preset = $request->query('preset', 'mes');
        $hoy = date('Y-m-d');

        if ($preset === 'dia') {
            $f = $this->validDate($request->query('fecha')) ?? $hoy;
            return [$f, $f];
        }
        if ($preset === 'rango') {
            $i = $this->validDate($request->query('ini')) ?? date('Y-m-01');
            $f = $this->validDate($request->query('fin')) ?? $hoy;
            if ($i > $f) { $t = $i; $i = $f; $f = $t; }
            return [$i, $f];
        }

        $f = $this->validDate($request->query('fin')) ?? $hoy;
        $i = date('Y-m-01', strtotime($f));
        return [$i, $f];
    }

    private function validDate(?string $s): ?string
    {
        if ($s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false) {
            return $s;
        }
        return null;
    }
}
