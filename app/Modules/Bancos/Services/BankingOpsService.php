<?php

namespace App\Modules\Bancos\Services;

use App\Modules\Shared\Database\RawConnection as DB;

/**
 * Operaciones de BANCOS del POS de cajeros.
 *
 * ── Por qué existe ───────────────────────────────────────────────────────────
 * El módulo legacy (`bo_cgr_hostinger/bancos`) resuelve traspasos, recepción,
 * reversión y depósitos con endpoints sin autenticación, SQL concatenado, sin
 * transacciones y sin validar fondos. Esta clase reimplementa ese flujo sobre
 * las MISMAS tablas de negocio (`traspaso_cuenta_bancaria`,
 * `movimiento_cuenta_bancaria`, `cuenta_bancaria`), de modo que la contabilidad
 * y los reportes existentes siguen funcionando, pero con:
 *
 *   · validación de fondos antes de debitar
 *   · transacción atómica (el cargo y el traspaso van juntos o no van)
 *   · idempotencia por uuid (un doble submit no duplica)
 *   · controles de estado y de agencia en recepción y reversión
 *   · trazabilidad del asiento contable para poder revertirlo
 *   · recepción manual por el destino, como en el legacy
 *
 * ── Códigos de conciliación ──────────────────────────────────────────────────
 * Se conservan los del legacy para no romper los reportes ya existentes:
 *
 *   ST = salida por traspaso (cargo al origen, al registrar)
 *   ET = entrada por traspaso (abono al destino, al aceptar)
 *   RT = reintegro al origen (al revertir o rechazar)
 *
 * El saldo de una cuenta se calcula como `SUM(monto)` de sus movimientos: a
 * diferencia de `saldo_caja`, aquí `movimiento_cuenta_bancaria` NO mezcla filas
 * de arqueo con deltas — el cierre ('C') es un importe absoluto que también se
 * acumula, y la apertura ('A') idem. Se replica el criterio que ya usaba
 * `BankService::saldos()` para no cambiar el significado de los saldos.
 */
class BankingOpsService
{
    /** Conciliaciones del libro bancario. */
    private const CONC_SALIDA_TRASPASO  = 'ST';
    private const CONC_ENTRADA_TRASPASO = 'ET';
    private const CONC_REINTEGRO        = 'RT';

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

    /**
     * Todas las agencias activas.
     *
     * Los traspasos son inter-agencia, así que el POS necesita el listado
     * completo: `/auth/agencies` solo devuelve aquellas a las que el usuario
     * tiene acceso, y eso no sirve para elegir una cuenta destino.
     */
    public function agencias(): array
    {
        return DB::select("
            SELECT id_agencia, cod_agencia, abreviatura, descripcion
            FROM agencia
            WHERE estado = 'A'
            ORDER BY descripcion
        ");
    }

    /**
     * Cuentas bancarias con su saldo real y las que son accesibles como origen.
     *
     * `propia` indica si la cuenta pertenece a la agencia del cajero: solo las
     * propias pueden ser origen (nadie gira desde una cuenta ajena), pero
     * cualquier cuenta activa puede ser destino (traspaso inter-agencia).
     */
    public function cuentas(int $idAgencia): array
    {
        $rows = DB::select("
            SELECT cb.id_agencia, cb.id_tipo_cuenta_bancaria, cb.id_entidad_financiera,
                   cb.num_cuenta_bancaria, cb.id_moneda, cb.codsubcuenta,
                   a.descripcion AS agencia, a.abreviatura AS agencia_abr, a.cod_agencia,
                   ef.nombre_corto AS entidad, ef.descripcion AS entidad_desc,
                   m.abreviatura AS moneda, tcb.descripcion AS tipo_cuenta, tcb.abreviatura AS tipo_cuenta_abr
            FROM cuenta_bancaria cb
            INNER JOIN agencia a ON a.id_agencia = cb.id_agencia
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = cb.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            INNER JOIN tipo_cuenta_bancaria tcb ON tcb.id_tipo_cuenta_bancaria = cb.id_tipo_cuenta_bancaria
            WHERE cb.estado = 'A'
            ORDER BY a.descripcion, m.abreviatura, ef.nombre_corto
        ");

        $out = [];
        foreach ($rows as $r) {
            $saldo = $this->balance->saldoCuenta(
                (int) $r['id_agencia'],
                (int) $r['id_entidad_financiera'],
                $r['num_cuenta_bancaria'],
                (int) $r['id_moneda']
            );

            $out[] = [
                'id_agencia'          => (int) $r['id_agencia'],
                'agencia'             => $r['agencia'],
                'agencia_abr'         => $r['agencia_abr'],
                'cod_agencia'         => $r['cod_agencia'],
                'id_entidad'          => (int) $r['id_entidad_financiera'],
                'entidad'             => $r['entidad'],
                'entidad_desc'        => $r['entidad_desc'],
                'id_tipo_cuenta'      => (int) $r['id_tipo_cuenta_bancaria'],
                'tipo_cuenta'         => $r['tipo_cuenta'],
                'tipo_cuenta_abr'     => $r['tipo_cuenta_abr'],
                'num_cuenta'          => $r['num_cuenta_bancaria'],
                'id_moneda'           => (int) $r['id_moneda'],
                'moneda'              => $r['moneda'],
                'codsubcuenta'        => $r['codsubcuenta'],
                'saldo'               => $saldo,
                'propia'              => ((int) $r['id_agencia'] === $idAgencia),
            ];
        }

        return $out;
    }

    /**
     * Identifica una cuenta por su clave natural. Devuelve null si no existe o
     * no está activa.
     */
    private function cuenta(int $idAgencia, int $idEntidad, int $idTipoCuenta, string $numCuenta, int $idMoneda): ?array
    {
        return DB::selectOne("
            SELECT cb.*, ef.nombre_corto AS entidad, m.abreviatura AS moneda
            FROM cuenta_bancaria cb
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = cb.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            WHERE cb.id_agencia = :ag AND cb.id_entidad_financiera = :ent
              AND cb.id_tipo_cuenta_bancaria = :tcb AND cb.num_cuenta_bancaria = :num
              AND cb.id_moneda = :mon AND cb.estado = 'A'
            LIMIT 1
        ", [
            ':ag' => $idAgencia, ':ent' => $idEntidad, ':tcb' => $idTipoCuenta,
            ':num' => $numCuenta, ':mon' => $idMoneda,
        ]);
    }

    /**
     * Traspasos donde la agencia participa, como origen o destino.
     */
    public function traspasos(int $idAgencia, ?string $estado = null, ?string $desde = null, ?string $hasta = null, int $limite = 200): array
    {
        $cond = ['(t.id_agencia_origen = :ag OR t.id_agencia_destino = :ag)'];
        $params = [':ag' => $idAgencia];

        if ($estado !== null && $estado !== '' && $estado !== 'TODO') {
            $cond[] = 't.estado = :estado';
            $params[':estado'] = $estado;
        }
        if ($desde) { $cond[] = 'DATE(t.created_at) >= :desde'; $params[':desde'] = $desde; }
        if ($hasta) { $cond[] = 'DATE(t.created_at) <= :hasta'; $params[':hasta'] = $hasta; }

        $where = implode(' AND ', $cond);

        $rows = DB::select("
            SELECT t.*,
                   ao.descripcion AS agencia_origen_desc, ao.abreviatura AS agencia_origen_abr,
                   ad.descripcion AS agencia_destino_desc, ad.abreviatura AS agencia_destino_abr,
                   efo.nombre_corto AS entidad_origen, efd.nombre_corto AS entidad_destino,
                   mo.abreviatura AS moneda,
                   uo.login_usu AS usuario_origen, ud.login_usu AS usuario_destino
            FROM banco_traspaso t
            INNER JOIN agencia ao ON ao.id_agencia = t.id_agencia_origen
            INNER JOIN agencia ad ON ad.id_agencia = t.id_agencia_destino
            INNER JOIN entidad_financiera efo ON efo.id_entidad_financiera = t.id_entidad_origen
            INNER JOIN entidad_financiera efd ON efd.id_entidad_financiera = t.id_entidad_destino
            INNER JOIN moneda mo ON mo.id_moneda = t.id_moneda
            LEFT JOIN usuario uo ON uo.cod_usuario = t.cod_usuario_origen
            LEFT JOIN usuario ud ON ud.cod_usuario = t.cod_usuario_destino
            WHERE {$where}
            ORDER BY t.id DESC
            LIMIT " . (int) $limite . "
        ", $params);

        return array_map([$this, 'mapTraspaso'], $rows);
    }

    /** Traspasos pendientes de aceptar por esta agencia. */
    public function traspasosPendientes(int $idAgencia): array
    {
        $rows = DB::select("
            SELECT t.*,
                   ao.descripcion AS agencia_origen_desc, ao.abreviatura AS agencia_origen_abr,
                   ad.descripcion AS agencia_destino_desc, ad.abreviatura AS agencia_destino_abr,
                   efo.nombre_corto AS entidad_origen, efd.nombre_corto AS entidad_destino,
                   mo.abreviatura AS moneda,
                   uo.login_usu AS usuario_origen, ud.login_usu AS usuario_destino
            FROM banco_traspaso t
            INNER JOIN agencia ao ON ao.id_agencia = t.id_agencia_origen
            INNER JOIN agencia ad ON ad.id_agencia = t.id_agencia_destino
            INNER JOIN entidad_financiera efo ON efo.id_entidad_financiera = t.id_entidad_origen
            INNER JOIN entidad_financiera efd ON efd.id_entidad_financiera = t.id_entidad_destino
            INNER JOIN moneda mo ON mo.id_moneda = t.id_moneda
            LEFT JOIN usuario uo ON uo.cod_usuario = t.cod_usuario_origen
            LEFT JOIN usuario ud ON ud.cod_usuario = t.cod_usuario_destino
            WHERE t.id_agencia_destino = :ag AND t.estado = 'P'
            ORDER BY t.id ASC
        ", [':ag' => $idAgencia]);

        return array_map([$this, 'mapTraspaso'], $rows);
    }

    private function mapTraspaso(array $r): array
    {
        $estados = ['P' => 'Pendiente', 'A' => 'Aceptado', 'X' => 'Rechazado', 'R' => 'Revertido'];

        return [
            'id'                    => (int) $r['id'],
            'uuid'                  => $r['uuid'],
            'id_agencia_origen'     => (int) $r['id_agencia_origen'],
            'agencia_origen'        => $r['agencia_origen_desc'],
            'agencia_origen_abr'    => $r['agencia_origen_abr'],
            'id_entidad_origen'     => (int) $r['id_entidad_origen'],
            'entidad_origen'        => $r['entidad_origen'],
            'id_tipo_cuenta_origen' => (int) $r['id_tipo_cuenta_origen'],
            'num_cuenta_origen'     => $r['num_cuenta_origen'],
            'id_agencia_destino'    => (int) $r['id_agencia_destino'],
            'agencia_destino'       => $r['agencia_destino_desc'],
            'agencia_destino_abr'   => $r['agencia_destino_abr'],
            'id_entidad_destino'    => (int) $r['id_entidad_destino'],
            'entidad_destino'       => $r['entidad_destino'],
            'num_cuenta_destino'    => $r['num_cuenta_destino'],
            'id_moneda'             => (int) $r['id_moneda'],
            'moneda'                => $r['moneda'],
            'monto'                 => (float) $r['monto'],
            'documento_origen'      => $r['documento_origen'],
            'documento_destino'     => $r['documento_destino'],
            'glosa'                 => $r['glosa'],
            'observaciones'         => $r['observaciones'],
            'estado'                => $r['estado'],
            'estado_label'          => $estados[$r['estado']] ?? $r['estado'],
            'usuario_origen'        => $r['usuario_origen'],
            'usuario_destino'       => $r['usuario_destino'],
            'fecha'                 => $r['created_at'],
            'fec_recepcion'         => $r['fec_recepcion'],
            'fec_reversion'         => $r['fec_reversion'],
            'comprobante_path'      => $r['comprobante_path'] ?? null,
            'comprobante_nombre'    => $r['comprobante_nombre'] ?? null,
            'es_origen'             => true, // el filtro ya garantiza participación
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Registrar traspaso (origen)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Registra un traspaso entre cuentas bancarias y carga el monto al origen.
     *
     * A diferencia del legacy, aquí SÍ se valida que la cuenta origen tenga
     * fondos suficientes y todo ocurre dentro de una transacción: si el cargo
     * falla, no queda el traspaso colgado.
     */
    public function registrarTraspaso(array $in, int $codUsuario, int $idAgencia, string $ip): array
    {
        $uuid = trim($in['uuid'] ?? '');
        if ($uuid === '') {
            return ['success' => false, 'message' => 'Falta el identificador de la operación (uuid).'];
        }

        $monto = round((float) ($in['monto'] ?? 0), 4);
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0.'];
        }

        $documento = trim((string) ($in['documento'] ?? ''));
        if ($documento === '') {
            return ['success' => false, 'message' => 'El número de transacción/comprobante es requerido.'];
        }

        // Idempotencia: si el uuid ya existe se devuelve el traspaso previo.
        $previo = DB::selectOne("SELECT id FROM banco_traspaso WHERE uuid = :uuid", [':uuid' => $uuid]);
        if ($previo) {
            $actual = DB::selectOne("SELECT * FROM banco_traspaso WHERE id = :id", [':id' => $previo['id']]);
            return ['success' => true, 'message' => 'El traspaso ya había sido registrado.', 'data' => $this->mapTraspaso($this->hidratar($actual))];
        }

        // ── Cuenta origen: debe ser de la agencia del cajero ──────────────
        $origen = $this->cuenta(
            (int) ($in['id_agencia_origen'] ?? $idAgencia),
            (int) ($in['id_entidad_origen'] ?? 0),
            (int) ($in['id_tipo_cuenta_origen'] ?? 0),
            trim((string) ($in['num_cuenta_origen'] ?? '')),
            (int) ($in['id_moneda'] ?? 0)
        );
        if (!$origen) {
            return ['success' => false, 'message' => 'La cuenta de origen no existe o no está activa.'];
        }
        if ((int) $origen['id_agencia'] !== $idAgencia) {
            return ['success' => false, 'message' => 'Solo puede girar desde cuentas de su propia agencia.'];
        }

        // ── Cuenta destino ────────────────────────────────────────────────
        $destino = $this->cuenta(
            (int) ($in['id_agencia_destino'] ?? 0),
            (int) ($in['id_entidad_destino'] ?? 0),
            (int) ($in['id_tipo_cuenta_destino'] ?? 0),
            trim((string) ($in['num_cuenta_destino'] ?? '')),
            (int) ($in['id_moneda'] ?? 0)
        );
        if (!$destino) {
            return ['success' => false, 'message' => 'La cuenta de destino no existe o no está activa.'];
        }

        $mismaCuenta = ((int) $origen['id_agencia'] === (int) $destino['id_agencia'])
            && ($origen['num_cuenta_bancaria'] === $destino['num_cuenta_bancaria'])
            && ((int) $origen['id_moneda'] === (int) $destino['id_moneda']);
        if ($mismaCuenta) {
            return ['success' => false, 'message' => 'La cuenta de origen y destino no pueden ser la misma.'];
        }

        // ── Validación de fondos (lo que el legacy NO hacía) ──────────────
        $saldo = $this->balance->saldoCuenta(
            (int) $origen['id_agencia'],
            (int) $origen['id_entidad_financiera'],
            $origen['num_cuenta_bancaria'],
            (int) $origen['id_moneda']
        );
        if ($monto > $saldo) {
            return [
                'success' => false,
                'message' => 'Saldo insuficiente en la cuenta de origen. Disponible: '
                           . number_format($saldo, 2) . ' ' . $origen['moneda'] . '.',
            ];
        }

        $glosa = $this->glosaTraspaso($origen, $destino, $documento, false);

        return DB::transaction(function () use ($in, $origen, $destino, $monto, $documento, $glosa, $uuid, $codUsuario, $idAgencia, $ip) {
            $ok = DB::execute("
                INSERT INTO banco_traspaso
                    (uuid, id_agencia_origen, id_entidad_origen, id_tipo_cuenta_origen, num_cuenta_origen,
                     id_moneda, documento_origen, id_agencia_destino, id_entidad_destino, id_tipo_cuenta_destino,
                     num_cuenta_destino, monto, glosa, observaciones, estado, cod_usuario_origen, ip_origen)
                VALUES
                    (:uuid, :ago, :ento, :tbco, :ctao, :mon, :doc, :agd, :entd, :tbcd, :ctad, :monto, :glosa,
                     :obs, 'P', :usr, :ip)
            ", [
                ':uuid'  => $uuid,
                ':ago'   => (int) $origen['id_agencia'],
                ':ento'  => (int) $origen['id_entidad_financiera'],
                ':tbco'  => (int) $origen['id_tipo_cuenta_bancaria'],
                ':ctao'  => $origen['num_cuenta_bancaria'],
                ':mon'   => (int) $origen['id_moneda'],
                ':doc'   => $documento,
                ':agd'   => (int) $destino['id_agencia'],
                ':entd'  => (int) $destino['id_entidad_financiera'],
                ':tbcd'  => (int) $destino['id_tipo_cuenta_bancaria'],
                ':ctad'  => $destino['num_cuenta_bancaria'],
                ':monto' => $monto,
                ':glosa' => $glosa,
                ':obs'   => trim((string) ($in['observaciones'] ?? '')) ?: null,
                ':usr'   => $codUsuario,
                ':ip'    => $ip,
            ]);
            if ($ok === 0) {
                throw new \RuntimeException('No se pudo crear el traspaso.');
            }

            $id = (int) DB::scalar("SELECT id FROM banco_traspaso WHERE uuid = :uuid", [':uuid' => $uuid]);

            // Espejo en la tabla del legacy para que la contabilidad y los
            // reportes existentes lo sigan viendo.
            DB::execute("
                INSERT INTO traspaso_cuenta_bancaria
                    (ent_fin_origen, num_cuenta_origen, agencia_origen, documento_origen,
                     ent_fin_destino, num_cuenta_destino, agencia_destino, documento_destino,
                     monto_total, fec_crea_traspaso_cuenta_bancaria, fec_mod_traspaso_cuenta_bancaria, estado)
                VALUES (:ento, :ctao, :ago, :doc, :entd, :ctad, :agd, NULL, :monto, NOW(), NULL, 'P')
            ", [
                ':ento' => (int) $origen['id_entidad_financiera'],
                ':ctao' => $origen['num_cuenta_bancaria'],
                ':ago'  => (int) $origen['id_agencia'],
                ':doc'  => $documento,
                ':entd' => (int) $destino['id_entidad_financiera'],
                ':ctad' => $destino['num_cuenta_bancaria'],
                ':agd'  => (int) $destino['id_agencia'],
                ':monto' => $monto,
            ]);

            // Cargo al origen.
            $this->movimientoBanco($origen, -$monto, self::CONC_SALIDA_TRASPASO, $codUsuario, $glosa);

            return [
                'success' => true,
                'message' => 'Traspaso registrado. Queda pendiente de recepción por la agencia destino.',
                'data'    => ['id' => $id, 'uuid' => $uuid, 'monto' => $monto, 'saldo_origen_nuevo' => $this->balance->saldoCuenta(
                    (int) $origen['id_agencia'], (int) $origen['id_entidad_financiera'],
                    $origen['num_cuenta_bancaria'], (int) $origen['id_moneda']
                )],
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Aceptar / rechazar (destino)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * El destino confirma la recepción: acredita su cuenta y genera el asiento.
     */
    public function aceptarTraspaso(int $id, int $codUsuario, int $idAgencia, string $documento, string $ip): array
    {
        $documento = trim($documento);
        if ($documento === '') {
            return ['success' => false, 'message' => 'El número de comprobante es requerido.'];
        }

        $t = DB::selectOne("SELECT * FROM banco_traspaso WHERE id = :id", [':id' => $id]);
        if (!$t) {
            return ['success' => false, 'message' => 'Traspaso no encontrado.'];
        }
        if ((int) $t['id_agencia_destino'] !== $idAgencia) {
            return ['success' => false, 'message' => 'Este traspaso no corresponde a su agencia.'];
        }
        if ($t['estado'] !== 'P') {
            return ['success' => false, 'message' => 'Este traspaso ya fue ' . strtolower($this->estadoLabel($t['estado'])) . '.'];
        }

        $origen = $this->cuenta(
            (int) $t['id_agencia_origen'], (int) $t['id_entidad_origen'],
            (int) $t['id_tipo_cuenta_origen'], $t['num_cuenta_origen'], (int) $t['id_moneda']
        );
        $destino = $this->cuenta(
            (int) $t['id_agencia_destino'], (int) $t['id_entidad_destino'],
            (int) $t['id_tipo_cuenta_destino'], $t['num_cuenta_destino'], (int) $t['id_moneda']
        );
        if (!$origen || !$destino) {
            return ['success' => false, 'message' => 'Alguna de las cuentas ya no está activa.'];
        }

        $monto = (float) $t['monto'];
        $glosa = $this->glosaTraspaso($origen, $destino, $documento, true);

        $asientoEncolado = null;

        $resultado = DB::transaction(function () use ($t, $id, $origen, $destino, $monto, $glosa, $documento, $codUsuario, $ip, &$asientoEncolado) {
            // Bloqueo lógico: solo avanza si sigue pendiente.
            $upd = DB::execute("
                UPDATE banco_traspaso
                SET estado = 'A', documento_destino = :doc, cod_usuario_destino = :usr,
                    fec_recepcion = NOW(), ip_recepcion = :ip
                WHERE id = :id AND estado = 'P'
            ", [':doc' => $documento, ':usr' => $codUsuario, ':ip' => $ip, ':id' => $id]);
            if ($upd === 0) {
                throw new \RuntimeException('El traspaso ya no está pendiente.');
            }

            // Espejo en el legacy.
            DB::execute("
                UPDATE traspaso_cuenta_bancaria
                SET documento_destino = :doc, estado = 'A', fec_mod_traspaso_cuenta_bancaria = NOW()
                WHERE id_traspaso_cuenta_bancaria = (
                    SELECT id FROM (
                        SELECT MIN(id_traspaso_cuenta_bancaria) AS id
                        FROM traspaso_cuenta_bancaria
                        WHERE agencia_origen = :ago AND agencia_destino = :agd
                          AND num_cuenta_origen = :ctao AND num_cuenta_destino = :ctad
                          AND monto_total = :monto AND estado = 'P'
                    ) x
                )
            ", [
                ':doc' => $documento,
                ':ago' => (int) $t['id_agencia_origen'], ':agd' => (int) $t['id_agencia_destino'],
                ':ctao' => $t['num_cuenta_origen'], ':ctad' => $t['num_cuenta_destino'],
                ':monto' => $monto,
            ]);

            // Abono al destino.
            $this->movimientoBanco($destino, $monto, self::CONC_ENTRADA_TRASPASO, $codUsuario, $glosa);

            // El asiento NO se escribe a `conta` aquí dentro: es otra conexión y
            // quedaría fuera de esta transacción (ver `encolarAsiento`). Se
            // prepara el payload y se contabiliza después del commit.
            $asientoEncolado = $this->prepararAsiento($origen, $destino, $monto, $glosa);

            return [
                'success' => true,
                'message' => 'Recepción confirmada. Se acreditó la cuenta.',
                'data'    => [
                    'id' => $id,
                    'estado' => 'A',
                    'saldo_destino_nuevo' => $this->balance->saldoCuenta(
                        (int) $destino['id_agencia'], (int) $destino['id_entidad_financiera'],
                        $destino['num_cuenta_bancaria'], (int) $destino['id_moneda']
                    ),
                ],
            ];
        });

        // Fuera de la transacción: ahora sí, el asiento en `conta`.
        $asiento = $this->contabilizar($asientoEncolado, $t['uuid'], 'traspaso_aceptacion', $id);
        $resultado['data']['asiento'] = $asiento;
        $resultado['message'] = $asiento['contabilizado']
            ? 'Recepción confirmada. Se acreditó la cuenta y se generó el asiento contable.'
            : 'Recepción confirmada. El asiento contable quedó en cola para su contabilización.';

        return $resultado;
    }

    /**
     * El destino rechaza: se devuelve el monto al origen.
     */
    public function rechazarTraspaso(int $id, int $codUsuario, int $idAgencia, string $motivo, string $ip): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            return ['success' => false, 'message' => 'Debe indicar el motivo del rechazo.'];
        }

        $t = DB::selectOne("SELECT * FROM banco_traspaso WHERE id = :id", [':id' => $id]);
        if (!$t) {
            return ['success' => false, 'message' => 'Traspaso no encontrado.'];
        }
        if ((int) $t['id_agencia_destino'] !== $idAgencia) {
            return ['success' => false, 'message' => 'Este traspaso no corresponde a su agencia.'];
        }
        if ($t['estado'] !== 'P') {
            return ['success' => false, 'message' => 'Este traspaso ya fue ' . strtolower($this->estadoLabel($t['estado'])) . '.'];
        }

        $origen = $this->cuenta(
            (int) $t['id_agencia_origen'], (int) $t['id_entidad_origen'],
            (int) $t['id_tipo_cuenta_origen'], $t['num_cuenta_origen'], (int) $t['id_moneda']
        );
        if (!$origen) {
            return ['success' => false, 'message' => 'La cuenta de origen ya no está activa.'];
        }

        $monto = (float) $t['monto'];
        $glosa = 'RECHAZO TRASPASO #' . $id . ' - ' . $motivo;

        return DB::transaction(function () use ($t, $id, $origen, $monto, $glosa, $motivo, $codUsuario, $ip) {
            $upd = DB::execute("
                UPDATE banco_traspaso
                SET estado = 'X', motivo_rechazo = :motivo, observaciones = :motivo,
                    cod_usuario_destino = :usr, fec_recepcion = NOW(), ip_recepcion = :ip
                WHERE id = :id AND estado = 'P'
            ", [':motivo' => $motivo, ':usr' => $codUsuario, ':ip' => $ip, ':id' => $id]);
            if ($upd === 0) {
                throw new \RuntimeException('El traspaso ya no está pendiente.');
            }

            DB::execute("
                UPDATE traspaso_cuenta_bancaria SET estado = 'R', fec_mod_traspaso_cuenta_bancaria = NOW()
                WHERE agencia_origen = :ago AND agencia_destino = :agd
                  AND num_cuenta_origen = :ctao AND num_cuenta_destino = :ctad
                  AND monto_total = :monto AND estado = 'P'
            ", [
                ':ago' => (int) $t['id_agencia_origen'], ':agd' => (int) $t['id_agencia_destino'],
                ':ctao' => $t['num_cuenta_origen'], ':ctad' => $t['num_cuenta_destino'],
                ':monto' => $monto,
            ]);

            $this->movimientoBanco($origen, $monto, self::CONC_REINTEGRO, $codUsuario, $glosa);

            return [
                'success' => true,
                'message' => 'Traspaso rechazado. El monto fue devuelto a la cuenta de origen.',
                'data'    => ['id' => $id, 'estado' => 'X', 'monto_devuelto' => $monto],
            ];
        });
    }

    /**
     * El origen revierte un traspaso que el destino todavía no aceptó.
     *
     * El legacy permitía revertir CUALQUIER estado, incluso una ya aceptada, y
     * en ese caso reingresaba el monto solo al origen sin debitar el destino:
     * el mismo dinero quedaba contado dos veces. Aquí solo se permite mientras
     * sigue pendiente.
     */
    public function revertirTraspaso(int $id, int $codUsuario, int $idAgencia, string $motivo, string $ip): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            return ['success' => false, 'message' => 'Debe indicar el motivo de la reversión.'];
        }

        $t = DB::selectOne("SELECT * FROM banco_traspaso WHERE id = :id", [':id' => $id]);
        if (!$t) {
            return ['success' => false, 'message' => 'Traspaso no encontrado.'];
        }
        if ((int) $t['id_agencia_origen'] !== $idAgencia) {
            return ['success' => false, 'message' => 'Solo la agencia de origen puede revertir el traspaso.'];
        }
        if ($t['estado'] === 'A') {
            return [
                'success' => false,
                'message' => 'El traspaso ya fue aceptado por el destino. '
                           . 'Para deshacerlo debe coordinarse una operación inversa, no una reversión.',
            ];
        }
        if ($t['estado'] !== 'P') {
            return ['success' => false, 'message' => 'Este traspaso ya fue ' . strtolower($this->estadoLabel($t['estado'])) . '.'];
        }

        $origen = $this->cuenta(
            (int) $t['id_agencia_origen'], (int) $t['id_entidad_origen'],
            (int) $t['id_tipo_cuenta_origen'], $t['num_cuenta_origen'], (int) $t['id_moneda']
        );
        if (!$origen) {
            return ['success' => false, 'message' => 'La cuenta de origen ya no está activa.'];
        }

        $monto = (float) $t['monto'];
        $glosa = 'REVERSION TRASPASO #' . $id . ' - ' . $motivo;

        return DB::transaction(function () use ($t, $id, $origen, $monto, $glosa, $motivo, $codUsuario, $ip) {
            $upd = DB::execute("
                UPDATE banco_traspaso
                SET estado = 'R', observaciones = :motivo, cod_usuario_reversion = :usr,
                    fec_reversion = NOW()
                WHERE id = :id AND estado = 'P'
            ", [':motivo' => $motivo, ':usr' => $codUsuario, ':id' => $id]);
            if ($upd === 0) {
                throw new \RuntimeException('El traspaso ya no está pendiente.');
            }

            DB::execute("
                UPDATE traspaso_cuenta_bancaria SET estado = 'R', fec_mod_traspaso_cuenta_bancaria = NOW()
                WHERE agencia_origen = :ago AND agencia_destino = :agd
                  AND num_cuenta_origen = :ctao AND num_cuenta_destino = :ctad
                  AND monto_total = :monto AND estado = 'P'
            ", [
                ':ago' => (int) $t['id_agencia_origen'], ':agd' => (int) $t['id_agencia_destino'],
                ':ctao' => $t['num_cuenta_origen'], ':ctad' => $t['num_cuenta_destino'],
                ':monto' => $monto,
            ]);

            $this->movimientoBanco($origen, $monto, self::CONC_REINTEGRO, $codUsuario, $glosa);

            return [
                'success' => true,
                'message' => 'Traspaso revertido. El monto fue reintegrado a la cuenta de origen.',
                'data'    => ['id' => $id, 'estado' => 'R', 'monto_reintegrado' => $monto],
            ];
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Depósitos y retiros caja ↔ banco
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Deposita efectivo de una caja en una cuenta bancaria, o retira efectivo
     * del banco hacia la caja.
     *
     * ── Qué faltaba ──────────────────────────────────────────────────────────
     * El POS ya tenía `transaccionBancaria`, pero solo escribía en `saldo_caja`:
     * el saldo del BANCO nunca se movía, así que la cuenta quedaba descuadrada
     * respecto a la caja y el legacy `deposito_cuenta` tampoco se reflejaba.
     * Aquí el movimiento es doble y atómico: caja y banco, o ninguno.
     *
     * En un depósito el efectivo sale de la caja (conciliación `DB`, negativa) y
     * entra al banco (`DE`). En un retiro, al revés: entra a la caja (`RB`) y sale
     * del banco (`SE`).
     */
    public function registrarDepositoRetiro(
        string $tipo,
        int $idCaja,
        int $idAgencia,
        int $codUsuario,
        array $cuentaClave,
        float $monto,
        ?string $referencia,
        ?string $glosa,
        ?string $comprobantePath,
        string $uuid,
        string $ip
    ): array {
        $tipo = strtoupper(trim($tipo));
        if (!in_array($tipo, ['D', 'R'], true)) {
            return ['success' => false, 'message' => 'Tipo de operación inválido (D = depósito, R = retiro).'];
        }
        if ($uuid === '') {
            return ['success' => false, 'message' => 'Falta el identificador de la operación (uuid).'];
        }
        $monto = round($monto, 4);
        if ($monto <= 0) {
            return ['success' => false, 'message' => 'El monto debe ser mayor a 0.'];
        }

        $previo = DB::selectOne("SELECT id, tipo, monto, estado FROM banco_deposito WHERE uuid = :uuid", [':uuid' => $uuid]);
        if ($previo) {
            return ['success' => true, 'message' => 'La operación ya había sido registrada.', 'data' => $previo];
        }

        // La caja debe pertenecer al cajero y estar abierta hoy.
        $caja = DB::selectOne("
            SELECT * FROM caja
            WHERE id_caja = :id AND cod_usuario = :usr AND estado = 'A' AND id_tipo_unidad = 2
        ", [':id' => $idCaja, ':usr' => $codUsuario]);
        if (!$caja) {
            return ['success' => false, 'message' => 'Caja no encontrada o no pertenece al usuario.'];
        }

        $sesion = DB::selectOne("
            SELECT 1 FROM pos_sesion_caja
            WHERE id_caja = :id AND fecha = CURDATE() AND estado = 'abierta' LIMIT 1
        ", [':id' => $idCaja]);
        if (!$sesion) {
            return ['success' => false, 'message' => 'La caja no está abierta.'];
        }

        // La cuenta debe existir, estar activa y ser de la agencia.
        $cuenta = $this->cuenta(
            (int) ($cuentaClave['id_agencia'] ?? $idAgencia),
            (int) ($cuentaClave['id_entidad'] ?? 0),
            (int) ($cuentaClave['id_tipo_cuenta'] ?? 0),
            trim((string) ($cuentaClave['num_cuenta'] ?? '')),
            (int) ($cuentaClave['id_moneda'] ?? 0)
        );
        if (!$cuenta) {
            return ['success' => false, 'message' => 'La cuenta bancaria no existe o no está activa.'];
        }
        if ((int) $cuenta['id_agencia'] !== $idAgencia) {
            return ['success' => false, 'message' => 'La cuenta bancaria no pertenece a su agencia.'];
        }
        // Caja y cuenta deben operar en la misma moneda.
        if ((int) $cuenta['id_moneda'] !== (int) $caja['id_moneda']) {
            $mc = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $cuenta['id_moneda']]);
            $mj = DB::scalar("SELECT abreviatura FROM moneda WHERE id_moneda = :m", [':m' => $caja['id_moneda']]);
            return ['success' => false, 'message' => "La caja opera en {$mj} y la cuenta en {$mc}. Deben coincidir."];
        }

        $saldoCaja = $this->caja->saldoCaja($idCaja, date('Y-m-d'));
        $saldoBanco = $this->balance->saldoCuenta(
            (int) $cuenta['id_agencia'], (int) $cuenta['id_entidad_financiera'],
            $cuenta['num_cuenta_bancaria'], (int) $cuenta['id_moneda']
        );

        if ($tipo === 'D') {
            if ($monto > $saldoCaja) {
                return ['success' => false, 'message' => 'Saldo insuficiente en caja. Disponible: '
                    . number_format($saldoCaja, 2) . '.'];
            }
            $concCaja = 'DB';   // depósito bancario: sale efectivo de caja
            $signoCaja = -1.0;
            $concBanco = 'DE';  // entra al banco
            $signoBanco = 1.0;
            $label = 'Depósito';
        } else {
            if ($monto > $saldoBanco) {
                return ['success' => false, 'message' => 'Saldo insuficiente en la cuenta bancaria. Disponible: '
                    . number_format($saldoBanco, 2) . ' ' . $cuenta['moneda'] . '.'];
            }
            $concCaja = 'RB';   // retiro bancario: entra efectivo a caja
            $signoCaja = 1.0;
            $concBanco = 'SE';  // sale del banco
            $signoBanco = -1.0;
            $label = 'Retiro';
        }

        // No se puede exceder el máximo de la caja al retirar.
        if ($tipo === 'R' && ($saldoCaja + $monto) > (float) $caja['maximo']) {
            return ['success' => false, 'message' => 'El retiro excedería el máximo de la caja ('
                . number_format((float) $caja['maximo'], 2) . ').'];
        }

        $tc = (int) $cuenta['id_moneda'] === 1 ? $this->tipoCambioOficialVenta() : 1.0;
        $montoBob = round($monto * $tc, 2);

        $textoGlosa = trim((string) $glosa);
        if ($textoGlosa === '') {
            $textoGlosa = $label . ' ' . $cuenta['entidad'] . ' cta ' . $cuenta['num_cuenta_bancaria'];
        }
        if ($referencia) {
            $textoGlosa .= ' (Ref: ' . $referencia . ')';
        }

        return DB::transaction(function () use (
            $tipo, $idCaja, $caja, $idAgencia, $codUsuario, $cuenta, $monto, $montoBob, $tc, $label,
            $referencia, $textoGlosa, $comprobantePath, $uuid, $ip, $concCaja, $signoCaja, $concBanco, $signoBanco
        ) {
            // 1. Documento operativo
            $ok = DB::execute("
                INSERT INTO banco_deposito
                    (uuid, tipo, id_agencia, id_caja, id_entidad, id_tipo_cuenta, num_cuenta, id_moneda,
                     monto, monto_bob, tipo_cambio, referencia, glosa, comprobante_path, estado,
                     cod_usuario, ip_origen)
                VALUES
                    (:uuid, :tipo, :ag, :caja, :ent, :tcb, :num, :mon,
                     :monto, :montoBob, :tc, :ref, :glosa, :comp, 'A',
                     :usr, :ip)
            ", [
                ':uuid' => $uuid, ':tipo' => $tipo, ':ag' => $idAgencia, ':caja' => $idCaja,
                ':ent' => (int) $cuenta['id_entidad_financiera'],
                ':tcb' => (int) $cuenta['id_tipo_cuenta_bancaria'],
                ':num' => $cuenta['num_cuenta_bancaria'],
                ':mon' => (int) $cuenta['id_moneda'],
                ':monto' => $monto, ':montoBob' => $montoBob, ':tc' => $tc,
                ':ref' => $referencia ?: null, ':glosa' => $textoGlosa,
                ':comp' => $comprobantePath ?: null,
                ':usr' => $codUsuario, ':ip' => $ip,
            ]);
            if ($ok === 0) {
                throw new \RuntimeException('No se pudo registrar la operación bancaria.');
            }
            $idDeposito = (int) DB::scalar("SELECT LAST_INSERT_ID()");

            // 2. Caja
            DB::execute("
                INSERT INTO saldo_caja (id_caja, saldo, fec_crea_saldo, conciliacion)
                VALUES (:id, :saldo, NOW(), :conc)
            ", [':id' => $idCaja, ':saldo' => round($monto * $signoCaja, 4), ':conc' => $concCaja]);

            // 3. Banco
            $this->movimientoBanco($cuenta, $monto * $signoBanco, $concBanco, $codUsuario, $textoGlosa);

            // 4. Trazabilidad en el log de operaciones del POS
            $this->registrarLog($uuid, 'transaccion_bancaria', $idCaja, null, $codUsuario, $idAgencia,
                (int) $cuenta['id_moneda'], $monto, $ip, [
                    'tipo' => $tipo === 'D' ? 'deposito' : 'retiro',
                    'id_deposito' => $idDeposito,
                    'num_cuenta' => $cuenta['num_cuenta_bancaria'],
                    'referencia' => $referencia,
                ]);

            return [
                'success' => true,
                'message' => $label . ' registrado correctamente.',
                'data' => [
                    'id' => $idDeposito,
                    'uuid' => $uuid,
                    'tipo' => $tipo,
                    'tipo_label' => $label,
                    'monto' => $monto,
                    'moneda' => $cuenta['moneda'],
                    'monto_bob' => $montoBob,
                    'tipo_cambio' => $tc,
                    'saldo_caja_nuevo' => $this->caja->saldoCaja($idCaja, date('Y-m-d')),
                    'saldo_banco_nuevo' => $this->balance->saldoCuenta(
                        (int) $cuenta['id_agencia'], (int) $cuenta['id_entidad_financiera'],
                        $cuenta['num_cuenta_bancaria'], (int) $cuenta['id_moneda']
                    ),
                ],
            ];
        });
    }

    /** Historial de depósitos y retiros de la agencia. */
    public function depositos(int $idAgencia, ?string $desde = null, ?string $hasta = null, int $limite = 200): array
    {
        $cond = ['d.id_agencia = :ag'];
        $params = [':ag' => $idAgencia];
        if ($desde) { $cond[] = 'DATE(d.created_at) >= :desde'; $params[':desde'] = $desde; }
        if ($hasta) { $cond[] = 'DATE(d.created_at) <= :hasta'; $params[':hasta'] = $hasta; }
        $where = implode(' AND ', $cond);

        return DB::select("
            SELECT d.*, ef.nombre_corto AS entidad, m.abreviatura AS moneda,
                   c.num_caja, u.login_usu AS usuario
            FROM banco_deposito d
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = d.id_entidad
            INNER JOIN moneda m ON m.id_moneda = d.id_moneda
            LEFT JOIN caja c ON c.id_caja = d.id_caja
            LEFT JOIN usuario u ON u.cod_usuario = d.cod_usuario
            WHERE {$where}
            ORDER BY d.id DESC
            LIMIT " . (int) $limite . "
        ", $params);
    }

    /**
     * Libro unificado de movimientos bancarios de la agencia.
     *
     * Una sola lista con todo lo que mueve una cuenta —traspasos (cargo y abono),
     * depósitos, retiros, cobros de cheque y el arqueo de apertura/cierre— con
     * filtros, porque `movimiento_cuenta_bancaria` tiene 157.000 filas y sin
     * filtros no hay forma de encontrar nada.
     *
     * @param array $f desde, hasta, cuenta, entidad, moneda, conciliacion, agencia, texto
     */
    public function movimientos(int $idAgencia, array $f = [], int $limite = 300): array
    {
        $cond = ['m.id_agencia = :ag'];
        $params = [':ag' => $idAgencia];

        if (!empty($f['desde'])) { $cond[] = 'DATE(m.fec_crea_movimiento_cuenta_bancaria) >= :desde'; $params[':desde'] = $f['desde']; }
        if (!empty($f['hasta'])) { $cond[] = 'DATE(m.fec_crea_movimiento_cuenta_bancaria) <= :hasta'; $params[':hasta'] = $f['hasta']; }
        if (!empty($f['cuenta'])) { $cond[] = 'm.num_cuenta_bancaria = :cuenta'; $params[':cuenta'] = $f['cuenta']; }
        if (!empty($f['entidad'])) { $cond[] = 'm.id_entidad_financiera = :entidad'; $params[':entidad'] = (int) $f['entidad']; }
        if (!empty($f['moneda'])) { $cond[] = 'm.id_moneda = :moneda'; $params[':moneda'] = (int) $f['moneda']; }

        // La conciliación admite varios códigos separados por coma.
        if (!empty($f['conciliacion']) && $f['conciliacion'] !== 'TODO') {
            $codigos = array_values(array_filter(array_map('trim', explode(',', strtoupper($f['conciliacion'])))));
            if ($codigos) {
                $ph = [];
                foreach ($codigos as $i => $c) { $ph[] = ":conc{$i}"; $params[":conc{$i}"] = $c; }
                $cond[] = 'm.conciliacion IN (' . implode(', ', $ph) . ')';
            }
        }

        // Rango de monto.
        if (isset($f['monto_min']) && $f['monto_min'] !== '' && $f['monto_min'] !== null) {
            $cond[] = 'ABS(m.monto) >= :mmin'; $params[':mmin'] = (float) $f['monto_min'];
        }
        if (isset($f['monto_max']) && $f['monto_max'] !== '' && $f['monto_max'] !== null) {
            $cond[] = 'ABS(m.monto) <= :mmax'; $params[':mmax'] = (float) $f['monto_max'];
        }

        // Búsqueda libre sobre glosa, cuenta o usuario.
        if (!empty($f['texto'])) {
            $cond[] = '(m.glosa LIKE :txt OR m.num_cuenta_bancaria LIKE :txt2 OR u.login_usu LIKE :txt3)';
            $params[':txt'] = '%' . $f['texto'] . '%';
            $params[':txt2'] = '%' . $f['texto'] . '%';
            $params[':txt3'] = '%' . $f['texto'] . '%';
        }

        $where = implode(' AND ', $cond);

        $rows = DB::select("
            SELECT m.id_agencia, m.id_tipo_cuenta_bancaria, m.id_entidad_financiera,
                   m.num_cuenta_bancaria, m.id_moneda, m.monto, m.cod_usuario,
                   m.fec_crea_movimiento_cuenta_bancaria AS fecha, m.conciliacion, m.glosa,
                   ef.nombre_corto AS entidad, mo.abreviatura AS moneda,
                   u.login_usu AS usuario,
                   CASE
                     WHEN m.conciliacion IN ('DE', 'SE') THEN (
                       SELECT d.comprobante_path FROM banco_deposito d
                       WHERE d.id_agencia = m.id_agencia AND d.id_entidad = m.id_entidad_financiera
                         AND d.id_tipo_cuenta = m.id_tipo_cuenta_bancaria AND d.num_cuenta = m.num_cuenta_bancaria
                         AND d.id_moneda = m.id_moneda
                         AND d.tipo = CASE WHEN m.conciliacion = 'DE' THEN 'D' ELSE 'R' END
                         AND ABS(d.monto - ABS(m.monto)) < 0.01
                         AND DATE(d.created_at) = DATE(m.fec_crea_movimiento_cuenta_bancaria)
                         AND UPPER(COALESCE(d.glosa, '')) = UPPER(COALESCE(m.glosa, ''))
                       ORDER BY ABS(TIMESTAMPDIFF(SECOND, d.created_at, m.fec_crea_movimiento_cuenta_bancaria)), d.id DESC
                       LIMIT 1
                     )
                     WHEN m.conciliacion IN ('ST', 'ET') THEN (
                       SELECT t.comprobante_path FROM banco_traspaso t
                       WHERE t.id_moneda = m.id_moneda AND ABS(t.monto - ABS(m.monto)) < 0.01
                         AND DATE(t.created_at) = DATE(m.fec_crea_movimiento_cuenta_bancaria)
                         AND UPPER(COALESCE(t.glosa, '')) = UPPER(COALESCE(m.glosa, ''))
                         AND ((m.conciliacion = 'ST' AND t.id_agencia_origen = m.id_agencia
                               AND t.id_entidad_origen = m.id_entidad_financiera
                               AND t.id_tipo_cuenta_origen = m.id_tipo_cuenta_bancaria
                               AND t.num_cuenta_origen = m.num_cuenta_bancaria)
                           OR (m.conciliacion = 'ET' AND t.id_agencia_destino = m.id_agencia
                               AND t.id_entidad_destino = m.id_entidad_financiera
                               AND t.id_tipo_cuenta_destino = m.id_tipo_cuenta_bancaria
                               AND t.num_cuenta_destino = m.num_cuenta_bancaria))
                       ORDER BY ABS(TIMESTAMPDIFF(SECOND, t.created_at, m.fec_crea_movimiento_cuenta_bancaria)), t.id DESC
                       LIMIT 1
                     )
                     WHEN m.conciliacion = 'CC' THEN (
                       SELECT COALESCE(c.adjunto_anverso_path, c.adjunto_reverso_path) FROM banco_cheque_mov c
                       WHERE c.id_agencia = m.id_agencia AND c.id_entidad = m.id_entidad_financiera
                         AND c.id_tipo_cuenta = m.id_tipo_cuenta_bancaria AND c.num_cuenta = m.num_cuenta_bancaria
                         AND c.id_moneda = m.id_moneda AND ABS(c.monto - ABS(m.monto)) < 0.01
                         AND DATE(c.created_at) = DATE(m.fec_crea_movimiento_cuenta_bancaria)
                         AND UPPER(COALESCE(c.glosa, '')) = UPPER(COALESCE(m.glosa, ''))
                       ORDER BY ABS(TIMESTAMPDIFF(SECOND, c.created_at, m.fec_crea_movimiento_cuenta_bancaria)), c.id DESC
                       LIMIT 1
                     )
                     ELSE NULL
                   END AS comprobante_path,
                   CASE
                     WHEN m.conciliacion IN ('DE', 'SE') THEN (
                       SELECT d.comprobante_nombre FROM banco_deposito d
                       WHERE d.id_agencia = m.id_agencia AND d.id_entidad = m.id_entidad_financiera
                         AND d.id_tipo_cuenta = m.id_tipo_cuenta_bancaria AND d.num_cuenta = m.num_cuenta_bancaria
                         AND d.id_moneda = m.id_moneda
                         AND d.tipo = CASE WHEN m.conciliacion = 'DE' THEN 'D' ELSE 'R' END
                         AND ABS(d.monto - ABS(m.monto)) < 0.01
                         AND DATE(d.created_at) = DATE(m.fec_crea_movimiento_cuenta_bancaria)
                         AND UPPER(COALESCE(d.glosa, '')) = UPPER(COALESCE(m.glosa, ''))
                       ORDER BY ABS(TIMESTAMPDIFF(SECOND, d.created_at, m.fec_crea_movimiento_cuenta_bancaria)), d.id DESC
                       LIMIT 1
                     )
                     WHEN m.conciliacion IN ('ST', 'ET') THEN (
                       SELECT t.comprobante_nombre FROM banco_traspaso t
                       WHERE t.id_moneda = m.id_moneda AND ABS(t.monto - ABS(m.monto)) < 0.01
                         AND DATE(t.created_at) = DATE(m.fec_crea_movimiento_cuenta_bancaria)
                         AND UPPER(COALESCE(t.glosa, '')) = UPPER(COALESCE(m.glosa, ''))
                         AND ((m.conciliacion = 'ST' AND t.id_agencia_origen = m.id_agencia
                               AND t.id_entidad_origen = m.id_entidad_financiera
                               AND t.id_tipo_cuenta_origen = m.id_tipo_cuenta_bancaria
                               AND t.num_cuenta_origen = m.num_cuenta_bancaria)
                           OR (m.conciliacion = 'ET' AND t.id_agencia_destino = m.id_agencia
                               AND t.id_entidad_destino = m.id_entidad_financiera
                               AND t.id_tipo_cuenta_destino = m.id_tipo_cuenta_bancaria
                               AND t.num_cuenta_destino = m.num_cuenta_bancaria))
                       ORDER BY ABS(TIMESTAMPDIFF(SECOND, t.created_at, m.fec_crea_movimiento_cuenta_bancaria)), t.id DESC
                       LIMIT 1
                     )
                     WHEN m.conciliacion = 'CC' THEN (
                       SELECT COALESCE(c.adjunto_anverso_nombre, c.adjunto_reverso_nombre) FROM banco_cheque_mov c
                       WHERE c.id_agencia = m.id_agencia AND c.id_entidad = m.id_entidad_financiera
                         AND c.id_tipo_cuenta = m.id_tipo_cuenta_bancaria AND c.num_cuenta = m.num_cuenta_bancaria
                         AND c.id_moneda = m.id_moneda AND ABS(c.monto - ABS(m.monto)) < 0.01
                         AND DATE(c.created_at) = DATE(m.fec_crea_movimiento_cuenta_bancaria)
                         AND UPPER(COALESCE(c.glosa, '')) = UPPER(COALESCE(m.glosa, ''))
                       ORDER BY ABS(TIMESTAMPDIFF(SECOND, c.created_at, m.fec_crea_movimiento_cuenta_bancaria)), c.id DESC
                       LIMIT 1
                     )
                     ELSE NULL
                   END AS comprobante_nombre
            FROM movimiento_cuenta_bancaria m
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = m.id_entidad_financiera
            INNER JOIN moneda mo ON mo.id_moneda = m.id_moneda
            LEFT JOIN usuario u ON u.cod_usuario = m.cod_usuario
            WHERE {$where}
            ORDER BY m.fec_crea_movimiento_cuenta_bancaria DESC
            LIMIT " . (int) $limite . "
        ", $params);

        // Etiquetas legibles para los códigos de conciliación.
        $etiquetas = [
            'A' => 'Apertura', 'C' => 'Cierre',
            'ST' => 'Salida por traspaso', 'ET' => 'Entrada por traspaso', 'RT' => 'Reintegro',
            'DE' => 'Depósito', 'DB' => 'Depósito', 'SE' => 'Retiro', 'RB' => 'Retiro',
            'DR' => 'Débito remesa', 'LR' => 'Pago de remesa', 'CC' => 'Cobro de cheque', 'IC' => 'Ingreso cheque',
            'TF' => 'Transferencia', 'QC' => 'Cobro QR', 'SR' => 'Salida remesa',
        ];

        return array_map(function ($r) use ($etiquetas) {
            $monto = (float) $r['monto'];
            $comprobanteRemesa = $this->comprobanteRemesaLegado($r['conciliacion'], $r['glosa']);
            return [
                'id_agencia'   => (int) $r['id_agencia'],
                'id_entidad'   => (int) $r['id_entidad_financiera'],
                'id_tipo_cuenta' => (int) $r['id_tipo_cuenta_bancaria'],
                'num_cuenta'   => $r['num_cuenta_bancaria'],
                'id_moneda'    => (int) $r['id_moneda'],
                'entidad'      => $r['entidad'],
                'moneda'       => $r['moneda'],
                'fecha'        => $r['fecha'],
                'conciliacion' => $r['conciliacion'],
                'tipo'         => $etiquetas[$r['conciliacion']] ?? $r['conciliacion'],
                'glosa'        => $r['glosa'],
                'usuario'      => $r['usuario'],
                'comprobante_path' => $r['comprobante_path'] ?? null,
                'comprobante_url' => $comprobanteRemesa['url'] ?? null,
                'comprobante_nombre' => $r['comprobante_nombre'] ?? ($comprobanteRemesa['nombre'] ?? null),
                'monto'        => $monto,
                'signo'        => $monto >= 0 ? 'C' : 'D',
                'clave'        => $r['id_agencia'] . '|' . $r['id_entidad_financiera'] . '|'
                                . $r['id_tipo_cuenta_bancaria'] . '|' . $r['num_cuenta_bancaria'] . '|' . $r['id_moneda'],
            ];
        }, $rows);
    }

    /** Resuelve boletas legacy de pagos de remesa (LR) si el archivo fue cargado. */
    private function comprobanteRemesaLegado(string $conciliacion, ?string $glosa): ?array
    {
        if ($conciliacion !== 'LR' || !$glosa || !preg_match('/#(\d+)/', $glosa, $coincidencia)) return null;
        $codigo = $coincidencia[1];
        $root = rtrim((string) env('CGR_PUBLIC_PATH', '/home/u752765750/domains/cgr.owl-tech.net/public_html'), '/');
        $base = 'boletas/' . $codigo . '/deposito';
        foreach (['pdf', 'jpg', 'jpeg', 'png'] as $extension) {
            if (is_file($root . '/' . $base . '.' . $extension)) {
                return [
                    'url' => rtrim((string) env('CGR_PUBLIC_URL', 'https://cgr.owl-tech.net'), '/') . '/' . $base . '.' . $extension,
                    'nombre' => 'Comprobante de remesa #' . $codigo,
                ];
            }
        }
        return null;
    }

    /** Totales (cargos/abonos) del conjunto filtrado, útiles para cuadrar rápido. */
    public function movimientosTotales(array $movs): array
    {
        $cargos = 0.0; $abonos = 0.0;
        foreach ($movs as $m) {
            // Apertura y cierre son saldos absolutos, no movimientos de dinero.
            if (in_array($m['conciliacion'] ?? '', ['A', 'C'], true)) continue;
            if ($m['monto'] >= 0) $abonos += $m['monto']; else $cargos += abs($m['monto']);
        }
        return ['cargos' => round($cargos, 2), 'abonos' => round($abonos, 2), 'neto' => round($abonos - $cargos, 2)];
    }

    public function saldoCuentaAlCorte(int $idAgencia, int $idEntidad, string $numCuenta, int $idMoneda, string $hasta): float
    {
        return $this->balance->saldoCuentaAlCorte($idAgencia, $idEntidad, $numCuenta, $idMoneda, $hasta);
    }

    /** Registro en `pos_operacion_log` (auditoría del POS). */
    private function registrarLog(string $uuid, string $tipo, ?int $idCaja, ?int $idBoveda, int $codUsuario,
                                  int $idAgencia, ?int $idMoneda, ?float $monto, string $ip, array $meta = []): void
    {
        DB::execute("
            INSERT INTO pos_operacion_log
                (uuid, tipo_operacion, id_caja, id_boveda, cod_usuario, id_agencia, id_moneda,
                 monto_total, ip_origen, metadata)
            VALUES (:uuid, :tipo, :caja, :bov, :usr, :ag, :mon, :monto, :ip, :meta)
        ", [
            ':uuid' => $uuid, ':tipo' => $tipo, ':caja' => $idCaja, ':bov' => $idBoveda,
            ':usr' => $codUsuario, ':ag' => $idAgencia, ':mon' => $idMoneda,
            ':monto' => $monto, ':ip' => $ip,
            ':meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Internos
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Inserta una fila en el libro bancario.
     *
     * `movimiento_cuenta_bancaria` tiene una PK compuesta que incluye el monto,
     * el usuario y `fec_crea_movimiento_cuenta_bancaria`, y esa columna es un
     * `datetime` SIN precisión de fracción de segundo. Por tanto dos movimientos
     * del mismo importe, cuenta y usuario dentro del MISMO segundo chocan.
     *
     * No ocurría en el legacy porque los importes variaban, pero el POS nuevo sí
     * puede provocarlo (p. ej. un traspaso y un depósito del mismo monto
     * seguidos). Ante la colisión se reintenta desplazando un segundo: el
     * movimiento es correcto y la fecha sigue siendo esencialmente la misma.
     */
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
                    ':ag'    => (int) $cuenta['id_agencia'],
                    ':tcb'   => (int) $cuenta['id_tipo_cuenta_bancaria'],
                    ':ent'   => (int) $cuenta['id_entidad_financiera'],
                    ':num'   => $cuenta['num_cuenta_bancaria'],
                    ':mon'   => (int) $cuenta['id_moneda'],
                    ':monto' => $monto,
                    ':usr'   => $codUsuario,
                    ':desp'  => $desplazamiento,
                    ':conc'  => $conciliacion,
                    ':glosa' => $glosa,
                ]);

                if ($ok === 0) {
                    throw new \RuntimeException('No se pudo registrar el movimiento bancario.');
                }
                return;
            } catch (\PDOException $e) {
                // 23000 / 1062 = entrada duplicada por la PK compuesta
                $esDuplicado = ($e->getCode() === '23000' || strpos($e->getMessage(), '1062') !== false);
                if (!$esDuplicado || $intentos >= 4) {
                    throw $e;
                }
                $desplazamiento++;
            }
        }
    }

    /**
     * Registra un movimiento en el libro bancario desde fuera de la clase.
     *
     * Lo usa la conciliación de extractos para asentar una línea que el banco
     * muestra pero OBEL no tenía. Reutiliza el insert robusto `movimientoBanco`
     * (control de colisión por la PK compuesta). No genera asiento contable: es
     * un ajuste del libro bancario, no una operación de caja.
     *
     * @param array $cuenta {id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria, id_moneda}
     */
    public function registrarMovimientoManual(array $cuenta, float $monto, string $conciliacion, int $codUsuario, string $glosa): void
    {
        $this->movimientoBanco($cuenta, $monto, $conciliacion, $codUsuario, $glosa);
    }

    /**
     * Registra un ajuste de conciliación: el movimiento en el libro bancario y,
     * si hay cuenta de contrapartida configurada, su asiento contable.
     *
     * El asiento es de partida doble banco ↔ contrapartida. El banco es un
     * activo: si el ajuste lo ABONA (monto > 0) la subcuenta del banco va al DEBE
     * y la contrapartida al HABER; si lo CARGA (monto < 0), al revés. Se escribe
     * en `conta` FUERA de la transacción de `cgr` (misma política que el traspaso);
     * si `conta` falla, `contabilizar()` lo encola.
     *
     * Si no hay contrapartida configurada para el tipo, el movimiento bancario
     * igual se registra y se informa que el asiento quedó pendiente de cuenta.
     *
     * @param array $cuenta {id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria, id_moneda, codsubcuenta}
     */
    public function registrarAjusteConciliacion(
        array $cuenta, float $monto, string $conciliacion, int $codUsuario,
        string $glosa, string $uuid, string $contraCodsubcuenta
    ): array {
        $this->movimientoBanco($cuenta, $monto, $conciliacion, $codUsuario, $glosa);

        $bancoCodsub = trim((string) ($cuenta['codsubcuenta'] ?? ''));
        $contraCodsubcuenta = trim($contraCodsubcuenta);
        if ($contraCodsubcuenta === '' || $bancoCodsub === '') {
            return ['contabilizado' => false, 'motivo' => 'sin_cuenta_contrapartida'];
        }

        $asiento = $this->prepararAsientoSimple($bancoCodsub, $contraCodsubcuenta, (int) ($cuenta['id_moneda'] ?? 0), $monto, $glosa);
        return $this->contabilizar($asiento, $uuid, 'conciliacion_ajuste', 0);
    }

    /**
     * Asiento de partida doble banco ↔ contrapartida para un ajuste de
     * conciliación. El importe se lleva a BOB con el TC de venta si la cuenta es
     * en moneda extranjera (id_moneda = 1).
     */
    private function prepararAsientoSimple(string $bancoCodsub, string $contraCodsub, int $idMoneda, float $montoBanco, string $glosa): array
    {
        $ejercicio = date('Y');
        $tc = $idMoneda === 1 ? $this->tipoCambioOficialVenta() : 1.0;
        $importe = round(abs($montoBanco) * $tc, 2);

        $subBanco  = $this->subcuenta($bancoCodsub, $ejercicio);
        $subContra = $this->subcuenta($contraCodsub, $ejercicio);
        if (!$subBanco || !$subContra) {
            throw new \RuntimeException('No se pudieron resolver las subcuentas contables del ajuste de conciliación.');
        }

        $bancoAlDebe = $montoBanco >= 0;

        return [
            'codejercicio' => $ejercicio,
            'fecha'        => date('Y-m-d'),
            'concepto'     => mb_strtoupper($glosa),
            'importe'      => $importe,
            'tipo_cambio'  => $tc,
            'partidas'     => [
                ['idsubcuenta' => (int) $subBanco['idsubcuenta'], 'codsubcuenta' => $bancoCodsub,
                 'debe' => $bancoAlDebe ? $importe : 0.0, 'haber' => $bancoAlDebe ? 0.0 : $importe],
                ['idsubcuenta' => (int) $subContra['idsubcuenta'], 'codsubcuenta' => $contraCodsub,
                 'debe' => $bancoAlDebe ? 0.0 : $importe, 'haber' => $bancoAlDebe ? $importe : 0.0],
            ],
        ];
    }

    /**
     * Prepara el asiento del traspaso: DEBE la cuenta destino, HABER la origen.
     *
     * NO escribe nada: resuelve las subcuentas (que viven en `conta`) y devuelve
     * el payload listo. La escritura la hace `contabilizar()` FUERA de la
     * transacción de `cgr`, porque son conexiones distintas.
     *
     * Usa el `codsubcuenta` real de cada cuenta (el legacy lo resolvía con un
     * LIKE por número de cuenta sin filtrar agencia, y decidía debe/haber por el
     * último dígito del código, lo que contabilizaba en cuentas ajenas).
     */
    private function prepararAsiento(array $origen, array $destino, float $monto, string $glosa): array
    {
        $ejercicio = date('Y');

        if ((int) $origen['id_moneda'] === 1) {
            $tc = $this->tipoCambioOficialVenta();
            $montoBob = round($monto * $tc, 2);
        } else {
            $tc = 1.0;
            $montoBob = round($monto, 2);
        }

        $subO = $this->subcuenta($origen['codsubcuenta'], $ejercicio);
        $subD = $this->subcuenta($destino['codsubcuenta'], $ejercicio);
        if (!$subO || !$subD) {
            throw new \RuntimeException('No se pudieron resolver las subcuentas contables de las cuentas bancarias.');
        }

        return [
            'codejercicio' => $ejercicio,
            'fecha'        => date('Y-m-d'),
            'concepto'     => mb_strtoupper($glosa),
            'importe'      => $montoBob,
            'tipo_cambio'  => $tc,
            'partidas'     => [
                ['idsubcuenta' => (int) $subD['idsubcuenta'], 'codsubcuenta' => $destino['codsubcuenta'],
                 'debe' => $montoBob, 'haber' => 0.0],
                ['idsubcuenta' => (int) $subO['idsubcuenta'], 'codsubcuenta' => $origen['codsubcuenta'],
                 'debe' => 0.0, 'haber' => $montoBob],
            ],
        ];
    }

    /**
     * Contabiliza en `conta`. SIEMPRE se llama FUERA de la transacción de `cgr`.
     *
     * ── Por qué ──────────────────────────────────────────────────────────────
     * Operaciones y contabilidad son bases distintas, con conexiones PDO
     * distintas. `DB::transaction()` abre transacción solo en `cgr`, así que un
     * INSERT a `conta` hecho dentro del closure NO se revierte si la transacción
     * de `cgr` falla: el asiento quedaría huérfano apuntando a un traspaso que no
     * existe. Comprobado empíricamente contra la base real.
     *
     * Por eso el asiento se prepara dentro de la transacción (para resolver las
     * subcuentas) y se escribe aquí, una vez confirmado el movimiento bancario.
     * Si `conta` falla, el asiento se encola en `pos_asiento_pendiente` —el mismo
     * mecanismo que ya usan cambio y remesa—: la operación bancaria queda hecha y
     * el asiento no se pierde.
     */
    private function contabilizar(array $asiento, string $uuid, string $tipoOperacion, int $idReferencia): array
    {
        try {
            $numero = ((int) DB::scalar(
                "SELECT COALESCE(MAX(numero), 0) FROM co_asientos
                 WHERE codejercicio = :ej AND MONTH(fecha) = :mes",
                [':ej' => $asiento['codejercicio'], ':mes' => (int) date('n')],
                'conta'
            )) + 1;

            DB::execute("
                INSERT INTO co_asientos
                    (codejercicio, concepto, editable, fecha, idconcepto, importe, numero, tipodocumento, cod_usuario, fecha_trans)
                VALUES (:ej, :glosa, 0, :fecha, '000', :importe, :num, 'T', :usr, NOW())
            ", [
                ':ej' => $asiento['codejercicio'], ':glosa' => $asiento['concepto'],
                ':fecha' => $asiento['fecha'], ':importe' => $asiento['importe'],
                ':num' => $numero, ':usr' => 'pos',
            ], 'conta');

            $idasiento = (int) DB::scalar("SELECT LAST_INSERT_ID()", [], 'conta');

            foreach ($asiento['partidas'] as $p) {
                $this->partida($idasiento, $p);
            }

            DB::execute("
                INSERT INTO banco_operacion_asiento
                    (uuid, tipo_operacion, base_datos, idasiento, codejercicio, numero, glosa, monto_bob)
                VALUES (:uuid, :tipo, 'conta', :ida, :ej, :num, :glosa, :monto)
            ", [
                ':uuid' => $uuid, ':tipo' => $tipoOperacion, ':ida' => $idasiento,
                ':ej' => $asiento['codejercicio'], ':num' => $numero,
                ':glosa' => $asiento['concepto'], ':monto' => $asiento['importe'],
            ]);

            return [
                'contabilizado' => true, 'idasiento' => $idasiento, 'numero' => $numero,
                'monto_bob' => $asiento['importe'], 'tipo_cambio' => $asiento['tipo_cambio'],
            ];
        } catch (\Throwable $e) {
            $encolado = $this->encolarAsiento($asiento, $tipoOperacion, $idReferencia, $e->getMessage());

            return [
                'contabilizado' => false,
                'en_cola'       => $encolado,
                'error'         => $e->getMessage(),
                'monto_bob'     => $asiento['importe'],
                'tipo_cambio'   => $asiento['tipo_cambio'],
            ];
        }
    }

    /** Encola el asiento para reintento cuando `conta` no está disponible. */
    private function encolarAsiento(array $asiento, string $tipoOperacion, int $idReferencia, string $error): bool
    {
        try {
            $idLog = (int) DB::scalar("
                SELECT id FROM pos_operacion_log
                WHERE id_referencia = :ref
                ORDER BY id DESC LIMIT 1
            ", [':ref' => $idReferencia]);

            DB::execute("
                INSERT INTO pos_asiento_pendiente
                    (id_operacion_log, tipo_operacion, codejercicio, fecha, concepto, documento, partidas, estado, error_mensaje)
                VALUES (:log, :tipo, :ej, :fecha, :concepto, :doc, :partidas, 'pendiente', :err)
            ", [
                ':log' => $idLog, ':tipo' => $tipoOperacion, ':ej' => $asiento['codejercicio'],
                ':fecha' => $asiento['fecha'], ':concepto' => $asiento['concepto'],
                ':doc' => 'TRASPASO-' . $idReferencia,
                ':partidas' => json_encode($asiento['partidas'], JSON_UNESCAPED_UNICODE),
                ':err' => mb_substr($error, 0, 500),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function partida(int $idasiento, array $p): void
    {
        DB::execute("
            INSERT INTO co_partidas
                (idasiento, idsubcuenta, codsubcuenta, concepto, debe, debeme, haber, haberme,
                 iva, recargo, tasaconv, punteada, cod_usuario, fecha_trans, baseimponible, idconcepto)
            VALUES (:ida, :idsub, :cod, '', :debe, :debeme, :haber, :haberme,
                    0, 0, 1, 0, 'pos', NOW(), 0, '000')
        ", [
            ':ida' => $idasiento, ':idsub' => (int) $p['idsubcuenta'], ':cod' => $p['codsubcuenta'],
            ':debe' => $p['debe'], ':debeme' => $p['debe'],
            ':haber' => $p['haber'], ':haberme' => $p['haber'],
        ], 'conta');
    }

    private function subcuenta(string $codsubcuenta, string $ejercicio): ?array
    {
        return DB::selectOne("
            SELECT idsubcuenta, codsubcuenta FROM co_subcuentas
            WHERE codsubcuenta = :cod AND codejercicio = :ej LIMIT 1
        ", [':cod' => $codsubcuenta, ':ej' => $ejercicio], 'conta');
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

    private function glosaTraspaso(array $origen, array $destino, string $documento, bool $recepcion): string
    {
        $prefijo = $recepcion ? 'RECEPCION TRASPASO' : 'TRASPASO';
        return sprintf(
            '%s %s/%s %s -> %s/%s %s (TC #%s)',
            $prefijo,
            $origen['id_agencia'], $origen['entidad'], $origen['num_cuenta_bancaria'],
            $destino['id_agencia'], $destino['entidad'], $destino['num_cuenta_bancaria'],
            $documento
        );
    }

    private function estadoLabel(string $estado): string
    {
        return ['P' => 'Pendiente', 'A' => 'Aceptado', 'X' => 'Rechazado', 'R' => 'Revertido'][$estado] ?? $estado;
    }

    /** Completa el traspaso con los campos descriptivos que necesita `mapTraspaso`. */
    private function hidratar(array $t): array
    {
        if (!$t) return [];

        $extra = DB::selectOne("
            SELECT
                (SELECT descripcion  FROM agencia             WHERE id_agencia = :ago)  AS agencia_origen_desc,
                (SELECT abreviatura  FROM agencia             WHERE id_agencia = :ago2) AS agencia_origen_abr,
                (SELECT descripcion  FROM agencia             WHERE id_agencia = :agd)  AS agencia_destino_desc,
                (SELECT abreviatura  FROM agencia             WHERE id_agencia = :agd2) AS agencia_destino_abr,
                (SELECT nombre_corto FROM entidad_financiera  WHERE id_entidad_financiera = :ento) AS entidad_origen,
                (SELECT nombre_corto FROM entidad_financiera  WHERE id_entidad_financiera = :entd) AS entidad_destino,
                (SELECT abreviatura  FROM moneda              WHERE id_moneda = :mon)   AS moneda,
                (SELECT login_usu    FROM usuario             WHERE cod_usuario = :uo)   AS usuario_origen,
                (SELECT login_usu    FROM usuario             WHERE cod_usuario = :ud)   AS usuario_destino
        ", [
            ':ago'  => (int) $t['id_agencia_origen'],
            ':ago2' => (int) $t['id_agencia_origen'],
            ':agd'  => (int) $t['id_agencia_destino'],
            ':agd2' => (int) $t['id_agencia_destino'],
            ':ento' => (int) $t['id_entidad_origen'],
            ':entd' => (int) $t['id_entidad_destino'],
            ':mon'  => (int) $t['id_moneda'],
            ':uo'   => $t['cod_usuario_origen'] ?? 0,
            ':ud'   => $t['cod_usuario_destino'] ?? 0,
        ]);

        return array_merge($t, $extra ?: []);
    }
}
