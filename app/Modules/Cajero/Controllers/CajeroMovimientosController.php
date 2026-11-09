<?php

namespace App\Modules\Cajero\Controllers;

use App\Modules\Cajero\Services\CajeroMovimientosService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CajeroMovimientosController extends ApiController
{
    private CajeroMovimientosService $service;

    public function __construct(CajeroMovimientosService $service)
    {
        $this->service = $service;
    }

    private function claims(Request $request): array
    {
        return $request->attributes->get('jwt_claims');
    }

    public function solicitarFondos(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja   = (int) $request->input('id_caja');
        $monto    = (float) $request->input('monto');
        $concepto = trim($request->input('concepto', ''));

        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);

        $result = $this->service->solicitarFondos($codUsuario, $idAgencia, $idCaja, $monto, $concepto, (array) $request->input('cortes', []), $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function traspasoBoveda(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja   = (int) $request->input('id_caja');
        $monto    = (float) $request->input('monto');
        $concepto = trim($request->input('concepto', ''));

        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);

        $result = $this->service->traspasoBoveda($codUsuario, $idAgencia, $idCaja, $monto, $concepto, (array) $request->input('cortes', []), $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function traspasoCajero(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCajaOrigen  = (int) $request->input('id_caja_origen');
        $idCajaDestino = (int) $request->input('id_caja_destino');
        $monto         = (float) $request->input('monto');
        $concepto      = trim($request->input('concepto', ''));

        if (!$idCajaOrigen) return $this->error('id_caja_origen es requerido', 400);
        if (!$idCajaDestino) return $this->error('id_caja_destino es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);

        $result = $this->service->traspasoCajero($codUsuario, $idAgencia, $idCajaOrigen, $idCajaDestino, $monto, $concepto, (array) $request->input('cortes', []), $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function transferenciaBoveda(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idBovedaOrigen  = (int) $request->input('id_boveda_origen');
        $idBovedaDestino = (int) $request->input('id_boveda_destino');
        $monto           = (float) $request->input('monto');
        $concepto        = trim($request->input('concepto', ''));

        if (!$idBovedaOrigen) return $this->error('id_boveda_origen es requerido', 400);
        if (!$idBovedaDestino) return $this->error('id_boveda_destino es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);

        $result = $this->service->transferenciaBoveda($codUsuario, $idAgencia, $idBovedaOrigen, $idBovedaDestino, $monto, $concepto, (array) $request->input('cortes', []), $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    /** Transferencia de una caja a la bóveda de una agencia (la propia u otra). */
    public function transferenciaCajaAgencia(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja           = (int) $request->input('id_caja');
        $idAgenciaDestino = (int) $request->input('id_agencia_destino');
        $idBovedaDestino  = $request->filled('id_boveda_destino') ? (int) $request->input('id_boveda_destino') : null;
        $monto            = (float) $request->input('monto');
        $concepto         = trim($request->input('concepto', ''));

        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        if (!$idAgenciaDestino) return $this->error('id_agencia_destino es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);

        $result = $this->service->transferenciaCajaAgencia(
            $codUsuario, $idAgencia, $idCaja, $idAgenciaDestino, $monto, $concepto,
            (array) $request->input('cortes', []), $request->ip(), $idBovedaDestino
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function egreso(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja       = (int) $request->input('id_caja');
        $monto        = (float) $request->input('monto');
        $concepto     = trim($request->input('concepto', ''));
        $origen       = trim($request->input('origen', 'caja'));
        $beneficiario = trim($request->input('beneficiario', ''));
        $categoria    = trim($request->input('categoria', 'otros'));

        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);
        if (!$concepto) return $this->error('concepto es requerido', 400);
        if (!in_array($origen, ['caja', 'banca'])) return $this->error('origen debe ser caja o banca', 400);

        $result = $this->service->egreso($codUsuario, $idAgencia, $idCaja, $monto, $concepto, $request->ip(), $origen, $beneficiario, $categoria);
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function subirComprobanteEgreso(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idEgreso   = (int) $request->input('id_egreso');

        if (!$idEgreso) return $this->error('id_egreso es requerido', 400);

        $file = $request->file('comprobante');
        if (!$file || !$file->isValid()) {
            return $this->error('Archivo de comprobante es requerido', 400);
        }

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExt)) {
            return $this->error('Formato no permitido. Use: ' . implode(', ', $allowedExt), 400);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->error('El archivo no debe superar 5MB', 400);
        }

        $fileName = $file->getClientOriginalName();
        $storedName = 'egreso_' . $idEgreso . '_' . time() . '.' . $ext;
        $file->move(storage_path('app/comprobantes/egresos'), $storedName);
        $filePath = 'comprobantes/egresos/' . $storedName;

        $rol = $claims['rol']['id_rol'] ?? 0;
        $esCajero = in_array((int) $rol, [1, 4, 6]);

        $result = $this->service->subirComprobanteEgreso($idEgreso, $filePath, $fileName, $codUsuario, $esCajero ? 'cajero' : 'contador');
        return $result['success'] ? $this->ok(['path' => $filePath], $result['message']) : $this->error($result['message'], 422);
    }

    public function subirComprobanteIngreso(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $codUsuario = (int) $claims['user']['cod_usuario'];
        $idAgencia = (int) $claims['agencia']['id_agencia'];
        $uuid = trim((string) $request->input('uuid'));
        $file = $request->file('comprobante');

        if (!$uuid) return $this->error('uuid es requerido', 400);
        if (!$file || !$file->isValid()) return $this->error('Archivo de comprobante es requerido', 400);

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExt, true)) {
            return $this->error('Formato no permitido. Use: ' . implode(', ', $allowedExt), 400);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->error('El archivo no debe superar 5MB', 400);
        }

        $directory = storage_path('app/comprobantes/ingresos');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return $this->error('No se pudo preparar el almacenamiento del comprobante', 500);
        }

        $fileName = $file->getClientOriginalName();
        $storedName = 'ingreso_' . preg_replace('/[^a-zA-Z0-9-]/', '', $uuid) . '_' . time() . '.' . $ext;
        $file->move($directory, $storedName);
        $filePath = 'comprobantes/ingresos/' . $storedName;

        $result = $this->service->subirComprobanteIngreso($uuid, $filePath, $fileName, $codUsuario, $idAgencia);
        if (!$result['success']) {
            $storedPath = $directory . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($storedPath)) unlink($storedPath);
            return $this->error($result['message'], 422);
        }

        return $this->ok(['path' => $filePath], $result['message']);
    }

    public function subirComprobanteTransaccionBancaria(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $codUsuario = (int) $claims['user']['cod_usuario'];
        $idAgencia = (int) $claims['agencia']['id_agencia'];
        $uuid = trim((string) $request->input('uuid'));
        $file = $request->file('comprobante');

        if (!$uuid) return $this->error('uuid es requerido', 400);
        if (!$file || !$file->isValid()) return $this->error('Archivo de comprobante es requerido', 400);

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, $allowedExt, true)) return $this->error('Formato no permitido. Use: ' . implode(', ', $allowedExt), 400);
        if ($file->getSize() > 5 * 1024 * 1024) return $this->error('El archivo no debe superar 5MB', 400);

        $directory = storage_path('app/comprobantes/transacciones-bancarias');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return $this->error('No se pudo preparar el almacenamiento del comprobante', 500);
        }

        $fileName = $file->getClientOriginalName();
        $storedName = 'transaccion_' . preg_replace('/[^a-zA-Z0-9-]/', '', $uuid) . '_' . time() . '.' . $ext;
        $file->move($directory, $storedName);
        $filePath = 'comprobantes/transacciones-bancarias/' . $storedName;

        $result = $this->service->subirComprobanteTransaccionBancaria($uuid, $filePath, $fileName, $codUsuario, $idAgencia);
        if (!$result['success']) {
            $storedPath = $directory . DIRECTORY_SEPARATOR . $storedName;
            if (is_file($storedPath)) unlink($storedPath);
            return $this->error($result['message'], 422);
        }

        return $this->ok(['path' => $filePath], $result['message']);
    }

    public function egresosPendientes(Request $request): JsonResponse
    {
        $claims    = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];
        $desde     = $this->optionalFecha($request, 'desde');
        $hasta     = $this->optionalFecha($request, 'hasta');

        return $this->ok($this->service->egresosPendientes($idAgencia, $desde, $hasta));
    }

    public function movimientosCaja(Request $request): JsonResponse
    {
        $claims = $this->claims($request);
        $idCaja = (int) $request->query('id_caja');
        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        $desde = $this->optionalFecha($request, 'desde') ?: date('Y-m-01');
        $hasta = $this->optionalFecha($request, 'hasta') ?: date('Y-m-d');
        if ($desde > $hasta) return $this->error('La fecha desde no puede ser posterior a hasta', 422);

        $result = $this->service->movimientosCaja(
            $idCaja,
            (int) $claims['user']['cod_usuario'],
            (int) $claims['agencia']['id_agencia'],
            $desde,
            $hasta
        );
        return $result['success'] ? $this->ok($result['data']) : $this->error($result['message'], 403);
    }

    public function aprobarEgreso(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];
        $idEgreso   = (int) $request->input('id_egreso');
        $obs        = trim($request->input('observaciones', ''));

        if (!$idEgreso) return $this->error('id_egreso es requerido', 400);

        $result = $this->service->aprobarEgreso($idEgreso, $codUsuario, $idAgencia, $obs, $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function rechazarEgreso(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];
        $idEgreso   = (int) $request->input('id_egreso');
        $obs        = trim($request->input('observaciones', ''));

        if (!$idEgreso) return $this->error('id_egreso es requerido', 400);
        if (!$obs) return $this->error('Debe indicar el motivo del rechazo', 400);

        $result = $this->service->rechazarEgreso($idEgreso, $codUsuario, $idAgencia, $obs, $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function ingreso(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja   = (int) $request->input('id_caja');
        $monto    = (float) $request->input('monto');
        $concepto = trim($request->input('concepto', ''));
        $origen   = trim((string) $request->input('origen_ingreso', 'efectivo'));
        $entregadoPor = trim((string) $request->input('entregado_por', ''));
        $categoria = trim((string) $request->input('categoria', 'otros'));

        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);
        if (!$concepto) return $this->error('concepto es requerido', 400);
        if (!in_array($origen, ['efectivo', 'transferencia', 'deposito', 'devolucion', 'otros'], true)) {
            return $this->error('origen_ingreso no es válido', 400);
        }
        if (!in_array($categoria, ['ventas', 'servicios', 'transferencias', 'devoluciones', 'otros'], true)) {
            return $this->error('categoria no es válida', 400);
        }
        if (mb_strlen($entregadoPor) > 200) return $this->error('entregado_por no debe superar 200 caracteres', 400);

        $result = $this->service->ingreso($codUsuario, $idAgencia, $idCaja, $monto, $concepto, $request->ip(), $origen, $entregadoPor, $categoria);
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function transaccionBancaria(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja     = (int) $request->input('id_caja');
        $tipo       = trim($request->input('tipo', ''));
        $monto      = (float) $request->input('monto');
        $referencia = trim($request->input('referencia', ''));
        $concepto   = trim($request->input('concepto', ''));

        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        if (!$tipo) return $this->error('tipo es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);
        if (mb_strlen($concepto) < 3) return $this->error('concepto debe tener al menos 3 caracteres', 400);

        $result = $this->service->transaccionBancaria($codUsuario, $idAgencia, $idCaja, $tipo, $monto, $referencia, $concepto, $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function buscarAsientoAutorizado(Request $request): JsonResponse
    {
        $tipo   = strtoupper(trim($request->query('tipo', '')));
        $numero = (int) $request->query('numero');

        if (!in_array($tipo, ['E', 'I'])) return $this->error('tipo debe ser E o I', 400);
        if ($numero <= 0) return $this->error('numero es requerido', 400);

        $result = $this->service->buscarAsientoAutorizado($tipo, $numero);
        return $result['success'] ? $this->ok($result['data']) : $this->error($result['message'], 422);
    }

    public function completarEgresoAutorizado(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja   = (int) $request->input('id_caja');
        $numero   = (int) $request->input('numero');
        $glosa    = trim($request->input('glosa', ''));
        $monto    = (float) $request->input('monto');
        $idMoneda = (int) $request->input('id_moneda');

        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        if (!$numero) return $this->error('numero es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);
        if (!$idMoneda) return $this->error('id_moneda es requerido', 400);

        $result = $this->service->completarEgresoAutorizado($codUsuario, $idAgencia, $idCaja, $numero, $glosa, $monto, $idMoneda, $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function completarIngresoAutorizado(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];

        $idCaja   = (int) $request->input('id_caja');
        $numero   = (int) $request->input('numero');
        $glosa    = trim($request->input('glosa', ''));
        $monto    = (float) $request->input('monto');
        $idMoneda = (int) $request->input('id_moneda');

        if (!$idCaja) return $this->error('id_caja es requerido', 400);
        if (!$numero) return $this->error('numero es requerido', 400);
        if ($monto <= 0) return $this->error('monto debe ser mayor a 0', 400);
        if (!$idMoneda) return $this->error('id_moneda es requerido', 400);

        $result = $this->service->completarIngresoAutorizado($codUsuario, $idAgencia, $idCaja, $numero, $glosa, $monto, $idMoneda, $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function cajasDestino(Request $request): JsonResponse
    {
        $claims     = $this->claims($request);
        $codUsuario = $claims['user']['cod_usuario'];
        $idAgencia  = $claims['agencia']['id_agencia'];
        $idMoneda   = (int) $request->query('id_moneda');

        if (!$idMoneda) return $this->error('id_moneda es requerido', 400);

        return $this->ok($this->service->cajasDestinoTraspaso($codUsuario, $idAgencia, $idMoneda));
    }

    public function bovedasDisponibles(Request $request): JsonResponse
    {
        $claims    = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];

        return $this->ok($this->service->bovedasDisponibles($idAgencia));
    }

    public function cuentasBancariasCobro(Request $request): JsonResponse
    {
        $claims    = $this->claims($request);
        $idAgencia = $claims['agencia']['id_agencia'];

        return $this->ok($this->service->cuentasBancariasCobro($idAgencia));
    }
}
