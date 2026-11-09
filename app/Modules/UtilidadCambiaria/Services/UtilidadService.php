<?php

namespace App\Modules\UtilidadCambiaria\Services;

use App\Modules\Shared\Database\RawConnection;

/**
 * UtilidadService — Motor de cálculo de Utilidad Cambiaria (FIFO / Kardex).
 *
 * Port fiel del módulo legacy `reportes/utilidad_cambiaria/lib/Utilidad.php`
 * (ruta activa de index.php: saldo de bóveda + costeo FIFO por agencia+moneda).
 *
 * Mide la rentabilidad real de las operaciones de cambio usando FIFO:
 *   utilidad_venta = BOB_recibidos − costo_FIFO_de_los_lotes_consumidos
 *
 * Toda la lógica y las consultas SQL se conservan idénticas al legacy para
 * garantizar exactitud financiera. La presentación se delega al frontend.
 */
class UtilidadService
{
    /** Fecha de corte de la 3-patas CLP (operaciones ficticias pre-octubre 2026). */
    const FECHA_FILTRO_TRIPLE = '2026-10-01';

    /** Rotación (días) a partir de la cual se considera inventario estancado. */
    const ROTACION_ESTANCADO = 30;

    // ─────────────────────────────────────────────────────────────
    // Catálogos (meta)
    // ─────────────────────────────────────────────────────────────

    /** Listado de agencias activas (sin sistema/oficina central). */
    public function agencias(): array
    {
        return RawConnection::select(
            "SELECT id_agencia, descripcion FROM agencia WHERE id_agencia > 1 AND estado = 'A' ORDER BY descripcion"
        );
    }

    /** Listado de monedas activas (sin BOB). */
    public function monedas(): array
    {
        return RawConnection::select(
            "SELECT id_moneda, abreviatura, descripcion FROM moneda WHERE id_moneda != 2 AND estado = 'A' ORDER BY abreviatura"
        );
    }

    /** Listado de cajeros ACTIVOS con operaciones de cambio. */
    public function cajeros(): array
    {
        return RawConnection::select(
            "SELECT DISTINCT u.login_usu AS cajero, u.cod_usuario,
                    CONCAT(t.nom_trab, ' ', t.ape_pat_trab, ' ', t.ape_mat_trab) AS nombre
             FROM cambio c
             JOIN usuario u ON u.cod_usuario = c.cod_usuario
             JOIN trabajador t ON t.cod_trab = u.cod_trab
             WHERE c.id_operacion IN (1, 2)
               AND u.estado = 'A'
             ORDER BY u.login_usu"
        );
    }

    /**
     * Cajeros que aperturaron caja en el período (saldo_caja conciliacion='A')
     * pero NO registraron operaciones de cambio (compra/venta) en ese rango.
     * Sirve para identificar cajeros que abrieron pero no generaron utilidad.
     */
    public function cajerosSinMovimiento(string $ini, string $fin, ?int $id_agencia = null): array
    {
        $where_ag = $id_agencia ? 'AND c.id_agencia = :ag' : '';

        $sql = "
        SELECT DISTINCT
            u.login_usu AS cajero,
            CONCAT(t.nom_trab, ' ', t.ape_pat_trab, ' ', t.ape_mat_trab) AS nombre,
            a.descripcion AS agencia
        FROM saldo_caja sc
        JOIN caja c ON c.id_caja = sc.id_caja
        JOIN usuario u ON u.cod_usuario = c.cod_usuario
        JOIN trabajador t ON t.cod_trab = u.cod_trab
        JOIN agencia a ON a.id_agencia = c.id_agencia
        WHERE sc.conciliacion = 'A'
          AND DATE(sc.fec_crea_saldo) BETWEEN :ini AND :fin
          AND c.id_tipo_unidad = 2
          AND u.estado = 'A'
          {$where_ag}
          AND c.cod_usuario NOT IN (
              SELECT DISTINCT cx.cod_usuario FROM cambio cx
              WHERE cx.id_operacion IN (1, 2)
                AND cx.fec_crea_cambio >= :ini2
                AND cx.fec_crea_cambio < DATE_ADD(:fin2, INTERVAL 1 DAY)
          )
        ORDER BY a.descripcion, u.login_usu
        ";

        $params = [':ini' => $ini, ':fin' => $fin, ':ini2' => $ini, ':fin2' => $fin];
        if ($id_agencia) $params[':ag'] = $id_agencia;

        return RawConnection::select($sql, $params);
    }

    // ─────────────────────────────────────────────────────────────
    // Fuentes de datos
    // ─────────────────────────────────────────────────────────────

    /**
     * Saldo de apertura de bóveda por agencia+moneda en una fecha dada.
     * Suma todas las bóvedas de cada agencia+moneda (conciliacion = 'A').
     */
    public function saldoAperturaBoveda(string $fecha, ?int $id_agencia = null, ?int $id_moneda = null): array
    {
        $where_ag = $id_agencia ? 'AND b.id_agencia = :ag' : '';
        $where_mon = $id_moneda ? 'AND b.id_moneda = :mon' : '';

        $sql = "
        SELECT
            b.id_agencia,
            a.descripcion AS agencia,
            b.id_moneda,
            m.abreviatura AS moneda,
            SUM(sb.saldo) AS saldo_me
        FROM saldo_boveda sb
        JOIN boveda b ON b.id_boveda = sb.id_boveda
        JOIN agencia a ON a.id_agencia = b.id_agencia
        JOIN moneda m ON m.id_moneda = b.id_moneda
        WHERE DATE(sb.fec_crea_saldo) = :fecha
          AND sb.conciliacion = 'A'
          AND b.id_moneda != 2
          AND b.id_agencia > 1
          {$where_ag}
          {$where_mon}
        GROUP BY b.id_agencia, a.descripcion, b.id_moneda, m.abreviatura
        ORDER BY a.descripcion, m.abreviatura
        ";

        $params = [':fecha' => $fecha];
        if ($id_agencia) $params[':ag'] = $id_agencia;
        if ($id_moneda) $params[':mon'] = $id_moneda;

        return RawConnection::select($sql, $params);
    }

    /**
     * Cotización base de compra vigente en una fecha, por agencia+moneda.
     * Devuelve mapa "id_agencia_id_moneda" => valor_base.
     */
    public function cotizacionBaseEnFecha(string $fecha, ?int $id_agencia = null, ?int $id_moneda = null): array
    {
        $where_ag_outer = $id_agencia ? 'AND cb.id_agencia = :ag' : '';
        $where_mon_outer = $id_moneda ? 'AND cb.id_moneda = :mon' : '';
        $where_ag_inner = $id_agencia ? 'AND id_agencia = :ag2' : '';
        $where_mon_inner = $id_moneda ? 'AND id_moneda = :mon2' : '';

        $sql = "
        SELECT cb.id_agencia, cb.id_moneda, cb.valor_base
        FROM cotizacion_base cb
        INNER JOIN (
            SELECT id_agencia, id_moneda, MAX(fecha_creacion) AS max_fecha
            FROM cotizacion_base
            WHERE id_operacion = 1
              AND fecha_creacion <= :fecha_corte
              {$where_ag_inner}
              {$where_mon_inner}
            GROUP BY id_agencia, id_moneda
        ) ult ON cb.id_agencia = ult.id_agencia
             AND cb.id_moneda = ult.id_moneda
             AND cb.fecha_creacion = ult.max_fecha
             AND cb.id_operacion = 1
        {$where_ag_outer}
        {$where_mon_outer}
        ";

        $params = [':fecha_corte' => $fecha];
        if ($id_agencia) { $params[':ag'] = $id_agencia; $params[':ag2'] = $id_agencia; }
        if ($id_moneda) { $params[':mon'] = $id_moneda; $params[':mon2'] = $id_moneda; }

        $rows = RawConnection::select($sql, $params);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['id_agencia'] . '_' . $r['id_moneda']] = (float) $r['valor_base'];
        }
        return $map;
    }

    /**
     * Operaciones crudas (compras op=1 / ventas op=2) de la tabla `cambio`,
     * normalizadas y con el filtro de operaciones ficticias de 3 patas (CLP).
     */
    public function operacionesCrudas(string $fin, string $desde, ?int $id_agencia = null, ?int $id_moneda = null): array
    {
        $where_ag = $id_agencia ? 'AND c.id_agencia = :ag' : '';
        $where_mon = $id_moneda ? 'AND sub_mon.id_moneda = :mon' : '';
        $corte = self::FECHA_FILTRO_TRIPLE;

        $sql = "
        SELECT
            u.login_usu AS cajero,
            CONCAT(t.nom_trab, ' ', t.ape_pat_trab, ' ', t.ape_mat_trab) AS nombre,
            a.descripcion AS agencia,
            c.id_agencia,
            sub_mon.id_moneda,
            sub_mon.abreviatura AS moneda,
            c.id_operacion AS op,
            CASE WHEN c.id_operacion = 1 THEN c.monto ELSE c.total_cambio END AS monto_me,
            c.total_operacion_bob AS bob,
            c.fec_crea_cambio AS fecha
        FROM cambio c
        JOIN usuario u ON u.cod_usuario = c.cod_usuario
        JOIN trabajador t ON t.cod_trab = u.cod_trab
        JOIN agencia a ON a.id_agencia = c.id_agencia
        JOIN (
            SELECT c2.id_cambio,
                CASE WHEN c2.id_operacion = 1 THEN c2.mon_rec ELSE c2.mon_ent END AS id_moneda,
                m.abreviatura
            FROM cambio c2
            JOIN moneda m ON m.id_moneda = CASE WHEN c2.id_operacion = 1 THEN c2.mon_rec ELSE c2.mon_ent END
            WHERE c2.fec_crea_cambio >= :ini1
              AND c2.fec_crea_cambio < DATE_ADD(:fin1, INTERVAL 1 DAY)
              AND c2.id_operacion IN (1, 2)
              AND CASE WHEN c2.id_operacion = 1 THEN c2.mon_rec ELSE c2.mon_ent END != 2
              AND NOT (
                  CASE WHEN c2.id_operacion = 1 THEN c2.mon_rec ELSE c2.mon_ent END = 3
                  AND c2.fec_crea_cambio < '{$corte}'
                  AND EXISTS (
                      SELECT 1 FROM cambio cx
                      WHERE cx.cod_usuario = c2.cod_usuario
                        AND cx.id_agencia = c2.id_agencia
                        AND cx.fec_crea_cambio = c2.fec_crea_cambio
                        AND cx.id_operacion != c2.id_operacion
                        AND (cx.mon_rec = 3 OR cx.mon_ent = 3)
                        AND cx.id_cambio != c2.id_cambio
                  )
              )
        ) sub_mon ON sub_mon.id_cambio = c.id_cambio
        WHERE c.fec_crea_cambio >= :ini2
          AND c.fec_crea_cambio < DATE_ADD(:fin2, INTERVAL 1 DAY)
          AND c.id_operacion IN (1, 2)
          {$where_ag}
          {$where_mon}
        ORDER BY u.login_usu, c.id_agencia, sub_mon.id_moneda, c.fec_crea_cambio
        ";

        $params = [':ini1' => $desde, ':fin1' => $fin, ':ini2' => $desde, ':fin2' => $fin];
        if ($id_agencia) $params[':ag'] = $id_agencia;
        if ($id_moneda) $params[':mon'] = $id_moneda;

        return RawConnection::select($sql, $params);
    }

    /**
     * Saldo físico actual (hoy) por agencia+moneda = cajas (saldo_caja) + bóveda (saldo_boveda).
     * Es el efectivo real disponible para redistribuir (no el inventario FIFO).
     */
    public function saldoActualAgencias(): array
    {
        return RawConnection::select("
            SELECT t.id_agencia, a.descripcion AS agencia,
                   t.id_moneda, m.abreviatura AS moneda,
                   ROUND(SUM(t.saldo), 2) AS saldo
            FROM (
                -- Cajas de cajero AÚN ABIERTAS hoy (si ya cerraron, su efectivo pasó a la bóveda)
                SELECT c.id_agencia, c.id_moneda, sc.saldo
                FROM caja c
                JOIN saldo_caja sc ON sc.id_caja = c.id_caja
                WHERE c.estado = 'A' AND c.id_tipo_unidad = 2
                  AND sc.conciliacion <> 'C'
                  AND sc.fec_crea_saldo >= CURDATE()
                  AND sc.fec_crea_saldo < CURDATE() + INTERVAL 1 DAY
                  AND NOT EXISTS (
                      SELECT 1 FROM saldo_caja sc2
                      WHERE sc2.id_caja = c.id_caja AND sc2.conciliacion = 'C'
                        AND sc2.fec_crea_saldo >= CURDATE()
                        AND sc2.fec_crea_saldo < CURDATE() + INTERVAL 1 DAY
                  )
                UNION ALL
                -- Bóvedas
                SELECT b.id_agencia, b.id_moneda, sb.saldo
                FROM boveda b
                JOIN saldo_boveda sb ON sb.id_boveda = b.id_boveda
                WHERE b.estado = 'A'
                  AND sb.conciliacion <> 'C'
                  AND sb.fec_crea_saldo >= CURDATE()
                  AND sb.fec_crea_saldo < CURDATE() + INTERVAL 1 DAY
            ) t
            JOIN agencia a ON a.id_agencia = t.id_agencia
            JOIN moneda m ON m.id_moneda = t.id_moneda
            WHERE t.id_agencia > 1 AND t.id_moneda != 2
            GROUP BY t.id_agencia, a.descripcion, t.id_moneda, m.abreviatura
            HAVING SUM(t.saldo) > 0
            ORDER BY a.descripcion, m.abreviatura
        ");
    }

    /**
     * Cotización de venta actual por agencia+moneda (para valorizar inventario).
     * Devuelve mapa "id_agencia_id_moneda" => tc_venta_actual.
     */
    public function cotizacionVentaActual(): array
    {
        $rows = RawConnection::select(
            "SELECT id_moneda, id_agencia, valor AS tc_venta_actual
             FROM cotizacion
             WHERE id_operacion = 2 AND estado = 'A' AND id_agencia > 1"
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['id_agencia'] . '_' . $r['id_moneda']] = (float) $r['tc_venta_actual'];
        }
        return $map;
    }

    // ─────────────────────────────────────────────────────────────
    // Motor Kardex FIFO
    // ─────────────────────────────────────────────────────────────

    /**
     * Kardex FIFO — agrupa por agencia+moneda, procesa las operaciones de todos
     * los cajeros en orden cronológico. Cada movimiento incluye el saldo y el
     * costo valorizado restante. Si se indica $id_cajero, las ops de otros
     * cajeros se procesan en la cola pero no emiten movimientos visibles.
     *
     * @param array      $operaciones  Operaciones crudas (operacionesCrudas()).
     * @param array      $bases_ini    Mapa agencia_moneda => TC base (cotizacionBaseEnFecha()).
     * @param string     $ini_reporte  Fecha de inicio del período visible (YYYY-MM-DD).
     * @param string|null $id_cajero   Filtra la emisión de movimientos a este cajero.
     * @param array      $boveda       Saldo de apertura de bóveda (saldoAperturaBoveda()).
     */
    public function calcularKardex(array $operaciones, array $bases_ini, string $ini_reporte, ?string $id_cajero = null, array $boveda = []): array
    {
        // Saldos iniciales por agencia+moneda (desde bóveda)
        $saldo_ini_ag = [];
        foreach ($boveda as $b) {
            $k = $b['id_agencia'] . '_' . $b['id_moneda'];
            $saldo_ini_ag[$k] = [
                'me' => (float) $b['saldo_me'],
                'agencia' => $b['agencia'],
                'moneda' => $b['moneda'],
                'id_agencia' => $b['id_agencia'],
                'id_moneda' => $b['id_moneda'],
            ];
        }

        // Agrupar operaciones por agencia+moneda
        $grupos = [];
        foreach ($operaciones as $op) {
            $k = $op['id_agencia'] . '_' . $op['id_moneda'];
            if (!isset($grupos[$k])) {
                $grupos[$k] = [
                    'agencia' => $op['agencia'],
                    'id_agencia' => $op['id_agencia'],
                    'moneda' => $op['moneda'],
                    'id_moneda' => $op['id_moneda'],
                    'ops' => [],
                ];
            }
            $grupos[$k]['ops'][] = $op;
        }

        // Ordenar ops dentro de cada grupo cronológicamente
        foreach ($grupos as $k => &$grp) {
            usort($grp['ops'], function ($a, $b) {
                return strcmp($a['fecha'], $b['fecha']);
            });
        }
        unset($grp);

        // Crear grupos para bóvedas sin operaciones en el período
        foreach ($saldo_ini_ag as $k => $si) {
            if (!isset($grupos[$k]) && $si['me'] > 0.01 && $si['agencia'] !== '') {
                $grupos[$k] = [
                    'agencia' => $si['agencia'],
                    'id_agencia' => $si['id_agencia'],
                    'moneda' => $si['moneda'],
                    'id_moneda' => $si['id_moneda'],
                    'ops' => [],
                ];
            }
        }

        $movimientos = [];

        foreach ($grupos as $k => $g) {
            $tc_base = $bases_ini[$k] ?? 0;

            $lotes = [];
            $si_me = isset($saldo_ini_ag[$k]) ? $saldo_ini_ag[$k]['me'] : 0;
            if ($si_me > 0.01) {
                $lotes[] = ['me' => $si_me, 'tc' => $tc_base];
            }

            $saldo_ini_emitido = false;

            foreach ($g['ops'] as $op) {
                $me = (float) $op['monto_me'];
                $bob = (float) $op['bob'];
                $en_periodo = ($op['fecha'] >= $ini_reporte);
                $tc_op = $me > 0 ? $bob / $me : 0;

                if ($en_periodo && !$saldo_ini_emitido) {
                    $saldo_ini_emitido = true;
                    $s_me = 0; $s_bob = 0;
                    foreach ($lotes as $l) { $s_me += $l['me']; $s_bob += $l['me'] * $l['tc']; }
                    $s_tc = $s_me > 0 ? $s_bob / $s_me : 0;

                    $movimientos[] = [
                        'fecha' => $ini_reporte . ' 00:00:00',
                        'cajero' => '',
                        'nombre' => '',
                        'agencia' => $g['agencia'],
                        'id_agencia' => $g['id_agencia'],
                        'moneda' => $g['moneda'],
                        'id_moneda' => $g['id_moneda'],
                        'op' => 0,
                        'me' => round($s_me, 2),
                        'tc' => round($s_tc, 6),
                        'bob' => round($s_bob, 2),
                        'costo_fifo' => 0,
                        'utilidad' => null,
                        'saldo_me' => round($s_me, 2),
                        'costo_saldo_bob' => round($s_bob, 2),
                        'n_lotes' => count($lotes),
                    ];
                }

                // Operaciones de otros cajeros: procesar la cola en silencio
                if ($id_cajero && $op['cajero'] !== $id_cajero) {
                    if ($op['op'] == 1) {
                        $lotes[] = ['me' => $me, 'tc' => $tc_op];
                    } else {
                        $me_pv = $me;
                        while ($me_pv > 0.001 && !empty($lotes)) {
                            if ($lotes[0]['me'] <= $me_pv) { $me_pv -= $lotes[0]['me']; array_shift($lotes); }
                            else { $lotes[0]['me'] -= $me_pv; $me_pv = 0; }
                        }
                    }
                    continue;
                }

                if ($op['op'] == 1) {
                    $lotes[] = ['me' => $me, 'tc' => $tc_op];
                    $saldo_me = 0; $costo_bob = 0;
                    foreach ($lotes as $l) { $saldo_me += $l['me']; $costo_bob += $l['me'] * $l['tc']; }

                    if ($en_periodo) {
                        $movimientos[] = [
                            'fecha' => $op['fecha'],
                            'cajero' => $op['cajero'],
                            'nombre' => $op['nombre'],
                            'agencia' => $g['agencia'],
                            'id_agencia' => $g['id_agencia'],
                            'moneda' => $g['moneda'],
                            'id_moneda' => $g['id_moneda'],
                            'op' => 1,
                            'me' => $me,
                            'tc' => $tc_op,
                            'bob' => $bob,
                            'costo_fifo' => 0,
                            'utilidad' => null,
                            'saldo_me' => round($saldo_me, 2),
                            'costo_saldo_bob' => round($costo_bob, 2),
                            'n_lotes' => count($lotes),
                        ];
                    }
                } else {
                    $me_por_vender = $me;
                    $costo_fifo = 0.0;

                    while ($me_por_vender > 0.001 && !empty($lotes)) {
                        if ($lotes[0]['me'] <= $me_por_vender) {
                            $costo_fifo += $lotes[0]['me'] * $lotes[0]['tc'];
                            $me_por_vender -= $lotes[0]['me'];
                            array_shift($lotes);
                        } else {
                            $costo_fifo += $me_por_vender * $lotes[0]['tc'];
                            $lotes[0]['me'] -= $me_por_vender;
                            $me_por_vender = 0;
                        }
                    }

                    $saldo_me = 0; $costo_bob = 0;
                    foreach ($lotes as $l) { $saldo_me += $l['me']; $costo_bob += $l['me'] * $l['tc']; }

                    if ($en_periodo) {
                        $me_cubierta = $me - $me_por_vender;
                        $bob_cubierto = $me_cubierta >= $me ? $bob : $bob * ($me_cubierta / $me);
                        $util = $bob_cubierto - $costo_fifo;

                        $movimientos[] = [
                            'fecha' => $op['fecha'],
                            'cajero' => $op['cajero'],
                            'nombre' => $op['nombre'],
                            'agencia' => $g['agencia'],
                            'id_agencia' => $g['id_agencia'],
                            'moneda' => $g['moneda'],
                            'id_moneda' => $g['id_moneda'],
                            'op' => 2,
                            'me' => $me,
                            'tc' => $tc_op,
                            'bob' => $bob,
                            'costo_fifo' => round($costo_fifo, 2),
                            'utilidad' => round($util, 2),
                            'saldo_me' => round($saldo_me, 2),
                            'costo_saldo_bob' => round($costo_bob, 2),
                            'n_lotes' => count($lotes),
                        ];
                    }
                }
            }

            // Fallback: sin operaciones en el período pero con saldo de bóveda
            if (!$saldo_ini_emitido && !empty($lotes)) {
                $s_me = 0; $s_bob = 0;
                foreach ($lotes as $l) { $s_me += $l['me']; $s_bob += $l['me'] * $l['tc']; }
                if ($s_me > 0.01) {
                    $movimientos[] = [
                        'fecha' => $ini_reporte . ' 00:00:00',
                        'cajero' => '',
                        'nombre' => '',
                        'agencia' => $g['agencia'],
                        'id_agencia' => $g['id_agencia'],
                        'moneda' => $g['moneda'],
                        'id_moneda' => $g['id_moneda'],
                        'op' => 0,
                        'me' => round($s_me, 2),
                        'tc' => round($s_bob / $s_me, 6),
                        'bob' => round($s_bob, 2),
                        'costo_fifo' => 0,
                        'utilidad' => null,
                        'saldo_me' => round($s_me, 2),
                        'costo_saldo_bob' => round($s_bob, 2),
                        'n_lotes' => count($lotes),
                    ];
                }
            }
        }

        usort($movimientos, function ($a, $b) {
            $cmp = strcmp($a['agencia'] . '|' . $a['moneda'], $b['agencia'] . '|' . $b['moneda']);
            if ($cmp !== 0) return $cmp;
            return strcmp($a['fecha'], $b['fecha']);
        });

        return $movimientos;
    }

    /**
     * Agrega los movimientos del Kardex en filas resumidas.
     * $group_by: 'empresa' (moneda) | 'agencia' (agencia+moneda) | 'cajero'.
     */
    public function agregarKardex(array $movimientos, string $group_by = 'cajero'): array
    {
        $grupos = [];
        foreach ($movimientos as $m) {
            if ($m['op'] == 0) continue;

            if ($group_by === 'cajero') {
                $k = $m['cajero'] . '|' . $m['id_agencia'] . '|' . $m['id_moneda'];
            } elseif ($group_by === 'agencia') {
                $k = $m['id_agencia'] . '|' . $m['id_moneda'];
            } else {
                $k = $m['id_moneda'];
            }

            if (!isset($grupos[$k])) {
                $grupos[$k] = [
                    'cajero' => $group_by === 'cajero' ? $m['cajero'] : '',
                    'nombre' => $group_by === 'cajero' ? $m['nombre'] : '',
                    'agencia' => $m['agencia'],
                    'id_agencia' => $m['id_agencia'],
                    'moneda' => $m['moneda'],
                    'id_moneda' => $m['id_moneda'],
                    'n_compras' => 0,
                    'me_comprada' => 0,
                    'bob_compras' => 0,
                    'n_ventas' => 0,
                    'me_vendida' => 0,
                    'bob_ventas' => 0,
                    'utilidad_fifo' => 0,
                ];
            }

            if ($m['op'] == 1) {
                $grupos[$k]['n_compras']++;
                $grupos[$k]['me_comprada'] += $m['me'];
                $grupos[$k]['bob_compras'] += $m['bob'];
            } else {
                $grupos[$k]['n_ventas']++;
                $grupos[$k]['me_vendida'] += $m['me'];
                $grupos[$k]['bob_ventas'] += $m['bob'];
                $grupos[$k]['utilidad_fifo'] += $m['utilidad'];
            }
        }

        // Saldo final por agencia+moneda (último movimiento visto)
        $last_saldo = [];
        for ($i = count($movimientos) - 1; $i >= 0; $i--) {
            $m = $movimientos[$i];
            $sk = $m['id_agencia'] . '|' . $m['id_moneda'];
            if (!isset($last_saldo[$sk])) {
                $last_saldo[$sk] = [
                    'saldo_me' => $m['saldo_me'],
                    'costo_saldo_bob' => $m['costo_saldo_bob'],
                ];
            }
        }

        // Saldo total por moneda (suma de todas las agencias) — para la vista 'empresa',
        // donde "Saldo ME" debe ser el inventario total de la moneda, no el de una sola agencia.
        $saldo_moneda = [];
        foreach ($last_saldo as $sk => $v) {
            $mn = explode('|', $sk)[1];
            if (!isset($saldo_moneda[$mn])) $saldo_moneda[$mn] = ['saldo_me' => 0, 'costo_saldo_bob' => 0];
            $saldo_moneda[$mn]['saldo_me'] += $v['saldo_me'];
            $saldo_moneda[$mn]['costo_saldo_bob'] += $v['costo_saldo_bob'];
        }

        $resultados = [];
        foreach ($grupos as $g) {
            if ($group_by === 'empresa') {
                $saldo_me  = isset($saldo_moneda[$g['id_moneda']]) ? $saldo_moneda[$g['id_moneda']]['saldo_me'] : 0;
                $costo_bob = isset($saldo_moneda[$g['id_moneda']]) ? $saldo_moneda[$g['id_moneda']]['costo_saldo_bob'] : 0;
            } else {
                $sk = $g['id_agencia'] . '|' . $g['id_moneda'];
                $saldo_me  = isset($last_saldo[$sk]) ? $last_saldo[$sk]['saldo_me'] : 0;
                $costo_bob = isset($last_saldo[$sk]) ? $last_saldo[$sk]['costo_saldo_bob'] : 0;
            }

            $g['tc_compra_prom'] = $g['me_comprada'] > 0 ? round($g['bob_compras'] / $g['me_comprada'], 6) : 0;
            $g['tc_venta_prom'] = $g['me_vendida'] > 0 ? round($g['bob_ventas'] / $g['me_vendida'], 6) : 0;
            $g['spread'] = ($g['me_comprada'] > 0 && $g['me_vendida'] > 0)
                ? round($g['tc_venta_prom'] - $g['tc_compra_prom'], 6) : null;
            $g['utilidad_fifo'] = round($g['utilidad_fifo'], 2);
            $g['saldo_me'] = round($saldo_me, 2);
            $g['tc_costo_actual'] = $saldo_me > 0 ? round($costo_bob / $saldo_me, 6) : null;
            $g['me_comprada'] = round($g['me_comprada'], 2);
            $g['me_vendida'] = round($g['me_vendida'], 2);
            $g['bob_compras'] = round($g['bob_compras'], 2);
            $g['bob_ventas'] = round($g['bob_ventas'], 2);
            $resultados[] = $g;
        }

        return $resultados;
    }

    /** Agrega los movimientos por fecha → utilidad diaria y acumulada. */
    public function tendenciaDiaria(array $movimientos): array
    {
        $dias = [];
        foreach ($movimientos as $m) {
            if ($m['op'] == 0) continue;
            $d = substr($m['fecha'], 0, 10);
            if (!isset($dias[$d])) {
                $dias[$d] = [
                    'fecha' => $d,
                    'utilidad' => 0,
                    'bob_compras' => 0,
                    'bob_ventas' => 0,
                    'n_compras' => 0,
                    'n_ventas' => 0,
                ];
            }
            if ($m['op'] == 1) {
                $dias[$d]['bob_compras'] += $m['bob'];
                $dias[$d]['n_compras']++;
            } else {
                $dias[$d]['bob_ventas'] += $m['bob'];
                $dias[$d]['n_ventas']++;
                $dias[$d]['utilidad'] += $m['utilidad'];
            }
        }
        ksort($dias);
        $acum = 0;
        $result = [];
        foreach ($dias as &$d) {
            $acum += $d['utilidad'];
            $d['utilidad_acum'] = round($acum, 2);
            $d['utilidad'] = round($d['utilidad'], 2);
            $d['bob_compras'] = round($d['bob_compras'], 2);
            $d['bob_ventas'] = round($d['bob_ventas'], 2);
            $result[] = $d;
        }
        return $result;
    }

    /** Ranking de cajeros por utilidad FIFO total generada. */
    public function rankingCajeros(array $movimientos): array
    {
        $cajeros = [];
        foreach ($movimientos as $m) {
            if ($m['op'] == 0 || $m['cajero'] === '') continue;
            $k = $m['cajero'];
            if (!isset($cajeros[$k])) {
                $cajeros[$k] = [
                    'cajero' => $m['cajero'],
                    'nombre' => $m['nombre'],
                    'n_compras' => 0,
                    'n_ventas' => 0,
                    'bob_compras' => 0,
                    'bob_ventas' => 0,
                    'me_comprada' => 0,
                    'me_vendida' => 0,
                    'utilidad' => 0,
                    'n_ops' => 0,
                    'agencias' => [],
                    'monedas' => [],
                ];
            }
            $cajeros[$k]['n_ops']++;
            $cajeros[$k]['agencias'][$m['agencia']] = 1;
            $cajeros[$k]['monedas'][$m['moneda']] = 1;
            if ($m['op'] == 1) {
                $cajeros[$k]['n_compras']++;
                $cajeros[$k]['bob_compras'] += $m['bob'];
                $cajeros[$k]['me_comprada'] += $m['me'];
            } else {
                $cajeros[$k]['n_ventas']++;
                $cajeros[$k]['bob_ventas'] += $m['bob'];
                $cajeros[$k]['me_vendida'] += $m['me'];
                $cajeros[$k]['utilidad'] += $m['utilidad'];
            }
        }

        $result = [];
        foreach ($cajeros as $c) {
            $n_ventas = $c['n_ventas'] > 0 ? $c['n_ventas'] : 1;
            $c['utilidad_por_venta'] = round($c['utilidad'] / $n_ventas, 2);
            $c['utilidad'] = round($c['utilidad'], 2);
            $c['bob_compras'] = round($c['bob_compras'], 2);
            $c['bob_ventas'] = round($c['bob_ventas'], 2);
            $c['me_comprada'] = round($c['me_comprada'], 2);
            $c['me_vendida'] = round($c['me_vendida'], 2);
            $c['volumen_total'] = round($c['bob_compras'] + $c['bob_ventas'], 2);
            $c['agencias'] = implode(', ', array_keys($c['agencias']));
            $c['monedas'] = implode(', ', array_keys($c['monedas']));
            $result[] = $c;
        }

        usort($result, function ($a, $b) {
            $d = $b['utilidad'] - $a['utilidad'];
            return $d > 0 ? 1 : ($d < 0 ? -1 : 0);
        });

        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    // Orquestación por vista
    // ─────────────────────────────────────────────────────────────

    /**
     * Obtiene los movimientos Kardex del período (sin filtro de cajero).
     *
     * El costeo FIFO SIEMPRE se ancla al 1ro del mes de $ini: se toma la apertura de
     * bóveda y el TC base de esa fecha, y las operaciones desde el día 1. Las operaciones
     * entre el día 1 y $ini se procesan en la cola FIFO pero no emiten movimientos ni
     * acumulan utilidad ($ini es solo el inicio del período visible). Así, cambiar el
     * rango de fechas NO altera los costos: solo filtra qué fechas se muestran.
     */
    public function obtenerMovimientos(string $ini, string $fin, ?int $id_agencia = null, ?int $id_moneda = null): array
    {
        $mes_ini = date('Y-m-01', strtotime($ini));  // corte: 1ro del mes
        $boveda = $this->saldoAperturaBoveda($mes_ini, $id_agencia, $id_moneda);
        $bases_ini = $this->cotizacionBaseEnFecha($mes_ini, $id_agencia, $id_moneda);
        $ops = $this->operacionesCrudas($fin, $mes_ini, $id_agencia, $id_moneda);
        return $this->calcularKardex($ops, $bases_ini, $ini, null, $boveda);
    }

    /** Resumen (tarjetas) + alertas de pérdida FIFO a partir de filas agregadas. */
    public function resumen(array $rows): array
    {
        $tot_util = 0; $tot_bob_c = 0; $tot_bob_v = 0; $tot_ops = 0;
        $monedas_perdida = [];

        foreach ($rows as $r) {
            $tot_util += $r['utilidad_fifo'];
            $tot_bob_c += $r['bob_compras'];
            $tot_bob_v += $r['bob_ventas'];
            $tot_ops += $r['n_compras'] + $r['n_ventas'];

            if ($r['utilidad_fifo'] < -0.01) {
                $mon = $r['moneda'] ?? '';
                $ag = $r['agencia'] ?? '';
                $monedas_perdida[] = [
                    'agencia' => $ag,
                    'moneda' => $mon,
                    'utilidad_fifo' => round($r['utilidad_fifo'], 2),
                ];
            }
        }

        return [
            'utilidad_total' => round($tot_util, 2),
            'bob_compras' => round($tot_bob_c, 2),
            'bob_ventas' => round($tot_bob_v, 2),
            'operaciones' => $tot_ops,
            'monedas_perdida' => $monedas_perdida,
        ];
    }

    /** Vista Empresa — agregado por moneda. */
    public function empresa(string $ini, string $fin, ?int $ag, ?int $mn, string $orden = ''): array
    {
        $movs = $this->obtenerMovimientos($ini, $fin, $ag, $mn);
        $rows = $this->agregarKardex($movs, 'empresa');
        $this->ordenarPorUtilidad($rows, $orden);
        return ['resumen' => $this->resumen($rows), 'rows' => array_values($rows)];
    }

    /** Vista Agencia — agregado por agencia+moneda (ordenado para subtotales). */
    public function agencia(string $ini, string $fin, ?int $ag, ?int $mn): array
    {
        $movs = $this->obtenerMovimientos($ini, $fin, $ag, $mn);
        $rows = $this->agregarKardex($movs, 'agencia');
        usort($rows, function ($a, $b) {
            $cmp = strcmp($a['agencia'], $b['agencia']);
            if ($cmp !== 0) return $cmp;
            $d = $b['utilidad_fifo'] - $a['utilidad_fifo'];
            return $d > 0 ? 1 : ($d < 0 ? -1 : 0);
        });
        return ['resumen' => $this->resumen($rows), 'rows' => array_values($rows)];
    }

    /** Vista Cajero — agregado por cajero+agencia+moneda. */
    public function cajero(string $ini, string $fin, ?int $ag, ?int $mn, string $cajero = '', string $orden = ''): array
    {
        $movs = $this->obtenerMovimientos($ini, $fin, $ag, $mn);
        $rows = $this->agregarKardex($movs, 'cajero');

        if ($cajero !== '') {
            $rows = array_values(array_filter($rows, function ($r) use ($cajero) {
                return $r['cajero'] === $cajero;
            }));
        }
        $this->ordenarPorUtilidad($rows, $orden);
        return ['resumen' => $this->resumen($rows), 'rows' => array_values($rows)];
    }

    /** Vista Kardex — detalle lote por lote (con filtro opcional por cajero). */
    public function kardex(string $ini, string $fin, ?int $ag, ?int $mn, string $cajero = ''): array
    {
        $mes_ini = date('Y-m-01', strtotime($ini));  // corte FIFO: 1ro del mes (ver obtenerMovimientos)
        $boveda = $this->saldoAperturaBoveda($mes_ini, $ag, $mn);
        $bases_ini = $this->cotizacionBaseEnFecha($mes_ini, $ag, $mn);
        $ops = $this->operacionesCrudas($fin, $mes_ini, $ag, $mn);
        $caj = $cajero !== '' ? $cajero : null;
        $movs = $this->calcularKardex($ops, $bases_ini, $ini, $caj, $boveda);
        $resumen = $this->resumen($this->agregarKardex($movs, 'empresa'));
        return ['resumen' => $resumen, 'movimientos' => $movs];
    }

    /** Vista Ranking — clasificación de cajeros por utilidad, incluyendo los que aperturaron sin operar. */
    public function ranking(string $ini, string $fin, ?int $ag, ?int $mn): array
    {
        $movs = $this->obtenerMovimientos($ini, $fin, $ag, $mn);
        $ranking = $this->rankingCajeros($movs);
        foreach ($ranking as &$r) { $r['sin_movimiento'] = false; }
        unset($r);

        // Cajeros que aperturaron caja pero no operaron cambios (solo si no se filtra por moneda).
        if (!$mn) {
            $activos = [];
            foreach ($ranking as $r) $activos[$r['cajero']] = true;
            foreach ($this->cajerosSinMovimiento($ini, $fin, $ag) as $c) {
                if (isset($activos[$c['cajero']])) continue;
                $ranking[] = [
                    'cajero' => $c['cajero'], 'nombre' => $c['nombre'],
                    'n_compras' => 0, 'n_ventas' => 0, 'bob_compras' => 0, 'bob_ventas' => 0,
                    'me_comprada' => 0, 'me_vendida' => 0, 'utilidad' => 0, 'n_ops' => 0,
                    'agencias' => $c['agencia'], 'monedas' => '',
                    'utilidad_por_venta' => 0, 'volumen_total' => 0,
                    'sin_movimiento' => true,
                ];
            }
        }

        $resumen = $this->resumen($this->agregarKardex($movs, 'empresa'));
        return ['resumen' => $resumen, 'ranking' => $ranking];
    }

    /** Vista Tendencia — utilidad diaria y acumulada. */
    public function tendencia(string $ini, string $fin, ?int $ag, ?int $mn): array
    {
        $movs = $this->obtenerMovimientos($ini, $fin, $ag, $mn);
        $tendencia = $this->tendenciaDiaria($movs);
        $resumen = $this->resumen($this->agregarKardex($movs, 'empresa'));
        return ['resumen' => $resumen, 'tendencia' => $tendencia];
    }

    /** Vista Inventario — posición valorizada al costo FIFO y a mercado. */
    public function inventario(string $ini, string $fin, ?int $ag, ?int $mn): array
    {
        $movs = $this->obtenerMovimientos($ini, $fin, $ag, $mn);
        $inv = $this->agregarKardex($movs, 'agencia');
        $tcVenta = $this->cotizacionVentaActual();
        $dias_periodo = max(1, (strtotime($fin) - strtotime($ini)) / 86400);

        // Solo saldos > 0.01, ordenados por agencia + moneda
        $inv = array_values(array_filter($inv, function ($r) { return $r['saldo_me'] > 0.01; }));
        usort($inv, function ($a, $b) {
            $cmp = strcmp($a['agencia'], $b['agencia']);
            return $cmp !== 0 ? $cmp : strcmp($a['moneda'], $b['moneda']);
        });

        $rows = [];
        $totCosto = 0; $totMerc = 0;
        $alertas = [];

        foreach ($inv as $r) {
            $key = $r['id_agencia'] . '_' . $r['id_moneda'];
            $tcV = $tcVenta[$key] ?? 0;
            $tcCosto = $r['tc_costo_actual'] ?: 0;
            $costoBob = $r['saldo_me'] * $tcCosto;
            $valorMerc = $r['saldo_me'] * $tcV;
            $gnr = $valorMerc - $costoBob;

            $me_vendida_diaria = ($dias_periodo > 0 && $r['me_vendida'] > 0) ? $r['me_vendida'] / $dias_periodo : 0;
            $rotacion = $me_vendida_diaria > 0 ? round($r['saldo_me'] / $me_vendida_diaria, 1) : null;

            $severidad = null;
            if ($gnr < -0.01) {
                $severidad = $gnr < -100 ? 'grave' : 'leve';
                $alertas[] = [
                    'agencia' => $r['agencia'],
                    'moneda' => $r['moneda'],
                    'gnr' => round($gnr, 2),
                    'severidad' => $severidad,
                ];
            }

            $rows[] = [
                'agencia' => $r['agencia'],
                'id_agencia' => $r['id_agencia'],
                'moneda' => $r['moneda'],
                'id_moneda' => $r['id_moneda'],
                'saldo_me' => round($r['saldo_me'], 2),
                'tc_costo_actual' => $r['tc_costo_actual'],
                'costo_total' => round($costoBob, 2),
                'tc_minimo_venta' => $tcCosto > 0 ? round($tcCosto, 6) : null,
                'tc_venta_actual' => $tcV > 0 ? round($tcV, 6) : null,
                'valor_mercado' => round($valorMerc, 2),
                'ganancia_no_realizada' => round($gnr, 2),
                'rotacion_dias' => $rotacion,
                'me_vendida' => round($r['me_vendida'], 2),
                'severidad' => $severidad,
            ];

            $totCosto += $costoBob;
            $totMerc += $valorMerc;
        }

        return [
            'rows' => $rows,
            'totales' => [
                'costo_total' => round($totCosto, 2),
                'valor_mercado' => round($totMerc, 2),
                'ganancia_no_realizada' => round($totMerc - $totCosto, 2),
            ],
            'alertas' => $alertas,
            'dias_periodo' => (int) $dias_periodo,
        ];
    }

    /** Vista Comparar — período actual vs. período anterior (misma duración). */
    public function comparar(string $ini, string $fin, ?int $ag, ?int $mn): array
    {
        $dias = strtotime($fin) - strtotime($ini);
        $ini2 = date('Y-m-d', strtotime($ini) - $dias - 86400);
        $fin2 = date('Y-m-d', strtotime($ini) - 86400);

        $actual = $this->agregarKardex($this->obtenerMovimientos($ini, $fin, $ag, $mn), 'empresa');
        $anterior = $this->agregarKardex($this->obtenerMovimientos($ini2, $fin2, $ag, $mn), 'empresa');

        $totA = $this->totalesComparar($actual);
        $totB = $this->totalesComparar($anterior);

        $metricas = [
            ['key' => 'utilidad', 'label' => 'Utilidad FIFO'],
            ['key' => 'bob_compras', 'label' => 'BOB Compras'],
            ['key' => 'bob_ventas', 'label' => 'BOB Ventas'],
            ['key' => 'n_ops', 'label' => 'Operaciones'],
        ];
        $deltas = [];
        foreach ($metricas as $m) {
            $a = $totA[$m['key']];
            $b = $totB[$m['key']];
            $diff = $a - $b;
            $pct = $b != 0 ? (($a - $b) / abs($b)) * 100 : ($a != 0 ? 100 : 0);
            $deltas[] = [
                'label' => $m['label'],
                'actual' => round($a, 2),
                'anterior' => round($b, 2),
                'diff' => round($diff, 2),
                'pct' => round($pct, 1),
                'es_conteo' => $m['key'] === 'n_ops',
            ];
        }

        // Comparación por moneda
        $mapA = []; foreach ($actual as $r) $mapA[$r['id_moneda']] = $r;
        $mapB = []; foreach ($anterior as $r) $mapB[$r['id_moneda']] = $r;
        $all = array_unique(array_merge(array_keys($mapA), array_keys($mapB)));
        $por_moneda = [];
        foreach ($all as $mid) {
            $a = $mapA[$mid] ?? null;
            $b = $mapB[$mid] ?? null;
            $ua = $a ? $a['utilidad_fifo'] : 0;
            $ub = $b ? $b['utilidad_fifo'] : 0;
            $por_moneda[] = [
                'moneda' => $a ? $a['moneda'] : ($b ? $b['moneda'] : (string) $mid),
                'id_moneda' => $mid,
                'util_actual' => round($ua, 2),
                'util_anterior' => round($ub, 2),
                'diff' => round($ua - $ub, 2),
                'ops_actual' => $a ? ($a['n_compras'] + $a['n_ventas']) : 0,
                'ops_anterior' => $b ? ($b['n_compras'] + $b['n_ventas']) : 0,
            ];
        }

        return [
            'periodos' => ['ini1' => $ini, 'fin1' => $fin, 'ini2' => $ini2, 'fin2' => $fin2],
            'totales' => ['actual' => $totA, 'anterior' => $totB],
            'deltas' => $deltas,
            'por_moneda' => $por_moneda,
        ];
    }

    /**
     * Vista Redistribución — para cada posición de inventario estancada (una moneda
     * que no se está vendiendo o con rotación lenta en una agencia), recomienda la
     * agencia destino con mejor TC de venta ENTRE las que tienen demanda real de esa
     * moneda, y estima la ganancia potencial de moverla ahí.
     *
     * El análisis es cross-agencia: no se filtra por agencia en el cálculo (se necesitan
     * todas las plazas); el filtro de agencia solo restringe los ORÍGENES en el resultado.
     */
    public function redistribucion(string $ini, string $fin, ?int $ag, ?int $mn): array
    {
        $movs    = $this->obtenerMovimientos($ini, $fin, null, $mn);   // todas las agencias
        $inv     = $this->agregarKardex($movs, 'agencia');             // costo FIFO + demanda (me_vendida)
        $tcVenta = $this->cotizacionVentaActual();                     // TC venta actual por agencia+moneda
        $bases   = $this->cotizacionBaseEnFecha(date('Y-m-d'));        // costo de respaldo (TC base compra actual)
        $saldos  = $this->saldoActualAgencias();                       // saldo REAL hoy (cajas + bóveda)
        $dias    = max(1, (strtotime($fin) - strtotime($ini)) / 86400);

        // Celda combinada por agencia+moneda: saldo real + costo FIFO + demanda + TC venta
        $cell = [];
        foreach ($inv as $r) {
            $k = $r['id_agencia'] . '_' . $r['id_moneda'];
            $cell[$k] = [
                'id_agencia' => (int) $r['id_agencia'], 'agencia' => $r['agencia'],
                'id_moneda'  => (int) $r['id_moneda'],  'moneda'  => $r['moneda'],
                'saldo'      => 0.0,
                'tc_costo'   => (float) ($r['tc_costo_actual'] ?: 0),
                'me_vendida' => (float) $r['me_vendida'],
                'tc_venta'   => 0.0,
            ];
        }
        foreach ($saldos as $s) {
            $k = $s['id_agencia'] . '_' . $s['id_moneda'];
            if (!isset($cell[$k])) {
                $cell[$k] = [
                    'id_agencia' => (int) $s['id_agencia'], 'agencia' => $s['agencia'],
                    'id_moneda'  => (int) $s['id_moneda'],  'moneda'  => $s['moneda'],
                    'saldo'      => 0.0, 'tc_costo' => 0.0, 'me_vendida' => 0.0, 'tc_venta' => 0.0,
                ];
            }
            $cell[$k]['saldo'] = (float) $s['saldo'];
        }
        foreach ($cell as $k => &$c) {
            $c['tc_venta'] = (float) ($tcVenta[$k] ?? 0);
            if ($c['tc_costo'] <= 0) $c['tc_costo'] = (float) ($bases[$k] ?? 0);  // respaldo: TC base compra actual
        }
        unset($c);

        // Agrupar por moneda
        $porMoneda = [];
        foreach ($cell as $c) {
            if ($mn && $c['id_moneda'] != $mn) continue;
            $mid = $c['id_moneda'];
            if (!isset($porMoneda[$mid])) $porMoneda[$mid] = ['moneda' => $c['moneda'], 'ag' => []];
            $porMoneda[$mid]['ag'][] = $c;
        }

        $oportunidades = [];
        foreach ($porMoneda as $mid => $g) {
            // Destinos con demanda (vendieron esa moneda en el período) y TC de venta válido.
            $destinos = array_values(array_filter($g['ag'], function ($a) { return $a['me_vendida'] > 0 && $a['tc_venta'] > 0; }));
            usort($destinos, function ($a, $b) { return $b['tc_venta'] <=> $a['tc_venta']; });
            if (empty($destinos)) continue;

            foreach ($g['ag'] as $o) {
                if ($o['saldo'] <= 0.01) continue;  // solo lo que hay físicamente hoy
                $rot = $o['me_vendida'] > 0 ? round($o['saldo'] / ($o['me_vendida'] / $dias), 1) : null;
                $estancado = ($o['me_vendida'] <= 0) || ($rot !== null && $rot > self::ROTACION_ESTANCADO);
                if (!$estancado) continue;

                $dest = null;
                foreach ($destinos as $d) { if ($d['id_agencia'] != $o['id_agencia']) { $dest = $d; break; } }
                if (!$dest) continue;

                $gan_unit  = $dest['tc_venta'] - $o['tc_costo'];
                $gan_total = $o['saldo'] * $gan_unit;

                $oportunidades[] = [
                    'moneda'              => $g['moneda'],
                    'id_moneda'           => $mid,
                    'agencia_origen'      => $o['agencia'],
                    'id_agencia_origen'   => $o['id_agencia'],
                    'saldo_me'            => round($o['saldo'], 2),
                    'tc_costo_origen'     => $o['tc_costo'] > 0 ? round($o['tc_costo'], 6) : null,
                    'motivo'              => $o['me_vendida'] <= 0 ? 'sin_ventas' : 'rotacion_lenta',
                    'rotacion_origen'     => $rot,
                    'agencia_destino'     => $dest['agencia'],
                    'id_agencia_destino'  => $dest['id_agencia'],
                    'tc_venta_destino'    => round($dest['tc_venta'], 6),
                    'me_vendida_destino'  => round($dest['me_vendida'], 2),
                    'rotacion_destino'    => null,
                    'ganancia_unitaria'   => round($gan_unit, 6),
                    'ganancia_potencial'  => round($gan_total, 2),
                ];
            }
        }

        usort($oportunidades, function ($a, $b) { return $b['ganancia_potencial'] <=> $a['ganancia_potencial']; });

        if ($ag) {
            $oportunidades = array_values(array_filter($oportunidades, function ($o) use ($ag) { return $o['id_agencia_origen'] == $ag; }));
        }

        $total = 0;
        foreach ($oportunidades as $o) { if ($o['ganancia_potencial'] > 0) $total += $o['ganancia_potencial']; }

        return [
            'oportunidades'     => $oportunidades,
            'ganancia_total'    => round($total, 2),
            'rotacion_umbral'   => self::ROTACION_ESTANCADO,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers internos
    // ─────────────────────────────────────────────────────────────

    private function ordenarPorUtilidad(array &$rows, string $orden): void
    {
        if ($orden === 'utilidad_asc') {
            usort($rows, function ($a, $b) { $d = $a['utilidad_fifo'] - $b['utilidad_fifo']; return $d > 0 ? 1 : ($d < 0 ? -1 : 0); });
        } elseif ($orden === 'utilidad_desc') {
            usort($rows, function ($a, $b) { $d = $b['utilidad_fifo'] - $a['utilidad_fifo']; return $d > 0 ? 1 : ($d < 0 ? -1 : 0); });
        } else {
            // Por defecto: mayor utilidad primero (como el legacy en empresa)
            usort($rows, function ($a, $b) { $d = $b['utilidad_fifo'] - $a['utilidad_fifo']; return $d > 0 ? 1 : ($d < 0 ? -1 : 0); });
        }
    }

    private function totalesComparar(array $rows): array
    {
        $t = ['utilidad' => 0, 'bob_compras' => 0, 'bob_ventas' => 0, 'n_ops' => 0];
        foreach ($rows as $r) {
            $t['utilidad'] += $r['utilidad_fifo'];
            $t['bob_compras'] += $r['bob_compras'];
            $t['bob_ventas'] += $r['bob_ventas'];
            $t['n_ops'] += $r['n_compras'] + $r['n_ventas'];
        }
        $t['utilidad'] = round($t['utilidad'], 2);
        $t['bob_compras'] = round($t['bob_compras'], 2);
        $t['bob_ventas'] = round($t['bob_ventas'], 2);
        return $t;
    }
}
