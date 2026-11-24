<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Reporte formal de arqueo, construido exclusivamente con datos persistidos. */
class CajeroCajaReportService
{
    public function pdf(int $idCaja, int $usuario, int $agencia, string $tipo): array
    {
        $tipoArqueo = $tipo === 'cierre' ? 2 : 1;
        $sesion = DB::selectOne("SELECT s.*, c.num_caja, m.abreviatura moneda, a.descripcion agencia,
                TRIM(CONCAT(COALESCE(t.nom_trab, ''), ' ', COALESCE(t.ape_pat_trab, ''), ' ', COALESCE(t.ape_mat_trab, ''))) cajero_nombre
            FROM pos_sesion_caja s JOIN caja c ON c.id_caja=s.id_caja JOIN moneda m ON m.id_moneda=s.id_moneda
            JOIN agencia a ON a.id_agencia=s.id_agencia
            LEFT JOIN usuario u ON u.cod_usuario=s.cod_usuario LEFT JOIN trabajador t ON t.cod_trab=u.cod_trab
            WHERE s.id_caja=:c AND s.cod_usuario=:u AND s.id_agencia=:a AND s.fecha=CURDATE() LIMIT 1",
            [':c'=>$idCaja, ':u'=>$usuario, ':a'=>$agencia]);
        if (!$sesion) throw new \RuntimeException('No existe una sesión de caja para la fecha actual.');
        if ($tipo === 'cierre' && $sesion['estado'] !== 'cerrada') throw new \RuntimeException('La caja aún no fue cerrada.');
        $sesionesMoneda = DB::select("SELECT m.abreviatura moneda, s.saldo_apertura, s.saldo_cierre, s.saldo_esperado, s.diferencia
            FROM pos_sesion_caja s JOIN caja c ON c.id_caja=s.id_caja JOIN moneda m ON m.id_moneda=s.id_moneda
            WHERE c.num_caja=:n AND s.cod_usuario=:u AND s.id_agencia=:a AND s.fecha=CURDATE()
            ORDER BY m.abreviatura", [':n'=>$sesion['num_caja'], ':u'=>$usuario, ':a'=>$agencia]);

        $rows = DB::select("SELECT m.abreviatura moneda, co.valor, tc.descripcion tipo_corte, ag.descripcion agrupacion, ar.cantidad,
            (co.valor * atc.cantidad * ar.cantidad) subtotal
            FROM arqueo_caja ar JOIN corte co ON co.id_corte=ar.id_corte
            JOIN moneda m ON m.id_moneda=co.id_moneda
            JOIN agrupacion_tipo_corte atc ON atc.id_tipo_corte=ar.id_tipo_corte AND atc.id_agrupacion=ar.id_agrupacion
            JOIN tipo_corte tc ON tc.id_tipo_corte=ar.id_tipo_corte JOIN agrupacion ag ON ag.id_agrupacion=ar.id_agrupacion
            WHERE ar.id_agencia=:a AND ar.num_caja=:n AND DATE(ar.fecha)=CURDATE() AND ar.id_tipo_arqueo=:t
            ORDER BY co.valor DESC, ag.descripcion", [':a'=>$agencia, ':n'=>$sesion['num_caja'], ':t'=>$tipoArqueo]);
        $porMoneda = [];
        foreach ($rows as $r) $porMoneda[$r['moneda']][] = $r;
        $tablasArqueo = ''; $totalesArqueo = [];
        foreach ($porMoneda as $moneda => $filas) {
            $detalle = ''; $totalMoneda = 0;
            foreach ($filas as $r) {
                $totalMoneda += (float) $r['subtotal'];
                $detalle .= '<tr><td>'.number_format($r['valor'], 2).'</td><td>'.htmlspecialchars($r['tipo_corte']).'</td><td>'.htmlspecialchars($r['agrupacion']).'</td><td>'.(int) $r['cantidad'].'</td><td>'.number_format($r['subtotal'], 2).'</td></tr>';
            }
            $totalesArqueo[$moneda] = $totalMoneda;
            $monedaTexto = htmlspecialchars($moneda);
            $tablasArqueo .= "<div class='currency-title'>ARQUEO EN $monedaTexto</div><table class='arqueo'><thead><tr><th>Valor</th><th>Tipo</th><th>Marbete</th><th>Cantidad</th><th>Subtotal ($monedaTexto)</th></tr></thead><tbody>$detalle<tr class='total'><td colspan='4'>TOTAL ARQUEO $monedaTexto</td><td>".number_format($totalMoneda, 2)."</td></tr></tbody></table>";
        }
        if (!$tablasArqueo) $tablasArqueo = "<table class='arqueo'><tbody><tr><td class='empty'>Sin marbetes registrados</td></tr></tbody></table>";
        $titulo=$tipo === 'cierre' ? 'REPORTE DE CIERRE DE CAJA' : 'REPORTE DE APERTURA DE CAJA';
        $fechaHora = $tipo === 'cierre' ? $sesion['hora_cierre'] : $sesion['hora_apertura'];
        $hora = $fechaHora ? date('H:i:s', strtotime($fechaHora)) : '—';
        $resumenFilas = '';
        foreach ($sesionesMoneda as $sesionMoneda) {
            $moneda = $sesionMoneda['moneda'];
            $declarado = $tipo === 'cierre' ? (float) $sesionMoneda['saldo_cierre'] : (float) $sesionMoneda['saldo_apertura'];
            $resumenFilas .= '<tr><td><b>'.htmlspecialchars($moneda).'</b></td><td>'.number_format($declarado, 2).'</td><td>'.number_format($totalesArqueo[$moneda] ?? 0, 2).'</td>';
            if ($tipo === 'cierre') $resumenFilas .= '<td>'.number_format((float) $sesionMoneda['saldo_esperado'], 2).'</td><td>'.number_format((float) $sesionMoneda['diferencia'], 2).'</td>';
            $resumenFilas .= '</tr>';
        }
        $logoPath = public_path('images/gmbsrl.png');
        $logo = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : '';
        $logoHtml = $logo ? "<img src=\"$logo\" class=\"logo\" alt=\"Gambarte S.R.L.\">" : '';
        $firma = (new PosUsuarioPreferenciaService())->firmaDataUri($usuario);
        $firmaHtml = $firma ? "<div class=\"firma-wrap\"><img src=\"$firma\" class=\"firma\" alt=\"Firma digital del cajero\"></div>" : '';
        $cajero = trim((string) $sesion['cajero_nombre']) ?: 'Código '.$sesion['cod_usuario'];
        $columnasCierre = '<th>Saldo esperado</th><th>Diferencia</th>';
        $controlCierre = $tipo === 'cierre'
            ? "<table class='meta box'><thead><tr><th colspan='5'>RESUMEN DE CONTROL POR MONEDA</th></tr><tr><th>Moneda</th><th>Monto declarado</th><th>Total marbetes</th>$columnasCierre</tr></thead><tbody>$resumenFilas</tbody></table>"
            : '';
        $html="<html><head><style>@page{margin:22mm 15mm 20mm}body{font:10px DejaVu Sans;color:#334155}.head{min-height:42px;border-bottom:3px solid #94a3b8;padding-bottom:8px}.logo{height:38px;vertical-align:middle}.doc-kind{float:right;padding-top:12px;color:#64748b}.title{text-align:center;font-size:16px;font-weight:bold;color:#475569;margin:12px}.meta,table{width:100%;border-collapse:collapse}.meta td{padding:5px}.box{background:#f8fafc;border:1px solid #d7dde5;padding:8px;margin:10px 0}th{background:#eef1f4;color:#475569;border-bottom:1px solid #cbd5e1;padding:7px;text-align:left}td{border-bottom:1px solid #e2e8f0;padding:6px}.currency-title{margin-top:12px;padding:6px 8px;background:#f1f3f5;border:1px solid #d7dde5;color:#475569;font-size:11px;font-weight:bold}.arqueo{margin-bottom:8px}.total{font-size:12px;font-weight:bold;background:#f3f4f6;color:#475569}.empty{text-align:center;color:#64748b}.sign{margin-top:55px;width:100%;text-align:center}.sign td{border:0;padding-top:28px;text-align:center}.firma-wrap{text-align:center;height:66px}.firma{height:60px;max-width:250px}.line{border-top:1px solid #94a3b8;display:inline-block;width:180px}.foot{position:fixed;bottom:-12mm;font-size:8px;color:#64748b;width:100%}</style></head><body><div class='head'>$logoHtml<span class='doc-kind'>Documento de respaldo operativo</span></div><div class='title'>$titulo</div><table class='meta box'><tr><td><b>Agencia:</b> ".htmlspecialchars($sesion['agencia'])."</td><td><b>Caja:</b> {$sesion['num_caja']}</td><td><b>Monedas:</b> Consolidado</td></tr><tr><td><b>Fecha:</b> {$sesion['fecha']}</td><td><b>Hora:</b> $hora</td><td><b>Cajero:</b> ".htmlspecialchars($cajero)."</td></tr></table>$tablasArqueo$controlCierre<p>Declaro que el arqueo detallado corresponde al efectivo físico verificado en caja y que la información es fiel al registro operativo.</p><table class='sign'><tr><td>$firmaHtml<span class='line'></span><br>Cajero responsable<br>Firma y sello</td><td><div class='firma-wrap'></div><span class='line'></span><br>Supervisor / Responsable de agencia<br>Firma y sello</td></tr></table><div class='foot'>Gambarte S.R.L. - $titulo - Caja {$sesion['num_caja']} - Emitido ".date('d/m/Y H:i:s')." - Página {PAGE_NUM} de {PAGE_COUNT}</div></body></html>";
        $opt=new Options(); $opt->set('isRemoteEnabled',false); $pdf=new Dompdf($opt); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('letter','portrait'); $pdf->render();
        return ['binary'=>$pdf->output(), 'filename'=>strtolower(str_replace(' ','_',$titulo))."_caja_{$sesion['num_caja']}.pdf"];
    }
}
