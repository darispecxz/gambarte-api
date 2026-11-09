<?php

namespace App\Modules\GestionOperativa\Services;

use App\Modules\Shared\Database\RawConnection;

class GastosOperativoService
{
    private const CATEGORIAS = [
        'alquileres' => [
            'label'    => 'Alquileres',
            'icon'     => 'ti-building',
            'patrones' => ['45902%'],
        ],
        'sueldos' => [
            'label'    => 'Sueldos y Beneficios',
            'icon'     => 'ti-users',
            'patrones' => ['45102%', '45106%', '45107%', '45109%', '45110%', '45111%', '45101%', '45103%', '45104%', '45108%', '45114%'],
        ],
        'cargas_sociales' => [
            'label'    => 'Cargas Sociales',
            'icon'     => 'ti-heartbeat',
            'patrones' => ['45115%', '45116%'],
        ],
        'impuestos' => [
            'label'    => 'Impuestos',
            'icon'     => 'ti-receipt-tax',
            'patrones' => ['45502%', '45599%', '45504%', '45501%'],
        ],
        'servicios_basicos' => [
            'label'    => 'Servicios Básicos',
            'icon'     => 'ti-bulb',
            'patrones' => ['45903%'],
        ],
        'comunicaciones' => [
            'label'    => 'Comunicaciones y Traslados',
            'icon'     => 'ti-phone',
            'patrones' => ['45401%', '45402%', '45403%', '45404%', '45405%', '45406%'],
        ],
        'mantenimiento' => [
            'label'    => 'Mantenimiento y Reparaciones',
            'icon'     => 'ti-tool',
            'patrones' => ['45601%', '45602%'],
        ],
        'servicios_contratados' => [
            'label'    => 'Servicios Contratados',
            'icon'     => 'ti-file-check',
            'patrones' => ['45201%', '45202%', '45203%', '45204%', '45205%', '45206%', '45299%'],
        ],
        'seguros' => [
            'label'    => 'Seguros',
            'icon'     => 'ti-shield-check',
            'patrones' => ['45301%', '45302%', '45303%', '45304%', '45305%', '45306%', '45399%'],
        ],
        'depreciacion' => [
            'label'    => 'Depreciación y Amortización',
            'icon'     => 'ti-trending-down-3',
            'patrones' => ['45701%', '45702%', '45703%', '45704%', '45705%', '45801%', '45802%', '45804%', '45805%'],
        ],
        'personal_otros' => [
            'label'    => 'Gastos de Personal (otros)',
            'icon'     => 'ti-briefcase',
            'patrones' => ['45105%', '45112%', '45113%', '45117%', '45118%', '45119%', '45199%'],
        ],
        'generales' => [
            'label'    => 'Gastos Generales',
            'icon'     => 'ti-notes',
            'patrones' => ['45901%', '45904%', '45905%', '45906%', '45907%', '45908%', '45909%', '45910%', '45911%', '45912%', '45913%', '45999%'],
        ],
    ];

    private const AGENCIA_MAP = [
        'OF. CENTRAL'  => 0,
        'OF CENTRAL'   => 0,
        'MERCADO'      => 1,
        '6 DE MARZO'   => 2,
        'EL ALTO'      => 2,
        'SUCRE'        => 3,
        'COCHABAMBA'    => 4,
        'SANTA CRUZ'   => 5,
        'UYUNI'        => 6,
        'ORURO'        => 8,
        'YAPACANI'     => 9,
        'COLON'        => 10,
        'COLÓN'        => 10,
    ];

    private const AGENCIA_NOMBRES = [
        0  => 'Of. Central',
        1  => 'Mercado',
        2  => '6 de Marzo / El Alto',
        3  => 'Sucre',
        4  => 'Cochabamba',
        5  => 'Santa Cruz',
        6  => 'Uyuni',
        8  => 'Oruro',
        9  => 'Yapacani',
        10 => 'Colón',
    ];

    public function resumenMensual(string $mes): array
    {
        $ejercicio = substr($mes, 0, 4);
        $desde = $mes . '-01';
        $hasta = date('Y-m-t', strtotime($desde));
        $hoy = date('Y-m-d');
        $diasMes = (int) date('t', strtotime($desde));
        $diaActual = min((int) date('d', strtotime(min($hoy, $hasta))), $diasMes);

        $gastosDiarios = $this->gastosPorDia($ejercicio, $desde, $hasta);
        $ingresosDiarios = $this->ingresosPorDia($ejercicio, $desde, $hasta);
        $porCategoria = $this->gastosPorCategoria($ejercicio, $desde, $hasta);
        $porAgencia = $this->gastosPorAgencia($ejercicio, $desde, $hasta);
        $ingresosPorAgencia = $this->ingresosPorAgencia($ejercicio, $desde, $hasta);

        $totalGastos = array_sum(array_column($gastosDiarios, 'monto'));
        $totalIngresos = array_sum(array_column($ingresosDiarios, 'monto'));

        $promedioMensual = $this->promedioMensualHistorico($ejercicio, $desde);

        $acumulado = $this->buildAcumulado($gastosDiarios, $ingresosDiarios, $diasMes);

        return [
            'mes'             => $mes,
            'diasMes'         => $diasMes,
            'diaActual'       => $diaActual,
            'totalGastos'     => round($totalGastos, 2),
            'totalIngresos'   => round($totalIngresos, 2),
            'balance'         => round($totalIngresos - $totalGastos, 2),
            'cobertura'       => $totalGastos > 0 ? round(($totalIngresos / $totalGastos) * 100, 1) : 0,
            'proyeccion'      => round($promedioMensual, 2),
            'acumulado'       => $acumulado,
            'categorias'      => $porCategoria,
            'agencias'        => $this->mergeAgenciaIngresos($porAgencia, $ingresosPorAgencia, $totalGastos),
            'categoriaMeta'   => $this->getCategoriaMeta(),
        ];
    }

    private function gastosPorDia(string $ej, string $desde, string $hasta): array
    {
        $sql = <<<SQL
            SELECT a.fecha, SUM(p.debe) as monto
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta LIKE '45%'
              AND p.debe > 0
              AND a.fecha BETWEEN :desde AND :hasta
            GROUP BY a.fecha
            ORDER BY a.fecha
        SQL;

        return RawConnection::select($sql, [
            ':ej' => $ej, ':desde' => $desde, ':hasta' => $hasta,
        ], 'conta');
    }

    private function ingresosPorDia(string $ej, string $desde, string $hasta): array
    {
        $sql = <<<SQL
            SELECT a.fecha, SUM(p.haber) as monto
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta LIKE '54%'
              AND p.haber > 0
              AND a.fecha BETWEEN :desde AND :hasta
            GROUP BY a.fecha
            ORDER BY a.fecha
        SQL;

        return RawConnection::select($sql, [
            ':ej' => $ej, ':desde' => $desde, ':hasta' => $hasta,
        ], 'conta');
    }

    private function gastosPorCategoria(string $ej, string $desde, string $hasta): array
    {
        $sql = <<<SQL
            SELECT p.codsubcuenta, s.descripcion, SUM(p.debe) as total
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            JOIN co_subcuentas s ON p.codsubcuenta = s.codsubcuenta
                AND s.codejercicio = a.codejercicio
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta LIKE '45%'
              AND p.debe > 0
              AND a.fecha BETWEEN :desde AND :hasta
            GROUP BY p.codsubcuenta, s.descripcion
            ORDER BY total DESC
        SQL;

        $rows = RawConnection::select($sql, [
            ':ej' => $ej, ':desde' => $desde, ':hasta' => $hasta,
        ], 'conta');

        $result = [];
        foreach (self::CATEGORIAS as $key => $cat) {
            $result[$key] = [
                'key'   => $key,
                'label' => $cat['label'],
                'icon'  => $cat['icon'],
                'total' => 0,
                'subcuentas' => [],
            ];
        }

        foreach ($rows as $row) {
            $cod = $row['codsubcuenta'];
            $categorized = false;
            foreach (self::CATEGORIAS as $key => $cat) {
                foreach ($cat['patrones'] as $patron) {
                    $prefix = str_replace('%', '', $patron);
                    if (str_starts_with($cod, $prefix)) {
                        $result[$key]['total'] += (float) $row['total'];
                        $result[$key]['subcuentas'][] = [
                            'codsubcuenta' => $cod,
                            'descripcion'  => $row['descripcion'],
                            'total'        => round((float) $row['total'], 2),
                        ];
                        $categorized = true;
                        break 2;
                    }
                }
            }
            if (!$categorized) {
                $result['generales']['total'] += (float) $row['total'];
                $result['generales']['subcuentas'][] = [
                    'codsubcuenta' => $cod,
                    'descripcion'  => $row['descripcion'],
                    'total'        => round((float) $row['total'], 2),
                ];
            }
        }

        foreach ($result as &$cat) {
            $cat['total'] = round($cat['total'], 2);
        }

        uasort($result, fn($a, $b) => $b['total'] <=> $a['total']);

        return array_values($result);
    }

    private function gastosPorAgencia(string $ej, string $desde, string $hasta): array
    {
        $sql = <<<SQL
            SELECT a.concepto, SUM(p.debe) as total
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta LIKE '45%'
              AND p.debe > 0
              AND a.fecha BETWEEN :desde AND :hasta
            GROUP BY a.concepto
        SQL;

        $rows = RawConnection::select($sql, [
            ':ej' => $ej, ':desde' => $desde, ':hasta' => $hasta,
        ], 'conta');

        $agencias = [];
        foreach (self::AGENCIA_NOMBRES as $id => $nombre) {
            $agencias[$id] = ['agenciaId' => $id, 'nombre' => $nombre, 'total' => 0];
        }
        $agencias[-1] = ['agenciaId' => -1, 'nombre' => 'Sin identificar', 'total' => 0];

        foreach ($rows as $row) {
            $agId = $this->detectarAgencia($row['concepto']);
            $key = $agId ?? -1;
            if (!isset($agencias[$key])) {
                $agencias[$key] = ['agenciaId' => $key, 'nombre' => 'Agencia ' . $key, 'total' => 0];
            }
            $agencias[$key]['total'] += (float) $row['total'];
        }

        foreach ($agencias as &$ag) {
            $ag['total'] = round($ag['total'], 2);
        }

        $agencias = array_filter($agencias, fn($a) => $a['total'] > 0);
        usort($agencias, fn($a, $b) => $b['total'] <=> $a['total']);

        return array_values($agencias);
    }

    private function detectarAgencia(string $concepto): ?int
    {
        $upper = mb_strtoupper($concepto);
        foreach (self::AGENCIA_MAP as $keyword => $id) {
            if (str_contains($upper, $keyword)) {
                return $id;
            }
        }
        return null;
    }

    private function promedioMensualHistorico(string $ejercicio, string $mesFin): float
    {
        $sql = <<<SQL
            SELECT DATE_FORMAT(a.fecha, '%Y-%m') as mes, SUM(p.debe) as total
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta LIKE '45%'
              AND p.debe > 0
              AND a.fecha < :fin
            GROUP BY DATE_FORMAT(a.fecha, '%Y-%m')
            HAVING total > 100000
            ORDER BY mes
        SQL;

        $rows = RawConnection::select($sql, [
            ':ej' => $ejercicio, ':fin' => $mesFin,
        ], 'conta');

        if (empty($rows)) return 0;

        $sum = array_sum(array_column($rows, 'total'));
        return $sum / count($rows);
    }

    private function buildAcumulado(array $gastosDiarios, array $ingresosDiarios, int $diasMes): array
    {
        $gastoMap = [];
        foreach ($gastosDiarios as $g) {
            $dia = (int) substr($g['fecha'], 8, 2);
            $gastoMap[$dia] = (float) $g['monto'];
        }

        $ingresoMap = [];
        foreach ($ingresosDiarios as $i) {
            $dia = (int) substr($i['fecha'], 8, 2);
            $ingresoMap[$dia] = (float) $i['monto'];
        }

        $acumulado = [];
        $acumGasto = 0;
        $acumIngreso = 0;

        for ($d = 1; $d <= $diasMes; $d++) {
            $acumGasto += $gastoMap[$d] ?? 0;
            $acumIngreso += $ingresoMap[$d] ?? 0;
            $acumulado[] = [
                'dia'             => $d,
                'gastoDelDia'     => round($gastoMap[$d] ?? 0, 2),
                'ingresoDelDia'   => round($ingresoMap[$d] ?? 0, 2),
                'gastoAcumulado'  => round($acumGasto, 2),
                'ingresoAcumulado' => round($acumIngreso, 2),
            ];
        }

        return $acumulado;
    }

    private function getCategoriaMeta(): array
    {
        $meta = [];
        foreach (self::CATEGORIAS as $key => $cat) {
            $meta[] = [
                'key'   => $key,
                'label' => $cat['label'],
                'icon'  => $cat['icon'],
            ];
        }
        return $meta;
    }

    private function ingresosPorAgencia(string $ej, string $desde, string $hasta): array
    {
        $sql = <<<SQL
            SELECT a.concepto, SUM(p.haber) as total
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta LIKE '54%'
              AND p.haber > 0
              AND a.fecha BETWEEN :desde AND :hasta
            GROUP BY a.concepto
        SQL;

        $rows = RawConnection::select($sql, [
            ':ej' => $ej, ':desde' => $desde, ':hasta' => $hasta,
        ], 'conta');

        $agencias = [];
        foreach ($rows as $row) {
            $agId = $this->detectarAgencia($row['concepto']) ?? -1;
            $agencias[$agId] = ($agencias[$agId] ?? 0) + (float) $row['total'];
        }

        return $agencias;
    }

    private function mergeAgenciaIngresos(array $porAgencia, array $ingresosPorAgencia, float $totalGastos): array
    {
        $centralGasto = 0;
        foreach ($porAgencia as &$ag) {
            if ($ag['agenciaId'] === 0) {
                $centralGasto = $ag['total'];
            }
        }
        unset($ag);

        $totalIngresosOp = array_sum($ingresosPorAgencia);
        foreach ($porAgencia as &$ag) {
            $id = $ag['agenciaId'];
            $ingresos = round($ingresosPorAgencia[$id] ?? 0, 2);
            $esAdmin = ($id === 0);
            $ag['ingresos'] = $ingresos;
            $ag['balance'] = round($ingresos - $ag['total'], 2);
            $ag['cobertura'] = $ag['total'] > 0 ? round(($ingresos / $ag['total']) * 100, 1) : 0;
            $ag['esAdministrativa'] = $esAdmin;

            if (!$esAdmin && $totalIngresosOp > 0 && $centralGasto > 0) {
                $share = $ingresos / $totalIngresosOp;
                $ag['cuotaCentral'] = round($centralGasto * $share, 2);
                $ag['balanceNeto'] = round($ingresos - $ag['total'] - ($centralGasto * $share), 2);
            } else {
                $ag['cuotaCentral'] = 0;
                $ag['balanceNeto'] = $esAdmin ? round(0 - $ag['total'], 2) : $ag['balance'];
            }
        }
        unset($ag);

        return array_values($porAgencia);
    }

    public function detalleGastosAgencia(string $mes, int $agenciaId): array
    {
        $ejercicio = substr($mes, 0, 4);
        $desde = $mes . '-01';
        $hasta = date('Y-m-t', strtotime($desde));

        $keywords = $this->keywordsForAgencia($agenciaId);
        if (empty($keywords)) {
            return ['agenciaId' => $agenciaId, 'nombre' => 'Desconocida', 'categorias' => [], 'asientos' => [], 'totalGastos' => 0, 'totalIngresos' => 0];
        }

        $allKeywords = array_keys(self::AGENCIA_MAP);

        // Gastos by subcuenta for this agency
        $gastoRows = $this->asientosPorAgenciaFilter($ejercicio, $desde, $hasta, '45%', 'debe', $keywords, $agenciaId, $allKeywords);
        $ingresoRows = $this->asientosPorAgenciaFilter($ejercicio, $desde, $hasta, '54%', 'haber', $keywords, $agenciaId, $allKeywords);

        // Group gastos into categories
        $categorias = [];
        foreach (self::CATEGORIAS as $key => $cat) {
            $categorias[$key] = [
                'key' => $key, 'label' => $cat['label'], 'icon' => $cat['icon'],
                'total' => 0, 'subcuentas' => [],
            ];
        }

        foreach ($gastoRows as $row) {
            $cod = $row['codsubcuenta'];
            $categorized = false;
            foreach (self::CATEGORIAS as $key => $cat) {
                foreach ($cat['patrones'] as $patron) {
                    $prefix = str_replace('%', '', $patron);
                    if (str_starts_with($cod, $prefix)) {
                        $categorias[$key]['total'] += (float) $row['total'];
                        $categorias[$key]['subcuentas'][] = [
                            'codsubcuenta' => $cod,
                            'descripcion'  => $row['descripcion'],
                            'total'        => round((float) $row['total'], 2),
                        ];
                        $categorized = true;
                        break 2;
                    }
                }
            }
            if (!$categorized) {
                $categorias['generales']['total'] += (float) $row['total'];
                $categorias['generales']['subcuentas'][] = [
                    'codsubcuenta' => $cod,
                    'descripcion'  => $row['descripcion'],
                    'total'        => round((float) $row['total'], 2),
                ];
            }
        }

        foreach ($categorias as &$cat) {
            $cat['total'] = round($cat['total'], 2);
        }
        unset($cat);

        $categorias = array_filter($categorias, fn($c) => $c['total'] > 0);
        uasort($categorias, fn($a, $b) => $b['total'] <=> $a['total']);

        $totalGastos = array_sum(array_column(array_values($categorias), 'total'));
        $totalIngresos = array_sum(array_column($ingresoRows, 'total'));

        // Recent asientos (top 30)
        $asientos = $this->topAsientosAgencia($ejercicio, $desde, $hasta, $keywords, $agenciaId, $allKeywords);

        return [
            'agenciaId'     => $agenciaId,
            'nombre'        => self::AGENCIA_NOMBRES[$agenciaId] ?? 'Agencia ' . $agenciaId,
            'esAdministrativa' => ($agenciaId === 0),
            'totalGastos'   => round($totalGastos, 2),
            'totalIngresos' => round($totalIngresos, 2),
            'balance'       => round($totalIngresos - $totalGastos, 2),
            'cobertura'     => $totalGastos > 0 ? round(($totalIngresos / $totalGastos) * 100, 1) : 0,
            'categorias'    => array_values($categorias),
            'asientos'      => $asientos,
        ];
    }

    private function keywordsForAgencia(int $agenciaId): array
    {
        $keywords = [];
        foreach (self::AGENCIA_MAP as $kw => $id) {
            if ($id === $agenciaId) {
                $keywords[] = $kw;
            }
        }
        return $keywords;
    }

    private function asientosPorAgenciaFilter(string $ej, string $desde, string $hasta, string $cuentaLike, string $campo, array $keywords, int $agId, array $allKeywords): array
    {
        $whereParts = [];
        $params = [':ej' => $ej, ':desde' => $desde, ':hasta' => $hasta];

        if ($agId === -1) {
            // "Sin identificar": exclude all known keywords
            foreach ($allKeywords as $i => $kw) {
                $whereParts[] = "UPPER(a.concepto) NOT LIKE :kw{$i}";
                $params[":kw{$i}"] = '%' . $kw . '%';
            }
        } else {
            foreach ($keywords as $i => $kw) {
                $whereParts[] = "UPPER(a.concepto) LIKE :kw{$i}";
                $params[":kw{$i}"] = '%' . $kw . '%';
            }
        }

        $whereClause = $agId === -1
            ? implode(' AND ', $whereParts)
            : '(' . implode(' OR ', $whereParts) . ')';

        $sql = "SELECT p.codsubcuenta, s.descripcion, SUM(p.{$campo}) as total
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            JOIN co_subcuentas s ON p.codsubcuenta = s.codsubcuenta AND s.codejercicio = a.codejercicio
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta LIKE '{$cuentaLike}'
              AND p.{$campo} > 0
              AND a.fecha BETWEEN :desde AND :hasta
              AND {$whereClause}
            GROUP BY p.codsubcuenta, s.descripcion
            ORDER BY total DESC";

        return RawConnection::select($sql, $params, 'conta');
    }

    private function topAsientosAgencia(string $ej, string $desde, string $hasta, array $keywords, int $agId, array $allKeywords): array
    {
        $whereParts = [];
        $params = [':ej' => $ej, ':desde' => $desde, ':hasta' => $hasta];

        if ($agId === -1) {
            foreach ($allKeywords as $i => $kw) {
                $whereParts[] = "UPPER(a.concepto) NOT LIKE :kw{$i}";
                $params[":kw{$i}"] = '%' . $kw . '%';
            }
        } else {
            foreach ($keywords as $i => $kw) {
                $whereParts[] = "UPPER(a.concepto) LIKE :kw{$i}";
                $params[":kw{$i}"] = '%' . $kw . '%';
            }
        }

        $whereClause = $agId === -1
            ? implode(' AND ', $whereParts)
            : '(' . implode(' OR ', $whereParts) . ')';

        $sql = "SELECT a.idasiento, a.fecha, a.concepto, p.codsubcuenta, p.debe
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta LIKE '45%'
              AND p.debe > 0
              AND a.fecha BETWEEN :desde AND :hasta
              AND {$whereClause}
            ORDER BY p.debe DESC
            LIMIT 30";

        $rows = RawConnection::select($sql, $params, 'conta');

        return array_map(fn($r) => [
            'idasiento'    => (int) $r['idasiento'],
            'fecha'        => $r['fecha'],
            'concepto'     => $r['concepto'],
            'codsubcuenta' => $r['codsubcuenta'],
            'debe'         => round((float) $r['debe'], 2),
        ], $rows);
    }

    public function detalleSubcuenta(string $mes, string $codsubcuenta): array
    {
        $ejercicio = substr($mes, 0, 4);
        $desde = $mes . '-01';
        $hasta = date('Y-m-t', strtotime($desde));

        $sql = <<<SQL
            SELECT a.fecha, a.concepto, p.debe, a.idasiento
            FROM co_partidas p
            JOIN co_asientos a ON p.idasiento = a.idasiento
            WHERE a.codejercicio = :ej
              AND p.codsubcuenta = :cod
              AND p.debe > 0
              AND a.fecha BETWEEN :desde AND :hasta
            ORDER BY a.fecha, a.idasiento
        SQL;

        $rows = RawConnection::select($sql, [
            ':ej' => $ejercicio, ':cod' => $codsubcuenta,
            ':desde' => $desde, ':hasta' => $hasta,
        ], 'conta');

        $total = 0;
        $asientos = [];
        foreach ($rows as $r) {
            $monto = round((float) $r['debe'], 2);
            $total += $monto;
            $asientos[] = [
                'idasiento' => (int) $r['idasiento'],
                'fecha'     => $r['fecha'],
                'concepto'  => $r['concepto'],
                'debe'      => $monto,
            ];
        }

        return [
            'codsubcuenta' => $codsubcuenta,
            'total'        => round($total, 2),
            'asientos'     => $asientos,
        ];
    }
}
