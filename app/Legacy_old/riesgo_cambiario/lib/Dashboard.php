<?php
/**
 * Dashboard.php — Agregador ejecutivo del Panel de Gestión Integral de Riesgos.
 *
 * Corre los motores de cada dominio y extrae un resumen compacto (semáforo,
 * indicadores clave y alertas) para una vista de dirección / comité de riesgos.
 * Reutiliza los mismos motores que los informes de detalle (una sola fuente de verdad).
 */

namespace RiesgoCambiario;

require_once __DIR__ . '/Panel.php';

class Dashboard
{
    private static function peor($sems)
    {
        if (in_array('bad', $sems, true))  return 'bad';
        if (in_array('warn', $sems, true)) return 'warn';
        return 'ok';
    }

    /**
     * @return array ['dominios'=>[...], 'alertas'=>[...], 'semaforo'=>'ok|warn|bad', 'periodo'=>...]
     */
    public static function resumen($ini, $fin)
    {
        Panel::cargar('cambiario'); Panel::cargar('liquidez');
        Panel::cargar('operativo'); Panel::cargar('plaft'); Panel::cargar('seguridad');

        $cam = (new RiesgoCambiario($ini, $fin))->informe();
        $liq = (new RiesgoLiquidez($ini, $fin))->informe();
        $ope = (new RiesgoOperativo($ini, $fin))->informe();
        $pla = (new RiesgoPLAFT($ini, $fin))->informe();
        $seg = (new Seguridad($ini, $fin))->informe();

        // VaR total de mercado (tipo de cambio)
        $varTotal = 0.0; foreach ($cam['riesgo'] as $r) $varTotal += (float) $r['var'];

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

        // --- Alertas prioritarias (transversales) ---
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

    private static function n($v, $dec = 2) { return number_format((float) $v, $dec, ',', '.'); }

    private static function cachePath($ini, $fin)
    {
        return __DIR__ . '/../cache/dash_' . $ini . '_' . $fin . '.json';
    }

    /**
     * Igual que resumen() pero con caché en disco: la primera vez (o con $forzar)
     * calcula y guarda; luego devuelve el snapshot al instante. Pensado para una
     * vista de dirección que se consulta con frecuencia.
     */
    public static function resumenCache($ini, $fin, $forzar = false)
    {
        $path = self::cachePath($ini, $fin);
        if (!$forzar && is_file($path)) {
            $j = json_decode(file_get_contents($path), true);
            if (is_array($j)) { $j['from_cache'] = true; return $j; }
        }
        $r = self::resumen($ini, $fin);
        $r['generado'] = date('Y-m-d H:i:s');
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        @file_put_contents($path, json_encode($r));
        $r['from_cache'] = false;
        return $r;
    }
}
