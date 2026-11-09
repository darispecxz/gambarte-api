<?php

namespace App\Modules\Manuales\Controllers;

use App\Modules\Manuales\Services\ManualLecturaService;
use App\Modules\Shared\Database\RawConnection;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ManualLecturaController extends ApiController
{
    private const MANUALES = [
        'apertura_caja', 'cierre_caja', 'compra_moneda', 'venta_moneda', 'reportes_cambio',
        'enviar_giro_internacional', 'reportes_giros', 'traspaso_cajero', 'operaciones_boveda',
        'cuentas_bancarias', 'traspasos_bancarios', 'depositos_retiros', 'movimientos_bancarios',
        'cheques', 'conciliacion', 'egreso_directo', 'ingreso_directo', 'egreso_autorizado',
        'ingreso_autorizado', 'transaccion_bancaria', 'pago_remesa_efectivo',
        'pago_remesa_deposito', 'reporte_pago_remesas',
    ];
    private ManualLecturaService $service;

    public function __construct()
    {
        $this->service = new ManualLecturaService();
    }

    public function mias(Request $request): JsonResponse
    {
        $claims = $request->attributes->get('jwt_claims', []);
        return $this->ok($this->service->listarMias(
            (int)($claims['user']['cod_usuario'] ?? 0),
            (int)($claims['agencia']['id_agencia'] ?? 0)
        ));
    }

    public function marcarLeido(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'manual_key' => 'required|string|max:80',
            'version' => 'required|in:1',
        ]);
        if (!in_array($validated['manual_key'], self::MANUALES, true)) {
            return $this->error('El manual indicado no es válido.', 422);
        }
        $claims = $request->attributes->get('jwt_claims', []);
        $userId = (int)($claims['user']['cod_usuario'] ?? 0);
        if (!$userId) return $this->error('No se pudo identificar al usuario.', 401);

        return $this->ok($this->service->marcarLeido(
            $userId,
            (int)($claims['agencia']['id_agencia'] ?? 0),
            $validated['manual_key'],
            $validated['version']
        ), 'Lectura registrada');
    }

    public function seguimiento(Request $request): JsonResponse
    {
        $manual = $request->query('manual', 'apertura_caja');
        $agencia = $request->query('agencia');
        return $this->ok($this->service->seguimiento(
            is_string($manual) && $manual !== '' ? $manual : 'apertura_caja',
            is_numeric($agencia) ? (int)$agencia : null
        ));
    }

    public function videoInfo(string $manualKey): JsonResponse
    {
        $this->validarManual($manualKey);
        $video = RawConnection::selectOne(
            'SELECT nombre_original, tamano_bytes, actualizado_en FROM pos_manual_video WHERE manual_key = :manual LIMIT 1',
            [':manual' => $manualKey]
        );

        return $this->ok([
            'disponible' => (bool)$video,
            'nombre' => $video['nombre_original'] ?? null,
            'tamano_bytes' => isset($video['tamano_bytes']) ? (int)$video['tamano_bytes'] : null,
            'actualizado_en' => $video['actualizado_en'] ?? null,
            'max_size_mb' => max(1, (int)config('manuales.video_max_mb', 10)),
        ]);
    }

    /** Solo el rol administrador (2) puede reemplazar el video de un manual. */
    public function subirVideo(Request $request): JsonResponse
    {
        $videoRecibido = $request->file('video');
        if ($videoRecibido && method_exists($videoRecibido, 'isValid') && !$videoRecibido->isValid()) {
            $errorCarga = $videoRecibido->getError();
            if ($errorCarga === UPLOAD_ERR_INI_SIZE || $errorCarga === UPLOAD_ERR_FORM_SIZE) {
                return $this->error('PHP rechazó el video antes de validarlo. Aumenta upload_max_filesize y post_max_size en la configuración PHP del servidor para permitir archivos de hasta ' . max(1, (int)config('manuales.video_max_mb', 10)) . ' MB.', 413);
            }
            return $this->error('PHP no pudo recibir el video (código de carga ' . (int)$errorCarga . '). Revisa el espacio temporal y la configuración de carga del servidor.', 400);
        }

        $validated = $request->validate([
            'manual_key' => 'required|string|max:80',
            'video' => 'required|file|mimes:mp4|max:' . (max(1, (int)config('manuales.video_max_mb', 10)) * 1024),
        ]);
        $manualKey = $validated['manual_key'];
        $this->validarManual($manualKey);

        $claims = $request->attributes->get('jwt_claims', []);
        $userId = (int)($claims['user']['cod_usuario'] ?? 0);
        if (!$userId) return $this->error('No se pudo identificar al administrador.', 401);

        $file = $request->file('video');
        if (!$file || !$file->isValid()) return $this->error('El video no se recibió correctamente.', 400);

        $directorio = storage_path('app/manuales/videos');
        if (!is_dir($directorio) && !@mkdir($directorio, 0750, true) && !is_dir($directorio)) {
            return $this->error('No se pudo preparar el almacenamiento de videos.', 500);
        }

        $nombreGuardado = $manualKey . '_' . bin2hex(random_bytes(12)) . '.mp4';
        $nombreOriginal = mb_substr($file->getClientOriginalName(), 0, 255);
        $tamano = (int)$file->getSize();
        $destino = $directorio . DIRECTORY_SEPARATOR . $nombreGuardado;
        $rutaAnterior = null;

        try {
            $file->move($directorio, $nombreGuardado);
            $anterior = RawConnection::selectOne(
                'SELECT archivo_path FROM pos_manual_video WHERE manual_key = :manual LIMIT 1',
                [':manual' => $manualKey]
            );
            $rutaAnterior = $anterior['archivo_path'] ?? null;
            RawConnection::execute(
                'INSERT INTO pos_manual_video (manual_key, archivo_path, nombre_original, mime_type, tamano_bytes, cod_usuario_actualiza, creado_en, actualizado_en)
                 VALUES (:manual, :path, :nombre, :mime, :tamano, :usuario, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE archivo_path = VALUES(archivo_path), nombre_original = VALUES(nombre_original),
                     mime_type = VALUES(mime_type), tamano_bytes = VALUES(tamano_bytes),
                     cod_usuario_actualiza = VALUES(cod_usuario_actualiza), actualizado_en = NOW()',
                [
                    ':manual' => $manualKey,
                    ':path' => 'manuales/videos/' . $nombreGuardado,
                    ':nombre' => $nombreOriginal,
                    ':mime' => 'video/mp4',
                    ':tamano' => $tamano,
                    ':usuario' => $userId,
                ]
            );
        } catch (\Throwable $error) {
            if (is_file($destino)) @unlink($destino);
            throw $error;
        }

        if ($rutaAnterior && strpos($rutaAnterior, 'manuales/videos/') === 0) {
            $pathAnterior = storage_path('app/' . $rutaAnterior);
            if (is_file($pathAnterior)) @unlink($pathAnterior);
        }

        return $this->ok([
            'manual_key' => $manualKey,
            'nombre' => $nombreOriginal,
            'tamano_bytes' => $tamano,
            'actualizado_en' => date('Y-m-d H:i:s'),
        ], 'Video asociado al manual.');
    }

    /** Devuelve el video asociado al manual usando el almacenamiento del backend. */
    public function video(string $manualKey): BinaryFileResponse
    {
        $this->validarManual($manualKey);
        $registro = RawConnection::selectOne(
            'SELECT archivo_path, nombre_original FROM pos_manual_video WHERE manual_key = :manual LIMIT 1',
            [':manual' => $manualKey]
        );
        abort_unless($registro, 404, 'Este manual todavía no tiene un video asociado.');
        $archivoPath = (string)$registro['archivo_path'];
        abort_unless(strpos($archivoPath, 'manuales/videos/') === 0 && strpos($archivoPath, '..') === false, 404);

        $ruta = storage_path('app/' . $archivoPath);
        abort_unless(is_file($ruta) && is_readable($ruta), 404, 'Video no encontrado.');

        return response()->file($ruta, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="' . $manualKey . '.mp4"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function validarManual(string $manualKey): void
    {
        abort_unless(in_array($manualKey, self::MANUALES, true), 404, 'Manual no encontrado.');
    }
}
