<?php

namespace App\Modules\Contabilidad\Services;

use App\Modules\POS\Services\PosAdminService;
use App\Modules\Shared\Database\RawConnection;
use Dompdf\Dompdf;
use Dompdf\Options;

class CashAuditService
{
    private const DIFFERENCE_TOLERANCE = 1.00;

    private AccountingService $accounting;
    private PosAdminService $pos;

    public function __construct(AccountingService $accounting, PosAdminService $pos)
    {
        $this->accounting = $accounting;
        $this->pos = $pos;
    }

    public function report(string $fecha, ?int $agencia = null, bool $includeAccounting = false): array
    {
        $groups = $this->pos->dashboard($fecha, $agencia);
        $reconciliation = $this->accounting->cashierDetails($fecha, $agencia);
        $byCaja = [];
        foreach ($reconciliation['cashiers'] as $cashier) {
            foreach ($cashier['currencies'] as $currency) {
                $byCaja[(int) $currency['idCaja']] = $currency;
            }
        }
        $fallas = $this->fallas($fecha, $agencia);
        $followups = $this->latestFollowups($fecha, $agencia);
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['cashiers'] as $cashier) {
                // Solo forman parte de la jornada los cajeros que registraron
                // una apertura en la fecha consultada.
                if ($cashier['estado'] === 'sin_apertura' || !$cashier['horaApertura']) {
                    continue;
                }
                foreach ($cashier['balances'] as $balance) {
                    // La apertura del cajero agrupa varias monedas. Solo se
                    // incluye la caja/moneda que tuvo apertura real ese día.
                    if ($balance['apertura'] === null) {
                        continue;
                    }
                    $id = (int) $balance['idCaja'];
                    $account = $byCaja[$id] ?? null;
                    $theoretical = $account ? round((float) $account['totalSaldo'], 2) : round((float) $balance['saldoActual'], 2);
                    $declared = $balance['cierre'] === null ? null : round((float) $balance['cierre'], 2);
                    // Para el informe de Sistemas, el contado es automático y
                    // corresponde al cierre registrado por el sistema.
                    $physical = $declared;
                    $difference = $declared === null ? null : round($theoretical - $declared, 2);
                    if ($declared === null) $status = 'pendiente_cierre';
                    elseif (abs($difference) > self::DIFFERENCE_TOLERANCE) $status = 'diferencia';
                    else $status = 'conciliada';
                    $rows[] = [
                        'idCaja' => $id,
                        'numCaja' => (int) $cashier['numCaja'],
                        'idAgencia' => (int) $group['idAgencia'],
                        'agencia' => $group['nombre'],
                        'cajero' => $cashier['nombreCajero'] ?: $cashier['loginUsuario'],
                        'login' => $cashier['loginUsuario'],
                        'moneda' => $balance['moneda'],
                        'estadoCaja' => $cashier['estado'],
                        'horaApertura' => $cashier['horaApertura'],
                        'horaCierre' => $cashier['horaCierre'],
                        'saldoTeorico' => $theoretical,
                        'cierreDeclarado' => $declared,
                        'conteoIndependiente' => $physical,
                        'diferenciaDeclarada' => $difference,
                        'diferenciaFisica' => $difference,
                        'estado' => $status,
                        'conteoId' => null,
                        'fechaConteo' => $cashier['horaCierre'],
                        'versionesConteo' => 0,
                        'falla' => $fallas[$id] ?? null,
                        'seguimiento' => $followups[$id] ?? null,
                        'apertura' => $account['aperturaCaja'] ?? $balance['apertura'],
                        'totalIngresos' => $account ? round((float) $account['totalIngreso'] - (float) $account['aperturaCaja'], 2) : null,
                        'totalEgresos' => $account['totalEgreso'] ?? null,
                    ];
                }
            }
        }
        usort($rows, fn ($a, $b) => [$a['agencia'], $a['numCaja'], $a['moneda']] <=> [$b['agencia'], $b['numCaja'], $b['moneda']]);
        $cashiers = $this->groupCashiers($rows);
        $summary = ['previstas' => count($cashiers), 'arqueadas' => 0, 'pendientes' => 0, 'sinDiferencia' => 0, 'conFaltante' => 0, 'conSobrante' => 0, 'enRevision' => 0, 'porMoneda' => []];
        foreach ($rows as $row) {
            $m = $row['moneda'];
            if (!isset($summary['porMoneda'][$m])) $summary['porMoneda'][$m] = ['moneda' => $m, 'faltantes' => 0, 'sobrantes' => 0, 'pendientes' => 0];
            if ($row['cierreDeclarado'] === null) $summary['porMoneda'][$m]['pendientes']++;
            elseif ($row['diferenciaFisica'] > self::DIFFERENCE_TOLERANCE) $summary['porMoneda'][$m]['faltantes'] += $row['diferenciaFisica'];
            elseif ($row['diferenciaFisica'] < -self::DIFFERENCE_TOLERANCE) $summary['porMoneda'][$m]['sobrantes'] += abs($row['diferenciaFisica']);
        }
        foreach ($cashiers as $cashier) {
            $statuses = array_column($cashier['monedas'], 'estado');
            $differences = array_column($cashier['monedas'], 'diferenciaFisica');
            $pending = count(array_filter($statuses, fn ($status) => in_array($status, ['pendiente_cierre', 'pendiente_conteo', 'desactualizado'], true))) > 0;
            if ($pending) $summary['pendientes']++;
            else $summary['arqueadas']++;
            if (count(array_filter($statuses, fn ($status) => $status === 'conciliada')) === count($statuses)) $summary['sinDiferencia']++;
            if (count(array_filter($differences, fn ($difference) => $difference !== null && $difference > self::DIFFERENCE_TOLERANCE)) > 0) $summary['conFaltante']++;
            if (count(array_filter($differences, fn ($difference) => $difference !== null && $difference < -self::DIFFERENCE_TOLERANCE)) > 0) $summary['conSobrante']++;
            if (count(array_filter($statuses, fn ($status) => in_array($status, ['diferencia', 'desactualizado'], true))) > 0) $summary['enRevision']++;
        }
        $summary['porMoneda'] = array_values($summary['porMoneda']);
        // La conciliación contable usa otra conexión y puede ser costosa. Se
        // solicita de forma explícita para no bloquear el informe operativo.
        $daily = $includeAccounting && $agencia === null ? $this->accounting->dailySummary($fecha) : null;
        return ['fecha' => $fecha, 'agencia' => $agencia, 'generadoEn' => date('Y-m-d H:i:s'),
            'huellaDatos' => hash('sha256', json_encode([$fecha, $agencia, $summary, $rows, $daily], JSON_UNESCAPED_UNICODE)),
            'resumen' => $summary, 'conciliacionContable' => $daily, 'cajeros' => $cashiers, 'cajas' => $rows];
    }

    private function groupCashiers(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) $row['login'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'clave' => $key,
                    'idAgencia' => $row['idAgencia'],
                    'agencia' => $row['agencia'],
                    'numCaja' => $row['numCaja'],
                    'numerosCaja' => [$row['numCaja']],
                    'cajero' => $row['cajero'],
                    'login' => $row['login'],
                    'estadoCaja' => $row['estadoCaja'],
                    'horaApertura' => $row['horaApertura'],
                    'horaCierre' => $row['horaCierre'],
                    'estado' => 'conciliada',
                    'monedas' => [],
                ];
            }
            if (!in_array($row['numCaja'], $groups[$key]['numerosCaja'], true)) $groups[$key]['numerosCaja'][] = $row['numCaja'];
            $groups[$key]['monedas'][] = $row;
        }

        $priority = ['conciliada' => 0, 'pendiente_conteo' => 1, 'pendiente_cierre' => 2, 'desactualizado' => 3, 'diferencia' => 4];
        foreach ($groups as &$group) {
            sort($group['numerosCaja']);
            foreach ($group['monedas'] as $currency) {
                if (($priority[$currency['estado']] ?? 0) > ($priority[$group['estado']] ?? 0)) $group['estado'] = $currency['estado'];
            }
        }
        unset($group);
        return array_values($groups);
    }

    public function detail(string $fecha, int $idCaja, ?int $agencia = null): ?array
    {
        $report = $this->report($fecha, $agencia, false);
        $row = null;
        foreach ($report['cajas'] as $candidate) if ($candidate['idCaja'] === $idCaja) { $row = $candidate; break; }
        if ($row === null) return null;
        return $this->detailForRow($fecha, $row);
    }

    private function detailForRow(string $fecha, array $row): array
    {
        $idCaja = $row['idCaja'];
        $declared = $this->pos->arqueo($row['numCaja'], $row['idAgencia'], $fecha, 'cierre');
        $counts = RawConnection::select('SELECT * FROM control_arqueos_caja WHERE fecha = :fecha AND id_caja = :id ORDER BY id DESC', [':fecha' => $fecha, ':id' => $idCaja]);
        $followups = RawConnection::select('SELECT * FROM control_arqueos_seguimiento WHERE fecha = :fecha AND id_caja = :id ORDER BY id DESC', [':fecha' => $fecha, ':id' => $idCaja]);
        foreach ($counts as &$count) {
            $count['id'] = (int) $count['id'];
            $count['total_contado'] = (float) $count['total_contado'];
            $count['items'] = json_decode($count['detalle_json'], true) ?: [];
            unset($count['detalle_json']);
        }
        return [
            'caja' => $row,
            'arqueoDeclarado' => $declared,
            'historialConteos' => $counts,
            'historialSeguimiento' => $followups,
            'historialSaldos' => $this->pos->saldoHistory($idCaja, $fecha),
            'movimientos' => $this->pos->movements($row['numCaja'], $row['idAgencia'], $fecha),
        ];
    }

    private function latestCounts(string $fecha, ?int $agencia): array
    {
        $sql = 'SELECT x.*, v.versiones FROM control_arqueos_caja x JOIN (SELECT id_caja, MAX(id) AS latest_id, COUNT(*) AS versiones FROM control_arqueos_caja WHERE fecha = :fecha GROUP BY id_caja) v ON x.id = v.latest_id JOIN caja c ON c.id_caja = x.id_caja WHERE (:agencia IS NULL OR c.id_agencia = :agencia)';
        $rows = RawConnection::select($sql, [':fecha' => $fecha, ':agencia' => $agencia]);
        $map = [];
        foreach ($rows as $row) $map[(int) $row['id_caja']] = $row;
        return $map;
    }

    public function recordFollowup(string $fecha, int $idCaja, array $input, string $user): array
    {
        $exists = false;
        foreach ($this->report($fecha)['cajas'] as $row) if ($row['idCaja'] === $idCaja) { $exists = true; break; }
        if (!$exists) throw new \InvalidArgumentException('Caja no encontrada para la fecha.');
        RawConnection::execute('INSERT INTO control_arqueos_seguimiento (fecha,id_caja,estado,responsable,fecha_compromiso,observacion,registrado_por) VALUES (:fecha,:id,:estado,:responsable,:compromiso,:observacion,:usuario)', [
            ':fecha' => $fecha, ':id' => $idCaja, ':estado' => $input['estado'], ':responsable' => trim($input['responsable']),
            ':compromiso' => $input['fechaCompromiso'] ?? null, ':observacion' => trim($input['observacion']), ':usuario' => $user,
        ]);
        return ['id' => RawConnection::lastInsertId()];
    }

    private function latestFollowups(string $fecha, ?int $agencia): array
    {
        $sql = 'SELECT x.* FROM control_arqueos_seguimiento x JOIN (SELECT id_caja,MAX(id) AS latest_id FROM control_arqueos_seguimiento WHERE fecha = :fecha GROUP BY id_caja) v ON x.id = v.latest_id JOIN caja c ON c.id_caja = x.id_caja WHERE (:agencia IS NULL OR c.id_agencia = :agencia)';
        $map = [];
        foreach (RawConnection::select($sql, [':fecha' => $fecha, ':agencia' => $agencia]) as $row) $map[(int) $row['id_caja']] = $row;
        return $map;
    }

    private function fallas(string $fecha, ?int $agencia): array
    {
        $sql = 'SELECT fc.id_caja,fc.diferencia,fc.estado,fc.glosa FROM falla_caja fc JOIN caja c ON c.id_caja = fc.id_caja WHERE fc.fecha = :fecha AND (:agencia IS NULL OR c.id_agencia = :agencia)';
        $map = [];
        foreach (RawConnection::select($sql, [':fecha' => $fecha, ':agencia' => $agencia]) as $row) $map[(int) $row['id_caja']] = ['diferencia' => (float) $row['diferencia'], 'estado' => $row['estado'], 'glosa' => $row['glosa']];
        return $map;
    }

    public function pdf(string $fecha, ?int $agencia = null, string $generatedBy = ''): string
    {
        // Dompdf mantiene en memoria el árbol completo del documento. Un día
        // con varias monedas por cajero puede producir más de cien actas aun
        // cuando solo hayan trabajado pocos cajeros. El límite PHP por defecto
        // (128 MB) no alcanza para ese documento y terminaba en HTTP 500.
        if ($this->memoryLimitBytes((string) ini_get('memory_limit')) < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $report = $this->report($fecha, $agencia, false);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $n = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.');
        $s = $report['resumen'];
        $cashiers = $report['cajeros'];
        $agencies = array_values(array_unique(array_column($cashiers, 'agencia')));
        $agencyLabel = count($agencies) === 1 ? $agencies[0] : 'TODAS LAS AGENCIAS';
        $logoPath = public_path('images/gambarte-logo.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : '';
        $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $cut = \DateTime::createFromFormat('Y-m-d', $fecha);
        $longDate = $cut ? $cut->format('j').' de '.$months[(int) $cut->format('n')].' de '.$cut->format('Y') : $fecha;
        $letterhead = function (string $title, string $meta) use ($e, $logo): string {
            return '<table class="letterhead"><tr><td class="logo-cell">'.($logo !== '' ? '<img src="'.$logo.'" alt="Gambarte">' : '').'</td><td class="header-space"></td><td class="page-label">ARQUEO DE CAJAS</td></tr></table><div class="report-title">'.$e($title).'</div><div class="meta">'.$meta.'</div>';
        };
        $html = '<html><head><meta charset="utf-8"><style>
            @page{size:letter landscape;margin:10mm 12mm}*{box-sizing:border-box}
            body{font-family:"Courier New",Courier,monospace;font-size:9px;color:#000;margin:0;padding:0}
            .letterhead{width:100%;border-collapse:collapse;margin:0 0 2px}.letterhead td{border:0;padding:0;vertical-align:top}
            .logo-cell{width:28%;text-align:left}.logo-cell img{width:200px;height:auto}.header-space{width:48%}.page-label{width:24%;text-align:right;font-size:9px;white-space:nowrap}
            .report-title{font-size:12px;font-weight:700;text-align:center;margin:2px 0;text-transform:uppercase}.meta{font-size:9px;margin:4px 0 8px;line-height:1.5}.meta b{font-weight:700}
            .section-label{font-size:10px;font-weight:700;margin:11px 0 4px;text-transform:uppercase}
            table.data{width:100%;border-collapse:collapse;margin:0 0 7px;page-break-inside:auto}table.data tr{page-break-inside:avoid}
            table.data th{background:#e8e8e8;color:#000;font-size:8px;font-weight:700;text-transform:uppercase;padding:4px 5px;border:1px solid #999;text-align:center}
            table.data td{padding:3px 5px;border:1px solid #ccc;font-size:8.5px;vertical-align:top}.r{text-align:right}.c{text-align:center}.small{font-size:8px;color:#333;line-height:1.4}.summary{background:#f0f0f0;border:1px solid #bbb;padding:6px 8px;margin:6px 0 8px}
            .page{page-break-before:always}.sign{width:100%;border-collapse:collapse;margin-top:28px}.sign td{border:0;border-top:1px solid #777;width:42%;padding-top:4px;font-size:9px}.sign .gap{width:16%;border:0}
            .gen-footer{margin-top:18px;font-size:8px}.gen-footer table{width:100%;border-collapse:collapse}.gen-footer td{border:0;padding:0}.gen-footer .right{text-align:right;font-weight:700}
        </style></head><body>';
        $summaryMeta = '<b>Agencia:</b> '.$e($agencyLabel).' &nbsp;&nbsp; <b>Usuario:</b> '.$e($generatedBy !== '' ? $generatedBy : 'SISTEMAS').' &nbsp;&nbsp; '.$e($longDate).' - '.$e(substr($report['generadoEn'], 11)).'<br><b>Ejercicio:</b> '.$e(substr($fecha, 0, 4)).' &nbsp;&nbsp; <b>Fecha de corte:</b> '.$e($fecha);
        $html .= $letterhead('INFORME DE ARQUEO Y CONCILIACIÓN DE CAJAS', $summaryMeta);
        $html .= '<div class="summary"><b>Cajas previstas:</b> '.$s['previstas'].' &nbsp;&nbsp; <b>Arqueadas:</b> '.$s['arqueadas'].' &nbsp;&nbsp; <b>Pendientes:</b> '.$s['pendientes'].' &nbsp;&nbsp; <b>Sin diferencia:</b> '.$s['sinDiferencia'].' &nbsp;&nbsp; <b>En revisión:</b> '.$s['enRevision'].'</div>';
        $html .= '<div class="section-label">OBJETO Y ALCANCE</div><p>Se comparó el saldo teórico con el cierre declarado registrado automáticamente por el sistema. La diferencia se calcula como saldo teórico menos cierre declarado. Se aplica una tolerancia de +/- '.$n(self::DIFFERENCE_TOLERANCE).' por moneda; solo una diferencia absoluta mayor a ese valor genera faltante o sobrante. Los importes no se compensan entre monedas ni entre cajeros.</p><div class="section-label">RELACIÓN DE CAJAS POR CAJERO</div><table class="data"><tr><th>Agencia</th><th>Cajero</th><th>Monedas</th><th>Apertura</th><th>Cierre</th><th>Estado general</th></tr>';
        foreach ($cashiers as $cashier) $html .= '<tr><td>'.$e($cashier['agencia']).'</td><td>'.$e($cashier['cajero']).'</td><td class="c">'.count($cashier['monedas']).'</td><td class="c">'.$e($cashier['horaApertura'] ?? '-').'</td><td class="c">'.$e($cashier['horaCierre'] ?? '-').'</td><td>'.$e($cashier['estado']).'</td></tr>';
        $html .= '</table><p class="small">Cada cajero representa una caja lógica. Las monedas se detallan dentro de una única acta y conservan su trazabilidad digital.</p>';
        if ($report['conciliacionContable'] !== null) {
            $html .= '<div class="section-label">CONCILIACIÓN GLOBAL CON CONTABILIDAD</div><table class="data"><tr><th>Moneda</th><th>Cajas</th><th>Bóveda</th><th>Total en BOB</th><th>Contabilidad BOB</th><th>Diferencia BOB</th></tr>';
            foreach ($report['conciliacionContable']['currencies'] as $c) $html .= '<tr><td>'.$e($c['moneda']).'</td><td class="r">'.$n($c['totalCajas']).'</td><td class="r">'.$n($c['totalBoveda']).'</td><td class="r">'.$n($c['monedasEnBob']).'</td><td class="r">'.$n($c['contabilidad']).'</td><td class="r">'.$n($c['diferencia']).'</td></tr>';
            $html .= '</table>';
        }
        $html .= '<div class="section-label">HALLAZGOS Y ACCIONES</div><table class="data"><tr><th>Agencia</th><th>Cajero</th><th>Monedas observadas</th><th>Monto de diferencia</th><th>Estado general</th></tr>';
        foreach ($cashiers as $cashier) {
            if ($cashier['estado'] === 'conciliada') continue;
            $observed = [];
            $amounts = [];
            foreach ($cashier['monedas'] as $currency) {
                if ($currency['estado'] === 'conciliada') continue;
                $observed[] = $currency['moneda'].' ('.$currency['estado'].')';
                $amounts[] = $currency['moneda'].': '.$n($currency['diferenciaFisica']);
            }
            $html .= '<tr><td>'.$e($cashier['agencia']).'</td><td>'.$e($cashier['cajero']).'</td><td>'.$e(implode(', ', $observed)).'</td><td class="r">'.$e(implode(' · ', $amounts)).'</td><td>'.$e($cashier['estado']).'</td></tr>';
        }
        $html .= '</table><div class="section-label">CONCLUSIÓN Y SEGUIMIENTO</div><p>Se identificaron '.$s['conFaltante'].' caja(s) con faltante y '.$s['conSobrante'].' con sobrante. Las cajas pendientes y las diferencias requieren revisión antes de su regularización.</p><p class="small">Huella de datos SHA-256: '.$report['huellaDatos'].'</p><p class="small">Este PDF se genera sin firmas electrónicas. La firma manuscrita del cajero y del revisor formaliza la revisión del cierre.</p><div class="gen-footer"><table><tr><td>Generado: '.$e($report['generadoEn']).' - '.$e($generatedBy !== '' ? $generatedBy : 'SISTEMAS').'</td><td class="right">CGR - Sistema de Gestión y Reportes</td></tr></table></div>';
        foreach ($cashiers as $cashier) {
            $actMeta = '<b>Agencia:</b> '.$e($cashier['agencia']).' &nbsp;&nbsp; <b>Cajero:</b> '.$e($cashier['cajero']).' ('.$e(strtoupper($cashier['login'])).') &nbsp;&nbsp; <b>Caja:</b> '.$e(implode(', ', $cashier['numerosCaja'])).'<br><b>Fecha de corte:</b> '.$e($fecha).' &nbsp;&nbsp; <b>Monedas:</b> '.count($cashier['monedas']);
            $html .= '<div class="page">'.$letterhead('ACTA DE ARQUEO DE CAJA', $actMeta);
            $html .= '<div class="section-label">DETALLE DE MONEDAS</div><table class="data"><tr><th>Moneda</th><th>Apertura</th><th>Ingresos</th><th>Egresos</th><th>Teórico</th><th>Declarado</th><th>Contado automático</th><th>Diferencia</th><th>Estado</th></tr>';
            foreach ($cashier['monedas'] as $currency) {
                $html .= '<tr><td>'.$e($currency['moneda']).'</td><td class="r">'.$n($currency['apertura']).'</td><td class="r">'.$n($currency['totalIngresos']).'</td><td class="r">'.$n($currency['totalEgresos']).'</td><td class="r">'.$n($currency['saldoTeorico']).'</td><td class="r">'.$n($currency['cierreDeclarado']).'</td><td class="r">'.$n($currency['conteoIndependiente']).'</td><td class="r">'.$n($currency['diferenciaFisica']).'</td><td>'.$e($currency['estado']).'</td></tr>';
            }
            $html .= '</table><p><b>Apertura:</b> '.$e($cashier['horaApertura'] ?? '-').' &nbsp;&nbsp; <b>Cierre:</b> '.$e($cashier['horaCierre'] ?? '-').' &nbsp;&nbsp; <b>Estado general:</b> '.$e($cashier['estado']).'</p>';
            $html .= '<p class="small">Los movimientos, comprobantes, saldos y seguimientos permanecen disponibles por moneda en la trazabilidad digital.</p><table class="sign"><tr><td>Cajero</td><td class="gap"></td><td>Revisor</td></tr></table><div class="gen-footer"><table><tr><td>Generado: '.$e($report['generadoEn']).'</td><td class="right">CGR - Sistema de Gestión y Reportes</td></tr></table></div></div>';
        }
        $html .= '</body></html>';
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter', 'landscape');
        $pdf->render();
        $font = $pdf->getFontMetrics()->getFont('Courier', 'normal');
        $pdf->getCanvas()->page_text(690, 34, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0, 0, 0]);
        return $pdf->output();
    }

    private function memoryLimitBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') return PHP_INT_MAX;
        if ($value === '') return 0;

        $unit = strtolower(substr($value, -1));
        $bytes = (int) $value;
        if ($unit === 'g') return $bytes * 1024 * 1024 * 1024;
        if ($unit === 'm') return $bytes * 1024 * 1024;
        if ($unit === 'k') return $bytes * 1024;
        return $bytes;
    }
}
