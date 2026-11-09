<?php
/**
 * Panel.php — Registro de riesgos y estilos compartidos del
 * «Panel de Gestión Integral de Riesgos».
 *
 * Cada riesgo expone:
 *   - engine: clase con constructor ($ini, $fin) y método informe()
 *   - vista:  clase con método estático html($data, $opts)
 */

namespace RiesgoCambiario;

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Fmt.php';

class Panel
{
    /** Catálogo de riesgos del panel (orden = orden de pestañas). */
    public static function risks()
    {
        return array(
            'cambiario' => array(
                'title' => 'Riesgo Cambiario', 'icon' => '💱',
                'engine'=> '\\RiesgoCambiario\\RiesgoCambiario',
                'vista' => '\\RiesgoCambiario\\VistaCambiario',
            ),
            'mercado' => array(
                'title' => 'Riesgo de Mercado', 'icon' => '📈',
                'engine'=> '\\RiesgoCambiario\\RiesgoCambiario',
                'vista' => '\\RiesgoCambiario\\VistaMercado',
            ),
            'liquidez' => array(
                'title' => 'Riesgo de Liquidez', 'icon' => '💧',
                'engine'=> '\\RiesgoCambiario\\RiesgoLiquidez',
                'vista' => '\\RiesgoCambiario\\VistaLiquidez',
            ),
            'operativo' => array(
                'title' => 'Riesgo Operativo', 'icon' => '⚙️',
                'engine'=> '\\RiesgoCambiario\\RiesgoOperativo',
                'vista' => '\\RiesgoCambiario\\VistaOperativo',
            ),
            'plaft' => array(
                'title' => 'PLA/FT/FP', 'icon' => '🛡️',
                'engine'=> '\\RiesgoCambiario\\RiesgoPLAFT',
                'vista' => '\\RiesgoCambiario\\VistaPLAFT',
            ),
            'seguridad' => array(
                'title' => 'Seguridad y Trazabilidad', 'icon' => '🔒',
                'engine'=> '\\RiesgoCambiario\\Seguridad',
                'vista' => '\\RiesgoCambiario\\VistaSeguridad',
            ),
        );
    }

    /** Carga los archivos (engine + vista) de un riesgo por su slug. */
    public static function cargar($slug)
    {
        $r = self::risks();
        if (!isset($r[$slug])) return null;
        $engineShort = substr(strrchr($r[$slug]['engine'], '\\'), 1);
        $vistaShort  = substr(strrchr($r[$slug]['vista'],  '\\'), 1);
        require_once __DIR__ . "/$engineShort.php";
        require_once __DIR__ . "/$vistaShort.php";
        return $r[$slug];
    }

    /** Genera la data + HTML de un riesgo. Devuelve array('data','html'). */
    public static function generar($slug, $ini, $fin, $opts = array())
    {
        $meta = self::cargar($slug);
        if (!$meta) throw new \Exception("Riesgo desconocido: $slug");
        $engine = new $meta['engine']($ini, $fin);
        $data   = $engine->informe();
        $html   = call_user_func(array($meta['vista'], 'html'), $data, $opts);
        return array('meta' => $meta, 'data' => $data, 'html' => $html);
    }

    /** Ruta al logo por defecto usado en los PDF. */
    private static function logoPath()
    {
        $p = realpath(__DIR__ . '/../../logocompleto.png');
        return $p ? $p : '';
    }

    /**
     * Genera el PDF (mPDF) de un informe y devuelve el binario.
     * ÚNICA implementación de generación de PDF: la usan tanto descargar.php
     * como la API, para no duplicar la lógica.
     *
     * @return string binario del PDF
     */
    public static function pdf($slug, $ini, $fin, $logo = null)
    {
        if ($logo === null) $logo = self::logoPath();
        $res  = self::generar($slug, $ini, $fin, array('logo' => $logo, 'pdf' => true));
        $html = '<style>' . self::reportCss() . '</style>' . $res['html'];

        require_once __DIR__ . '/../../../mpdf/mpdf.php';
        $mpdf = new \mPDF('utf-8', 'Letter', 9, 'dejavusanscondensed', 12, 12, 12, 14, 8, 8);
        $mpdf->SetTitle($res['meta']['title']);
        $mpdf->SetAuthor('Gambarte S.R.L.');
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', 'S'); // 'S' = devuelve el binario como string
    }

    /** Nombre de archivo sugerido para el PDF de un informe. */
    public static function pdfFilename($slug, $ini, $fin)
    {
        return 'Informe_' . ucfirst($slug) . '_' . $ini . '_a_' . $fin . '.pdf';
    }

    // ------------------------------------------------------------------ //
    //  Estilos                                                            //
    // ------------------------------------------------------------------ //

    /** CSS del cromo de la página (solo pantalla). */
    public static function pageCss()
    {
        return <<<CSS
:root{--naranja:#e8721c;--azul:#1f3a63;}
*{box-sizing:border-box;}
body{margin:0;background:#eef1f5;font-family:'Segoe UI',Arial,sans-serif;color:#222;}
.wrap{max-width:940px;margin:0 auto;padding:18px;}
.top{display:flex;align-items:center;gap:14px;margin-bottom:12px;}
.top img{max-height:46px;}
.top h1{font-size:20px;margin:0;color:var(--naranja);}
.top .sub{font-size:12px;color:#667;}
.badge{background:#e6f4ea;color:#1e7e34;border:1px solid #b7e0c2;border-radius:10px;padding:1px 8px;font-size:11px;}
.tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;border-bottom:2px solid #d7dde5;}
.tab{text-decoration:none;padding:9px 16px;font-size:13px;font-weight:600;color:#556;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0;}
.tab:hover{background:#e7ecf2;}
.tab.active{background:#fff;color:var(--naranja);border-color:#d7dde5;box-shadow:0 -2px 0 var(--naranja) inset;}
.panel-c{background:#fff;border:1px solid #dde3ea;border-radius:10px;padding:14px 16px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);}
.controls{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.field{display:flex;flex-direction:column;gap:3px;}
.field label{font-size:11px;color:#556;font-weight:600;}
.field input,.field select{padding:6px 8px;border:1px solid #c4ccd6;border-radius:6px;font-size:13px;}
.btn{display:inline-block;text-decoration:none;border:none;cursor:pointer;border-radius:6px;padding:8px 14px;font-size:13px;font-weight:600;}
.btn-primary{background:var(--naranja);color:#fff;}
.btn-pdf{background:#c0392b;color:#fff;}
.rc-render{background:#fff;border:1px solid #dde3ea;border-radius:10px;padding:26px 30px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.err{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24;padding:12px 14px;border-radius:8px;}
.note{font-size:11px;color:#778;margin-top:8px;}
CSS;
    }

    /** CSS compartido de los informes (pantalla + PDF). Clases .rc-*. */
    public static function reportCss()
    {
        return <<<CSS
.rc-doc{font-family:'DejaVu Sans',Arial,sans-serif;color:#222;font-size:12px;line-height:1.5;}
.rc-doc h1{font-size:19px;color:#e8721c;margin:0;}
.rc-doc h2{font-size:14px;color:#333;border-bottom:2px solid #e8721c;padding-bottom:3px;margin:18px 0 6px;}
.rc-doc h3{font-size:12.5px;color:#1f3a63;margin:14px 0 4px;}
.rc-doc p{margin:6px 0;text-align:justify;}
.rc-cab{border-bottom:3px solid #e8721c;padding-bottom:8px;margin-bottom:10px;}
.rc-logo{max-height:52px;}
.rc-tit .rc-sub{font-size:13px;color:#555;margin-top:2px;}
.rc-tit .rc-cod{font-size:11px;color:#888;}
.rc-t{border-collapse:collapse;width:100%;margin:10px 0;font-size:11px;}
.rc-t th,.rc-t td{border:1px solid #b9b9b9;padding:4px 7px;}
.rc-t th{background:#dfe6f2;color:#1f3a63;text-align:left;font-weight:bold;}
.rc-t td.r,.rc-t th.r{text-align:right;}
.rc-t td.c,.rc-t th.c{text-align:center;}
.rc-t tr.tot td{background:#f2f2f2;font-weight:bold;}
.rc-t tr.sub td{background:#eef2f8;font-weight:bold;}
.rc-narrow{max-width:560px;}
.rc-pos{border:1px solid #444;padding:12px 14px;margin:12px 0;max-width:640px;}
.rc-pos-tit{text-align:center;font-weight:bold;font-size:12px;margin-bottom:8px;}
.rc-pos-tit span{font-weight:normal;font-size:11px;}
.rc-t2 td{border:none;padding:2px 6px;}
.rc-t2 tr.grp td{font-weight:bold;border-top:1px solid #444;padding-top:6px;}
.rc-t2 tr.sub td{font-weight:bold;border-top:1px solid #444;background:none;}
.rc-t2 tr.pos td{font-weight:bold;color:#c0392b;border-top:2px solid #444;padding-top:5px;}
.rc-eco{width:75mm;margin-top:6px;}
.rc-eco td{border:1px solid #999;}
.rc-eco .ok{background:#d5f5d5;font-weight:bold;}
.rc-eco .bad{background:#f7d4d4;font-weight:bold;}
.rc-chk{color:#c0392b;}
.rc-nota{font-size:10px;color:#666;background:#fff8e6;border-left:3px solid #e8b923;padding:6px 10px;}
.rc-pie{margin-top:16px;font-size:9px;color:#999;text-align:center;border-top:1px solid #ddd;padding-top:6px;}
.rc-kpis{width:100%;border-collapse:separate;border-spacing:8px 0;margin:10px 0;}
.rc-kpi{border:1px solid #d7dde5;border-radius:8px;padding:10px 12px;background:#f8fafc;text-align:center;}
.rc-kpi .k-lbl{font-size:10px;color:#667;text-transform:uppercase;letter-spacing:.03em;}
.rc-kpi .k-val{font-size:18px;font-weight:bold;color:#1f3a63;margin-top:3px;}
.rc-kpi .k-sub{font-size:9.5px;color:#889;margin-top:2px;}
.sem{display:inline-block;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:bold;}
.sem-ok{background:#d5f5d5;color:#1e7e34;}
.sem-warn{background:#fff3cd;color:#856404;}
.sem-bad{background:#f7d4d4;color:#a12a2a;}
.pos-val{color:#1e7e34;font-weight:bold;}
.neg-val{color:#c0392b;font-weight:bold;}
CSS;
    }
}
