<?php

namespace App\Modules\Notificaciones\Services;

use App\Modules\Shared\Database\RawConnection;

class NotificacionService
{
    // ── CRUD de notificaciones ──

    public function listar(array $filtros = []): array
    {
        $conditions = ['1=1'];
        $params = [];

        if (!empty($filtros['tipo'])) {
            $conditions[] = 'n.tipo = :tipo';
            $params[':tipo'] = $filtros['tipo'];
        }
        if (!empty($filtros['urgencia'])) {
            $conditions[] = 'n.urgencia = :urgencia';
            $params[':urgencia'] = $filtros['urgencia'];
        }
        if (isset($filtros['leida'])) {
            $conditions[] = 'n.leida = :leida';
            $params[':leida'] = (int)$filtros['leida'];
        }
        if (!empty($filtros['agencia'])) {
            $conditions[] = '(n.id_agencia = :ag OR n.id_agencia IS NULL)';
            $params[':ag'] = (int)$filtros['agencia'];
        }

        $where = implode(' AND ', $conditions);
        $limit = min((int)($filtros['limit'] ?? 50), 200);
        $offset = max((int)($filtros['offset'] ?? 0), 0);

        $rows = RawConnection::select("
            SELECT n.*, a.descripcion AS agencia_nombre
            FROM notificacion n
            LEFT JOIN agencia a ON a.id_agencia = n.id_agencia
            WHERE {$where}
            ORDER BY n.fec_creacion DESC
            LIMIT {$limit} OFFSET {$offset}
        ", $params);

        $total = (int)RawConnection::scalar("
            SELECT COUNT(*) FROM notificacion n WHERE {$where}
        ", $params);

        return [
            'items' => array_map([$this, 'formatRow'], $rows),
            'total' => $total,
        ];
    }

    public function contarNoLeidas(?int $idAgencia = null): array
    {
        $agWhere = $idAgencia
            ? 'AND (n.id_agencia = :ag OR n.id_agencia IS NULL)'
            : '';
        $params = $idAgencia ? [':ag' => $idAgencia] : [];

        $rows = RawConnection::select("
            SELECT n.urgencia, COUNT(*) AS total
            FROM notificacion n
            WHERE n.leida = 0 {$agWhere}
            GROUP BY n.urgencia
        ", $params);

        $result = ['total' => 0, 'critica' => 0, 'alta' => 0, 'media' => 0, 'baja' => 0];
        foreach ($rows as $r) {
            $result[$r['urgencia']] = (int)$r['total'];
            $result['total'] += (int)$r['total'];
        }
        return $result;
    }

    public function marcarLeida(int $id, string $usuario): bool
    {
        RawConnection::execute("
            UPDATE notificacion SET leida = 1, leida_por = :usr, fec_lectura = NOW()
            WHERE id_notificacion = :id AND leida = 0
        ", [':id' => $id, ':usr' => $usuario]);
        return true;
    }

    public function marcarTodasLeidas(string $usuario, ?string $tipo = null): int
    {
        $tipoWhere = $tipo ? 'AND tipo = :tipo' : '';
        $params = [':usr' => $usuario];
        if ($tipo) $params[':tipo'] = $tipo;

        RawConnection::execute("
            UPDATE notificacion SET leida = 1, leida_por = :usr, fec_lectura = NOW()
            WHERE leida = 0 {$tipoWhere}
        ", $params);

        return 1;
    }

    // ── Crear notificación ──

    public function crear(string $codigo, string $tipo, string $urgencia, string $titulo, string $mensaje, ?array $datos = null, ?int $idAgencia = null, ?int $idRol = null): int
    {
        RawConnection::execute("
            INSERT INTO notificacion (codigo, tipo, urgencia, titulo, mensaje, datos, id_agencia, id_rol_destino)
            VALUES (:codigo, :tipo, :urgencia, :titulo, :mensaje, :datos, :agencia, :rol)
        ", [
            ':codigo'   => $codigo,
            ':tipo'     => $tipo,
            ':urgencia' => $urgencia,
            ':titulo'   => $titulo,
            ':mensaje'  => $mensaje,
            ':datos'    => $datos ? json_encode($datos) : null,
            ':agencia'  => $idAgencia,
            ':rol'      => $idRol,
        ]);

        return (int)RawConnection::pdo()->lastInsertId();
    }

    // ── GENERADORES DE NOTIFICACIONES ──

    /**
     * #1 Fondeo inteligente por remesas
     * Detecta agencias con remesas pendientes cuyo saldo no alcanza para pagarlas
     */
    public function generarFondeoRemesas(string $fecha): int
    {
        $rows = RawConnection::select("
            SELECT a.id_agencia, a.descripcion AS agencia, m.abreviatura AS moneda,
                   COALESCE(SUM(CASE WHEN r.estado = 'P' THEN r.monto_giro ELSE 0 END), 0) AS remesas_pendientes,
                   COALESCE((
                       SELECT SUM(sc.saldo) FROM saldo_caja sc
                       INNER JOIN caja c ON c.id_caja = sc.id_caja
                       WHERE c.id_agencia = a.id_agencia AND c.id_moneda = m.id_moneda
                         AND sc.conciliacion <> 'C' AND DATE(sc.fec_crea_saldo) = :fecha2
                   ), 0) AS saldo_caja
            FROM remesa r
            INNER JOIN agencia a ON a.id_agencia = r.id_agencia
            INNER JOIN moneda m ON m.id_moneda = r.id_moneda
            WHERE r.estado = 'P' AND DATE(r.fec_crea_remesa) = :fecha
            GROUP BY a.id_agencia, a.descripcion, m.abreviatura, m.id_moneda
            HAVING remesas_pendientes > saldo_caja
        ", [':fecha' => $fecha, ':fecha2' => $fecha]);

        $count = 0;
        foreach ($rows as $r) {
            $deficit = round((float)$r['remesas_pendientes'] - (float)$r['saldo_caja'], 2);

            $disponibles = RawConnection::select("
                SELECT a2.descripcion AS agencia,
                       COALESCE((SELECT SUM(sb.saldo) FROM saldo_boveda sb
                                 INNER JOIN boveda b ON b.id_boveda = sb.id_boveda
                                 INNER JOIN moneda m ON m.id_moneda = b.id_moneda AND m.abreviatura = :mon
                                 WHERE b.id_agencia = a2.id_agencia
                                   AND sb.conciliacion <> 'C' AND DATE(sb.fec_crea_saldo) = :fecha
                       ), 0) AS disponible
                FROM agencia a2
                WHERE a2.estado = 'A' AND a2.id_agencia <> :ag AND a2.id_agencia > 0
                HAVING disponible > 0
                ORDER BY disponible DESC
                LIMIT 3
            ", [':mon' => $r['moneda'], ':fecha' => $fecha, ':ag' => $r['id_agencia']]);

            $fuentes = array_map(fn($d) => $d['agencia'] . ' (' . number_format((float)$d['disponible'], 2) . ')', $disponibles);

            $this->crear(
                'fondeo_remesas', 'fondeo', 'critica',
                "Fondeo urgente: {$r['agencia']}",
                "Remesas pendientes por {$r['moneda']} " . number_format((float)$r['remesas_pendientes'], 2) .
                " superan el saldo disponible. Déficit: {$r['moneda']} " . number_format($deficit, 2) .
                ($fuentes ? ". Fuentes posibles: " . implode(', ', $fuentes) : ". Sin fuentes disponibles."),
                [
                    'idAgencia' => (int)$r['id_agencia'],
                    'moneda' => $r['moneda'],
                    'remesasPendientes' => (float)$r['remesas_pendientes'],
                    'saldoCaja' => (float)$r['saldo_caja'],
                    'deficit' => $deficit,
                    'fuentes' => $disponibles,
                ],
                (int)$r['id_agencia']
            );
            $count++;
        }
        return $count;
    }

    /**
     * #2 Resumen matutino 09:00
     */
    public function generarResumenMatutino(string $fecha): int
    {
        $agencias = RawConnection::select("
            SELECT a.id_agencia, a.descripcion,
                   COALESCE((SELECT ROUND(SUM(sc.saldo * COALESCE(co.valor,1)), 2) FROM saldo_caja sc
                             INNER JOIN caja c ON c.id_caja = sc.id_caja
                             LEFT JOIN moneda m ON m.id_moneda = c.id_moneda
                             LEFT JOIN cotizacion_oficial co ON co.id_moneda = m.id_moneda AND co.id_operacion = 1
                               AND co.fec_crea_cotizacion_oficial = (SELECT MAX(fec_crea_cotizacion_oficial) FROM cotizacion_oficial WHERE id_moneda = m.id_moneda AND id_operacion = 1)
                             WHERE c.id_agencia = a.id_agencia AND sc.conciliacion <> 'C' AND DATE(sc.fec_crea_saldo) = :f
                   ), 0) AS saldo_cajas,
                   COALESCE((SELECT ROUND(SUM(sb.saldo * COALESCE(co2.valor,1)), 2) FROM saldo_boveda sb
                             INNER JOIN boveda b ON b.id_boveda = sb.id_boveda
                             LEFT JOIN moneda m2 ON m2.id_moneda = b.id_moneda
                             LEFT JOIN cotizacion_oficial co2 ON co2.id_moneda = m2.id_moneda AND co2.id_operacion = 1
                               AND co2.fec_crea_cotizacion_oficial = (SELECT MAX(fec_crea_cotizacion_oficial) FROM cotizacion_oficial WHERE id_moneda = m2.id_moneda AND id_operacion = 1)
                             WHERE b.id_agencia = a.id_agencia AND sb.conciliacion <> 'C' AND DATE(sb.fec_crea_saldo) = :f2
                   ), 0) AS saldo_bovedas
            FROM agencia a WHERE a.estado = 'A' AND a.id_agencia > 0
            ORDER BY a.descripcion
        ", [':f' => $fecha, ':f2' => $fecha]);

        $totalGeneral = 0;
        $resumen = [];
        foreach ($agencias as $ag) {
            $total = (float)$ag['saldo_cajas'] + (float)$ag['saldo_bovedas'];
            if ($total == 0) continue;
            $totalGeneral += $total;
            $resumen[] = [
                'agencia' => $ag['descripcion'],
                'cajas' => (float)$ag['saldo_cajas'],
                'bovedas' => (float)$ag['saldo_bovedas'],
                'total' => round($total, 2),
            ];
        }

        $lineas = array_map(fn($r) => "• {$r['agencia']}: BOB " . number_format($r['total'], 2), $resumen);

        $this->crear(
            'resumen_matutino', 'reporte', 'baja',
            'Resumen matutino — ' . $fecha,
            "Total general: BOB " . number_format($totalGeneral, 2) . " en " . count($resumen) . " agencias.\n" . implode("\n", $lineas),
            ['fecha' => $fecha, 'totalGeneral' => round($totalGeneral, 2), 'agencias' => $resumen]
        );
        return 1;
    }

    /**
     * #3 Cierre operativo diario
     */
    public function generarCierreOperativo(string $fecha): int
    {
        $ops = RawConnection::select("
            SELECT 'Cambios' AS tipo, COUNT(*) AS total FROM cambio WHERE DATE(fec_crea_cambio) = :f
            UNION ALL
            SELECT 'Giros', COUNT(*) FROM giro WHERE DATE(fec_crea_giro) = :f2
            UNION ALL
            SELECT 'Remesas', COUNT(*) FROM remesa WHERE DATE(fec_crea_remesa) = :f3
        ", [':f' => $fecha, ':f2' => $fecha, ':f3' => $fecha]);

        $totalOps = 0;
        $detalle = [];
        foreach ($ops as $op) {
            $detalle[$op['tipo']] = (int)$op['total'];
            $totalOps += (int)$op['total'];
        }

        $topCajeros = RawConnection::select("
            SELECT CONCAT(t.nom_trab, ' ', t.ape_pat_trab) AS cajero,
                   a.descripcion AS agencia, COUNT(*) AS total
            FROM cambio c
            INNER JOIN usuario u ON u.cod_usuario = c.cod_usuario
            INNER JOIN trabajador t ON t.cod_trab = u.cod_trab
            INNER JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE DATE(c.fec_crea_cambio) = :f
            GROUP BY cajero, a.descripcion
            ORDER BY total DESC LIMIT 5
        ", [':f' => $fecha]);

        $cajList = array_map(fn($c) => "• {$c['cajero']} ({$c['agencia']}): {$c['total']} ops", $topCajeros);

        $this->crear(
            'cierre_operativo', 'operacion', 'baja',
            'Cierre operativo — ' . $fecha,
            "Total operaciones: {$totalOps} (Cambios: {$detalle['Cambios']}, Giros: {$detalle['Giros']}, Remesas: {$detalle['Remesas']}).\n\nTop cajeros:\n" . implode("\n", $cajList),
            ['fecha' => $fecha, 'totalOps' => $totalOps, 'detalle' => $detalle, 'topCajeros' => $topCajeros]
        );
        return 1;
    }

    /**
     * #4 Utilidad diaria por agencia
     */
    public function generarUtilidadDiaria(string $fecha): int
    {
        $rows = RawConnection::select("
            SELECT a.id_agencia, a.descripcion AS agencia,
                   COALESCE(SUM(
                       CASE WHEN c.id_operacion = 1 THEN c.monto * (c.cotizacion_venta - c.cotizacion_compra)
                            WHEN c.id_operacion = 2 THEN c.monto * (c.cotizacion_compra - c.cotizacion_venta)
                            ELSE 0 END
                   ), 0) AS utilidad
            FROM cambio c
            INNER JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE DATE(c.fec_crea_cambio) = :f AND c.estado = 'A'
            GROUP BY a.id_agencia, a.descripcion
            ORDER BY utilidad DESC
        ", [':f' => $fecha]);

        $totalUtil = 0;
        $agencias = [];
        foreach ($rows as $r) {
            $util = round((float)$r['utilidad'], 2);
            $totalUtil += $util;
            $agencias[] = [
                'agencia' => $r['agencia'],
                'utilidad' => $util,
                'estado' => $util >= 0 ? 'ganancia' : 'perdida',
            ];
        }

        $lineas = array_map(fn($a) =>
            "• {$a['agencia']}: BOB " . number_format($a['utilidad'], 2) . ($a['utilidad'] < 0 ? ' (PÉRDIDA)' : ''),
            $agencias
        );

        $perdidas = array_filter($agencias, fn($a) => $a['utilidad'] < 0);
        $urgencia = count($perdidas) > 0 ? 'alta' : 'baja';

        $this->crear(
            'utilidad_diaria', 'reporte', $urgencia,
            'Utilidad diaria — ' . $fecha,
            "Utilidad total estimada: BOB " . number_format($totalUtil, 2) .
            (count($perdidas) > 0 ? " (" . count($perdidas) . " agencia(s) con pérdida)" : '') .
            ".\n" . implode("\n", $lineas),
            ['fecha' => $fecha, 'totalUtilidad' => round($totalUtil, 2), 'agencias' => $agencias]
        );
        return 1;
    }

    /**
     * #5 Cotización fuera de mercado
     */
    public function generarCotizacionFueraMercado(): int
    {
        $rows = RawConnection::select("
            SELECT m.abreviatura AS moneda,
                   MAX(CASE WHEN cb.id_operacion = 1 THEN cb.valor_base END) AS compra_actual,
                   MAX(CASE WHEN cb.id_operacion = 2 THEN cb.valor_base END) AS venta_actual,
                   a.descripcion AS agencia, a.id_agencia,
                   (SELECT AVG(cb2.valor_base) FROM cotizacion_base cb2
                    WHERE cb2.id_moneda = cb.id_moneda AND cb2.id_agencia = 99 AND cb2.id_operacion = 1
                      AND cb2.fecha_creacion >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   ) AS prom_compra_7d,
                   (SELECT AVG(cb2.valor_base) FROM cotizacion_base cb2
                    WHERE cb2.id_moneda = cb.id_moneda AND cb2.id_agencia = 99 AND cb2.id_operacion = 2
                      AND cb2.fecha_creacion >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   ) AS prom_venta_7d
            FROM cotizacion_base cb
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            INNER JOIN agencia a ON a.id_agencia = cb.id_agencia
            INNER JOIN (
                SELECT id_moneda, id_agencia, id_operacion, MAX(fecha_creacion) AS max_fecha
                FROM cotizacion_base WHERE id_agencia <> 99
                GROUP BY id_moneda, id_agencia, id_operacion
            ) ult ON ult.id_moneda = cb.id_moneda AND ult.id_agencia = cb.id_agencia AND ult.id_operacion = cb.id_operacion AND ult.max_fecha = cb.fecha_creacion
            WHERE cb.id_agencia <> 99
            GROUP BY m.abreviatura, a.descripcion, a.id_agencia, cb.id_moneda
        ");

        $count = 0;
        foreach ($rows as $r) {
            if (!$r['prom_compra_7d'] || !$r['prom_venta_7d']) continue;
            $desvCompra = abs((float)$r['compra_actual'] - (float)$r['prom_compra_7d']) / (float)$r['prom_compra_7d'] * 100;
            $desvVenta = abs((float)$r['venta_actual'] - (float)$r['prom_venta_7d']) / (float)$r['prom_venta_7d'] * 100;

            if ($desvCompra > 3 || $desvVenta > 3) {
                $this->crear(
                    'cotizacion_mercado', 'riesgo', 'media',
                    "Cotización fuera de mercado: {$r['moneda']} en {$r['agencia']}",
                    "La cotización de {$r['moneda']} en {$r['agencia']} se desvía del promedio 7 días. " .
                    "Compra: {$r['compra_actual']} (prom: " . number_format((float)$r['prom_compra_7d'], 4) . ", desv: " . number_format($desvCompra, 1) . "%). " .
                    "Venta: {$r['venta_actual']} (prom: " . number_format((float)$r['prom_venta_7d'], 4) . ", desv: " . number_format($desvVenta, 1) . "%).",
                    [
                        'idAgencia' => (int)$r['id_agencia'],
                        'moneda' => $r['moneda'],
                        'compraActual' => (float)$r['compra_actual'],
                        'ventaActual' => (float)$r['venta_actual'],
                        'promCompra7d' => (float)$r['prom_compra_7d'],
                        'promVenta7d' => (float)$r['prom_venta_7d'],
                        'desvCompra' => round($desvCompra, 1),
                        'desvVenta' => round($desvVenta, 1),
                    ],
                    (int)$r['id_agencia']
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * #6 Concentración de efectivo
     */
    public function generarConcentracionEfectivo(string $fecha): int
    {
        $rows = RawConnection::select("
            SELECT a.id_agencia, a.descripcion AS agencia,
                   COALESCE(SUM(sc.saldo * COALESCE(co.valor, 1)), 0) AS total_bob
            FROM agencia a
            INNER JOIN caja c ON c.id_agencia = a.id_agencia AND c.estado = 'A'
            INNER JOIN saldo_caja sc ON sc.id_caja = c.id_caja AND sc.conciliacion <> 'C' AND DATE(sc.fec_crea_saldo) = :f
            LEFT JOIN moneda m ON m.id_moneda = c.id_moneda
            LEFT JOIN cotizacion_oficial co ON co.id_moneda = m.id_moneda AND co.id_operacion = 1
              AND co.fec_crea_cotizacion_oficial = (SELECT MAX(fec_crea_cotizacion_oficial) FROM cotizacion_oficial WHERE id_moneda = m.id_moneda AND id_operacion = 1)
            WHERE a.estado = 'A' AND a.id_agencia > 0
            GROUP BY a.id_agencia, a.descripcion
        ", [':f' => $fecha]);

        $totalEmpresa = array_sum(array_column($rows, 'total_bob'));
        if ($totalEmpresa <= 0) return 0;

        $count = 0;
        foreach ($rows as $r) {
            $pct = ((float)$r['total_bob'] / $totalEmpresa) * 100;
            if ($pct > 40) {
                $this->crear(
                    'concentracion_efectivo', 'riesgo', 'media',
                    "Concentración de efectivo: {$r['agencia']}",
                    "{$r['agencia']} concentra el " . number_format($pct, 1) . "% del efectivo total (BOB " .
                    number_format((float)$r['total_bob'], 2) . " de " . number_format($totalEmpresa, 2) . ").",
                    [
                        'idAgencia' => (int)$r['id_agencia'],
                        'totalAgencia' => round((float)$r['total_bob'], 2),
                        'totalEmpresa' => round($totalEmpresa, 2),
                        'porcentaje' => round($pct, 1),
                    ],
                    (int)$r['id_agencia']
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * #7 Solicitudes de fondeo sin atender
     */
    public function generarFondeoSinAtender(int $horasUmbral = 2): int
    {
        $rows = RawConnection::select("
            SELECT tc.id_transferencia_caja, tc.monto_total, tc.fec_crea_transferencia_caja AS fecha,
                   TIMESTAMPDIFF(HOUR, tc.fec_crea_transferencia_caja, NOW()) AS horas_pendiente,
                   a.id_agencia, a.descripcion AS agencia,
                   c.num_caja, m.abreviatura AS moneda,
                   CONCAT(t.nom_trab, ' ', t.ape_pat_trab) AS cajero
            FROM transferencia_caja tc
            INNER JOIN caja c ON c.id_caja = tc.id_caja
            INNER JOIN boveda b ON b.id_boveda = tc.id_boveda
            INNER JOIN agencia a ON a.id_agencia = c.id_agencia
            INNER JOIN moneda m ON m.id_moneda = b.id_moneda
            LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
            WHERE tc.estado = 'P'
              AND TIMESTAMPDIFF(HOUR, tc.fec_crea_transferencia_caja, NOW()) >= :horas
            ORDER BY horas_pendiente DESC
        ", [':horas' => $horasUmbral]);

        $count = 0;
        foreach ($rows as $r) {
            $horas = (int)$r['horas_pendiente'];
            $urgencia = $horas >= 4 ? 'critica' : 'alta';

            $this->crear(
                'fondeo_sin_atender', 'fondeo', $urgencia,
                "Solicitud sin atender: {$r['agencia']} ({$horas}h)",
                "Caja #{$r['num_caja']} ({$r['cajero']}) solicitó {$r['moneda']} " .
                number_format((float)$r['monto_total'], 2) . " hace {$horas} horas sin respuesta.",
                [
                    'idTransferencia' => (int)$r['id_transferencia_caja'],
                    'idAgencia' => (int)$r['id_agencia'],
                    'cajero' => $r['cajero'],
                    'moneda' => $r['moneda'],
                    'monto' => (float)$r['monto_total'],
                    'horasPendiente' => $horas,
                ],
                (int)$r['id_agencia']
            );
            $count++;
        }
        return $count;
    }

    /**
     * #8 Transferencias de bóveda inusuales
     */
    public function generarTransferenciaInusual(string $fecha): int
    {
        $transferencias = RawConnection::select("
            SELECT tb.id_transferencia_boveda, tb.monto_total,
                   bo.id_agencia AS ag_origen, bd.id_agencia AS ag_destino,
                   ao.descripcion AS agencia_origen, ad.descripcion AS agencia_destino,
                   mo.abreviatura AS moneda
            FROM transferencia_boveda tb
            INNER JOIN boveda bo ON bo.id_boveda = tb.boveda_origen
            INNER JOIN boveda bd ON bd.id_boveda = tb.boveda_destino
            INNER JOIN agencia ao ON ao.id_agencia = bo.id_agencia
            INNER JOIN agencia ad ON ad.id_agencia = bd.id_agencia
            INNER JOIN moneda mo ON mo.id_moneda = bo.id_moneda
            WHERE DATE(tb.fec_crea_transferencia_boveda) = :f
        ", [':f' => $fecha]);

        $count = 0;
        foreach ($transferencias as $t) {
            $stats = RawConnection::selectOne("
                SELECT AVG(tb2.monto_total) AS promedio,
                       STDDEV(tb2.monto_total) AS desv
                FROM transferencia_boveda tb2
                INNER JOIN boveda bo2 ON bo2.id_boveda = tb2.boveda_origen
                INNER JOIN boveda bd2 ON bd2.id_boveda = tb2.boveda_destino
                WHERE bo2.id_agencia = :ao AND bd2.id_agencia = :ad
                  AND tb2.fec_crea_transferencia_boveda >= DATE_SUB(:f, INTERVAL 90 DAY)
                  AND tb2.id_transferencia_boveda <> :id
            ", [':ao' => $t['ag_origen'], ':ad' => $t['ag_destino'], ':f' => $fecha, ':id' => $t['id_transferencia_boveda']]);

            $promedio = (float)($stats['promedio'] ?? 0);
            $desv = (float)($stats['desv'] ?? 0);
            $monto = (float)$t['monto_total'];

            $esInusual = ($promedio > 0 && $desv > 0 && $monto > $promedio + 2 * $desv)
                || ($promedio == 0 && $monto > 50000);

            if ($esInusual) {
                $this->crear(
                    'transferencia_inusual', 'riesgo', 'alta',
                    "Transferencia inusual: {$t['agencia_origen']} → {$t['agencia_destino']}",
                    "{$t['moneda']} " . number_format($monto, 2) . " transferido de {$t['agencia_origen']} a {$t['agencia_destino']}. " .
                    ($promedio > 0 ? "Promedio 90d: " . number_format($promedio, 2) . ", desv: " . number_format($desv, 2) . "." : "Sin historial previo entre estas agencias."),
                    [
                        'idTransferencia' => (int)$t['id_transferencia_boveda'],
                        'moneda' => $t['moneda'],
                        'monto' => $monto,
                        'promedio90d' => $promedio,
                        'desvEstandar' => $desv,
                    ]
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * #9 Cierre tardío
     */
    public function generarCierreTardio(string $fecha): int
    {
        $rows = RawConnection::select("
            SELECT c.id_caja, c.num_caja, a.id_agencia, a.descripcion AS agencia,
                   CONCAT(t.nom_trab, ' ', t.ape_pat_trab) AS cajero
            FROM caja c
            INNER JOIN agencia a ON a.id_agencia = c.id_agencia
            LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
            WHERE c.estado = 'A' AND c.id_tipo_unidad = 2
              AND EXISTS (
                  SELECT 1 FROM saldo_caja sc WHERE sc.id_caja = c.id_caja
                  AND sc.conciliacion = 'A' AND DATE(sc.fec_crea_saldo) = :f
              )
              AND NOT EXISTS (
                  SELECT 1 FROM saldo_caja sc2 WHERE sc2.id_caja = c.id_caja
                  AND sc2.conciliacion = 'C' AND DATE(sc2.fec_crea_saldo) = :f2
              )
        ", [':f' => $fecha, ':f2' => $fecha]);

        if (empty($rows)) return 0;

        $porAgencia = [];
        foreach ($rows as $r) {
            $ag = $r['agencia'];
            if (!isset($porAgencia[$ag])) {
                $porAgencia[$ag] = ['idAgencia' => (int)$r['id_agencia'], 'cajas' => []];
            }
            $porAgencia[$ag]['cajas'][] = "#{$r['num_caja']} ({$r['cajero']})";
        }

        $count = 0;
        foreach ($porAgencia as $agencia => $info) {
            $this->crear(
                'cierre_tardio', 'cumplimiento', 'critica',
                "Cierre tardío: {$agencia}",
                count($info['cajas']) . " caja(s) sin cerrar: " . implode(', ', $info['cajas']),
                ['idAgencia' => $info['idAgencia'], 'cajasSinCerrar' => $info['cajas']],
                $info['idAgencia']
            );
            $count++;
        }
        return $count;
    }

    /**
     * #10 Operación de monto elevado
     */
    public function generarOperacionElevada(string $fecha, array $umbrales = []): int
    {
        $defaults = ['USD' => 5000, 'BOB' => 35000, 'EUR' => 4000, 'BRL' => 20000];
        $umbrales = array_merge($defaults, $umbrales);

        $rows = RawConnection::select("
            SELECT c.id_cambio, c.monto, m.abreviatura AS moneda,
                   a.id_agencia, a.descripcion AS agencia,
                   CONCAT(t.nom_trab, ' ', t.ape_pat_trab) AS cajero,
                   c.fec_crea_cambio AS fecha,
                   CASE c.id_operacion WHEN 1 THEN 'Compra' ELSE 'Venta' END AS operacion
            FROM cambio c
            INNER JOIN moneda m ON m.id_moneda = c.id_moneda
            INNER JOIN agencia a ON a.id_agencia = c.id_agencia
            LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
            WHERE DATE(c.fec_crea_cambio) = :f AND c.estado = 'A'
            ORDER BY c.monto DESC
        ", [':f' => $fecha]);

        $count = 0;
        foreach ($rows as $r) {
            $umbral = $umbrales[$r['moneda']] ?? 50000;
            if ((float)$r['monto'] >= $umbral) {
                $this->crear(
                    'operacion_elevada', 'operacion', 'alta',
                    "{$r['operacion']} elevada: {$r['moneda']} " . number_format((float)$r['monto'], 2),
                    "{$r['cajero']} en {$r['agencia']} registró {$r['operacion']} de {$r['moneda']} " .
                    number_format((float)$r['monto'], 2) . " (umbral: " . number_format($umbral, 2) . ").",
                    [
                        'idCambio' => (int)$r['id_cambio'],
                        'idAgencia' => (int)$r['id_agencia'],
                        'moneda' => $r['moneda'],
                        'monto' => (float)$r['monto'],
                        'umbral' => $umbral,
                        'cajero' => $r['cajero'],
                        'operacion' => $r['operacion'],
                    ],
                    (int)$r['id_agencia']
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * #11 Velocidad de consumo de efectivo
     */
    public function generarConsumoEfectivo(string $fecha): int
    {
        $agencias = RawConnection::select("
            SELECT a.id_agencia, a.descripcion AS agencia FROM agencia a
            WHERE a.estado = 'A' AND a.id_agencia > 0
        ");

        $count = 0;
        foreach ($agencias as $ag) {
            $movimientos = RawConnection::select("
                SELECT c.id_moneda, m.abreviatura AS moneda,
                       SUM(CASE WHEN c.id_operacion = 2 THEN c.monto ELSE 0 END) AS vendido,
                       SUM(CASE WHEN c.id_operacion = 1 THEN c.monto ELSE 0 END) AS comprado,
                       MIN(c.fec_crea_cambio) AS primera_op,
                       MAX(c.fec_crea_cambio) AS ultima_op
                FROM cambio c
                INNER JOIN moneda m ON m.id_moneda = c.id_moneda
                WHERE c.id_agencia = :ag AND DATE(c.fec_crea_cambio) = :f AND c.estado = 'A'
                GROUP BY c.id_moneda, m.abreviatura
                HAVING vendido > comprado
            ", [':ag' => $ag['id_agencia'], ':f' => $fecha]);

            foreach ($movimientos as $mov) {
                $consumoNeto = (float)$mov['vendido'] - (float)$mov['comprado'];
                $saldoActual = (float)(RawConnection::scalar("
                    SELECT COALESCE(SUM(sc.saldo), 0) FROM saldo_caja sc
                    INNER JOIN caja c ON c.id_caja = sc.id_caja
                    WHERE c.id_agencia = :ag AND c.id_moneda = :mon
                      AND sc.conciliacion <> 'C' AND DATE(sc.fec_crea_saldo) = :f
                ", [':ag' => $ag['id_agencia'], ':mon' => $mov['id_moneda'], ':f' => $fecha]) ?? 0);

                if ($saldoActual <= 0 || $consumoNeto <= 0) continue;

                $horasTranscurridas = max(1, (strtotime($mov['ultima_op']) - strtotime($mov['primera_op'])) / 3600);
                $consumoPorHora = $consumoNeto / $horasTranscurridas;
                $horasRestantes = $saldoActual / $consumoPorHora;

                if ($horasRestantes < 3) {
                    $this->crear(
                        'consumo_efectivo', 'fondeo', 'critica',
                        "Efectivo agotándose: {$mov['moneda']} en {$ag['agencia']}",
                        "Al ritmo actual, {$ag['agencia']} se quedará sin {$mov['moneda']} en ~" .
                        number_format($horasRestantes, 1) . "h. Saldo: " . number_format($saldoActual, 2) .
                        ", consumo/h: " . number_format($consumoPorHora, 2) . ".",
                        [
                            'idAgencia' => (int)$ag['id_agencia'],
                            'moneda' => $mov['moneda'],
                            'saldoActual' => $saldoActual,
                            'consumoPorHora' => round($consumoPorHora, 2),
                            'horasRestantes' => round($horasRestantes, 1),
                        ],
                        (int)$ag['id_agencia']
                    );
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * #12 Brecha de cotización entre agencias
     */
    public function generarBrechaCotizacion(): int
    {
        $rows = RawConnection::select("
            SELECT cb.id_moneda, m.abreviatura AS moneda,
                   cb.id_agencia, a.descripcion AS agencia,
                   MAX(CASE WHEN cb.id_operacion = 1 THEN cb.valor_base END) AS precio_compra,
                   MAX(CASE WHEN cb.id_operacion = 2 THEN cb.valor_base END) AS precio_venta
            FROM cotizacion_base cb
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            INNER JOIN agencia a ON a.id_agencia = cb.id_agencia
            INNER JOIN (
                SELECT id_moneda, id_agencia, id_operacion, MAX(fecha_creacion) AS max_fecha
                FROM cotizacion_base WHERE id_agencia <> 99
                GROUP BY id_moneda, id_agencia, id_operacion
            ) ult ON ult.id_moneda = cb.id_moneda AND ult.id_agencia = cb.id_agencia AND ult.id_operacion = cb.id_operacion AND ult.max_fecha = cb.fecha_creacion
            WHERE cb.id_agencia <> 99
            GROUP BY cb.id_moneda, m.abreviatura, cb.id_agencia, a.descripcion
            ORDER BY cb.id_moneda, cb.id_agencia
        ");

        $porMoneda = [];
        foreach ($rows as $r) {
            $porMoneda[$r['moneda']][] = $r;
        }

        $count = 0;
        foreach ($porMoneda as $moneda => $agencias) {
            if (count($agencias) < 2) continue;

            $compras = array_column($agencias, 'precio_compra');
            $ventas = array_column($agencias, 'precio_venta');
            $maxCompra = max($compras);
            $minCompra = min($compras);
            $maxVenta = max($ventas);
            $minVenta = min($ventas);

            $avgCompra = array_sum($compras) / count($compras);
            $avgVenta = array_sum($ventas) / count($ventas);

            if ($avgCompra == 0 || $avgVenta == 0) continue;

            $brechaCompra = (($maxCompra - $minCompra) / $avgCompra) * 100;
            $brechaVenta = (($maxVenta - $minVenta) / $avgVenta) * 100;

            if ($brechaCompra > 2 || $brechaVenta > 2) {
                $agMax = $agencias[array_search($maxCompra, $compras)];
                $agMin = $agencias[array_search($minCompra, $compras)];

                $this->crear(
                    'brecha_cotizacion', 'riesgo', 'media',
                    "Brecha de cotización: {$moneda}",
                    "Diferencia de " . number_format(max($brechaCompra, $brechaVenta), 1) . "% en {$moneda}. " .
                    "Compra más alta: {$agMax['agencia']} ({$maxCompra}), más baja: {$agMin['agencia']} ({$minCompra}). " .
                    "Riesgo de arbitraje interno.",
                    [
                        'moneda' => $moneda,
                        'brechaCompra' => round($brechaCompra, 1),
                        'brechaVenta' => round($brechaVenta, 1),
                        'detalle' => array_map(fn($a) => [
                            'agencia' => $a['agencia'],
                            'compra' => (float)$a['precio_compra'],
                            'venta' => (float)$a['precio_venta'],
                        ], $agencias),
                    ]
                );
                $count++;
            }
        }
        return $count;
    }

    // ── Helpers ──

    private function formatRow(array $r): array
    {
        return [
            'id'          => (int)$r['id_notificacion'],
            'tipo'        => $r['tipo'],
            'urgencia'    => $r['urgencia'],
            'codigo'      => $r['codigo'],
            'titulo'      => $r['titulo'],
            'mensaje'     => $r['mensaje'],
            'datos'       => $r['datos'] ? json_decode($r['datos'], true) : null,
            'idAgencia'   => $r['id_agencia'] ? (int)$r['id_agencia'] : null,
            'agencia'     => $r['agencia_nombre'] ?? null,
            'leida'       => (bool)$r['leida'],
            'fechaCreacion' => $r['fec_creacion'],
        ];
    }
}
