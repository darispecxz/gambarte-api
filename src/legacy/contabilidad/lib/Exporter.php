<?php
/**
 * Exporter.php — Genera Excel (.xlsx) y PDF para los reportes de contabilidad.
 *
 * Reutiliza Auditoria\Xlsx (sin dependencias, PHP 5.6 compatible)
 * y mPDF para PDF.
 */

namespace Contabilidad;

require_once __DIR__ . '/../../auditoria/lib/Xlsx.php';

use Auditoria\Xlsx;

class Exporter
{
    // ═══════════════════════════════════════════════════════════════════
    //  Excel
    // ═══════════════════════════════════════════════════════════════════

    public static function dailySummaryXlsx($report)
    {
        $wb = new Xlsx();
        $sh = $wb->addSheet('Resumen Diario');
        $sh->setCols(array(14, 18, 18, 18, 14, 18, 18, 16));

        $sh->addTitle('Resumen Diario — ' . $report['fecha'], 8);
        $sh->addBlank();
        $sh->addHeader(array('Moneda', 'Total Cajas', 'Total Bóveda', 'Total C+B', 'TC Oficial', 'En BOB', 'Contabilidad', 'Diferencia'));

        $fmts = array('text', 'money', 'money', 'money', 'money', 'money', 'money', 'money');
        foreach ($report['currencies'] as $c) {
            $sh->addRow(array(
                $c['moneda'],
                $c['totalCajas'],
                $c['totalBoveda'],
                $c['totalCajasBoveda'],
                $c['tipoCambioOficial'],
                $c['monedasEnBob'],
                $c['contabilidad'],
                $c['diferencia']
            ), $fmts);
        }

        $sh->addBlank();
        $sh->addRow(array('', '', '', '', '', '', 'GRAN TOTAL', $report['grandTotalBob']), $fmts);

        return $wb->toString();
    }

    public static function cashierDetailsXlsx($report)
    {
        $wb = new Xlsx();
        $sh = $wb->addSheet('Arqueos de Cajero');
        $sh->setCols(array(18, 20, 12, 12, 16, 16, 16, 16, 16));

        $sh->addTitle('Arqueos de Cajero — ' . $report['fecha'], 9);
        $sh->addBlank();

        foreach ($report['cashiers'] as $cashier) {
            $sh->addTitle($cashier['cajero'] . ' — ' . $cashier['agencia'], 9, Xlsx::S_SUBTITLE);

            foreach ($cashier['currencies'] as $cur) {
                $sh->addBlank();
                $sh->addHeader(array(
                    $cur['moneda'] . ' (Caja #' . $cur['numCaja'] . ')',
                    '', '', '', '', '', '', '', ''
                ));
                $sh->addHeader(array('Categoría', '#', 'Monto'));

                $fmts = array('text', 'int', 'money');

                // Ingresos
                $sh->addRow(array('INGRESOS', '', ''), array('text', 'text', 'text'));
                $sh->addRow(array('Apertura', '—', $cur['aperturaCaja']), $fmts);
                $sh->addRow(array('Cambio Moneda', $cur['cambioMonedaIngreso']['count'], $cur['cambioMonedaIngreso']['amount']), $fmts);
                $sh->addRow(array('Envío Giro', $cur['envioGiro']['count'], $cur['envioGiro']['amount']), $fmts);
                $sh->addRow(array('Envío Remesa', $cur['envioRemesa']['count'], $cur['envioRemesa']['amount']), $fmts);
                $sh->addRow(array('Traspaso de Caja', $cur['traspasoDeCaja']['count'], $cur['traspasoDeCaja']['amount']), $fmts);
                $sh->addRow(array('Cobro Cheque', $cur['cobroCheque']['count'], $cur['cobroCheque']['amount']), $fmts);
                $sh->addRow(array('Ingreso Controlado', $cur['ingresoControlado']['count'], $cur['ingresoControlado']['amount']), $fmts);
                $sh->addRow(array('Transf. Bóveda', $cur['transferenciaBovedaIngreso']['count'], $cur['transferenciaBovedaIngreso']['amount']), $fmts);
                $sh->addRow(array('Total Ingresos', '', $cur['totalIngreso']), array('text', 'text', 'money'));

                // Egresos
                $sh->addBlank();
                $sh->addRow(array('EGRESOS', '', ''), array('text', 'text', 'text'));
                $sh->addRow(array('Cambio Moneda', $cur['cambioMonedaEgreso']['count'], $cur['cambioMonedaEgreso']['amount']), $fmts);
                $sh->addRow(array('Pago Giro', $cur['pagoGiro']['count'], $cur['pagoGiro']['amount']), $fmts);
                $sh->addRow(array('Devolución Giro', '—', $cur['devolucionGiro']), $fmts);
                $sh->addRow(array('Pago Remesa', $cur['pagoRemesa']['count'], $cur['pagoRemesa']['amount']), $fmts);
                $sh->addRow(array('Devolución Remesa', '—', $cur['devolucionRemesa']), $fmts);
                $sh->addRow(array('Traspaso a Caja', $cur['traspasoACaja']['count'], $cur['traspasoACaja']['amount']), $fmts);
                $sh->addRow(array('Depósito Bancario', $cur['depositoBancario']['count'], $cur['depositoBancario']['amount']), $fmts);
                $sh->addRow(array('Egreso Controlado', $cur['egresoControlado']['count'], $cur['egresoControlado']['amount']), $fmts);
                $sh->addRow(array('Total Egresos', '', $cur['totalEgreso']), array('text', 'text', 'money'));

                // Resumen
                $sh->addBlank();
                $sh->addRow(array('Saldo Teórico', '', $cur['totalSaldo']), array('text', 'text', 'money'));
                $sh->addRow(array('Cierre Caja', '', $cur['cierreCaja']), array('text', 'text', 'money'));
                $sh->addRow(array('Diferencia', '', $cur['diferencia']), array('text', 'text', 'money'));

                if (isset($cashier['fallasRegistradas'][$cur['idCaja']])) {
                    $sh->addRow(array('Falla Registrada', '', $cashier['fallasRegistradas'][$cur['idCaja']]), array('text', 'text', 'money'));
                }
            }
            $sh->addBlank();
        }

        return $wb->toString();
    }

    public static function movementSummaryXlsx($report)
    {
        $wb = new Xlsx();
        $sh = $wb->addSheet('Resumen Movimientos');
        $sh->setCols(array(14, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16));

        $sh->addTitle('Resumen de Movimientos — ' . $report['fecha'], 11);
        $sh->addBlank();
        $sh->addHeader(array(
            'Moneda', 'Apertura', 'Cambio', 'Envío Giro', 'Pago Giro',
            'Envío Remesa', 'Pago Remesa', 'Egresos', 'Transf.', 'Fallas', 'Cierre'
        ));

        $fmts = array('text', 'money', 'money', 'money', 'money', 'money', 'money', 'money', 'money', 'money', 'money');
        foreach ($report['currencies'] as $c) {
            $sh->addRow(array(
                $c['moneda'], $c['aperturaCaja'], $c['cambioMoneda'], $c['envioGiro'], $c['pagoGiro'],
                $c['envioRemesa'], $c['pagoRemesa'], $c['egresos'], $c['transferencias'], $c['fallasCaja'], $c['cierreCaja']
            ), $fmts);
        }

        return $wb->toString();
    }

    public static function agencyClosingsXlsx($report)
    {
        $wb = new Xlsx();
        $sh = $wb->addSheet('Cierres Agencia');

        $monedas = array_keys($report['totals']);
        sort($monedas);

        $colWidths = array(28);
        foreach ($monedas as $m) { $colWidths[] = 16; }
        $sh->setCols($colWidths);

        $sh->addTitle('Cierres por Agencia — ' . $report['fecha'], 1 + count($monedas));
        $sh->addBlank();

        $headers = array('Agencia');
        foreach ($monedas as $m) { $headers[] = $m; }
        $sh->addHeader($headers);

        $fmts = array('text');
        foreach ($monedas as $m) { $fmts[] = 'money'; }

        foreach ($report['agencies'] as $ag) {
            $row = array($ag['agencia']);
            foreach ($monedas as $m) {
                $row[] = isset($ag['currencyClosings'][$m]) ? $ag['currencyClosings'][$m] : 0;
            }
            $sh->addRow($row, $fmts);
        }

        // Totals row
        $totRow = array('TOTALES');
        foreach ($monedas as $m) {
            $totRow[] = isset($report['totals'][$m]) ? $report['totals'][$m] : 0;
        }
        $sh->addRow($totRow, $fmts);

        return $wb->toString();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PDF (via mPDF)
    // ═══════════════════════════════════════════════════════════════════

    private static function css()
    {
        return '
            body { font-family: dejavusanscondensed, sans-serif; font-size: 9pt; color: #1a2540; }
            h1 { font-size: 14pt; margin: 0 0 4px; color: #1a2540; }
            h2 { font-size: 11pt; margin: 14px 0 4px; color: #e67e22; }
            .sub { font-size: 9pt; color: #5a6a88; margin-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
            th { background: #1d4ed8; color: #fff; font-size: 8pt; padding: 5px 6px; text-align: left; }
            td { padding: 4px 6px; border-bottom: 1px solid #dde3ee; font-size: 8pt; }
            .r { text-align: right; }
            .tot td { background: #f3f4f8; font-weight: bold; border-top: 2px solid #1d4ed8; }
            .warn { color: #c0392b; font-weight: bold; }
            .ok { color: #27ae60; }
            .section-title { background: #f3f4f8; padding: 6px 8px; font-weight: bold; font-size: 9pt; margin: 10px 0 4px; border-left: 3px solid #e67e22; }
        ';
    }

    private static function renderPdf($html, $title)
    {
        require_once __DIR__ . '/../../../mpdf/mpdf.php';
        $mpdf = new \mPDF('utf-8', 'Letter', 9, 'dejavusanscondensed', 12, 12, 12, 14, 8, 8);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('Gambarte S.R.L.');
        $mpdf->WriteHTML('<style>' . self::css() . '</style>' . $html);
        return $mpdf->Output('', 'S');
    }

    private static function num($v, $decimals = 2)
    {
        return number_format((float)$v, $decimals, '.', ',');
    }

    public static function dailySummaryPdf($report)
    {
        $html = '<h1>Resumen Diario</h1><div class="sub">' . $report['fecha'] . '</div>';
        $html .= '<table><thead><tr><th>Moneda</th><th class="r">Total Cajas</th><th class="r">Total Bóveda</th>'
               . '<th class="r">Total C+B</th><th class="r">TC Oficial</th><th class="r">En BOB</th>'
               . '<th class="r">Contabilidad</th><th class="r">Diferencia</th></tr></thead><tbody>';

        foreach ($report['currencies'] as $c) {
            $difClass = ($c['diferencia'] !== null && $c['diferencia'] != 0) ? ' class="warn"' : '';
            $contaVal = ($c['contabilidad'] !== null) ? self::num($c['contabilidad']) : '—';
            $difVal = ($c['diferencia'] !== null) ? self::num($c['diferencia']) : '—';
            $html .= '<tr><td><strong>' . $c['moneda'] . '</strong></td>'
                    . '<td class="r">' . self::num($c['totalCajas']) . '</td>'
                    . '<td class="r">' . self::num($c['totalBoveda']) . '</td>'
                    . '<td class="r">' . self::num($c['totalCajasBoveda']) . '</td>'
                    . '<td class="r">' . self::num($c['tipoCambioOficial'], 4) . '</td>'
                    . '<td class="r"><strong>' . self::num($c['monedasEnBob']) . '</strong></td>'
                    . '<td class="r">' . $contaVal . '</td>'
                    . '<td class="r"' . $difClass . '>' . $difVal . '</td></tr>';
        }

        $html .= '<tr class="tot"><td colspan="7" class="r">GRAN TOTAL (BOB)</td>'
               . '<td class="r">' . self::num($report['grandTotalBob']) . '</td></tr>';
        $html .= '</tbody></table>';

        return self::renderPdf($html, 'Resumen Diario — ' . $report['fecha']);
    }

    public static function cashierDetailsPdf($report)
    {
        $html = '<h1>Arqueos de Cajero</h1><div class="sub">' . $report['fecha']
              . ' — ' . $report['totalDiscrepancies'] . ' discrepancia(s), '
              . count($report['cashiers']) . ' cajero(s)</div>';

        foreach ($report['cashiers'] as $cashier) {
            $html .= '<h2>' . htmlspecialchars($cashier['cajero']) . ' — ' . htmlspecialchars($cashier['agencia']) . '</h2>';

            foreach ($cashier['currencies'] as $cur) {
                $status = $cur['diferencia'] != 0
                    ? '<span class="warn">Discrepancia: ' . self::num($cur['diferencia']) . '</span>'
                    : '<span class="ok">OK</span>';
                $html .= '<div class="section-title">' . $cur['moneda'] . ' — Caja #' . $cur['numCaja'] . ' ' . $status . '</div>';

                $html .= '<table><thead><tr><th>Ingresos</th><th class="r">#</th><th class="r">Monto</th>'
                        . '<th>Egresos</th><th class="r">#</th><th class="r">Monto</th></tr></thead><tbody>';

                $ingresos = array(
                    array('Apertura', '—', self::num($cur['aperturaCaja'])),
                    array('Cambio Moneda', $cur['cambioMonedaIngreso']['count'], self::num($cur['cambioMonedaIngreso']['amount'])),
                    array('Envío Giro', $cur['envioGiro']['count'], self::num($cur['envioGiro']['amount'])),
                    array('Envío Remesa', $cur['envioRemesa']['count'], self::num($cur['envioRemesa']['amount'])),
                    array('Traspaso de Caja', $cur['traspasoDeCaja']['count'], self::num($cur['traspasoDeCaja']['amount'])),
                    array('Cobro Cheque', $cur['cobroCheque']['count'], self::num($cur['cobroCheque']['amount'])),
                    array('Ingreso Controlado', $cur['ingresoControlado']['count'], self::num($cur['ingresoControlado']['amount'])),
                    array('Transf. Bóveda', $cur['transferenciaBovedaIngreso']['count'], self::num($cur['transferenciaBovedaIngreso']['amount'])),
                );

                $egresos = array(
                    array('Cambio Moneda', $cur['cambioMonedaEgreso']['count'], self::num($cur['cambioMonedaEgreso']['amount'])),
                    array('Pago Giro', $cur['pagoGiro']['count'], self::num($cur['pagoGiro']['amount'])),
                    array('Devolución Giro', '—', self::num($cur['devolucionGiro'])),
                    array('Pago Remesa', $cur['pagoRemesa']['count'], self::num($cur['pagoRemesa']['amount'])),
                    array('Devolución Remesa', '—', self::num($cur['devolucionRemesa'])),
                    array('Traspaso a Caja', $cur['traspasoACaja']['count'], self::num($cur['traspasoACaja']['amount'])),
                    array('Depósito Bancario', $cur['depositoBancario']['count'], self::num($cur['depositoBancario']['amount'])),
                    array('Egreso Controlado', $cur['egresoControlado']['count'], self::num($cur['egresoControlado']['amount'])),
                );

                for ($i = 0; $i < 8; $i++) {
                    $html .= '<tr>'
                           . '<td>' . $ingresos[$i][0] . '</td><td class="r">' . $ingresos[$i][1] . '</td><td class="r">' . $ingresos[$i][2] . '</td>'
                           . '<td>' . $egresos[$i][0] . '</td><td class="r">' . $egresos[$i][1] . '</td><td class="r">' . $egresos[$i][2] . '</td>'
                           . '</tr>';
                }

                $html .= '<tr class="tot"><td>Total Ingresos</td><td></td><td class="r">' . self::num($cur['totalIngreso']) . '</td>'
                        . '<td>Total Egresos</td><td></td><td class="r">' . self::num($cur['totalEgreso']) . '</td></tr>';
                $html .= '</tbody></table>';

                $html .= '<table><tbody>'
                        . '<tr><td><strong>Saldo Teórico</strong></td><td class="r"><strong>' . self::num($cur['totalSaldo']) . '</strong></td></tr>'
                        . '<tr><td><strong>Cierre Caja</strong></td><td class="r"><strong>' . self::num($cur['cierreCaja']) . '</strong></td></tr>'
                        . '<tr><td><strong>Diferencia</strong></td><td class="r' . ($cur['diferencia'] != 0 ? ' warn' : '') . '"><strong>' . self::num($cur['diferencia']) . '</strong></td></tr>';

                if (isset($cashier['fallasRegistradas'][$cur['idCaja']])) {
                    $html .= '<tr><td class="warn">Falla Registrada</td><td class="r warn">' . self::num($cashier['fallasRegistradas'][$cur['idCaja']]) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        }

        if (empty($report['cashiers'])) {
            $html .= '<p>Sin datos de cajeros para esta fecha.</p>';
        }

        return self::renderPdf($html, 'Arqueos de Cajero — ' . $report['fecha']);
    }

    public static function movementSummaryPdf($report)
    {
        $html = '<h1>Resumen de Movimientos</h1><div class="sub">' . $report['fecha'] . '</div>';
        $html .= '<table><thead><tr><th>Moneda</th><th class="r">Apertura</th><th class="r">Cambio</th>'
               . '<th class="r">Envío Giro</th><th class="r">Pago Giro</th><th class="r">Envío Remesa</th>'
               . '<th class="r">Pago Remesa</th><th class="r">Egresos</th><th class="r">Transf.</th>'
               . '<th class="r">Fallas</th><th class="r">Cierre</th></tr></thead><tbody>';

        foreach ($report['currencies'] as $c) {
            $html .= '<tr><td><strong>' . $c['moneda'] . '</strong></td>'
                    . '<td class="r">' . self::num($c['aperturaCaja']) . '</td>'
                    . '<td class="r">' . self::num($c['cambioMoneda']) . '</td>'
                    . '<td class="r">' . self::num($c['envioGiro']) . '</td>'
                    . '<td class="r">' . self::num($c['pagoGiro']) . '</td>'
                    . '<td class="r">' . self::num($c['envioRemesa']) . '</td>'
                    . '<td class="r">' . self::num($c['pagoRemesa']) . '</td>'
                    . '<td class="r">' . self::num($c['egresos']) . '</td>'
                    . '<td class="r">' . self::num($c['transferencias']) . '</td>'
                    . '<td class="r">' . self::num($c['fallasCaja']) . '</td>'
                    . '<td class="r"><strong>' . self::num($c['cierreCaja']) . '</strong></td></tr>';
        }

        $html .= '</tbody></table>';
        return self::renderPdf($html, 'Resumen de Movimientos — ' . $report['fecha']);
    }

    public static function agencyClosingsPdf($report)
    {
        $monedas = array_keys($report['totals']);
        sort($monedas);

        $html = '<h1>Cierres por Agencia</h1><div class="sub">' . $report['fecha'] . '</div>';
        $html .= '<table><thead><tr><th>Agencia</th>';
        foreach ($monedas as $m) { $html .= '<th class="r">' . $m . '</th>'; }
        $html .= '</tr></thead><tbody>';

        foreach ($report['agencies'] as $ag) {
            $html .= '<tr><td><strong>' . htmlspecialchars($ag['agencia']) . '</strong></td>';
            foreach ($monedas as $m) {
                $val = isset($ag['currencyClosings'][$m]) ? $ag['currencyClosings'][$m] : 0;
                $html .= '<td class="r">' . self::num($val) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '<tr class="tot"><td><strong>TOTALES</strong></td>';
        foreach ($monedas as $m) {
            $html .= '<td class="r"><strong>' . self::num(isset($report['totals'][$m]) ? $report['totals'][$m] : 0) . '</strong></td>';
        }
        $html .= '</tr></tbody></table>';

        if (empty($report['agencies'])) {
            $html .= '<p>Sin cierres para esta fecha.</p>';
        }

        return self::renderPdf($html, 'Cierres por Agencia — ' . $report['fecha']);
    }
}
