<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;
use Illuminate\Http\UploadedFile;

class PosUsuarioPreferenciaService
{
    private const DEFAULTS = ['apariencia' => 'system', 'inicio' => 'dashboard', 'menu_compacto' => false];

    public function obtener(int $usuario): array
    {
        $row = DB::selectOne('SELECT configuracion_json, firma_nombre, firma_mime, firma_actualizada_en FROM pos_usuario_preferencia WHERE cod_usuario=:u', [':u' => $usuario]);
        $config = $row ? json_decode((string) $row['configuracion_json'], true) : [];
        return [
            'configuracion' => array_replace(self::DEFAULTS, is_array($config) ? $config : []),
            'firma' => $row && $row['firma_nombre'] ? ['nombre' => $row['firma_nombre'], 'mime' => $row['firma_mime'], 'actualizada_en' => $row['firma_actualizada_en']] : null,
        ];
    }

    public function guardar(int $usuario, array $configuracion): array
    {
        $config = array_replace(self::DEFAULTS, $configuracion);
        $config['apariencia'] = in_array($config['apariencia'], ['light', 'dark', 'system'], true) ? $config['apariencia'] : 'system';
        $config['inicio'] = in_array($config['inicio'], ['dashboard', 'caja', 'cambio'], true) ? $config['inicio'] : 'dashboard';
        $config['menu_compacto'] = (bool) $config['menu_compacto'];
        DB::execute('INSERT INTO pos_usuario_preferencia (cod_usuario, configuracion_json) VALUES (:u,:c)
            ON DUPLICATE KEY UPDATE configuracion_json=VALUES(configuracion_json), actualizado_en=NOW()', [':u' => $usuario, ':c' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
        return $this->obtener($usuario);
    }

    public function subirFirma(int $usuario, UploadedFile $file): array
    {
        if (!$file->isValid()) throw new \RuntimeException('La firma no se recibió correctamente.');
        $mime = (string) $file->getMimeType();
        $permitidos = ['image/png' => 'png'];
        if (!isset($permitidos[$mime])) throw new \RuntimeException('La firma debe estar en formato PNG.');
        if ((int) $file->getSize() > 2 * 1024 * 1024) throw new \RuntimeException('La firma no puede superar 2 MB.');

        $dir = storage_path('app/pos/firmas');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('No se pudo preparar el almacenamiento de firmas.');
        $old = DB::selectOne('SELECT firma_path FROM pos_usuario_preferencia WHERE cod_usuario=:u', [':u' => $usuario]);
        $name = 'firma_'.$usuario.'_'.bin2hex(random_bytes(10));
        $extension = $permitidos[$mime];
        $dest = $dir.DIRECTORY_SEPARATOR.$name.'.'.$extension;
        $mimeGuardar = $mime;
        try {
            $file->move($dir, basename($dest));
            DB::execute('INSERT INTO pos_usuario_preferencia (cod_usuario, configuracion_json, firma_path, firma_nombre, firma_mime, firma_actualizada_en)
                VALUES (:u,:c,:p,:n,:m,NOW()) ON DUPLICATE KEY UPDATE firma_path=VALUES(firma_path), firma_nombre=VALUES(firma_nombre), firma_mime=VALUES(firma_mime), firma_actualizada_en=NOW(), actualizado_en=NOW()', [
                    ':u'=>$usuario, ':c'=>json_encode(self::DEFAULTS), ':p'=>'pos/firmas/'.basename($dest), ':n'=>mb_substr($file->getClientOriginalName(),0,255), ':m'=>$mimeGuardar,
                ]);
        } catch (\Throwable $e) { if (is_file($dest)) @unlink($dest); throw $e; }
        if (!empty($old['firma_path']) && strpos($old['firma_path'], 'pos/firmas/') === 0) @unlink(storage_path('app/'.$old['firma_path']));
        return $this->obtener($usuario);
    }

    public function firmaDataUri(int $usuario): ?string
    {
        $row = DB::selectOne('SELECT firma_path, firma_mime FROM pos_usuario_preferencia WHERE cod_usuario=:u', [':u'=>$usuario]);
        if (!$row || empty($row['firma_path']) || strpos($row['firma_path'], 'pos/firmas/') !== 0) return null;
        $path = storage_path('app/'.$row['firma_path']);
        if (!is_file($path) || !is_readable($path)) return null;
        return 'data:'.$row['firma_mime'].';base64,'.base64_encode((string) file_get_contents($path));
    }
}
