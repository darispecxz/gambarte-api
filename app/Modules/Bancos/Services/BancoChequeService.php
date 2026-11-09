<?php

namespace App\Modules\Bancos\Services;

use App\Modules\Shared\Database\RawConnection as DB;

/**
 * Cheques: emisión, envío/recepción entre agencias, cobro, anulación y reversión.
 *
 * ── Qué corrige respecto al legacy ───────────────────────────────────────────
 * `bancos/model/guardarcheque.php` tomaba TODO del POST sin validar: no
 * comprobaba que el cheque existiera, que perteneciera a la chequera, su estado
 * previo, ni que estuviera dentro del rango [numero_ini, numero_ini+cantidad-1].
 * Además:
 *
 *   · `:47` usaba `$codsubd`, variable NUNCA definida → `subcuen_der('')`
 *     devolvía todas las subcuentas y contabilizaba en una arbitraria.
 *   · `:67` `$estado=C;` sin comillas (error fatal en PHP 8).
 *   · `:65` el movimiento de banco estaba COMENTADO y el bloque contable era
 *     inalcanzable porque el checkbox que lo activaba está `disabled`.
 *   · `:119` daba la operación por buena según si se MOVIÓ UN ARCHIVO, no según
 *     el resultado en base de datos.
 *
 * Aquí la emisión es atómica y validada, y el cobro mueve banco (`CC`) y caja
 * (`IC`) usando los MISMOS códigos de conciliación que ya usa el core, para que
 * los reportes existentes sigan cuadrando.
 *
 * ── Estados ──────────────────────────────────────────────────────────────────
 *   I = en chequera, sin emitir      C = cobrado
 *   A = emitido                      N = anulado
 *   E = enviado a otra agencia       T = recibido en la agencia destino
 *
 * ── Aviso de alcance contable ────────────────────────────────────────────────
 * El legacy NO contabiliza la emisión de un cheque (deja la cuenta bancaria sin
 * tocar hasta el cobro), y el POS nuevo mantiene ese criterio para no alterar el
 * saldo contable existente. La emisión sí queda registrada operativamente.
 */
class BancoChequeService
{
    private const ESTADOS = [
        'I' => 'Disponible',
        'A' => 'Emitido',
        'E' => 'Enviado',
        'T' => 'Recibido',
        'C' => 'Cobrado',
        'N' => 'Anulado',
    ];

    /** Movimientos en los que el cheque ya salió de la chequera. */
    private const ESTADOS_EMITIDOS = ['A', 'E', 'T', 'C', 'N'];

    private BankingBalanceService $balance;
    private \App\Modules\Cajero\Services\CajeroBalanceService $caja;

    public function __construct(
        BankingBalanceService $balance,
        \App\Modules\Cajero\Services\CajeroBalanceService $caja
    ) {
        $this->balance = $balance;
        $this->caja = $caja;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Consultas
    // ═══════════════════════════════════════════════════════════════════

    /** Chequeras de la agencia con su disponibilidad. */
    public function chequeras(int $idAgencia): array
    {
        $rows = DB::select("
            SELECT ch.id_chequera, ch.id_agencia, ch.id_entidad_financiera, ch.id_moneda,
                   ch.num_cuenta, ch.cantidad, ch.numero_ini, ch.estado,
                   ef.nombre_corto AS entidad, m.abreviatura AS moneda,
                   (SELECT COUNT(*) FROM cheque q WHERE q.id_chequera = ch.id_chequera) AS total,
                   (SELECT COUNT(*) FROM cheque q WHERE q.id_chequera = ch.id_chequera AND q.estado = 'I') AS disponibles,
                   (SELECT COUNT(*) FROM cheque q WHERE q.id_chequera = ch.id_chequera AND q.estado IN ('A','E','T')) AS en_circulacion,
                   (SELECT COUNT(*) FROM cheque q WHERE q.id_chequera = ch.id_chequera AND q.estado = 'C') AS cobrados,
                   (SELECT COUNT(*) FROM cheque q WHERE q.id_chequera = ch.id_chequera AND q.estado = 'N') AS anulados
            FROM chequera ch
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = ch.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = ch.id_moneda
            WHERE ch.id_agencia = :ag
            ORDER BY ch.id_chequera DESC
        ", [':ag' => $idAgencia]);

        return array_map(function ($r) {
            return [
                'id_chequera'    => (int) $r['id_chequera'],
                'id_agencia'     => (int) $r['id_agencia'],
                'id_entidad'     => (int) $r['id_entidad_financiera'],
                'entidad'        => $r['entidad'],
                'id_moneda'      => (int) $r['id_moneda'],
                'moneda'         => $r['moneda'],
                'num_cuenta'     => $r['num_cuenta'],
                'cantidad'       => (int) $r['cantidad'],
                'numero_ini'     => $r['numero_ini'],
                'estado'         => $r['estado'],
                'total'          => (int) $r['total'],
                'disponibles'    => (int) $r['disponibles'],
                'en_circulacion' => (int) $r['en_circulacion'],
                'cobrados'       => (int) $r['cobrados'],
                'anulados'       => (int) $r['anulados'],
            ];
        }, $rows);
    }

    /** Cheques de una chequera, opcionalmente filtrados por estado. */
    public function cheques(int $idChequera, ?string $estado = null): array
    {
        $cond = ['q.id_chequera = :ch'];
        $params = [':ch' => $idChequera];
        if ($estado && isset(self::ESTADOS[$estado])) {
            $cond[] = 'q.estado = :est';
            $params[':est'] = $estado;
        }
        $where = implode(' AND ', $cond);

        $rows = DB::select("
            SELECT q.id_chequera, q.id_cheque, q.estado, q.fec_crea_cheque, q.fec_mod_cheque,
                   rc.monto, rc.concepto_obs, rc.num_cuenta_bn, rc.id_entidad, rc.id_agencia,
                   rc.fec_crea_adj_cheque, rc.id_categoria_cheque, rc.cod_trab,
                   ef.nombre_corto AS entidad, cc.descripcion AS categoria,
                   (SELECT COUNT(*) FROM banco_cheque_envio e
                     WHERE e.id_chequera = q.id_chequera AND e.id_cheque = q.id_cheque AND e.estado = 'P') AS envio_pendiente
            FROM cheque q
            INNER JOIN chequera ch ON ch.id_chequera = q.id_chequera
            LEFT JOIN registro_cheque rc
                   ON rc.id_cheque = q.id_cheque
                  AND rc.num_cuenta_bn = ch.num_cuenta
                  AND rc.id_entidad = ch.id_entidad_financiera
                  AND rc.id_agencia = ch.id_agencia
            LEFT JOIN entidad_financiera ef ON ef.id_entidad_financiera = rc.id_entidad
            LEFT JOIN categoria_cheque cc ON cc.id_categoria_cheque = rc.id_categoria_cheque
            WHERE {$where}
            ORDER BY q.id_cheque ASC
        ", $params);

        return array_map([$this, 'mapCheque'], $rows);
    }

    /** Cheques emitidos/en circulación de la agencia (bandeja de trabajo). */
    public function chequesAgencia(int $idAgencia, ?string $estado = null, int $limite = 200): array
    {
        $cond = ["q.estado <> 'I'"];
        $params = [];
        if ($estado && isset(self::ESTADOS[$estado])) {
            $cond[] = 'q.estado = :est';
            $params[':est'] = $estado;
        }
        $where = implode(' AND ', $cond);

        $rows = DB::select("
            SELECT q.id_chequera, q.id_cheque, q.estado, q.fec_crea_cheque, q.fec_mod_cheque,
                   rc.monto, rc.concepto_obs, rc.num_cuenta_bn, rc.id_entidad, rc.id_agencia,
                   rc.fec_crea_adj_cheque, rc.id_categoria_cheque, rc.cod_trab,
                   ef.nombre_corto AS entidad, cc.descripcion AS categoria,
                   adj.adjunto_anverso_path, adj.adjunto_anverso_nombre,
                   adj.adjunto_reverso_path, adj.adjunto_reverso_nombre,
                   (SELECT COUNT(*) FROM banco_cheque_envio e
                     WHERE e.id_chequera = q.id_chequera AND e.id_cheque = q.id_cheque AND e.estado = 'P') AS envio_pendiente
            FROM cheque q
            INNER JOIN chequera ch ON ch.id_chequera = q.id_chequera
            INNER JOIN registro_cheque rc
                    ON rc.id_cheque = q.id_cheque
                   AND rc.num_cuenta_bn = ch.num_cuenta
                   AND rc.id_entidad = ch.id_entidad_financiera
                   AND rc.id_agencia = ch.id_agencia
            LEFT JOIN entidad_financiera ef ON ef.id_entidad_financiera = rc.id_entidad
            LEFT JOIN categoria_cheque cc ON cc.id_categoria_cheque = rc.id_categoria_cheque
            -- Adjuntos (anverso/reverso) del último movimiento del cheque: evita
            -- un N+1 desde el POS, que antes pedía los adjuntos cheque por cheque.
            LEFT JOIN banco_cheque_mov adj
                   ON adj.id = (SELECT MAX(m.id) FROM banco_cheque_mov m
                                 WHERE m.id_chequera = q.id_chequera AND m.id_cheque = q.id_cheque)
            WHERE rc.id_agencia = :ag AND {$where}
            ORDER BY rc.fec_crea_adj_cheque DESC
            LIMIT " . (int) $limite . "
        ", [':ag' => $idAgencia] + $params);

        return array_map([$this, 'mapCheque'], $rows);
    }

    private function mapCheque(array $r): array
    {
        return [
            'id_chequera'      => (int) $r['id_chequera'],
            'id_cheque'        => (int) $r['id_cheque'],
            'numero'           => $r['id_cheque'],
            'estado'           => $r['estado'],
            'estado_label'     => self::ESTADOS[$r['estado']] ?? $r['estado'],
            'monto'            => $r['monto'] === null ? null : (float) $r['monto'],
            'concepto'         => $r['concepto_obs'] ?? null,
            'num_cuenta'       => $r['num_cuenta_bn'] ?? null,
            'id_entidad'       => isset($r['id_entidad']) ? (int) $r['id_entidad'] : null,
            'entidad'          => $r['entidad'] ?? null,
            'id_agencia'       => isset($r['id_agencia']) ? (int) $r['id_agencia'] : null,
            'id_categoria'     => isset($r['id_categoria_cheque']) ? (int) $r['id_categoria_cheque'] : null,
            'categoria'        => $r['categoria'] ?? null,
            'fecha_registro'   => $r['fec_crea_adj_cheque'] ?? null,
            'fecha_modificacion' => $r['fec_mod_cheque'] ?? null,
            'envio_pendiente'  => (int) ($r['envio_pendiente'] ?? 0),
            'adjuntos'         => [
                'anverso' => !empty($r['adjunto_anverso_path'])
                    ? ['path' => $r['adjunto_anverso_path'], 'nombre' => $r['adjunto_anverso_nombre']]
                    : null,
                'reverso' => !empty($r['adjunto_reverso_path'])
                    ? ['path' => $r['adjunto_reverso_path'], 'nombre' => $r['adjunto_reverso_nombre']]
                    : null,
            ],
        ];
    }

    /** Chequeras de todas las agencias (para elegir destino de un envío). */
    public function destinosEnvio(int $idAgencia): array
    {
        return DB::select("
            SELECT DISTINCT a.id_agencia, a.descripcion, a.abreviatura
            FROM agencia a
            WHERE a.estado = 'A' AND a.id_agencia <> :ag
            ORDER BY a.descripcion
        ", [':ag' => $idAgencia]);
    }

    public function categorias(): array
    {
        return DB::select("SELECT id_categoria_cheque AS id, descripcion, abreviatura
                           FROM categoria_cheque WHERE estado = 'A' ORDER BY descripcion");
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Emisión
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Emite un cheque de la chequera: pasa de 'I' a 'A' y registra el detalle.
     *
     * A diferencia del legacy, valida que el número exista, pertenezca a la
     * chequera, esté sin emitir y que no haya otro registro para el mismo
     * cheque (la PK de `registro_cheque` lo impediría, pero el legacy no
     * comprobaba el resultado del INSERT y aun así informaba éxito).
     */
    public function emitir(array $in, int $codUsuario, int $idAgencia, string $ip): array
    {
        $uuid = trim((string) ($in['uuid'] ?? ''));
        if ($uuid === '') {
            return ['success' => false, 'message' => 'Falta el identificador de la operación (uuid).'];
        }
        $previo = DB::selectOne("SELECT id FROM banco_cheque_mov WHERE uuid = :u", [':u' => $uuid]);
        if ($previo) {
            return ['success' => true, 'message' => 'El cheque ya había sido emitido.', 'data' => ['id' => (int) $previo['id']]];
        }

        $idChequera = (int) ($in['id_chequera'] ?? 0);
        $numero     = (int) ($in['id_cheque'] ?? 0);
        $monto      = round((float) ($in['monto'] ?? 0), 4);
        $concepto   = trim((string) ($in['concepto'] ?? ''));
        $idCategoria = (int) ($in['id_categoria'] ?? 0);

        if (!$idChequera || !$numero) {
            return ['success' => false, 'message' => 'Debe indicar la chequera y el número de cheque.'];
        }
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0.'];
        }
        if ($concepto === '') {
            return ['success' => false, 'message' => 'El concepto u observación es requerido.'];
        }
        if (!$idCategoria) {
            return ['success' => false, 'message' => 'Debe indicar la categoría del cheque.'];
        }

        $chequera = DB::selectOne("
            SELECT ch.*, ef.nombre_corto AS entidad, m.abreviatura AS moneda
            FROM chequera ch
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = ch.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = ch.id_moneda
            WHERE ch.id_chequera = :id
        ", [':id' => $idChequera]);
        if (!$chequera) {
            return ['success' => false, 'message' => 'La chequera no existe.'];
        }
        if ((int) $chequera['id_agencia'] !== $idAgencia) {
            return ['success' => false, 'message' => 'La chequera no pertenece a su agencia.'];
        }

        $cheque = DB::selectOne("
            SELECT * FROM cheque WHERE id_chequera = :ch AND id_cheque = :n
        ", [':ch' => $idChequera, ':n' => $numero]);
        if (!$cheque) {
            return ['success' => false, 'message' => "El cheque N° {$numero} no pertenece a esta chequera."];
        }
        if ($cheque['estado'] !== 'I') {
            return ['success' => false, 'message' => 'El cheque N° ' . $numero . ' ya está '
                . strtolower(self::ESTADOS[$cheque['estado']] ?? $cheque['estado']) . '.'];
        }

        // Validación de rango que el legacy no hacía.
        $ini = (int) $chequera['numero_ini'];
        $fin = $ini + (int) $chequera['cantidad'] - 1;
        if ($numero < $ini || $numero > $fin) {
            return ['success' => false, 'message' => "El cheque N° {$numero} está fuera del rango de la chequera ({$ini}-{$fin})."];
        }

        // Cuenta bancaria asociada a la chequera (liga por número de cuenta).
        $cuenta = DB::selectOne("
            SELECT cb.* FROM cuenta_bancaria cb
            WHERE cb.id_agencia = :ag AND cb.id_entidad_financiera = :ent
              AND cb.num_cuenta_bancaria = :num AND cb.estado = 'A' LIMIT 1
        ", [
            ':ag' => (int) $chequera['id_agencia'],
            ':ent' => (int) $chequera['id_entidad_financiera'],
            ':num' => $chequera['num_cuenta'],
        ]);
        if (!$cuenta) {
            return ['success' => false, 'message' => 'No se encontró la cuenta bancaria asociada a la chequera.'];
        }

        $saldoCuenta = $this->balance->saldoCuenta(
            (int) $cuenta['id_agencia'], (int) $cuenta['id_entidad_financiera'],
            $cuenta['num_cuenta_bancaria'], (int) $cuenta['id_moneda']
        );
        if ($monto > $saldoCuenta) {
            return ['success' => false, 'message' => 'El monto supera el saldo de la cuenta ('
                . number_format($saldoCuenta, 2) . ' ' . $chequera['moneda'] . ').'];
        }

        // Si ya existe el detalle, el cheque ya fue emitido (la PK de
        // `registro_cheque` es (id_cheque, num_cuenta_bn, id_entidad, id_agencia):
        // no basta con el número, hay que acotar por cuenta y agencia).
        if ($this->registroCheque($chequera, $numero)) {
            return ['success' => false, 'message' => 'El cheque N° ' . $numero . ' ya tiene un registro de emisión.'];
        }

        $tc = (int) $cuenta['id_moneda'] === 1 ? $this->tipoCambioOficialVenta() : 1.0;
        $glosa = mb_strtoupper('EMISION CHEQUE #' . $numero . ' ' . $chequera['entidad'] . ' cta ' . $chequera['num_cuenta']);

        return DB::transaction(function () use (
            $uuid, $chequera, $cuenta, $numero, $monto, $tc, $concepto, $idCategoria, $glosa,
            $codUsuario, $idAgencia, $ip
        ) {
            // 1. Detalle del cheque
            $ok = DB::execute("
                INSERT INTO registro_cheque
                    (id_cheque, num_cuenta_bn, id_entidad, id_agencia, concepto_obs, cod_trab,
                     monto, fec_crea_adj_cheque, id_categoria_cheque)
                VALUES (:n, :num, :ent, :ag, :concepto, :trab, :monto, NOW(), :cat)
            ", [
                ':n' => $numero,
                ':num' => $chequera['num_cuenta'],
                ':ent' => (int) $chequera['id_entidad_financiera'],
                ':ag' => (int) $chequera['id_agencia'],
                ':concepto' => mb_strtoupper($concepto),
                ':trab' => $codUsuario,
                ':monto' => $monto,
                ':cat' => $idCategoria,
            ]);
            if ($ok === 0) {
                throw new \RuntimeException('No se pudo registrar el cheque (¿ya estaba emitido?).');
            }

            // 2. Estado I -> A, solo si seguía disponible.
            $upd = DB::execute("
                UPDATE cheque SET estado = 'A', fec_mod_cheque = NOW()
                WHERE id_chequera = :ch AND id_cheque = :n AND estado = 'I'
            ", [':ch' => (int) $chequera['id_chequera'], ':n' => $numero]);
            if ($upd === 0) {
                throw new \RuntimeException('El cheque ya no estaba disponible.');
            }

            // 3. Trazabilidad
            $this->movimientoCheque($uuid, $chequera, $numero, $monto, $tc, 'E', 'I', 'A',
                $concepto, $idCategoria, $glosa, $codUsuario, $idAgencia, null, $ip);

            return [
                'success' => true,
                'message' => 'Cheque emitido correctamente.',
                'data' => [
                    'id_chequera' => (int) $chequera['id_chequera'],
                    'id_cheque' => $numero,
                    'numero' => $numero,
                    'estado' => 'A',
                    'monto' => $monto,
                    'moneda' => $chequera['moneda'],
                ],
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Envío / recepción entre agencias
    // ═══════════════════════════════════════════════════════════════════

    /** Envía un cheque emitido a otra agencia (A -> E). */
    public function enviar(int $idChequera, int $numero, int $idAgenciaDestino, ?int $idCategoria,
                           ?string $observaciones, int $codUsuario, int $idAgencia, string $ip): array
    {
        $ch = $this->chequeDeAgencia($idChequera, $numero, $idAgencia);
        if (isset($ch['error'])) return $ch['error'];
        $cheque = $ch['cheque'];

        if ($cheque['estado'] !== 'A') {
            return ['success' => false, 'message' => 'Solo se pueden enviar cheques emitidos. Estado actual: '
                . strtolower(self::ESTADOS[$cheque['estado']] ?? $cheque['estado']) . '.'];
        }
        if ($idAgenciaDestino === $idAgencia) {
            return ['success' => false, 'message' => 'La agencia destino debe ser distinta de la de origen.'];
        }
        $destino = DB::selectOne("SELECT id_agencia, descripcion FROM agencia WHERE id_agencia = :id AND estado='A'",
            [':id' => $idAgenciaDestino]);
        if (!$destino) {
            return ['success' => false, 'message' => 'La agencia destino no existe o está inactiva.'];
        }

        $uuid = $this->uuid();
        $detalle = $this->detalleCheque($idChequera, $numero);

        return DB::transaction(function () use (
            $uuid, $cheque, $detalle, $idAgenciaDestino, $idCategoria, $observaciones,
            $codUsuario, $idAgencia, $ip
        ) {
            $ok = DB::execute("
                INSERT INTO banco_cheque_envio
                    (uuid, id_chequera, id_cheque, id_agencia_origen, id_agencia_destino,
                     id_categoria, observaciones, estado, cod_usuario_origen, ip_origen)
                VALUES (:uuid, :ch, :n, :ago, :agd, :cat, :obs, 'P', :usr, :ip)
            ", [
                ':uuid' => $uuid, ':ch' => (int) $cheque['id_chequera'], ':n' => (int) $cheque['id_cheque'],
                ':ago' => $idAgencia, ':agd' => $idAgenciaDestino,
                ':cat' => $idCategoria ?: null, ':obs' => $observaciones ?: null,
                ':usr' => $codUsuario, ':ip' => $ip,
            ]);
            if ($ok === 0) {
                throw new \RuntimeException('No se pudo registrar el envío.');
            }

            $upd = DB::execute("
                UPDATE cheque SET estado = 'E', fec_mod_cheque = NOW()
                WHERE id_chequera = :ch AND id_cheque = :n AND estado = 'A'
            ", [':ch' => (int) $cheque['id_chequera'], ':n' => (int) $cheque['id_cheque']]);
            if ($upd === 0) {
                throw new \RuntimeException('El cheque ya no estaba emitido.');
            }

            $this->movimientoCheque($uuid, $detalle['chequera'], (int) $cheque['id_cheque'],
                (float) $detalle['monto'], (float) $detalle['tc'], 'V', 'A', 'E',
                $observaciones, $idCategoria, 'ENVIO CHEQUE A AGENCIA ' . $idAgenciaDestino,
                $codUsuario, $idAgencia, $idAgenciaDestino, $ip);

            return [
                'success' => true,
                'message' => 'Cheque enviado. Queda pendiente de recepción por la agencia destino.',
                'data' => ['uuid' => $uuid, 'id_agencia_destino' => $idAgenciaDestino],
            ];
        });
    }

    /** Bandeja de cheques enviados pendientes de recibir por esta agencia. */
    public function enviosPendientes(int $idAgencia): array
    {
        return DB::select("
            SELECT e.*, q.estado AS estado_cheque,
                   rc.monto, rc.concepto_obs,
                   ao.descripcion AS agencia_origen_desc, ao.abreviatura AS agencia_origen_abr,
                   cc.descripcion AS categoria,
                   ef.nombre_corto AS entidad, m.abreviatura AS moneda, ch.num_cuenta
            FROM banco_cheque_envio e
            INNER JOIN cheque q ON q.id_chequera = e.id_chequera AND q.id_cheque = e.id_cheque
            INNER JOIN chequera ch ON ch.id_chequera = e.id_chequera
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = ch.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = ch.id_moneda
            INNER JOIN agencia ao ON ao.id_agencia = e.id_agencia_origen
            LEFT JOIN registro_cheque rc
                   ON rc.id_cheque = e.id_cheque
                  AND rc.num_cuenta_bn = ch.num_cuenta
                  AND rc.id_entidad = ch.id_entidad_financiera
                  AND rc.id_agencia = ch.id_agencia
            LEFT JOIN categoria_cheque cc ON cc.id_categoria_cheque = e.id_categoria
            WHERE e.id_agencia_destino = :ag AND e.estado = 'P'
            ORDER BY e.id ASC
        ", [':ag' => $idAgencia]);
    }

    /** Confirma la recepción de un cheque enviado (E -> T). */
    public function recibir(int $idEnvio, int $codUsuario, int $idAgencia, string $ip): array
    {
        $e = DB::selectOne("SELECT * FROM banco_cheque_envio WHERE id = :id", [':id' => $idEnvio]);
        if (!$e) {
            return ['success' => false, 'message' => 'Envío no encontrado.'];
        }
        if ((int) $e['id_agencia_destino'] !== $idAgencia) {
            return ['success' => false, 'message' => 'Este envío no corresponde a su agencia.'];
        }
        if ($e['estado'] !== 'P') {
            return ['success' => false, 'message' => 'Este envío ya fue procesado.'];
        }

        $detalle = $this->detalleCheque((int) $e['id_chequera'], (int) $e['id_cheque']);

        return DB::transaction(function () use ($e, $idEnvio, $detalle, $codUsuario, $idAgencia, $ip) {
            $upd = DB::execute("
                UPDATE banco_cheque_envio
                SET estado = 'R', cod_usuario_destino = :usr, fec_recepcion = NOW(), ip_recepcion = :ip
                WHERE id = :id AND estado = 'P'
            ", [':usr' => $codUsuario, ':ip' => $ip, ':id' => $idEnvio]);
            if ($upd === 0) {
                throw new \RuntimeException('El envío ya no estaba pendiente.');
            }

            $upd2 = DB::execute("
                UPDATE cheque SET estado = 'T', fec_mod_cheque = NOW()
                WHERE id_chequera = :ch AND id_cheque = :n AND estado = 'E'
            ", [':ch' => (int) $e['id_chequera'], ':n' => (int) $e['id_cheque']]);
            if ($upd2 === 0) {
                throw new \RuntimeException('El cheque no estaba en tránsito.');
            }

            $this->movimientoCheque($e['uuid'], $detalle['chequera'], (int) $e['id_cheque'],
                (float) $detalle['monto'], (float) $detalle['tc'], 'R', 'E', 'T',
                'RECEPCION DE CHEQUE', (int) $e['id_categoria'],
                'RECEPCION CHEQUE DE AGENCIA ' . $e['id_agencia_origen'],
                $codUsuario, $idAgencia, $idAgencia, $ip);

            return [
                'success' => true,
                'message' => 'Recepción confirmada. El cheque está disponible en su agencia.',
                'data' => ['id' => $idEnvio, 'estado' => 'T'],
            ];
        });
    }

    /** Rechaza un envío: el cheque vuelve a estar emitido en el origen (E -> A). */
    public function rechazarEnvio(int $idEnvio, int $codUsuario, int $idAgencia, string $motivo, string $ip): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            return ['success' => false, 'message' => 'Debe indicar el motivo del rechazo.'];
        }
        $e = DB::selectOne("SELECT * FROM banco_cheque_envio WHERE id = :id", [':id' => $idEnvio]);
        if (!$e) {
            return ['success' => false, 'message' => 'Envío no encontrado.'];
        }
        if ((int) $e['id_agencia_destino'] !== $idAgencia) {
            return ['success' => false, 'message' => 'Este envío no corresponde a su agencia.'];
        }
        if ($e['estado'] !== 'P') {
            return ['success' => false, 'message' => 'Este envío ya fue procesado.'];
        }

        $detalle = $this->detalleCheque((int) $e['id_chequera'], (int) $e['id_cheque']);

        return DB::transaction(function () use ($e, $idEnvio, $detalle, $motivo, $codUsuario, $idAgencia, $ip) {
            $upd = DB::execute("
                UPDATE banco_cheque_envio
                SET estado = 'X', motivo_rechazo = :motivo, cod_usuario_destino = :usr,
                    fec_recepcion = NOW(), ip_recepcion = :ip
                WHERE id = :id AND estado = 'P'
            ", [':motivo' => $motivo, ':usr' => $codUsuario, ':ip' => $ip, ':id' => $idEnvio]);
            if ($upd === 0) {
                throw new \RuntimeException('El envío ya no estaba pendiente.');
            }

            DB::execute("
                UPDATE cheque SET estado = 'A', fec_mod_cheque = NOW()
                WHERE id_chequera = :ch AND id_cheque = :n AND estado = 'E'
            ", [':ch' => (int) $e['id_chequera'], ':n' => (int) $e['id_cheque']]);

            $this->movimientoCheque($e['uuid'], $detalle['chequera'], (int) $e['id_cheque'],
                (float) $detalle['monto'], (float) $detalle['tc'], 'V', 'E', 'A',
                $motivo, (int) $e['id_categoria'], 'RECHAZO DE ENVIO: ' . $motivo,
                $codUsuario, $idAgencia, (int) $e['id_agencia_origen'], $ip);

            return [
                'success' => true,
                'message' => 'Envío rechazado. El cheque vuelve a estar emitido en la agencia de origen.',
                'data' => ['id' => $idEnvio, 'estado' => 'X'],
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Cobro, anulación y reversión
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cobra un cheque: lo pasa a 'C' y mueve banco (`CC`) y caja (`IC`).
     *
     * Se usan los códigos de conciliación que ya emplea el core para el cobro de
     * cheques, de modo que los reportes y la conciliación existentes siguen
     * reconociéndolo.
     */
    public function cobrar(int $idChequera, int $numero, int $idCaja, int $codUsuario, int $idAgencia,
                           ?string $tercero, string $ip): array
    {
        $ch = $this->chequeDeAgencia($idChequera, $numero, $idAgencia);
        if (isset($ch['error'])) return $ch['error'];
        $cheque = $ch['cheque'];
        $detalle = $this->detalleCheque($idChequera, $numero);

        if (!in_array($cheque['estado'], ['A', 'T'], true)) {
            return ['success' => false, 'message' => 'Solo se pueden cobrar cheques emitidos o recibidos. Estado actual: '
                . strtolower(self::ESTADOS[$cheque['estado']] ?? $cheque['estado']) . '.'];
        }
        if ((float) $detalle['monto'] <= 0) {
            return ['success' => false, 'message' => 'El cheque no tiene monto registrado.'];
        }

        // Caja del cajero, abierta y de la misma moneda que la cuenta del cheque.
        $caja = DB::selectOne("
            SELECT c.* FROM caja c
            WHERE c.id_caja = :id AND c.cod_usuario = :usr AND c.estado = 'A' AND c.id_tipo_unidad = 2
        ", [':id' => $idCaja, ':usr' => $codUsuario]);
        if (!$caja) {
            return ['success' => false, 'message' => 'Caja no encontrada o no pertenece al usuario.'];
        }
        $sesion = DB::selectOne("
            SELECT 1 FROM pos_sesion_caja WHERE id_caja = :id AND fecha = CURDATE() AND estado = 'abierta' LIMIT 1
        ", [':id' => $idCaja]);
        if (!$sesion) {
            return ['success' => false, 'message' => 'La caja no está abierta.'];
        }
        if ((int) $caja['id_moneda'] !== (int) $detalle['cuenta']['id_moneda']) {
            return ['success' => false, 'message' => 'La caja y la cuenta del cheque operan en monedas distintas.'];
        }

        $saldoBanco = $this->balance->saldoCuenta(
            (int) $detalle['cuenta']['id_agencia'], (int) $detalle['cuenta']['id_entidad_financiera'],
            $detalle['cuenta']['num_cuenta_bancaria'], (int) $detalle['cuenta']['id_moneda']
        );
        if ((float) $detalle['monto'] > $saldoBanco) {
            return ['success' => false, 'message' => 'Saldo insuficiente en la cuenta bancaria ('
                . number_format($saldoBanco, 2) . ').'];
        }

        $monto = (float) $detalle['monto'];
        $uuid = $this->uuid();
        $glosa = mb_strtoupper('RETIRO DE EFECTIVO CON CHEQUE #' . $numero .
            ($tercero ? ' - ' . $tercero : ''));

        return DB::transaction(function () use (
            $uuid, $cheque, $detalle, $idCaja, $monto, $glosa, $tercero, $codUsuario, $idAgencia, $ip
        ) {
            $upd = DB::execute("
                UPDATE cheque SET estado = 'C', fec_mod_cheque = NOW()
                WHERE id_chequera = :ch AND id_cheque = :n AND estado IN ('A','T')
            ", [':ch' => (int) $cheque['id_chequera'], ':n' => (int) $cheque['id_cheque']]);
            if ($upd === 0) {
                throw new \RuntimeException('El cheque ya no estaba disponible para cobro.');
            }

            // Banco: sale el efectivo (cobro de cheque).
            $this->movimientoBanco($detalle['cuenta'], -$monto, 'CC', $codUsuario, $glosa);
            // Caja: entra el efectivo.
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, NOW(), 'IC')
            ", [':id' => $idCaja, ':saldo' => round($monto, 4)]);

            $this->movimientoCheque($uuid, $detalle['chequera'], (int) $cheque['id_cheque'], $monto,
                (float) $detalle['tc'], 'C', $cheque['estado'], 'C',
                $tercero, (int) ($detalle['id_categoria'] ?? 0), $glosa,
                $codUsuario, $idAgencia, null, $ip);

            DB::execute("
                INSERT INTO pos_operacion_log
                    (uuid, tipo_operacion, id_caja, cod_usuario, id_agencia, id_moneda, monto_total, ip_origen, metadata)
                VALUES (:uuid, 'transaccion_bancaria', :caja, :usr, :ag, :mon, :monto, :ip, :meta)
            ", [
                ':uuid' => $uuid, ':caja' => $idCaja, ':usr' => $codUsuario, ':ag' => $idAgencia,
                ':mon' => (int) $detalle['cuenta']['id_moneda'], ':monto' => $monto, ':ip' => $ip,
                ':meta' => json_encode(['tipo' => 'cobro_cheque', 'id_cheque' => (int) $cheque['id_cheque']]),
            ]);

            return [
                'success' => true,
                'message' => 'Cheque cobrado correctamente.',
                'data' => [
                    'id_cheque' => (int) $cheque['id_cheque'],
                    'estado' => 'C',
                    'monto' => $monto,
                    'saldo_caja_nuevo' => $this->caja->saldoCaja($idCaja, date('Y-m-d')),
                ],
            ];
        });
    }

    /**
     * Anula un cheque que todavía no se cobró.
     *
     * El legacy permitía anular un cheque YA COBRADO (su UPDATE no filtraba por
     * estado) dejando el débito y el asiento hechos, y además su reversión de
     * saldo nunca funcionaba porque `busca_reg_cheque()` consultaba una columna
     * inexistente. Aquí solo se anula lo que no está cobrado, y se guarda el
     * estado previo para poder revertirlo.
     */
    public function anular(int $idChequera, int $numero, string $motivo, int $codUsuario, int $idAgencia, string $ip): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            return ['success' => false, 'message' => 'Debe indicar el motivo de la anulación.'];
        }
        $ch = $this->chequeDeAgencia($idChequera, $numero, $idAgencia);
        if (isset($ch['error'])) return $ch['error'];
        $cheque = $ch['cheque'];

        if ($cheque['estado'] === 'I') {
            return ['success' => false, 'message' => 'El cheque está sin emitir; no hay nada que anular.'];
        }
        if ($cheque['estado'] === 'C') {
            return ['success' => false, 'message' => 'No se puede anular un cheque ya cobrado. Registre la operación inversa.'];
        }
        if ($cheque['estado'] === 'N') {
            return ['success' => false, 'message' => 'El cheque ya está anulado.'];
        }

        $uuid = $this->uuid();
        $estadoAnterior = $cheque['estado'];
        $detalle = $this->detalleCheque($idChequera, $numero);

        return DB::transaction(function () use ($uuid, $cheque, $detalle, $estadoAnterior, $motivo, $codUsuario, $idAgencia, $ip) {
            $upd = DB::execute("
                UPDATE cheque SET estado = 'N', fec_mod_cheque = NOW()
                WHERE id_chequera = :ch AND id_cheque = :n AND estado IN ('A','E','T')
            ", [':ch' => (int) $cheque['id_chequera'], ':n' => (int) $cheque['id_cheque']]);
            if ($upd === 0) {
                throw new \RuntimeException('El cheque ya no estaba en un estado anulable.');
            }

            DB::execute("
                INSERT INTO banco_cheque_anulacion
                    (uuid, id_chequera, id_cheque, motivo, estado_anterior, cod_usuario, revertida)
                VALUES (:uuid, :ch, :n, :motivo, :prev, :usr, 0)
            ", [
                ':uuid' => $uuid, ':ch' => (int) $cheque['id_chequera'], ':n' => (int) $cheque['id_cheque'],
                ':motivo' => $motivo, ':prev' => $estadoAnterior, ':usr' => $codUsuario,
            ]);

            $this->movimientoCheque($uuid, $detalle['chequera'], (int) $cheque['id_cheque'],
                (float) $detalle['monto'], (float) $detalle['tc'], 'N', $estadoAnterior, 'N',
                $motivo, (int) ($detalle['id_categoria'] ?? 0), 'ANULACION: ' . $motivo,
                $codUsuario, $idAgencia, null, $ip);

            return [
                'success' => true,
                'message' => 'Cheque anulado.',
                'data' => ['id_cheque' => (int) $cheque['id_cheque'], 'estado' => 'N', 'estado_anterior' => $estadoAnterior],
            ];
        });
    }

    /** Revierte una anulación: el cheque vuelve al estado que tenía. */
    public function revertirAnulacion(int $idChequera, int $numero, string $motivo, int $codUsuario, int $idAgencia, string $ip): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            return ['success' => false, 'message' => 'Debe indicar el motivo de la reversión.'];
        }
        $ch = $this->chequeDeAgencia($idChequera, $numero, $idAgencia);
        if (isset($ch['error'])) return $ch['error'];
        $cheque = $ch['cheque'];

        if ($cheque['estado'] !== 'N') {
            return ['success' => false, 'message' => 'El cheque no está anulado.'];
        }

        $anul = DB::selectOne("
            SELECT * FROM banco_cheque_anulacion
            WHERE id_chequera = :ch AND id_cheque = :n AND revertida = 0
            ORDER BY id DESC LIMIT 1
        ", [':ch' => $idChequera, ':n' => $numero]);
        if (!$anul) {
            return ['success' => false, 'message' => 'No se encontró una anulación pendiente de revertir para este cheque.'];
        }

        $estadoPrevio = $anul['estado_anterior'];
        if (!in_array($estadoPrevio, ['A', 'E', 'T'], true)) {
            return ['success' => false, 'message' => 'El estado previo registrado no permite revertir la anulación.'];
        }

        $uuid = $this->uuid();
        $detalle = $this->detalleCheque($idChequera, $numero);

        return DB::transaction(function () use ($uuid, $anul, $cheque, $detalle, $estadoPrevio, $motivo, $codUsuario, $idAgencia, $ip) {
            $upd = DB::execute("
                UPDATE banco_cheque_anulacion SET revertida = 1, uuid_reversion = :rev WHERE id = :id AND revertida = 0
            ", [':rev' => $uuid, ':id' => (int) $anul['id']]);
            if ($upd === 0) {
                throw new \RuntimeException('La anulación ya había sido revertida.');
            }

            $upd2 = DB::execute("
                UPDATE cheque SET estado = :prev, fec_mod_cheque = NOW()
                WHERE id_chequera = :ch AND id_cheque = :n AND estado = 'N'
            ", [':prev' => $estadoPrevio, ':ch' => (int) $cheque['id_chequera'], ':n' => (int) $cheque['id_cheque']]);
            if ($upd2 === 0) {
                throw new \RuntimeException('El cheque ya no estaba anulado.');
            }

            $this->movimientoCheque($uuid, $detalle['chequera'], (int) $cheque['id_cheque'],
                (float) $detalle['monto'], (float) $detalle['tc'], 'V', 'N', $estadoPrevio,
                $motivo, (int) ($detalle['id_categoria'] ?? 0), 'REVERSION DE ANULACION: ' . $motivo,
                $codUsuario, $idAgencia, null, $ip);

            return [
                'success' => true,
                'message' => 'Anulación revertida. El cheque vuelve al estado '
                    . strtolower(self::ESTADOS[$estadoPrevio] ?? $estadoPrevio) . '.',
                'data' => ['id_cheque' => (int) $cheque['id_cheque'], 'estado' => $estadoPrevio],
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Internos
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Resuelve el cheque y comprueba que la agencia pueda operar sobre él.
     *
     * A diferencia del legacy, no basta con que la agencia sea dueña de la
     * chequera: una vez que el cheque se envía, **lo tiene la agencia destino**,
     * así que es ella la que debe poder anularlo o cobrarlo. Se acepta a la
     * agencia dueña de la chequera o a la última agencia que lo recibió.
     */
    private function chequeDeAgencia(int $idChequera, int $numero, int $idAgencia): array
    {
        $cheque = DB::selectOne("SELECT * FROM cheque WHERE id_chequera = :ch AND id_cheque = :n",
            [':ch' => $idChequera, ':n' => $numero]);
        if (!$cheque) {
            return ['error' => ['success' => false, 'message' => 'Cheque no encontrado.']];
        }
        $chequera = DB::selectOne("SELECT * FROM chequera WHERE id_chequera = :id", [':id' => $idChequera]);
        if (!$chequera) {
            return ['error' => ['success' => false, 'message' => 'Chequera no encontrada.']];
        }

        $esDueno = ((int) $chequera['id_agencia'] === $idAgencia);

        // Última agencia que tiene el cheque: la del último movimiento de
        // envío/recepción confirmado.
        $ultimoTenedor = null;
        $mov = DB::selectOne("
            SELECT m.id_agencia, m.id_agencia_destino, m.tipo_movimiento, m.estado_nuevo,
                   e.id_agencia_destino AS envio_destino, e.estado AS envio_estado
            FROM banco_cheque_mov m
            LEFT JOIN banco_cheque_envio e
                   ON e.id_chequera = m.id_chequera AND e.id_cheque = m.id_cheque
                  AND e.estado IN ('P','R')
            WHERE m.id_chequera = :ch AND m.id_cheque = :n
            ORDER BY m.id DESC LIMIT 1
        ", [':ch' => $idChequera, ':n' => $numero]);

        if ($mov) {
            if ($mov['tipo_movimiento'] === 'R') {
                // Recepción confirmada: el tenedor es la agencia destino del envío.
                $ultimoTenedor = $mov['envio_destino'] !== null
                    ? (int) $mov['envio_destino']
                    : (int) $mov['id_agencia'];
            } elseif ($mov['tipo_movimiento'] === 'V' && $mov['estado_nuevo'] === 'E') {
                // Enviado: todavía lo tiene el origen hasta que el destino reciba.
                $ultimoTenedor = (int) $mov['id_agencia'];
            } else {
                $ultimoTenedor = (int) $mov['id_agencia'];
            }
        }

        if (!$esDueno && $ultimoTenedor !== $idAgencia) {
            return ['error' => [
                'success' => false,
                'message' => 'El cheque no pertenece a su agencia.',
            ]];
        }

        return ['cheque' => $cheque, 'chequera' => $chequera];
    }

    /**
     * Datos derivados del cheque: chequera, cuenta, monto y tipo de cambio.
     *
     * IMPORTANTE: `id_cheque` NO es único globalmente — el mismo número se repite
     * entre agencias y cuentas (verificado: el cheque 247 aparece en 9
     * combinaciones agencia/cuenta distintas). `registro_cheque` tiene PK
     * compuesta `(id_cheque, num_cuenta_bn, id_entidad, id_agencia)`, así que hay
     * que filtrar por la clave COMPLETA; buscar solo por `id_cheque` devolvería el
     * registro de otra agencia.
     */
    private function detalleCheque(int $idChequera, int $numero): array
    {
        $chequera = DB::selectOne("
            SELECT ch.*, ef.nombre_corto AS entidad, m.abreviatura AS moneda
            FROM chequera ch
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = ch.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = ch.id_moneda
            WHERE ch.id_chequera = :id
        ", [':id' => $idChequera]);

        $reg = $this->registroCheque($chequera, $numero);

        $cuenta = DB::selectOne("
            SELECT cb.* FROM cuenta_bancaria cb
            WHERE cb.id_agencia = :ag AND cb.id_entidad_financiera = :ent
              AND cb.num_cuenta_bancaria = :num AND cb.estado = 'A' LIMIT 1
        ", [
            ':ag' => (int) $chequera['id_agencia'],
            ':ent' => (int) $chequera['id_entidad_financiera'],
            ':num' => $chequera['num_cuenta'],
        ]);

        $monto = (float) ($reg['monto'] ?? 0);
        $tc = ($cuenta && (int) $cuenta['id_moneda'] === 1) ? $this->tipoCambioOficialVenta() : 1.0;

        return [
            'chequera' => $chequera,
            'cuenta' => $cuenta,
            'monto' => $monto,
            'tc' => $tc,
            'id_categoria' => $reg['id_categoria_cheque'] ?? null,
        ];
    }

    /**
     * Registro del cheque acotado por su clave completa (agencia + cuenta +
     * entidad + número), que es la PK real de `registro_cheque`.
     */
    private function registroCheque(array $chequera, int $numero): ?array
    {
        return DB::selectOne("
            SELECT * FROM registro_cheque
            WHERE id_cheque = :n AND num_cuenta_bn = :num
              AND id_entidad = :ent AND id_agencia = :ag
            LIMIT 1
        ", [
            ':n' => $numero,
            ':num' => $chequera['num_cuenta'],
            ':ent' => (int) $chequera['id_entidad_financiera'],
            ':ag' => (int) $chequera['id_agencia'],
        ]);
    }

    /** Inserta una fila en el libro bancario (mismo criterio que BankingOpsService). */
    private function movimientoBanco(array $cuenta, float $monto, string $conciliacion, int $codUsuario, string $glosa): void
    {
        $monto = round($monto, 4);
        $glosa = mb_strtoupper($glosa);
        $intentos = 0;
        $desplazamiento = 0;

        while (true) {
            $intentos++;
            try {
                $ok = DB::execute("
                    INSERT INTO movimiento_cuenta_bancaria
                        (id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria,
                         id_moneda, monto, cod_usuario, fec_crea_movimiento_cuenta_bancaria, conciliacion, glosa)
                    VALUES (:ag, :tcb, :ent, :num, :mon, :monto, :usr,
                            DATE_ADD(NOW(), INTERVAL :desp SECOND), :conc, :glosa)
                ", [
                    ':ag' => (int) $cuenta['id_agencia'],
                    ':tcb' => (int) $cuenta['id_tipo_cuenta_bancaria'],
                    ':ent' => (int) $cuenta['id_entidad_financiera'],
                    ':num' => $cuenta['num_cuenta_bancaria'],
                    ':mon' => (int) $cuenta['id_moneda'],
                    ':monto' => $monto, ':usr' => $codUsuario, ':desp' => $desplazamiento,
                    ':conc' => $conciliacion, ':glosa' => $glosa,
                ]);
                if ($ok === 0) {
                    throw new \RuntimeException('No se pudo registrar el movimiento bancario.');
                }
                return;
            } catch (\PDOException $e) {
                $dup = ($e->getCode() === '23000' || strpos($e->getMessage(), '1062') !== false);
                if (!$dup || $intentos >= 4) throw $e;
                $desplazamiento++;
            }
        }
    }

    /** Trazabilidad del cheque. */
    private function movimientoCheque(string $uuid, array $chequera, int $numero, float $monto, float $tc,
                                      string $tipo, ?string $estadoPrev, ?string $estadoNuevo,
                                      ?string $concepto, ?int $idCategoria, string $glosa,
                                      int $codUsuario, int $idAgencia, ?int $idAgenciaDestino, string $ip): void
    {
        // El uuid identifica cada MOVIMIENTO, no la operación de negocio: envío y
        // recepción son dos movimientos distintos del mismo cheque y comparten el
        // uuid del envío, así que aquí se genera uno propio.
        $uuidMov = $this->uuid();

        DB::execute("
            INSERT INTO banco_cheque_mov
                (uuid, id_chequera, id_cheque, id_agencia, id_agencia_destino, id_entidad, id_tipo_cuenta,
                 num_cuenta, id_moneda, monto, monto_bob, tipo_cambio, tipo_movimiento,
                 estado_anterior, estado_nuevo, concepto, id_categoria, glosa, cod_usuario, ip_origen)
            VALUES (:uuid, :ch, :n, :ag, :agd, :ent, :tcb, :num, :mon, :monto, :montoBob, :tc, :tipo,
                    :prev, :nuevo, :concepto, :cat, :glosa, :usr, :ip)
        ", [
            ':uuid' => $uuidMov, ':ch' => (int) $chequera['id_chequera'], ':n' => $numero,
            ':ag' => $idAgencia, ':agd' => $idAgenciaDestino,
            ':ent' => (int) $chequera['id_entidad_financiera'],
            ':tcb' => 2, ':num' => $chequera['num_cuenta'], ':mon' => (int) $chequera['id_moneda'],
            ':monto' => $monto, ':montoBob' => round($monto * $tc, 2), ':tc' => $tc,
            ':tipo' => $tipo, ':prev' => $estadoPrev, ':nuevo' => $estadoNuevo,
            ':concepto' => $concepto, ':cat' => $idCategoria ?: null,
            ':glosa' => mb_strtoupper($glosa), ':usr' => $codUsuario, ':ip' => $ip,
        ]);
    }

    private function tipoCambioOficialVenta(): float
    {
        $v = DB::scalar("
            SELECT valor FROM cotizacion_oficial
            WHERE id_moneda = 1 AND id_operacion = 2 AND DATE(fec_crea_cotizacion_oficial) <= CURDATE()
            ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1
        ");
        return $v ? (float) $v : 1.0;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
