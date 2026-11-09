<?php

namespace App\Modules\GestionOperativa\Services;

use App\Modules\Shared\Database\RawConnection;

class GestionOperativaService
{
    public function consolidado(string $fecha): array
    {
        $agencias   = $this->queryAgencias();
        $monedas    = $this->queryMonedas();
        $tc         = $this->queryTiposCambio();
        $cajas      = $this->querySaldosCaja($fecha);
        $bovedas    = $this->querySaldosBoveda($fecha);
        $bancos     = $this->querySaldosBancos($fecha);
        $limites    = $this->queryLimitesCaja();
        $pendientes = $this->queryTransferenciasPendientes();

        $result = [];
        $grandTotal = ['BOB' => 0];

        foreach ($agencias as $ag) {
            $id = $ag['id_agencia'];
            $row = [
                'idAgencia' => (int)$id,
                'agencia'   => $ag['descripcion'],
                'abreviatura' => $ag['abreviatura'],
                'monedas'   => [],
                'totalBOB'  => 0,
                'alertas'   => [],
                'pendientes' => isset($pendientes[$id]) ? $pendientes[$id] : [],
            ];

            foreach ($monedas as $mon) {
                $mid  = $mon['id_moneda'];
                $mabr = $mon['abreviatura'];
                $key  = $id . '-' . $mid;
                $tcBob = isset($tc[$mabr]) ? $tc[$mabr] : 1;

                $saldoCaja   = isset($cajas[$key]) ? $cajas[$key] : 0;
                $saldoBoveda = isset($bovedas[$key]) ? $bovedas[$key] : 0;
                $saldoBanco  = isset($bancos[$key]) ? $bancos[$key] : 0;
                $totalMoneda = round($saldoCaja + $saldoBoveda + $saldoBanco, 2);
                $totalBOB    = round($totalMoneda * $tcBob, 2);

                $limKey = $id . '-' . $mid;
                $minCaja = isset($limites[$limKey]) ? $limites[$limKey]['minimo'] : null;
                $maxCaja = isset($limites[$limKey]) ? $limites[$limKey]['maximo'] : null;

                $alertasMoneda = [];
                if ($minCaja !== null && $saldoCaja < $minCaja && $saldoCaja != 0) {
                    $alertasMoneda[] = [
                        'tipo'    => 'saldo_bajo',
                        'mensaje' => "Saldo en caja ({$mabr}) por debajo del mínimo",
                        'actual'  => $saldoCaja,
                        'umbral'  => $minCaja,
                    ];
                }
                if ($maxCaja !== null && $saldoCaja > $maxCaja) {
                    $alertasMoneda[] = [
                        'tipo'    => 'saldo_excedido',
                        'mensaje' => "Saldo en caja ({$mabr}) excede el máximo",
                        'actual'  => $saldoCaja,
                        'umbral'  => $maxCaja,
                    ];
                }

                if ($totalMoneda != 0 || !empty($alertasMoneda)) {
                    $row['monedas'][] = [
                        'idMoneda'    => (int)$mid,
                        'moneda'      => $mabr,
                        'caja'        => $saldoCaja,
                        'boveda'      => $saldoBoveda,
                        'banco'       => $saldoBanco,
                        'total'       => $totalMoneda,
                        'tcBob'       => $tcBob,
                        'totalBOB'    => $totalBOB,
                        'minimoCaja'  => $minCaja,
                        'maximoCaja'  => $maxCaja,
                        'alertas'     => $alertasMoneda,
                    ];
                    $row['totalBOB'] += $totalBOB;
                    $row['alertas'] = array_merge($row['alertas'], $alertasMoneda);
                }

                if ($mabr !== 'BOB') {
                    if (!isset($grandTotal[$mabr])) $grandTotal[$mabr] = 0;
                    $grandTotal[$mabr] += $totalMoneda;
                }
                $grandTotal['BOB'] += $totalBOB;
            }

            $row['totalBOB'] = round($row['totalBOB'], 2);
            if (!empty($row['monedas'])) {
                $result[] = $row;
            }
        }

        $grandTotal['BOB'] = round($grandTotal['BOB'], 2);
        $filteredTotals = ['BOB' => $grandTotal['BOB']];
        $activeMonedas = [];
        foreach ($grandTotal as $k => $v) {
            if ($k === 'BOB') continue;
            $v = round($v, 2);
            if ($v != 0) {
                $filteredTotals[$k] = $v;
                $activeMonedas[] = $k;
            }
        }

        return [
            'fecha'      => $fecha,
            'agencias'   => $result,
            'totales'    => $filteredTotals,
            'monedas'    => $activeMonedas,
            'tc'         => $tc,
        ];
    }

    public function alertas(string $fecha): array
    {
        $consolidado = $this->consolidado($fecha);
        $alertas = [];

        foreach ($consolidado['agencias'] as $ag) {
            if (!empty($ag['alertas'])) {
                $alertas[] = [
                    'idAgencia'  => $ag['idAgencia'],
                    'agencia'    => $ag['agencia'],
                    'alertas'    => $ag['alertas'],
                    'totalBOB'   => $ag['totalBOB'],
                ];
            }
        }

        return $alertas;
    }

    public function recomendacionesFondeo(string $fecha): array
    {
        $consolidado = $this->consolidado($fecha);
        $necesitan   = [];
        $puedenDar   = [];

        foreach ($consolidado['agencias'] as $ag) {
            foreach ($ag['monedas'] as $mon) {
                if (!empty($mon['alertas'])) {
                    foreach ($mon['alertas'] as $alerta) {
                        if ($alerta['tipo'] === 'saldo_bajo') {
                            $deficit = $mon['minimoCaja'] - $mon['caja'];
                            $necesitan[] = [
                                'idAgencia'  => $ag['idAgencia'],
                                'agencia'    => $ag['agencia'],
                                'moneda'     => $mon['moneda'],
                                'saldoCaja'  => $mon['caja'],
                                'minimo'     => $mon['minimoCaja'],
                                'deficit'    => round($deficit, 2),
                                'saldoBoveda' => $mon['boveda'],
                            ];
                        }
                    }
                }

                $excedente = 0;
                if ($mon['maximoCaja'] !== null && $mon['caja'] > $mon['maximoCaja']) {
                    $excedente = $mon['caja'] - $mon['maximoCaja'];
                }
                $disponibleBoveda = $mon['boveda'];

                if ($excedente > 0 || $disponibleBoveda > 0) {
                    $puedenDar[] = [
                        'idAgencia'       => $ag['idAgencia'],
                        'agencia'         => $ag['agencia'],
                        'moneda'          => $mon['moneda'],
                        'excedenteCaja'   => round($excedente, 2),
                        'disponibleBoveda' => round($disponibleBoveda, 2),
                        'totalDisponible' => round($excedente + $disponibleBoveda, 2),
                    ];
                }
            }
        }

        $recomendaciones = [];
        foreach ($necesitan as $nec) {
            $opciones = [];
            foreach ($puedenDar as $pd) {
                if ($pd['moneda'] === $nec['moneda'] && $pd['idAgencia'] !== $nec['idAgencia'] && $pd['totalDisponible'] > 0) {
                    $opciones[] = $pd;
                }
            }
            usort($opciones, function ($a, $b) {
                return $b['totalDisponible'] <=> $a['totalDisponible'];
            });
            $recomendaciones[] = [
                'destino'  => [
                    'idAgencia' => $nec['idAgencia'],
                    'agencia'   => $nec['agencia'],
                    'moneda'    => $nec['moneda'],
                    'deficit'   => $nec['deficit'],
                    'saldoCaja' => $nec['saldoCaja'],
                ],
                'origenesPosibles' => array_slice($opciones, 0, 5),
            ];
        }

        return $recomendaciones;
    }

    public function fondeosPorRemesas(string $fecha): array
    {
        // Consultar remesas pendientes directo en Chile
        $remesas = RawConnection::select("
            SELECT id_destino, moneda,
                   COUNT(codigo) AS cant_remesas,
                   ROUND(SUM(envio), 2) AS total_remesas
            FROM tmp_reporte_remesa
            WHERE estado = 0
            GROUP BY id_destino, moneda
        ", [], 'chile');

        if (empty($remesas)) return [];

        $agencias = RawConnection::select(
            "SELECT id_agencia, descripcion FROM agencia WHERE estado = 'A' AND id_agencia > 0"
        );
        $agMap = [];
        foreach ($agencias as $ag) $agMap[$ag['id_agencia']] = $ag['descripcion'];

        // Redireccionamiento configurable: remesas a una agencia las atiende otra
        // Formato .env: FONDEO_REDIRECCION_AGENCIAS=origen:destino,origen2:destino2
        $redir = [];
        $redirEnv = env('FONDEO_REDIRECCION_AGENCIAS', '');
        if ($redirEnv) {
            foreach (explode(',', $redirEnv) as $par) {
                [$from, $to] = explode(':', trim($par));
                $redir[(int)$from] = (int)$to;
            }
        }

        $rows = [];
        foreach ($remesas as $r) {
            $idDest = (int)$r['id_destino'];
            $idResponsable = $redir[$idDest] ?? $idDest;
            if (!isset($agMap[$idResponsable])) continue;
            $rows[] = [
                'id_agencia'        => $idResponsable,
                'agencia'           => $agMap[$idResponsable],
                'agencia_destino'   => $agMap[$idDest] ?? "Agencia #$idDest",
                'id_agencia_destino'=> $idDest,
                'moneda'            => $r['moneda'],
                'cant_remesas'      => (int)$r['cant_remesas'],
                'total_remesas'     => (float)$r['total_remesas'],
                'redirigida'        => $idResponsable !== $idDest,
            ];
        }

        if (empty($rows)) return [];

        // Forma de pago predominante por agencia (1=efectivo, 2=transferencia)
        $fpRows = RawConnection::select("
            SELECT id_agencia, id_forma_pago, COUNT(*) AS cnt
            FROM pago
            WHERE id_operacion IN (4, 7, 8)
              AND fec_crea_pago >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY id_agencia, id_forma_pago
        ");
        $formaPagoPorAg = [];
        foreach ($fpRows as $fp) {
            $idAg = (int)$fp['id_agencia'];
            $forma = (int)$fp['id_forma_pago'];
            $cnt = (int)$fp['cnt'];
            if (!isset($formaPagoPorAg[$idAg]) || $cnt > $formaPagoPorAg[$idAg]['cnt']) {
                $formaPagoPorAg[$idAg] = ['forma' => $forma, 'cnt' => $cnt];
            }
        }

        $consolidado = $this->consolidado($fecha);

        // Saldos por agencia-moneda
        $saldosBanco = [];
        $saldosBoveda = [];
        $saldosCaja = [];
        $minCaja = [];
        $disponibles = [];
        foreach ($consolidado['agencias'] as $ag) {
            foreach ($ag['monedas'] as $mon) {
                $key = $ag['idAgencia'] . '-' . $mon['moneda'];
                $saldosBanco[$key]  = $mon['banco'];
                $saldosBoveda[$key] = $mon['boveda'];
                $saldosCaja[$key]   = $mon['caja'];
                $minCaja[$key]      = $mon['minimoCaja'] ?? 0;
                $disp = $mon['banco'] + $mon['boveda'] + max(0, $mon['caja'] - ($mon['minimoCaja'] ?? 0));
                if ($disp > 0) {
                    $disponibles[] = [
                        'idAgencia'   => $ag['idAgencia'],
                        'agencia'     => $ag['agencia'],
                        'moneda'      => $mon['moneda'],
                        'disponible'  => round($disp, 2),
                        'enBanco'     => $mon['banco'],
                        'enBoveda'    => $mon['boveda'],
                        'enCaja'      => $mon['caja'],
                    ];
                }
            }
        }

        $result = [];
        foreach ($rows as $r) {
            $idAg = (int)$r['id_agencia'];
            $moneda = $r['moneda'];
            $totalRemesas = (float)$r['total_remesas'];
            $key = $idAg . '-' . $moneda;
            $banco  = $saldosBanco[$key]  ?? 0;
            $boveda = $saldosBoveda[$key] ?? 0;
            $caja   = $saldosCaja[$key]   ?? 0;

            // Determinar forma de pago: 2=transferencia (necesita banco), 1=efectivo (necesita bóveda/caja)
            $formaPago = ($formaPagoPorAg[$idAg]['forma'] ?? 1) == 2 ? 'transferencia' : 'efectivo';

            if ($formaPago === 'transferencia') {
                // Pago por banco: verificar saldo bancario
                if ($totalRemesas <= $banco) continue;
                $deficitCanal = round($totalRemesas - $banco, 2);
                // Fondos propios que se pueden depositar al banco
                $fondosPropios = round($boveda + max(0, $caja - ($minCaja[$key] ?? 0)), 2);
            } else {
                // Pago en efectivo: verificar bóveda + caja
                $efectivoDisp = $boveda + $caja;
                if ($totalRemesas <= $efectivoDisp) continue;
                $deficitCanal = round($totalRemesas - $efectivoDisp, 2);
                // Fondos propios: banco puede transferirse a la agencia
                $fondosPropios = round(max(0, $banco), 2);
            }

            $deficitReal = round(max(0, $deficitCanal - $fondosPropios), 2);

            $fuentes = [];
            if ($deficitReal > 0) {
                foreach ($disponibles as $d) {
                    if ($d['moneda'] === $moneda && $d['idAgencia'] !== $idAg && $d['disponible'] > 0) {
                        $fuentes[] = $d;
                    }
                }
                usort($fuentes, fn($a, $b) => $b['disponible'] <=> $a['disponible']);
            }

            $result[] = [
                'destino' => [
                    'idAgencia'       => $idAg,
                    'agencia'         => $r['agencia'],
                    'agenciaDestino'  => $r['agencia_destino'],
                    'idAgenciaDestino'=> $r['id_agencia_destino'],
                    'redirigida'      => $r['redirigida'],
                    'formaPago'       => $formaPago,
                    'moneda'          => $moneda,
                    'cantRemesas'     => (int)$r['cant_remesas'],
                    'totalRemesas'    => $totalRemesas,
                    'saldoBanco'      => $banco,
                    'saldoBoveda'     => $boveda,
                    'saldoCaja'       => $caja,
                    'deficitCanal'    => $deficitCanal,
                    'fondosPropios'   => $fondosPropios,
                    'deficit'         => $deficitReal,
                    'autoFondeable'   => $deficitReal <= 0,
                ],
                'origenesPosibles' => array_slice($fuentes, 0, 5),
            ];
        }

        return $result;
    }

    public function transferencias(string $fecha, ?int $idAgencia = null): array
    {
        $conditions = ["DATE(tb.fec_crea_transferencia_boveda) = :fecha"];
        $params = [':fecha' => $fecha];

        if ($idAgencia !== null) {
            $conditions[] = "(bo.id_agencia = :ag OR bd.id_agencia = :ag2)";
            $params[':ag'] = $idAgencia;
            $params[':ag2'] = $idAgencia;
        }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT tb.id_transferencia_boveda, tb.id_tipo_transferencia,
                       tb.boveda_origen, tb.boveda_destino, tb.monto_total,
                       tb.fec_crea_transferencia_boveda AS fecha, tb.estado,
                       ao.descripcion AS agencia_origen, ad.descripcion AS agencia_destino,
                       mo.abreviatura AS moneda
                FROM transferencia_boveda tb
                INNER JOIN boveda bo ON bo.id_boveda = tb.boveda_origen
                INNER JOIN boveda bd ON bd.id_boveda = tb.boveda_destino
                INNER JOIN agencia ao ON ao.id_agencia = bo.id_agencia
                INNER JOIN agencia ad ON ad.id_agencia = bd.id_agencia
                INNER JOIN moneda mo ON mo.id_moneda = bo.id_moneda
                WHERE {$where}
                ORDER BY tb.fec_crea_transferencia_boveda DESC";

        $rows = RawConnection::select($sql, $params);

        return array_map(function ($r) {
            $estado = $r['estado'];
            return [
                'id'             => (int)$r['id_transferencia_boveda'],
                'agenciaOrigen'  => $r['agencia_origen'],
                'agenciaDestino' => $r['agencia_destino'],
                'moneda'         => $r['moneda'],
                'monto'          => (float)$r['monto_total'],
                'fecha'          => $r['fecha'],
                'estado'         => $estado,
                'estadoDesc'     => $estado === 'A' ? 'Aceptada' : ($estado === 'P' ? 'Pendiente' : 'Rechazada'),
            ];
        }, $rows);
    }

    public function solicitudesPendientes(): array
    {
        $sql = "SELECT tc.id_transferencia_caja, tc.monto_total,
                       tc.fec_crea_transferencia_caja AS fecha, tc.estado,
                       c.num_caja, a.descripcion AS agencia, a.id_agencia,
                       m.abreviatura AS moneda,
                       CONCAT(t.nom_trab, ' ', t.ape_pat_trab) AS cajero
                FROM transferencia_caja tc
                INNER JOIN caja c ON c.id_caja = tc.id_caja
                INNER JOIN boveda b ON b.id_boveda = tc.id_boveda
                INNER JOIN agencia a ON a.id_agencia = c.id_agencia
                INNER JOIN moneda m ON m.id_moneda = b.id_moneda
                LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
                LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
                WHERE tc.estado = 'P'
                ORDER BY tc.fec_crea_transferencia_caja ASC";

        $rows = RawConnection::select($sql);

        return array_map(function ($r) {
            return [
                'id'        => (int)$r['id_transferencia_caja'],
                'idAgencia' => (int)$r['id_agencia'],
                'agencia'   => $r['agencia'],
                'numCaja'   => $r['num_caja'],
                'cajero'    => $r['cajero'],
                'moneda'    => $r['moneda'],
                'monto'     => (float)$r['monto_total'],
                'fecha'     => $r['fecha'],
            ];
        }, $rows);
    }

    public function detalleAgencia(string $fecha, int $idAgencia): array
    {
        $agencia = RawConnection::selectOne(
            "SELECT id_agencia, descripcion, abreviatura FROM agencia WHERE id_agencia = :id",
            [':id' => $idAgencia]
        );
        if (!$agencia) return [];

        $tc = $this->queryTiposCambio();
        $desde = $fecha . ' 00:00:00';
        $hasta = $fecha . ' 23:59:59';

        $cajas = RawConnection::select("
            SELECT c.id_caja, c.num_caja, m.abreviatura AS moneda,
                   CONCAT(t.nom_trab, ' ', t.ape_pat_trab) AS cajero,
                   ROUND(SUM(sc.saldo), 2) AS saldo,
                   c.minimo, c.maximo
            FROM caja c
            INNER JOIN saldo_caja sc ON sc.id_caja = c.id_caja
            INNER JOIN moneda m ON m.id_moneda = c.id_moneda
            LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
            WHERE c.id_agencia = :ag
              AND sc.conciliacion <> 'C'
              AND sc.fec_crea_saldo BETWEEN :desde AND :hasta
              AND c.estado = 'A'
              AND c.id_tipo_unidad = 2
              AND NOT EXISTS (
                  SELECT 1 FROM saldo_caja sc2
                  WHERE sc2.id_caja = c.id_caja
                    AND sc2.conciliacion = 'C'
                    AND sc2.fec_crea_saldo BETWEEN :desde2 AND :hasta2
              )
            GROUP BY c.id_caja, c.num_caja, m.abreviatura, cajero, c.minimo, c.maximo
            ORDER BY c.num_caja, m.abreviatura
        ", [':ag' => $idAgencia, ':desde' => $desde, ':hasta' => $hasta, ':desde2' => $desde, ':hasta2' => $hasta]);

        $bovedas = RawConnection::select("
            SELECT b.id_boveda, m.abreviatura AS moneda,
                   ROUND(SUM(sb.saldo), 2) AS saldo
            FROM boveda b
            INNER JOIN saldo_boveda sb ON sb.id_boveda = b.id_boveda
            INNER JOIN moneda m ON m.id_moneda = b.id_moneda
            WHERE b.id_agencia = :ag
              AND sb.conciliacion <> 'C'
              AND sb.fec_crea_saldo BETWEEN :desde AND :hasta
              AND b.estado = 'A'
            GROUP BY b.id_boveda, m.abreviatura
            ORDER BY m.abreviatura
        ", [':ag' => $idAgencia, ':desde' => $desde, ':hasta' => $hasta]);

        // Fallback bóvedas: último día con actividad
        if (empty($bovedas)) {
            $bovedas = RawConnection::select("
                SELECT b.id_boveda, m.abreviatura AS moneda,
                       ROUND(SUM(sb.saldo), 2) AS saldo
                FROM boveda b
                INNER JOIN saldo_boveda sb ON sb.id_boveda = b.id_boveda
                INNER JOIN moneda m ON m.id_moneda = b.id_moneda
                INNER JOIN (
                    SELECT sb2.id_boveda, MAX(DATE(sb2.fec_crea_saldo)) AS max_dia
                    FROM saldo_boveda sb2
                    INNER JOIN boveda b2 ON b2.id_boveda = sb2.id_boveda
                    WHERE b2.id_agencia = :ag2
                      AND sb2.fec_crea_saldo < :desde2
                    GROUP BY sb2.id_boveda
                ) ult ON ult.id_boveda = sb.id_boveda
                     AND DATE(sb.fec_crea_saldo) = ult.max_dia
                WHERE b.id_agencia = :ag
                  AND sb.conciliacion <> 'C'
                  AND b.estado = 'A'
                GROUP BY b.id_boveda, m.abreviatura
                ORDER BY m.abreviatura
            ", [':ag' => $idAgencia, ':ag2' => $idAgencia, ':desde2' => $desde]);
        }

        $bancos = RawConnection::select("
            SELECT cb.num_cuenta_bancaria, ef.descripcion AS banco,
                   m.abreviatura AS moneda,
                   ROUND(SUM(
                       CASE
                           WHEN mcb.conciliacion = 'C'
                                AND mcb.fec_crea_movimiento_cuenta_bancaria = ult.max_cierre
                                THEN mcb.monto
                           WHEN mcb.conciliacion NOT IN ('A', 'C')
                                AND mcb.fec_crea_movimiento_cuenta_bancaria > ult.max_cierre
                                AND mcb.fec_crea_movimiento_cuenta_bancaria <= :hasta2
                                THEN mcb.monto
                           ELSE 0
                       END
                   ), 2) AS saldo
            FROM cuenta_bancaria cb
            INNER JOIN movimiento_cuenta_bancaria mcb
                ON mcb.id_agencia = cb.id_agencia
               AND mcb.id_entidad_financiera = cb.id_entidad_financiera
               AND mcb.num_cuenta_bancaria = cb.num_cuenta_bancaria
               AND mcb.id_moneda = cb.id_moneda
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = cb.id_entidad_financiera
            INNER JOIN (
                SELECT id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda,
                       MAX(fec_crea_movimiento_cuenta_bancaria) AS max_cierre
                FROM movimiento_cuenta_bancaria
                WHERE conciliacion = 'C'
                  AND fec_crea_movimiento_cuenta_bancaria <= :hasta
                  AND id_agencia = :ag2
                GROUP BY id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda
            ) ult ON ult.id_agencia = mcb.id_agencia
                 AND ult.id_entidad_financiera = mcb.id_entidad_financiera
                 AND ult.num_cuenta_bancaria = mcb.num_cuenta_bancaria
                 AND ult.id_moneda = mcb.id_moneda
            WHERE cb.id_agencia = :ag
              AND cb.estado = 'A'
            GROUP BY cb.num_cuenta_bancaria, ef.descripcion, m.abreviatura
            ORDER BY ef.descripcion
        ", [':ag' => $idAgencia, ':ag2' => $idAgencia, ':hasta' => $hasta, ':hasta2' => $hasta]);

        $cajaResult = [];
        foreach ($cajas as $c) {
            $num = $c['num_caja'];
            if (!isset($cajaResult[$num])) {
                $cajaResult[$num] = [
                    'numCaja' => $num,
                    'cajero'  => $c['cajero'],
                    'monedas' => [],
                    'totalBOB' => 0,
                ];
            }
            $saldo = (float)$c['saldo'];
            if ($saldo == 0) continue;
            $tcVal = isset($tc[$c['moneda']]) ? $tc[$c['moneda']] : 1;
            $bob = round($saldo * $tcVal, 2);
            $cajaResult[$num]['monedas'][] = [
                'moneda' => $c['moneda'],
                'saldo'  => (float)$c['saldo'],
                'totalBOB' => $bob,
                'minimo' => $c['minimo'] !== null ? (float)$c['minimo'] : null,
                'maximo' => $c['maximo'] !== null ? (float)$c['maximo'] : null,
            ];
            $cajaResult[$num]['totalBOB'] += $bob;
        }
        foreach ($cajaResult as $k => &$cr) {
            $cr['totalBOB'] = round($cr['totalBOB'], 2);
            if (empty($cr['monedas'])) unset($cajaResult[$k]);
        }

        $bovedaResult = [];
        foreach ($bovedas as $b) {
            if ((float)$b['saldo'] == 0) continue;
            $tcVal = isset($tc[$b['moneda']]) ? $tc[$b['moneda']] : 1;
            $bovedaResult[] = [
                'moneda'   => $b['moneda'],
                'saldo'    => (float)$b['saldo'],
                'totalBOB' => round((float)$b['saldo'] * $tcVal, 2),
            ];
        }

        $bancoResult = [];
        foreach ($bancos as $b) {
            if ((float)$b['saldo'] == 0) continue;
            $tcVal = isset($tc[$b['moneda']]) ? $tc[$b['moneda']] : 1;
            $bancoResult[] = [
                'cuenta'   => $b['num_cuenta_bancaria'],
                'banco'    => $b['banco'],
                'moneda'   => $b['moneda'],
                'saldo'    => (float)$b['saldo'],
                'totalBOB' => round((float)$b['saldo'] * $tcVal, 2),
            ];
        }

        return [
            'idAgencia'   => (int)$idAgencia,
            'agencia'     => $agencia['descripcion'],
            'abreviatura' => $agencia['abreviatura'],
            'cajas'       => array_values($cajaResult),
            'bovedas'     => $bovedaResult,
            'bancos'      => $bancoResult,
        ];
    }

    public function resumenEjecutivo(string $fecha): array
    {
        $tc = $this->queryTiposCambio();
        $monedas = $this->queryMonedas();
        $agencias = $this->queryAgencias();
        $cajas = $this->querySaldosCaja($fecha);
        $bovedas = $this->querySaldosBoveda($fecha);
        $bancos = $this->querySaldosBancos($fecha);

        $monedaMap = [];
        foreach ($monedas as $m) $monedaMap[$m['id_moneda']] = $m['abreviatura'];

        $totalEfectivo = 0;
        $totalBoveda = 0;
        $totalBanco = 0;
        foreach ($cajas as $key => $saldo) {
            $mid = explode('-', $key)[1];
            $tcVal = $tc[$monedaMap[$mid] ?? 'BOB'] ?? 1;
            $totalEfectivo += $saldo * $tcVal;
        }
        foreach ($bovedas as $key => $saldo) {
            $mid = explode('-', $key)[1];
            $tcVal = $tc[$monedaMap[$mid] ?? 'BOB'] ?? 1;
            $totalBoveda += $saldo * $tcVal;
        }
        foreach ($bancos as $key => $saldo) {
            $mid = explode('-', $key)[1];
            $tcVal = $tc[$monedaMap[$mid] ?? 'BOB'] ?? 1;
            $totalBanco += $saldo * $tcVal;
        }

        $actividad = $this->queryActividadAgencias($fecha);
        $cotizaciones = $this->queryCotizacionesActuales();

        $ayer = date('Y-m-d', strtotime($fecha . ' -1 day'));
        $cajasAyer = $this->querySaldosCaja($ayer);
        $bovedasAyer = $this->querySaldosBoveda($ayer);
        $bancosAyer = $this->querySaldosBancos($ayer);

        $comparativa = [];
        $totalHoy = 0;
        $totalAyer = 0;
        foreach ($agencias as $ag) {
            $id = $ag['id_agencia'];
            $hoy = 0;
            $ayerAg = 0;
            foreach ($monedas as $m) {
                $key = $id . '-' . $m['id_moneda'];
                $abr = $m['abreviatura'];
                $tcVal = $tc[$abr] ?? 1;
                $hoy += (($cajas[$key] ?? 0) + ($bovedas[$key] ?? 0) + ($bancos[$key] ?? 0)) * $tcVal;
                $ayerAg += (($cajasAyer[$key] ?? 0) + ($bovedasAyer[$key] ?? 0) + ($bancosAyer[$key] ?? 0)) * $tcVal;
            }
            if ($hoy == 0 && $ayerAg == 0) continue;
            $delta = round($hoy - $ayerAg, 2);
            $comparativa[] = [
                'idAgencia' => (int)$id,
                'agencia'   => $ag['descripcion'],
                'hoy'       => round($hoy, 2),
                'ayer'      => round($ayerAg, 2),
                'delta'     => $delta,
                'deltaPct'  => $ayerAg != 0 ? round(($delta / abs($ayerAg)) * 100, 1) : 0,
            ];
            $totalHoy += $hoy;
            $totalAyer += $ayerAg;
        }

        $rendimiento = $this->queryRendimientoCajeros($fecha);

        return [
            'totalesPorCategoria' => [
                'efectivo' => round($totalEfectivo, 2),
                'boveda'   => round($totalBoveda, 2),
                'banco'    => round($totalBanco, 2),
                'total'    => round($totalEfectivo + $totalBoveda + $totalBanco, 2),
            ],
            'actividadAgencias' => $actividad,
            'cotizaciones'      => $cotizaciones,
            'comparativa' => [
                'totalHoy'    => round($totalHoy, 2),
                'totalAyer'   => round($totalAyer, 2),
                'delta'       => round($totalHoy - $totalAyer, 2),
                'deltaPct'    => $totalAyer != 0 ? round((($totalHoy - $totalAyer) / abs($totalAyer)) * 100, 1) : 0,
                'porAgencia'  => $comparativa,
            ],
            'rendimientoCajeros' => $rendimiento,
        ];
    }

    // ── Queries privadas ──

    private function queryAgencias(): array
    {
        return RawConnection::select(
            "SELECT id_agencia, descripcion, abreviatura FROM agencia WHERE estado = 'A' AND id_agencia > 0 ORDER BY descripcion"
        );
    }

    private function queryMonedas(): array
    {
        return RawConnection::select(
            "SELECT id_moneda, abreviatura, descripcion FROM moneda WHERE estado = 'A' ORDER BY id_moneda"
        );
    }

    private function queryTiposCambio(): array
    {
        $rows = RawConnection::select("
            SELECT m.abreviatura, co.valor AS tc_bob
            FROM cotizacion_oficial co
            INNER JOIN (
                SELECT id_moneda, MAX(fec_crea_cotizacion_oficial) AS max_fecha
                FROM cotizacion_oficial WHERE id_operacion = 1
                GROUP BY id_moneda
            ) ult ON ult.id_moneda = co.id_moneda AND ult.max_fecha = co.fec_crea_cotizacion_oficial
            JOIN moneda m ON m.id_moneda = co.id_moneda
            WHERE co.id_operacion = 1
        ");
        $tc = ['BOB' => 1];
        foreach ($rows as $r) $tc[$r['abreviatura']] = (float)$r['tc_bob'];
        return $tc;
    }

    private function querySaldosCaja(string $fecha): array
    {
        $desde = $fecha . ' 00:00:00';
        $hasta = $fecha . ' 23:59:59';

        $rows = RawConnection::select("
            SELECT c.id_agencia, c.id_moneda,
                   ROUND(SUM(sc.saldo), 2) AS saldo
            FROM caja c
            INNER JOIN saldo_caja sc ON sc.id_caja = c.id_caja
            WHERE sc.conciliacion <> 'C'
              AND sc.fec_crea_saldo BETWEEN :desde AND :hasta
              AND c.estado = 'A'
              AND c.id_tipo_unidad = 2
              AND NOT EXISTS (
                  SELECT 1 FROM saldo_caja sc2
                  WHERE sc2.id_caja = c.id_caja
                    AND sc2.conciliacion = 'C'
                    AND sc2.fec_crea_saldo BETWEEN :desde2 AND :hasta2
              )
            GROUP BY c.id_agencia, c.id_moneda
        ", [':desde' => $desde, ':hasta' => $hasta, ':desde2' => $desde, ':hasta2' => $hasta]);

        $map = [];
        foreach ($rows as $r) {
            $map[$r['id_agencia'] . '-' . $r['id_moneda']] = (float)$r['saldo'];
        }

        return $map;
    }

    private function querySaldosBoveda(string $fecha): array
    {
        $desde = $fecha . ' 00:00:00';
        $hasta = $fecha . ' 23:59:59';

        $rows = RawConnection::select("
            SELECT b.id_agencia, b.id_moneda,
                   ROUND(SUM(sb.saldo), 2) AS saldo
            FROM boveda b
            INNER JOIN saldo_boveda sb ON sb.id_boveda = b.id_boveda
            WHERE sb.conciliacion <> 'C'
              AND sb.fec_crea_saldo BETWEEN :desde AND :hasta
              AND b.estado = 'A'
              AND b.id_agencia > 1
            GROUP BY b.id_agencia, b.id_moneda
        ", [':desde' => $desde, ':hasta' => $hasta]);

        $map = [];
        foreach ($rows as $r) {
            $map[$r['id_agencia'] . '-' . $r['id_moneda']] = (float)$r['saldo'];
        }

        // Sin actividad hoy: último día con registros (no solo cierre)
        $fallback = RawConnection::select("
            SELECT b.id_agencia, b.id_moneda,
                   ROUND(SUM(sb.saldo), 2) AS saldo
            FROM boveda b
            INNER JOIN saldo_boveda sb ON sb.id_boveda = b.id_boveda
            INNER JOIN (
                SELECT id_boveda, MAX(DATE(fec_crea_saldo)) AS max_dia
                FROM saldo_boveda
                WHERE fec_crea_saldo < :desde
                GROUP BY id_boveda
            ) ult ON ult.id_boveda = sb.id_boveda
                 AND DATE(sb.fec_crea_saldo) = ult.max_dia
            WHERE sb.conciliacion <> 'C'
              AND b.estado = 'A'
              AND b.id_agencia > 1
            GROUP BY b.id_agencia, b.id_moneda
        ", [':desde' => $desde]);

        foreach ($fallback as $r) {
            $key = $r['id_agencia'] . '-' . $r['id_moneda'];
            if (!isset($map[$key])) {
                $map[$key] = (float)$r['saldo'];
            }
        }

        return $map;
    }

    private function querySaldosBancos(string $fecha): array
    {
        $ejercicio = substr($fecha, 0, 4);

        // Last cierre per account
        $cierres = RawConnection::select("
            SELECT m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda,
                   m.monto
            FROM movimiento_cuenta_bancaria m
            INNER JOIN (
                SELECT id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda,
                       MAX(fec_crea_movimiento_cuenta_bancaria) AS max_fecha
                FROM movimiento_cuenta_bancaria
                WHERE conciliacion = 'C'
                  AND YEAR(fec_crea_movimiento_cuenta_bancaria) = :ej
                GROUP BY id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda
            ) sub ON m.id_agencia = sub.id_agencia
                 AND m.id_entidad_financiera = sub.id_entidad_financiera
                 AND m.num_cuenta_bancaria = sub.num_cuenta_bancaria
                 AND m.id_moneda = sub.id_moneda
                 AND m.fec_crea_movimiento_cuenta_bancaria = sub.max_fecha
            WHERE m.conciliacion = 'C'
        ", [':ej' => $ejercicio]);

        $saldosCierre = [];
        foreach ($cierres as $r) {
            $key = $r['id_agencia'] . '-' . $r['id_entidad_financiera'] . '-' . $r['num_cuenta_bancaria'] . '-' . $r['id_moneda'];
            $saldosCierre[$key] = (float) $r['monto'];
        }

        // Accounts with open apertura (no cierre after last apertura)
        $operando = RawConnection::select("
            SELECT m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda,
                   SUM(CASE WHEN m.conciliacion != 'C' THEN m.monto ELSE 0 END) AS saldo_actual
            FROM movimiento_cuenta_bancaria m
            INNER JOIN (
                SELECT id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda,
                       MAX(fec_crea_movimiento_cuenta_bancaria) AS ultima_apertura
                FROM movimiento_cuenta_bancaria
                WHERE conciliacion = 'A'
                  AND YEAR(fec_crea_movimiento_cuenta_bancaria) = :ej1
                GROUP BY id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda
            ) aper ON m.id_agencia = aper.id_agencia
                  AND m.id_entidad_financiera = aper.id_entidad_financiera
                  AND m.num_cuenta_bancaria = aper.num_cuenta_bancaria
                  AND m.id_moneda = aper.id_moneda
                  AND m.fec_crea_movimiento_cuenta_bancaria >= aper.ultima_apertura
            WHERE YEAR(m.fec_crea_movimiento_cuenta_bancaria) = :ej2
            GROUP BY m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda
            HAVING MAX(CASE WHEN m.conciliacion = 'C' THEN 1 ELSE 0 END) = 0
        ", [':ej1' => $ejercicio, ':ej2' => $ejercicio]);

        $saldosOperando = [];
        foreach ($operando as $r) {
            $key = $r['id_agencia'] . '-' . $r['id_entidad_financiera'] . '-' . $r['num_cuenta_bancaria'] . '-' . $r['id_moneda'];
            $saldosOperando[$key] = (float) $r['saldo_actual'];
        }

        // Aggregate per agencia+moneda: use saldoActual if operando, else last cierre
        $map = [];
        $allKeys = array_unique(array_merge(array_keys($saldosCierre), array_keys($saldosOperando)));
        foreach ($allKeys as $fullKey) {
            $parts = explode('-', $fullKey);
            $agKey = $parts[0] . '-' . $parts[3]; // id_agencia-id_moneda
            $saldo = isset($saldosOperando[$fullKey]) ? $saldosOperando[$fullKey] : ($saldosCierre[$fullKey] ?? 0);
            $map[$agKey] = ($map[$agKey] ?? 0) + $saldo;
        }

        foreach ($map as &$v) {
            $v = round($v, 2);
        }

        return $map;
    }

    private function queryLimitesCaja(): array
    {
        $rows = RawConnection::select("
            SELECT c.id_agencia, c.id_moneda,
                   SUM(c.minimo) AS minimo,
                   SUM(c.maximo) AS maximo
            FROM caja c
            WHERE c.estado = 'A'
              AND c.id_tipo_unidad = 2
            GROUP BY c.id_agencia, c.id_moneda
        ");

        $map = [];
        foreach ($rows as $r) {
            $map[$r['id_agencia'] . '-' . $r['id_moneda']] = [
                'minimo' => (float)$r['minimo'],
                'maximo' => (float)$r['maximo'],
            ];
        }
        return $map;
    }

    private function queryTransferenciasPendientes(): array
    {
        $rows = RawConnection::select("
            SELECT c.id_agencia, tc.id_transferencia_caja,
                   tc.monto_total, tc.fec_crea_transferencia_caja AS fecha,
                   m.abreviatura AS moneda, c.num_caja
            FROM transferencia_caja tc
            INNER JOIN caja c ON c.id_caja = tc.id_caja
            INNER JOIN boveda b ON b.id_boveda = tc.id_boveda
            INNER JOIN moneda m ON m.id_moneda = b.id_moneda
            WHERE tc.estado = 'P'
        ");

        $map = [];
        foreach ($rows as $r) {
            $id = $r['id_agencia'];
            if (!isset($map[$id])) $map[$id] = [];
            $map[$id][] = [
                'id'     => (int)$r['id_transferencia_caja'],
                'moneda' => $r['moneda'],
                'monto'  => (float)$r['monto_total'],
                'fecha'  => $r['fecha'],
                'caja'   => $r['num_caja'],
            ];
        }
        return $map;
    }

    private function queryActividadAgencias(string $fecha): array
    {
        $rows = RawConnection::select("
            SELECT a.id_agencia, a.descripcion AS agencia,
                   COALESCE(cam.total, 0) AS cambios,
                   COALESCE(gir.total, 0) AS giros,
                   COALESCE(rem.total, 0) AS remesas
            FROM agencia a
            LEFT JOIN (SELECT id_agencia, COUNT(*) AS total FROM cambio WHERE DATE(fec_crea_cambio) = :f1 GROUP BY id_agencia) cam ON cam.id_agencia = a.id_agencia
            LEFT JOIN (SELECT id_agencia_origen AS id_agencia, COUNT(*) AS total FROM giro WHERE DATE(fec_crea_giro) = :f2 GROUP BY id_agencia_origen) gir ON gir.id_agencia = a.id_agencia
            LEFT JOIN (SELECT id_agencia, COUNT(*) AS total FROM remesa WHERE DATE(fec_crea_remesa) = :f3 GROUP BY id_agencia) rem ON rem.id_agencia = a.id_agencia
            WHERE a.estado = 'A' AND a.id_agencia > 0
            ORDER BY (COALESCE(cam.total,0) + COALESCE(gir.total,0) + COALESCE(rem.total,0)) DESC
        ", [':f1' => $fecha, ':f2' => $fecha, ':f3' => $fecha]);

        $result = [];
        foreach ($rows as $r) {
            $total = (int)$r['cambios'] + (int)$r['giros'] + (int)$r['remesas'];
            $result[] = [
                'idAgencia' => (int)$r['id_agencia'],
                'agencia'   => $r['agencia'],
                'cambios'   => (int)$r['cambios'],
                'giros'     => (int)$r['giros'],
                'remesas'   => (int)$r['remesas'],
                'total'     => $total,
            ];
        }
        return $result;
    }

    private function queryCotizacionesActuales(): array
    {
        $rows = RawConnection::select("
            SELECT m.abreviatura AS moneda,
                   MAX(CASE WHEN cb.id_operacion = 1 THEN cb.valor_base END) AS compra,
                   MAX(CASE WHEN cb.id_operacion = 2 THEN cb.valor_base END) AS venta,
                   co.valor AS tc_oficial
            FROM cotizacion_base cb
            INNER JOIN (
                SELECT id_moneda, id_operacion, MAX(fecha_creacion) AS max_fecha
                FROM cotizacion_base WHERE id_agencia = 99
                GROUP BY id_moneda, id_operacion
            ) ult ON ult.id_moneda = cb.id_moneda AND ult.id_operacion = cb.id_operacion AND ult.max_fecha = cb.fecha_creacion
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            LEFT JOIN cotizacion_oficial co ON co.id_moneda = m.id_moneda AND co.id_operacion = 1
              AND co.fec_crea_cotizacion_oficial = (SELECT MAX(fec_crea_cotizacion_oficial) FROM cotizacion_oficial WHERE id_moneda = m.id_moneda AND id_operacion = 1)
            WHERE cb.id_agencia = 99
            GROUP BY m.abreviatura, m.id_moneda, co.valor
            ORDER BY m.id_moneda
        ");

        $ayerRows = RawConnection::select("
            SELECT m.abreviatura AS moneda,
                   MAX(CASE WHEN cb.id_operacion = 1 THEN cb.valor_base END) AS compra,
                   MAX(CASE WHEN cb.id_operacion = 2 THEN cb.valor_base END) AS venta
            FROM cotizacion_base cb
            INNER JOIN (
                SELECT id_moneda, id_operacion, MAX(fecha_creacion) AS max_fecha
                FROM cotizacion_base WHERE id_agencia = 99 AND DATE(fecha_creacion) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                GROUP BY id_moneda, id_operacion
            ) ult ON ult.id_moneda = cb.id_moneda AND ult.id_operacion = cb.id_operacion AND ult.max_fecha = cb.fecha_creacion
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            WHERE cb.id_agencia = 99
            GROUP BY m.abreviatura, m.id_moneda
        ");
        $ayerMap = [];
        foreach ($ayerRows as $a) $ayerMap[$a['moneda']] = $a;

        $result = [];
        foreach ($rows as $r) {
            $compra = $r['compra'] ? (float)$r['compra'] : 0;
            $venta = $r['venta'] ? (float)$r['venta'] : 0;
            if ($compra == 0 && $venta == 0) continue;
            $spread = round($venta - $compra, 4);

            $tendencia = 'estable';
            $ayer = $ayerMap[$r['moneda']] ?? null;
            if ($ayer && $ayer['compra'] && $ayer['venta']) {
                $promHoy = ($compra + $venta) / 2;
                $promAyer = ((float)$ayer['compra'] + (float)$ayer['venta']) / 2;
                if ($promAyer > 0) {
                    if ($promHoy > $promAyer * 1.001) $tendencia = 'alza';
                    elseif ($promHoy < $promAyer * 0.999) $tendencia = 'baja';
                }
            }
            $result[] = [
                'moneda'    => $r['moneda'],
                'compra'    => $compra,
                'venta'     => $venta,
                'spread'    => $spread,
                'tcOficial' => $r['tc_oficial'] ? (float)$r['tc_oficial'] : null,
                'tendencia' => $tendencia,
            ];
        }
        return $result;
    }

    private function queryRendimientoCajeros(string $fecha): array
    {
        $rows = RawConnection::select("
            SELECT c.num_caja, c.cod_usuario,
                   CONCAT(t.nom_trab, ' ', t.ape_pat_trab) AS cajero,
                   a.descripcion AS agencia, a.id_agencia,
                   COUNT(sc.id_saldo_caja) AS ops,
                   MIN(sc.fec_crea_saldo) AS primera_op,
                   MAX(sc.fec_crea_saldo) AS ultima_op
            FROM saldo_caja sc
            INNER JOIN caja c ON c.id_caja = sc.id_caja
            INNER JOIN agencia a ON a.id_agencia = c.id_agencia
            LEFT JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
            WHERE DATE(sc.fec_crea_saldo) = :fecha
              AND sc.conciliacion NOT IN ('A','C')
              AND c.estado = 'A' AND c.id_tipo_unidad = 2
            GROUP BY c.num_caja, c.cod_usuario, cajero, a.descripcion, a.id_agencia
            HAVING ops > 0
            ORDER BY ops DESC
        ", [':fecha' => $fecha]);

        $opsPorUsuario = $this->queryOperacionesPorCajero($fecha);

        $result = [];
        foreach ($rows as $r) {
            $ops = (int)$r['ops'];
            $primera = strtotime($r['primera_op']);
            $ultima = strtotime($r['ultima_op']);
            $horasActivo = max(($ultima - $primera) / 3600, 0.5);
            $opsHora = round($ops / $horasActivo, 1);
            $codUsuario = $r['cod_usuario'];
            $detalle = $opsPorUsuario[$codUsuario] ?? ['cambios' => 0, 'giros' => 0, 'remesas' => 0, 'pagos' => 0];

            $result[] = [
                'numCaja'     => $r['num_caja'],
                'cajero'      => $r['cajero'],
                'idAgencia'   => (int)$r['id_agencia'],
                'agencia'     => $r['agencia'],
                'ops'         => $ops,
                'opsHora'     => $opsHora,
                'horasActivo' => round($horasActivo, 1),
                'cambios'     => $detalle['cambios'],
                'giros'       => $detalle['giros'],
                'remesas'     => $detalle['remesas'],
                'pagos'       => $detalle['pagos'],
            ];
        }
        return $result;
    }

    private function queryOperacionesPorCajero(string $fecha): array
    {
        $sql = "
            SELECT cod_usuario,
                   SUM(cambios) AS cambios, SUM(giros) AS giros,
                   SUM(remesas) AS remesas, SUM(pagos) AS pagos
            FROM (
                SELECT cod_usuario, COUNT(*) AS cambios, 0 AS giros, 0 AS remesas, 0 AS pagos
                FROM cambio WHERE DATE(fec_crea_cambio) = :f1 GROUP BY cod_usuario
                UNION ALL
                SELECT cod_usuario, 0, COUNT(*), 0, 0
                FROM giro WHERE DATE(fec_crea_giro) = :f2 GROUP BY cod_usuario
                UNION ALL
                SELECT cod_usuario, 0, 0, COUNT(*), 0
                FROM remesa WHERE DATE(fec_crea_remesa) = :f3 GROUP BY cod_usuario
                UNION ALL
                SELECT cod_usuario, 0, 0, 0, COUNT(*)
                FROM pago WHERE DATE(fec_crea_pago) = :f4 GROUP BY cod_usuario
            ) t
            GROUP BY cod_usuario
        ";
        $rows = RawConnection::select($sql, [':f1' => $fecha, ':f2' => $fecha, ':f3' => $fecha, ':f4' => $fecha]);

        $map = [];
        foreach ($rows as $r) {
            $map[$r['cod_usuario']] = [
                'cambios' => (int)$r['cambios'],
                'giros'   => (int)$r['giros'],
                'remesas' => (int)$r['remesas'],
                'pagos'   => (int)$r['pagos'],
            ];
        }
        return $map;
    }

    // ── 1. Conciliación de remesas en tiempo real ──────────────────

    public function conciliacionRemesas(string $fecha): array
    {
        $desde = $fecha . ' 00:00:00';
        $hasta = $fecha . ' 23:59:59';

        $agMap = [];
        foreach ($this->queryAgencias() as $ag) $agMap[$ag['id_agencia']] = $ag['descripcion'];

        // Pendientes desde Chile
        $pendientes = RawConnection::select("
            SELECT id_destino, moneda, codigo, envio, destinatario, fec_crea_remesa
            FROM tmp_reporte_remesa
            WHERE estado = 0
            ORDER BY fec_crea_remesa
        ", [], 'chile');

        // Pagadas hoy (local)
        $pagos = RawConnection::select("
            SELECT p.codigo, p.id_agencia, p.total_bob, p.fec_crea_pago,
                   p.id_forma_pago, m.abreviatura AS moneda
            FROM pago p
            INNER JOIN moneda m ON m.id_moneda = p.mon_rec
            WHERE p.id_operacion IN (4, 7, 8)
              AND p.fec_crea_pago BETWEEN :desde AND :hasta
            ORDER BY p.fec_crea_pago
        ", [':desde' => $desde, ':hasta' => $hasta]);

        $pagadasHoy = [];
        foreach ($pagos as $p) {
            $pagadasHoy[(int)$p['codigo']] = $p;
        }

        // Pagadas en días anteriores (últimos 30 días, para saber cuáles están atrasadas vs pagadas antes)
        $pagosAnteriores = RawConnection::select("
            SELECT codigo FROM pago
            WHERE id_operacion IN (4, 7, 8)
              AND fec_crea_pago < :desde
              AND fec_crea_pago >= DATE_SUB(:desde2, INTERVAL 30 DAY)
        ", [':desde' => $desde, ':desde2' => $desde]);
        $yaPageadas = [];
        foreach ($pagosAnteriores as $pa) $yaPageadas[(int)$pa['codigo']] = true;

        // Clasificar
        $porAgencia = [];
        $totalPendientes = 0;
        $totalPagadasHoy = 0;
        $totalMontoPendiente = 0;
        $totalMontoPagado = 0;
        $atrasadas = 0;

        foreach ($pendientes as $rem) {
            $codigo = (int)$rem['codigo'];
            $idDest = (int)$rem['id_destino'];
            $agencia = $agMap[$idDest] ?? "Agencia #$idDest";

            if (!isset($porAgencia[$idDest])) {
                $porAgencia[$idDest] = [
                    'idAgencia' => $idDest,
                    'agencia' => $agencia,
                    'pendientes' => 0,
                    'montoPendiente' => 0,
                    'pagadasHoy' => 0,
                    'montoPagado' => 0,
                    'atrasadas' => 0,
                ];
            }

            if (isset($pagadasHoy[$codigo])) {
                $porAgencia[$idDest]['pagadasHoy']++;
                $porAgencia[$idDest]['montoPagado'] += (float)$rem['envio'];
                $totalPagadasHoy++;
                $totalMontoPagado += (float)$rem['envio'];
            } else {
                $porAgencia[$idDest]['pendientes']++;
                $porAgencia[$idDest]['montoPendiente'] += (float)$rem['envio'];
                $totalPendientes++;
                $totalMontoPendiente += (float)$rem['envio'];

                // Atrasada si tiene más de 24h
                $horasDesdeCreacion = (strtotime('now') - strtotime($rem['fec_crea_remesa'])) / 3600;
                if ($horasDesdeCreacion > 24) {
                    $atrasadas++;
                    $porAgencia[$idDest]['atrasadas']++;
                }
            }
        }

        // Pagadas hoy que ya no están en pendientes (ya marcadas estado=1 en Chile)
        foreach ($pagos as $p) {
            $codigo = (int)$p['codigo'];
            if (!isset($pagadasHoy[$codigo])) continue;
            $idAg = (int)$p['id_agencia'];
            if (!isset($porAgencia[$idAg])) {
                $porAgencia[$idAg] = [
                    'idAgencia' => $idAg,
                    'agencia' => $agMap[$idAg] ?? "Agencia #$idAg",
                    'pendientes' => 0, 'montoPendiente' => 0,
                    'pagadasHoy' => 0, 'montoPagado' => 0, 'atrasadas' => 0,
                ];
            }
        }

        // Redondear
        foreach ($porAgencia as &$ag) {
            $ag['montoPendiente'] = round($ag['montoPendiente'], 2);
            $ag['montoPagado'] = round($ag['montoPagado'], 2);
        }
        unset($ag);

        usort($porAgencia, fn($a, $b) => $b['pendientes'] <=> $a['pendientes']);

        return [
            'resumen' => [
                'pendientes'      => $totalPendientes,
                'montoPendiente'  => round($totalMontoPendiente, 2),
                'pagadasHoy'      => $totalPagadasHoy,
                'montoPagado'     => round($totalMontoPagado, 2),
                'atrasadas'       => $atrasadas,
                'totalRemesas'    => $totalPendientes + $totalPagadasHoy,
            ],
            'porAgencia' => array_values($porAgencia),
        ];
    }

    // ── 2. Movimientos del día ─────────────────────────────────────

    public function movimientosDia(string $fecha): array
    {
        $desde = $fecha . ' 00:00:00';
        $hasta = $fecha . ' 23:59:59';

        $agMap = [];
        foreach ($this->queryAgencias() as $ag) $agMap[$ag['id_agencia']] = $ag['descripcion'];

        // Operaciones por tipo y agencia (compras: id_operacion=1, ventas: id_operacion=2)
        $cambios = RawConnection::select("
            SELECT c.id_agencia, c.id_operacion, COUNT(*) AS cant,
                   ROUND(SUM(c.total_operacion_bob), 2) AS monto_bob
            FROM cambio c
            WHERE c.fec_crea_cambio BETWEEN :desde AND :hasta
            GROUP BY c.id_agencia, c.id_operacion
        ", [':desde' => $desde, ':hasta' => $hasta]);

        $giros = RawConnection::select("
            SELECT g.id_agencia_origen AS id_agencia, COUNT(*) AS cant,
                   ROUND(SUM(g.monto), 2) AS monto,
                   m.abreviatura AS moneda
            FROM giro g
            INNER JOIN moneda m ON m.id_moneda = g.mon_rec
            WHERE g.fec_crea_giro BETWEEN :desde AND :hasta
            GROUP BY g.id_agencia_origen, m.abreviatura
        ", [':desde' => $desde, ':hasta' => $hasta]);

        $remesasPagadas = RawConnection::select("
            SELECT p.id_agencia, COUNT(*) AS cant,
                   ROUND(SUM(p.monto), 2) AS monto,
                   p.id_forma_pago
            FROM pago p
            WHERE p.id_operacion IN (4, 7, 8)
              AND p.fec_crea_pago BETWEEN :desde AND :hasta
            GROUP BY p.id_agencia, p.id_forma_pago
        ", [':desde' => $desde, ':hasta' => $hasta]);

        $depositos = RawConnection::select("
            SELECT dc.id_agencia_destino AS id_agencia, COUNT(*) AS cant,
                   ROUND(SUM(dc.monto), 2) AS monto
            FROM deposito_cuenta dc
            WHERE dc.fec_crea_deposito_cuenta_caja BETWEEN :desde AND :hasta
              AND dc.estado = 1
            GROUP BY dc.id_agencia_destino
        ", [':desde' => $desde, ':hasta' => $hasta]);

        $transferencias = RawConnection::select("
            SELECT tb.agencia_origen AS id_agencia_origen, tb.agencia_destino AS id_agencia_destino,
                   COUNT(*) AS cant, ROUND(SUM(tb.monto_total), 2) AS monto
            FROM traspaso_cuenta_bancaria tb
            WHERE tb.fec_crea_traspaso_cuenta_bancaria BETWEEN :desde AND :hasta
              AND tb.estado = 'A'
            GROUP BY tb.agencia_origen, tb.agencia_destino
        ", [':desde' => $desde, ':hasta' => $hasta]);

        // Consolidar por agencia
        $resultado = [];
        foreach ($agMap as $idAg => $nombre) {
            if ($idAg == 0) continue;
            $resultado[$idAg] = [
                'idAgencia' => $idAg,
                'agencia'   => $nombre,
                'cambios'   => ['compras' => ['cant' => 0, 'montoBob' => 0], 'ventas' => ['cant' => 0, 'montoBob' => 0]],
                'giros'     => [],
                'remesas'   => [],
                'depositos' => 0,
                'montoDepositos' => 0,
                'transferencias' => [],
            ];
        }

        foreach ($cambios as $c) {
            $id = (int)$c['id_agencia'];
            if (!isset($resultado[$id])) continue;
            $tipo = (int)$c['id_operacion'] === 1 ? 'compras' : 'ventas';
            $resultado[$id]['cambios'][$tipo]['cant'] += (int)$c['cant'];
            $resultado[$id]['cambios'][$tipo]['montoBob'] += (float)$c['monto_bob'];
        }

        foreach ($giros as $g) {
            $id = (int)$g['id_agencia'];
            if (!isset($resultado[$id])) continue;
            $resultado[$id]['giros'][] = [
                'moneda' => $g['moneda'],
                'cant'   => (int)$g['cant'],
                'monto'  => (float)$g['monto'],
            ];
        }

        foreach ($remesasPagadas as $r) {
            $id = (int)$r['id_agencia'];
            if (!isset($resultado[$id])) continue;
            $resultado[$id]['remesas'][] = [
                'cant'      => (int)$r['cant'],
                'monto'     => (float)$r['monto'],
                'formaPago' => (int)$r['id_forma_pago'] == 2 ? 'transferencia' : 'efectivo',
            ];
        }

        foreach ($depositos as $d) {
            $id = (int)$d['id_agencia'];
            if (!isset($resultado[$id])) continue;
            $resultado[$id]['depositos'] = (int)$d['cant'];
            $resultado[$id]['montoDepositos'] = (float)$d['monto'];
        }

        foreach ($transferencias as $t) {
            $idOrig = (int)$t['id_agencia_origen'];
            $idDest = (int)$t['id_agencia_destino'];
            $entry = [
                'cant'   => (int)$t['cant'],
                'monto'  => (float)$t['monto'],
                'destino' => $agMap[$idDest] ?? "Ag #$idDest",
            ];
            if (isset($resultado[$idOrig])) {
                $resultado[$idOrig]['transferencias'][] = $entry;
            }
        }

        // Filtrar agencias sin actividad
        $activos = array_filter($resultado, function ($ag) {
            return $ag['cambios']['compras']['cant'] > 0 || $ag['cambios']['ventas']['cant'] > 0
                || !empty($ag['giros']) || !empty($ag['remesas'])
                || $ag['depositos'] > 0 || !empty($ag['transferencias']);
        });

        return array_values($activos);
    }

    // ── 3. Cotizaciones por agencia ────────────────────────────────

    public function cotizacionesAgencia(): array
    {
        $monedas = $this->queryMonedas();
        $monedaMap = [];
        foreach ($monedas as $m) $monedaMap[$m['id_moneda']] = $m['abreviatura'];

        $agMap = [];
        foreach ($this->queryAgencias() as $ag) $agMap[$ag['id_agencia']] = $ag['descripcion'];

        // Cotizaciones vigentes por agencia (de la tabla cotizacion)
        $rows = RawConnection::select("
            SELECT c.id_agencia, c.id_moneda, c.id_operacion,
                   c.valor, c.fec_mod_cotizacion
            FROM cotizacion c
            WHERE c.estado = 'A'
              AND c.id_operacion IN (1, 2)
              AND c.id_moneda IN (1, 3, 4, 5)
            ORDER BY c.id_agencia, c.id_moneda, c.id_operacion
        ");

        // Cotizaciones base (USD y otras que solo están en cotizacion_base)
        $baseRows = RawConnection::select("
            SELECT cb.id_agencia, cb.id_moneda, cb.id_operacion,
                   cb.valor_base AS valor, cb.fecha_creacion AS fec_mod_cotizacion
            FROM cotizacion_base cb
            INNER JOIN (
                SELECT id_agencia, id_moneda, id_operacion, MAX(fecha_creacion) AS max_fecha
                FROM cotizacion_base
                WHERE id_operacion IN (1, 2)
                  AND id_moneda IN (1, 3, 4, 5, 6, 12)
                GROUP BY id_agencia, id_moneda, id_operacion
            ) lat ON cb.id_agencia = lat.id_agencia
                 AND cb.id_moneda = lat.id_moneda
                 AND cb.id_operacion = lat.id_operacion
                 AND cb.fecha_creacion = lat.max_fecha
            WHERE cb.id_agencia > 0
              AND cb.valor_base > 0
              AND cb.id_moneda IN (1, 3, 4, 5, 6, 12)
        ");

        // Merge: para USD (id_moneda=1) cotizacion_base es el valor real,
        // cotizacion es solo referencial. Para otras monedas, cotizacion tiene prioridad.
        $usdMonedaId = 1;
        $baseKeys = [];
        foreach ($baseRows as $br) {
            $baseKeys[$br['id_agencia'] . '-' . $br['id_moneda'] . '-' . $br['id_operacion']] = $br;
        }

        // Filtrar USD de cotizacion (es referencial) y reemplazar con cotizacion_base
        $rows = array_filter($rows, function ($r) use ($usdMonedaId) {
            return (int)$r['id_moneda'] !== $usdMonedaId;
        });

        // Agregar cotizacion_base donde no exista en cotizacion
        $existingKeys = [];
        foreach ($rows as $r) {
            $existingKeys[$r['id_agencia'] . '-' . $r['id_moneda'] . '-' . $r['id_operacion']] = true;
        }
        foreach ($baseRows as $br) {
            $key = $br['id_agencia'] . '-' . $br['id_moneda'] . '-' . $br['id_operacion'];
            if (!isset($existingKeys[$key])) {
                $rows[] = $br;
            }
        }

        // Cotización oficial vigente por moneda
        $oficiales = RawConnection::select("
            SELECT co.id_moneda, co.id_operacion, co.valor
            FROM cotizacion_oficial co
            WHERE co.estado = 'A'
              AND co.id_operacion IN (1, 2)
              AND co.fec_crea_cotizacion_oficial = (
                  SELECT MAX(co2.fec_crea_cotizacion_oficial)
                  FROM cotizacion_oficial co2
                  WHERE co2.id_moneda = co.id_moneda
                    AND co2.id_operacion = co.id_operacion
                    AND co2.estado = 'A'
              )
        ");
        $oficialMap = [];
        foreach ($oficiales as $o) {
            $oficialMap[$o['id_moneda'] . '-' . $o['id_operacion']] = (float)$o['valor'];
        }

        // Agrupar por agencia y moneda
        $grouped = [];
        foreach ($rows as $r) {
            $idAg = (int)$r['id_agencia'];
            $idMon = (int)$r['id_moneda'];
            $op = (int)$r['id_operacion'];
            $abr = $monedaMap[$idMon] ?? "MON#$idMon";
            $key = "$idAg-$idMon";

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'idAgencia' => $idAg,
                    'agencia'   => $agMap[$idAg] ?? "Ag #$idAg",
                    'moneda'    => $abr,
                    'compra'    => 0,
                    'venta'     => 0,
                    'spread'    => 0,
                    'oficialCompra' => $oficialMap["$idMon-1"] ?? null,
                    'oficialVenta'  => $oficialMap["$idMon-2"] ?? null,
                    'diffCompra' => null,
                    'diffVenta'  => null,
                    'ultimaModif' => $r['fec_mod_cotizacion'],
                ];
            }

            if ($op == 1) $grouped[$key]['compra'] = (float)$r['valor'];
            if ($op == 2) $grouped[$key]['venta']  = (float)$r['valor'];
            if ($r['fec_mod_cotizacion'] > $grouped[$key]['ultimaModif']) {
                $grouped[$key]['ultimaModif'] = $r['fec_mod_cotizacion'];
            }
        }

        // Calcular spread y diferencia vs oficial
        foreach ($grouped as &$g) {
            $g['spread'] = round($g['venta'] - $g['compra'], 4);
            if ($g['oficialCompra'] && $g['compra'] > 0) {
                $g['diffCompra'] = round($g['compra'] - $g['oficialCompra'], 4);
            }
            if ($g['oficialVenta'] && $g['venta'] > 0) {
                $g['diffVenta'] = round($g['venta'] - $g['oficialVenta'], 4);
            }
        }
        unset($g);

        // Filtrar registros sin cotización real y oficina central
        $filtered = array_filter($grouped, fn($g) => ($g['compra'] > 0 || $g['venta'] > 0) && $g['idAgencia'] > 0);

        usort($filtered, function ($a, $b) {
            if ($a['moneda'] !== $b['moneda']) return strcmp($a['moneda'], $b['moneda']);
            return strcmp($a['agencia'], $b['agencia']);
        });

        return array_values($filtered);
    }

}
