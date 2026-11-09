<?php

namespace App\Modules\Riesgos\Services;

use App\Modules\Riesgos\Engines\RiesgoCambiario;
use App\Modules\Riesgos\Engines\RiesgoLiquidez;
use App\Modules\Riesgos\Engines\RiesgoOperativo;
use App\Modules\Riesgos\Engines\RiesgoPLAFT;
use App\Modules\Riesgos\Engines\Seguridad;
use App\Modules\Riesgos\Views\VistaCambiario;
use App\Modules\Riesgos\Views\VistaMercado;
use App\Modules\Riesgos\Views\VistaLiquidez;
use App\Modules\Riesgos\Views\VistaOperativo;
use App\Modules\Riesgos\Views\VistaPLAFT;
use App\Modules\Riesgos\Views\VistaSeguridad;

class RiskPanelService
{
    private static function risks()
    {
        return array(
            'cambiario' => array(
                'title' => 'Riesgo Cambiario', 'icon' => '💱',
                'engine' => RiesgoCambiario::class,
                'vista'  => VistaCambiario::class,
            ),
            'mercado' => array(
                'title' => 'Riesgo de Mercado', 'icon' => '📈',
                'engine' => RiesgoCambiario::class,
                'vista'  => VistaMercado::class,
            ),
            'liquidez' => array(
                'title' => 'Riesgo de Liquidez', 'icon' => '💧',
                'engine' => RiesgoLiquidez::class,
                'vista'  => VistaLiquidez::class,
            ),
            'operativo' => array(
                'title' => 'Riesgo Operativo', 'icon' => '⚙️',
                'engine' => RiesgoOperativo::class,
                'vista'  => VistaOperativo::class,
            ),
            'plaft' => array(
                'title' => 'PLA/FT/FP', 'icon' => '🛡️',
                'engine' => RiesgoPLAFT::class,
                'vista'  => VistaPLAFT::class,
            ),
            'seguridad' => array(
                'title' => 'Seguridad y Trazabilidad', 'icon' => '🔒',
                'engine' => Seguridad::class,
                'vista'  => VistaSeguridad::class,
            ),
        );
    }

    public function catalog(): array
    {
        $out = [];
        foreach (self::risks() as $slug => $r) {
            $out[] = ['slug' => $slug, 'title' => $r['title'], 'icon' => $r['icon']];
        }
        return $out;
    }

    public function slugs(): array
    {
        return array_keys(self::risks());
    }

    public function generate(string $slug, string $ini, string $fin, array $opts = array()): array
    {
        $r = self::risks();
        if (!isset($r[$slug])) throw new \InvalidArgumentException("Riesgo desconocido: $slug");
        $meta = $r[$slug];
        $engine = new $meta['engine']($ini, $fin);
        $data   = $engine->informe();
        $html   = call_user_func(array($meta['vista'], 'html'), $data, $opts);
        return array('meta' => $meta, 'data' => $data, 'html' => $html);
    }

    private function logoPath(): string
    {
        $p = realpath(base_path('mpdf/logocompleto.png'));
        if (!$p) $p = realpath(base_path('logocompleto.png'));
        return $p ?: '';
    }

    public function pdf(string $slug, string $ini, string $fin): string
    {
        $logo = $this->logoPath();
        $res  = $this->generate($slug, $ini, $fin, array('logo' => $logo, 'pdf' => true));
        $html = '<style>' . self::reportCss() . '</style>' . $res['html'];

        require_once base_path('mpdf/mpdf.php');
        $mpdf = new \mPDF('utf-8', 'Letter', 9, 'dejavusanscondensed', 12, 12, 12, 14, 8, 8);
        $mpdf->SetTitle($res['meta']['title']);
        $mpdf->SetAuthor('Gambarte S.R.L.');
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', 'S');
    }

    public function pdfFilename(string $slug, string $ini, string $fin): string
    {
        return 'Informe_' . ucfirst($slug) . '_' . $ini . '_a_' . $fin . '.pdf';
    }

    public function dashboard(string $ini, string $fin, bool $forceRefresh = false): array
    {
        $cachePath = storage_path('app/cache/dash_' . $ini . '_' . $fin . '.json');
        if (!$forceRefresh && is_file($cachePath)) {
            $j = json_decode(file_get_contents($cachePath), true);
            if (is_array($j)) { $j['from_cache'] = true; return $j; }
        }

        $r = $this->resumen($ini, $fin);
        $r['generado'] = date('Y-m-d H:i:s');

        $dir = dirname($cachePath);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        @file_put_contents($cachePath, json_encode($r));
        $r['from_cache'] = false;
        return $r;
    }

    private function resumen(string $ini, string $fin): array
    {
        $cam = (new RiesgoCambiario($ini, $fin))->informe();
        $liq = (new RiesgoLiquidez($ini, $fin))->informe();
        $ope = (new RiesgoOperativo($ini, $fin))->informe();
        $pla = (new RiesgoPLAFT($ini, $fin))->informe();
        $seg = (new Seguridad($ini, $fin))->informe();

        $varTotal = 0.0;
        foreach ($cam['riesgo'] as $r) $varTotal += (float) $r['var'];

        $dominios = array(
            array(
                'slug' => 'mercado', 'title' => 'Mercado / Cambiario', 'icon' => '📈', 'sem' => 'info',
                'metrics' => array(
                    array('VaR total (99%)', 'Bs ' . self::n($varTotal)),
                    array('Posición cambiaria', 'Bs ' . self::n($cam['posicion']['posicion_cambiaria'])),
                ),
            ),
            array(
                'slug' => 'liquidez', 'title' => 'Liquidez', 'icon' => '💧', 'sem' => $liq['semaforo'],
                'metrics' => array(
                    array('Cobertura', $liq['cobertura'] === null ? '—' : self::n($liq['cobertura'], 2) . '×'),
                    array('Disponibilidad', 'Bs ' . self::n($liq['tot_disponibilidad'])),
                ),
            ),
            array(
                'slug' => 'operativo', 'title' => 'Operativo', 'icon' => '⚙️', 'sem' => $ope['semaforo_global'],
                'metrics' => array(
                    array('Fallas de caja', $ope['fallas']['n'] . ' (Bs ' . self::n($ope['fallas']['faltante']) . ' faltante)'),
                    array('Billetes falsos / Anulaciones', $ope['billetes']['eventos'] . ' / ' . $ope['anulaciones']['ejecutadas']),
                ),
            ),
            array(
                'slug' => 'plaft', 'title' => 'PLA/FT/FP', 'icon' => '🛡️', 'sem' => $pla['semaforo'],
                'metrics' => array(
                    array('Sobre umbral / Fraccionamiento', $pla['umbral']['n'] . ' / ' . $pla['fraccionamiento']['n']),
                    array('PEP / Listas de riesgo', $pla['pep']['clientes'] . ' / ' . $pla['listas']['clientes'] . ' clientes'),
                ),
            ),
            array(
                'slug' => 'seguridad', 'title' => 'Seguridad y Trazabilidad', 'icon' => '🔒', 'sem' => $seg['semaforo'],
                'metrics' => array(
                    array('Operaciones trazadas', self::n($seg['traza']['cobertura']['operaciones_logueadas'], 0)),
                    array('Fallidos / Claves vencidas / SoD', $seg['accesos']['fallidos'] . ' / ' . $seg['claves']['n'] . ' / ' . $seg['segregacion']['conflictos_n']),
                ),
            ),
        );

        $alertas = array();
        if ($pla['listas']['ops'] > 0)
            $alertas[] = array('sev' => 'bad', 'dom' => 'PLA/FT', 'txt' => $pla['listas']['clientes'] . ' cliente(s) en listas de riesgo con ' . $pla['listas']['ops'] . ' operación(es) — revisión inmediata.');
        if ($seg['segregacion']['conflictos_n'] > 0)
            $alertas[] = array('sev' => 'bad', 'dom' => 'Seguridad', 'txt' => $seg['segregacion']['conflictos_n'] . ' usuario(s) con roles incompatibles (segregación de funciones).');
        if ($liq['cobertura'] !== null && $liq['cobertura'] < 1)
            $alertas[] = array('sev' => 'bad', 'dom' => 'Liquidez', 'txt' => 'Cobertura de liquidez por debajo de 1× (' . self::n($liq['cobertura'], 2) . '×).');
        if ($pla['fraccionamiento']['n'] > 0)
            $alertas[] = array('sev' => 'warn', 'dom' => 'PLA/FT', 'txt' => $pla['fraccionamiento']['n'] . ' caso(s) de posible fraccionamiento y ' . $pla['umbral']['n'] . ' operación(es) sobre umbral.');
        if ($pla['pep']['clientes'] > 0)
            $alertas[] = array('sev' => 'warn', 'dom' => 'PLA/FT', 'txt' => $pla['pep']['clientes'] . ' cliente(s) PEP con operaciones — debida diligencia ampliada.');
        if ($seg['claves']['n'] > 0)
            $alertas[] = array('sev' => 'warn', 'dom' => 'Seguridad', 'txt' => $seg['claves']['n'] . ' contraseña(s) vencida(s) de usuarios activos.');
        if ($seg['accesos']['fallidos'] >= 10)
            $alertas[] = array('sev' => 'warn', 'dom' => 'Seguridad', 'txt' => $seg['accesos']['fallidos'] . ' intentos fallidos de autenticación por credenciales.');
        if ($ope['billetes']['eventos'] > 0)
            $alertas[] = array('sev' => 'warn', 'dom' => 'Operativo', 'txt' => $ope['billetes']['eventos'] . ' detección(es) de billetes falsos.');
        if (empty($alertas))
            $alertas[] = array('sev' => 'ok', 'dom' => 'General', 'txt' => 'Sin alertas prioritarias en el período.');

        $sem = self::peor(array($liq['semaforo'], $ope['semaforo_global'], $pla['semaforo'], $seg['semaforo']));

        return array(
            'periodo' => array('ini' => $ini, 'fin' => $fin),
            'dominios' => $dominios, 'alertas' => $alertas, 'semaforo' => $sem,
        );
    }

    private static function n($v, $dec = 2)
    {
        return number_format((float) $v, $dec, ',', '.');
    }

    private static function peor($sems)
    {
        if (in_array('bad', $sems, true))  return 'bad';
        if (in_array('warn', $sems, true)) return 'warn';
        return 'ok';
    }

    public static function pageCss(): string
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

    public static function reportCss(): string
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
