<?php

namespace App\Modules\Auditoria\Controllers;

use App\Modules\Auditoria\Services\ReportesService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogsController extends ApiController
{
    private ReportesService $service;

    public function __construct(ReportesService $service)
    {
        $this->service = $service;
    }

    public function index(): JsonResponse
    {
        return $this->ok($this->service->catalog());
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $this->validateCode($code);
        $periodo = $this->requirePeriod($request);

        return $this->ok($this->service->data($code, $periodo));
    }

    public function excel(Request $request, string $code)
    {
        $this->validateCode($code);
        $periodo = $this->requirePeriod($request);

        $bin  = $this->service->excel($code, $periodo);
        $name = $this->service->excelFilename($code, $periodo);
        $disp = $request->query('disposition') === 'inline' ? 'inline' : 'attachment';

        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "{$disp}; filename=\"{$name}\"",
            'Content-Length'      => strlen($bin),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function zip(Request $request)
    {
        $periodo = $this->requirePeriod($request);

        $bin  = $this->service->zip($periodo);
        $name = $this->service->zipFilename($periodo);

        return response($bin, 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'Content-Length'      => strlen($bin),
            'Cache-Control'       => 'no-store',
        ]);
    }

    private function validateCode(string $code): void
    {
        if (!in_array($code, $this->service->codes(), true)) {
            abort(422, "Valor inválido para 'code'. Permitidos: " . implode(', ', $this->service->codes()) . '.');
        }
    }

    private function requirePeriod(Request $request): string
    {
        $v = trim($request->query('periodo', ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $v)) {
            abort(422, "Parámetro 'periodo' inválido (use AAAA-MM).");
        }
        return $v;
    }
}
