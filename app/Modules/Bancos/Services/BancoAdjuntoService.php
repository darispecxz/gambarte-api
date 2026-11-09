<?php

namespace App\Modules\Bancos\Services;

use App\Modules\Shared\Database\RawConnection as DB;
use Illuminate\Http\UploadedFile;

/**
 * Comprobantes adjuntos de las operaciones bancarias.
 *
 * El legacy dejaba el archivo en disco con el nombre que enviaba el navegador
 * (`$_POST['namefile']`) y sin referencia en base de datos. Aquí:
 *
 *   · el nombre lo genera el servidor (nunca el del cliente → sin traversal)
 *   · la extensión se valida contra una lista blanca
 *   · la ruta se guarda en la propia operación, así que no hay que adivinar
 *     la relación parseando la glosa
 *   · los cheques llevan dos caras (anverso y reverso)
 */
class BancoAdjuntoService
{
    /** Extensiones permitidas. */
    private const EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /** Tamaño máximo por archivo (5 MB, igual que los comprobantes de egreso). */
    private const MAX_BYTES = 5242880;

    /** Tipos de operación admitidos → tabla y columnas. */
    private const DESTINOS = [
        'traspaso'      => ['tabla' => 'banco_traspaso',      'pk' => 'id',
                            'col_path' => 'comprobante_path', 'col_nombre' => 'comprobante_nombre'],
        'deposito'      => ['tabla' => 'banco_deposito',      'pk' => 'id',
                            'col_path' => 'comprobante_path', 'col_nombre' => 'comprobante_nombre'],
        'cheque_envio'  => ['tabla' => 'banco_cheque_envio',  'pk' => 'id',
                            'col_path' => 'comprobante_path', 'col_nombre' => 'comprobante_nombre'],
    ];

    /** Caras del cheque sobre `banco_cheque_mov`. */
    private const CARAS_CHEQUE = [
        'anverso' => ['path' => 'adjunto_anverso_path', 'nombre' => 'adjunto_anverso_nombre'],
        'reverso' => ['path' => 'adjunto_reverso_path', 'nombre' => 'adjunto_reverso_nombre'],
    ];

    /**
     * Guarda un comprobante de una operación bancaria.
     */
    public function subir(string $tipo, int $id, UploadedFile $file, int $codUsuario, int $idAgencia): array
    {
        $destino = self::DESTINOS[$tipo] ?? null;
        if (!$destino) {
            return ['success' => false, 'message' => "Tipo de operación '{$tipo}' no admite comprobante."];
        }

        $val = $this->validar($file);
        if ($val !== null) return $val;

        // La operación debe existir y ser de la agencia del usuario.
        $filtroAgencia = $tipo === 'traspaso'
            ? '(id_agencia_origen = :ag OR id_agencia_destino = :ag)'
            : 'id_agencia = :ag';
        $fila = DB::selectOne(
            "SELECT {$destino['pk']} AS id FROM {$destino['tabla']}
             WHERE {$destino['pk']} = :id AND {$filtroAgencia} LIMIT 1",
            [':id' => $id, ':ag' => $idAgencia]
        );
        if (!$fila) {
            return ['success' => false, 'message' => 'La operación no existe o no pertenece a su agencia.'];
        }

        $ext      = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $original = $file->getClientOriginalName();
        $guardado = sprintf('%s_%d_%d_%s.%s', $tipo, $id, time(), bin2hex(random_bytes(4)), $ext);

        $file->move(storage_path('app/comprobantes/bancos'), $guardado);
        $ruta = 'comprobantes/bancos/' . $guardado;

        DB::execute(
            "UPDATE {$destino['tabla']}
             SET {$destino['col_path']} = :path, {$destino['col_nombre']} = :nombre
             WHERE {$destino['pk']} = :id",
            [':path' => $ruta, ':nombre' => $original, ':id' => $id]
        );

        return [
            'success' => true,
            'message' => 'Comprobante adjuntado correctamente.',
            'data'    => ['path' => $ruta, 'nombre' => $original],
        ];
    }

    /**
     * Adjunta una cara del cheque (anverso o reverso) al último movimiento del
     * cheque, que es donde queda la trazabilidad de la emisión.
     */
    public function subirCheque(int $idChequera, int $numero, string $cara, UploadedFile $file,
                                int $codUsuario, int $idAgencia): array
    {
        $cols = self::CARAS_CHEQUE[$cara] ?? null;
        if (!$cols) {
            return ['success' => false, 'message' => "Cara de cheque inválida ('{$cara}'). Use anverso o reverso."];
        }

        $val = $this->validar($file);
        if ($val !== null) return $val;

        // El cheque debe existir y la chequera ser de la agencia.
        $cheque = DB::selectOne("
            SELECT q.id_chequera, q.id_cheque
            FROM cheque q
            INNER JOIN chequera ch ON ch.id_chequera = q.id_chequera
            WHERE q.id_chequera = :ch AND q.id_cheque = :n AND ch.id_agencia = :ag
            LIMIT 1
        ", [':ch' => $idChequera, ':n' => $numero, ':ag' => $idAgencia]);
        if (!$cheque) {
            return ['success' => false, 'message' => 'El cheque no existe o no pertenece a su agencia.'];
        }

        $mov = DB::selectOne("
            SELECT id FROM banco_cheque_mov
            WHERE id_chequera = :ch AND id_cheque = :n
            ORDER BY id DESC LIMIT 1
        ", [':ch' => $idChequera, ':n' => $numero]);
        if (!$mov) {
            return ['success' => false, 'message' => 'El cheque todavía no tiene movimientos registrados.'];
        }

        $ext      = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $original = $file->getClientOriginalName();
        $guardado = sprintf('cheque_%d_%d_%s_%d_%s.%s',
            $idChequera, $numero, $cara, time(), bin2hex(random_bytes(4)), $ext);

        $file->move(storage_path('app/comprobantes/bancos'), $guardado);
        $ruta = 'comprobantes/bancos/' . $guardado;

        DB::execute("
            UPDATE banco_cheque_mov
            SET {$cols['path']} = :path, {$cols['nombre']} = :nombre
            WHERE id = :id
        ", [':path' => $ruta, ':nombre' => $original, ':id' => (int) $mov['id']]);

        return [
            'success' => true,
            'message' => 'Imagen del ' . $cara . ' adjuntada correctamente.',
            'data'    => ['path' => $ruta, 'nombre' => $original, 'cara' => $cara],
        ];
    }

    /** Adjuntos de un cheque (ambas caras), tomados de su último movimiento. */
    public function adjuntosCheque(int $idChequera, int $numero): array
    {
        $mov = DB::selectOne("
            SELECT adjunto_anverso_path, adjunto_anverso_nombre,
                   adjunto_reverso_path, adjunto_reverso_nombre
            FROM banco_cheque_mov
            WHERE id_chequera = :ch AND id_cheque = :n
            ORDER BY id DESC LIMIT 1
        ", [':ch' => $idChequera, ':n' => $numero]);

        return [
            'anverso' => $mov && $mov['adjunto_anverso_path']
                ? ['path' => $mov['adjunto_anverso_path'], 'nombre' => $mov['adjunto_anverso_nombre']]
                : null,
            'reverso' => $mov && $mov['adjunto_reverso_path']
                ? ['path' => $mov['adjunto_reverso_path'], 'nombre' => $mov['adjunto_reverso_nombre']]
                : null,
        ];
    }

    /** Valida el archivo. Devuelve null si es correcto, o el error. */
    private function validar(UploadedFile $file): ?array
    {
        if (!$file->isValid()) {
            return ['success' => false, 'message' => 'El archivo no se subió correctamente.'];
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return ['success' => false, 'message' => 'El archivo supera el máximo permitido (5 MB).'];
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, self::EXTENSIONES, true)) {
            return ['success' => false, 'message' => 'Formato no permitido. Use: ' . implode(', ', self::EXTENSIONES) . '.'];
        }
        return null;
    }
}
