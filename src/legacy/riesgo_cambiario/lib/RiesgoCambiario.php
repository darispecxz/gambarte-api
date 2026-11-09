<?php
/**
 * RiesgoCambiario.php — Motor de datos y cálculos del Informe de Riesgo Cambiario.
 *
 * Reproduce el informe mensual (GMB/GIR/RC) de forma parametrizable por período,
 * combinando datos de contabilidad (co_partidas) y del CGR (cotizaciones / cambios).
 *
 * Todas las fórmulas fueron validadas contra el informe real de diciembre 2025:
 *   - Posición Bs        = SUM(debe-haber) de co_partidas por codsubcuenta.
 *   - Saldo M/E          = Posición Bs / T/C BCB.
 *   - Volatilidad diaria = desv. estándar de los retornos diarios del T/C BCB.
 *   - VaR (99%)          = Posición Bs * volatilidad * 2.326.
 *   - Ganancia real      = Saldo M/E * (T/C venta comercial - T/C compra comercial).
 *   - Ganancia contable  = Saldo M/E * (T/C venta comercial - T/C BCB).
 */

namespace RiesgoCambiario;

require_once __DIR__ . '/Db.php';

class RiesgoCambiario
{
    /** Nivel de confianza 99% -> z (una cola). */
    const Z_99 = 2.326;

    /** id_moneda del Boliviano en la base CGR (moneda de curso legal). */
    const ID_BOB = 2;

    /**
     * Mapa de la "Posición cambiaria" (bloque ACTIVO/PASIVO, expresado en Bs).
     * Cada línea suma las subcuentas contables M/E indicadas.
     *   'like'  => patrón LIKE de codsubcuenta (usar % para grupos)
     *   'code'  => codsubcuenta exacta
     */
    public static function mapaPosicion()
    {
        return array(
            'activo' => array(
                // Validadas contra el informe real de dic-2025:
                array('label' => 'CAJA',                                              'code' => '11101201'),
                array('label' => 'BANCOS',                                            'like' => '113012%'),
                // INVERSIONES TEMPORARIAS: el informe muestra 3.589,88 pero ese valor
                // no proviene de co_partidas (ajuste manual del autor) -> requiere
                // confirmación del área contable.
                array('label' => 'INVERSIONES TEMPORARIAS',                           'like' => '11202%', 'verificar' => true),
                array('label' => 'SEGUROS PAGADOS POR ANTICIPADO M/E',                'code' => '14208201'),
                array('label' => 'COMISIONES POR COBRAR M/E',                         'code' => '14301201'),
                array('label' => 'OTRAS PARTIDAS PENDIENTES DE COBRO M/E',            'code' => '14399201'),
                // REMESAS EN TRANSITO (Gambarte Chile): la subcuenta 18301202 acumula
                // ~7,9 MM en BOB (remesas en CLP), que el informe EXCLUYE de la posición
                // cambiaria en USD (la muestra como 0). Excluida por defecto.
                array('label' => 'REMESAS EN TRANSITO POR COBRAR DE GAMBARTE CHILE M/E', 'code' => '18301202', 'excluir' => true, 'verificar' => true),
            ),
            'pasivo' => array(
                array('label' => 'ACREEDORES VARIOS M/E',       'like' => '242992%'),
                array('label' => 'REMESAS INTERNACIONALES M/E', 'code' => '24401202'),
                array('label' => 'FALLAS DE CAJA M/E',          'code' => '24402201'),
            ),
        );
    }

    private $ini;        // 'Y-m-d'
    private $fin;        // 'Y-m-d'
    private $ejercicio;  // 'YYYY' (derivado del año de $fin)

    public function __construct($ini, $fin)
    {
        $this->ini = $ini;
        $this->fin = $fin;
        $this->ejercicio = substr($fin, 0, 4);
    }

    public function ini()       { return $this->ini; }
    public function fin()       { return $this->fin; }
    public function ejercicio() { return $this->ejercicio; }

    // ------------------------------------------------------------------ //
    //  Monedas del informe                                               //
    // ------------------------------------------------------------------ //

    /**
     * Monedas con cuenta contable de caja (11101xxx), en el orden del informe.
     * Devuelve: id_moneda, abreviatura, descripcion, cuenta_contable.
     */
    public function monedas()
    {
        $sql = "SELECT id_moneda, abreviatura, descripcion, cuenta_contable
                FROM moneda
                WHERE estado = 'A'
                  AND cuenta_contable LIKE '11101%'
                GROUP BY cuenta_contable
                ORDER BY cuenta_contable";
        return Db::q('cgr', $sql);
    }

    // ------------------------------------------------------------------ //
    //  Saldos contables (co_partidas)                                    //
    // ------------------------------------------------------------------ //

    /** Saldo en Bs (SUM debe-haber) de una subcuenta exacta al corte. */
    public function saldoBs($codsubcuenta)
    {
        $sql = "SELECT COALESCE(SUM(p.debe - p.haber), 0) s
                FROM co_partidas p
                JOIN co_asientos a ON a.idasiento = p.idasiento
                WHERE p.codsubcuenta = :cod
                  AND a.codejercicio = :ej
                  AND a.fecha <= :fin";
        return (float) Db::val('conta', $sql, array(
            ':cod' => $codsubcuenta, ':ej' => $this->ejercicio, ':fin' => $this->fin,
        ));
    }

    /** Saldo en Bs (SUM debe-haber) de un grupo de subcuentas (LIKE) al corte. */
    public function saldoBsLike($like)
    {
        $sql = "SELECT COALESCE(SUM(p.debe - p.haber), 0) s
                FROM co_partidas p
                JOIN co_asientos a ON a.idasiento = p.idasiento
                WHERE p.codsubcuenta LIKE :like
                  AND a.codejercicio = :ej
                  AND a.fecha <= :fin";
        return (float) Db::val('conta', $sql, array(
            ':like' => $like, ':ej' => $this->ejercicio, ':fin' => $this->fin,
        ));
    }

    /** Saldo de una línea del mapa (usa 'code' o 'like'). */
    public function saldoLinea($linea)
    {
        return isset($linea['code'])
            ? $this->saldoBs($linea['code'])
            : $this->saldoBsLike($linea['like']);
    }

    // ------------------------------------------------------------------ //
    //  Cotizaciones                                                      //
    // ------------------------------------------------------------------ //

    /** T/C BCB (compra oficial, id_operacion = 1) vigente al corte, en Bs por unidad. */
    public function tcBcb($idMoneda)
    {
        if ((int) $idMoneda === self::ID_BOB) return 1.0;
        $sql = "SELECT valor
                FROM cotizacion_oficial
                WHERE id_moneda = :m
                  AND id_operacion = 1
                  AND DATE(fec_crea_cotizacion_oficial) <= :fin
                ORDER BY fec_crea_cotizacion_oficial DESC
                LIMIT 1";
        $v = Db::val('cgr', $sql, array(':m' => $idMoneda, ':fin' => $this->fin));
        return $v === null ? 0.0 : (float) $v;
    }

    /**
     * Promedio del tipo de cambio comercial (compra / venta) del período,
     * a partir de las operaciones reales de cambio (Bs por unidad de moneda ext.).
     *
     *   compra = la empresa RECIBE la moneda extranjera (mon_rec = X, mon_ent = BOB).
     *            La tasa en Bs/unidad está en el campo `tipo_cambio`.
     *   venta  = la empresa ENTREGA la moneda extranjera (mon_ent = X, mon_rec = BOB).
     *            La tasa en Bs/unidad está en el campo `tipo_cambio_bob`
     *            (en estas filas `tipo_cambio` guarda el inverso unidad/Bs).
     *
     * Validado dic-2025: USD compra 6,96 / venta 6,969; EUR venta 11,396; BRL venta 2,059.
     * Devuelve array('compra' => float, 'venta' => float).
     */
    public function comercial($idMoneda)
    {
        if ((int) $idMoneda === self::ID_BOB) return array('compra' => 1.0, 'venta' => 1.0);

        $bob = self::ID_BOB;
        $compra = Db::val('cgr',
            "SELECT AVG(tipo_cambio)
             FROM cambio
             WHERE mon_rec = :m AND mon_ent = :bob
               AND tipo_cambio > 0
               AND fec_crea_cambio BETWEEN :ini AND :finf",
            array(':m' => $idMoneda, ':bob' => $bob,
                  ':ini' => $this->ini . ' 00:00:00', ':finf' => $this->fin . ' 23:59:59'));

        $venta = Db::val('cgr',
            "SELECT AVG(tipo_cambio_bob)
             FROM cambio
             WHERE mon_ent = :m AND mon_rec = :bob
               AND tipo_cambio_bob > 0
               AND fec_crea_cambio BETWEEN :ini AND :finf",
            array(':m' => $idMoneda, ':bob' => $bob,
                  ':ini' => $this->ini . ' 00:00:00', ':finf' => $this->fin . ' 23:59:59'));

        return array(
            'compra' => $compra === null ? 0.0 : (float) $compra,
            'venta'  => $venta  === null ? 0.0 : (float) $venta,
        );
    }

    /**
     * Volatilidad diaria = desviación estándar (poblacional) de los retornos
     * diarios simples del T/C BCB (compra) dentro del período.
     */
    public function volatilidad($idMoneda)
    {
        if ((int) $idMoneda === self::ID_BOB) return 0.0;

        $sql = "SELECT DATE(fec_crea_cotizacion_oficial) f, valor
                FROM cotizacion_oficial
                WHERE id_moneda = :m
                  AND id_operacion = 1
                  AND DATE(fec_crea_cotizacion_oficial) BETWEEN :ini AND :fin
                ORDER BY fec_crea_cotizacion_oficial ASC";
        $rows = Db::q('cgr', $sql, array(':m' => $idMoneda, ':ini' => $this->ini, ':fin' => $this->fin));

        $prev = null; $rets = array();
        foreach ($rows as $r) {
            $v = (float) $r['valor'];
            if ($prev !== null && $prev > 0 && $v > 0) {
                $rets[] = ($v / $prev) - 1.0;
            }
            $prev = $v;
        }
        return self::stddev($rets);
    }

    /** Desviación estándar poblacional. */
    public static function stddev(array $x)
    {
        $n = count($x);
        if ($n < 2) return 0.0;
        $mean = array_sum($x) / $n;
        $sum = 0.0;
        foreach ($x as $v) { $d = $v - $mean; $sum += $d * $d; }
        return sqrt($sum / $n);
    }

    // ------------------------------------------------------------------ //
    //  Armado del informe completo                                       //
    // ------------------------------------------------------------------ //

    /**
     * Devuelve toda la data del informe:
     *   ['saldos']    => Tabla 1 (saldo por cuenta/moneda)
     *   ['posicion']  => Tabla 2 (ACTIVO/PASIVO M/E y posición cambiaria)
     *   ['riesgo']    => Tabla 3 (posición, volatilidad, VaR)
     *   ['ganancia']  => Tabla 4 (saldo M/E, T/C, ganancia real/contable)
     */
    public function informe()
    {
        $monedas = $this->monedas();

        // --- Tabla 1: saldos por cuenta (se memoizan para reutilizar en riesgo) ---
        $saldos = array();
        $totalSaldos = 0.0;
        $saldoMap = array();
        foreach ($monedas as $m) {
            $s = $this->saldoBs($m['cuenta_contable']);
            $saldoMap[$m['cuenta_contable']] = $s;
            $totalSaldos += $s;
            $saldos[] = array(
                'cuenta'     => $m['cuenta_contable'],
                'moneda'     => $m['descripcion'],
                'abreviatura'=> $m['abreviatura'],
                'monto'      => $s,
            );
        }

        // --- Tabla 3: riesgo (posición, volatilidad, VaR) ---
        // --- Tabla 4: ganancia real/contable ---
        $riesgo = array();
        $ganancia = array();
        $totGanReal = 0.0; $totGanCont = 0.0;
        foreach ($monedas as $m) {
            $idm   = (int) $m['id_moneda'];
            $pos   = $saldoMap[$m['cuenta_contable']]; // reutiliza el saldo ya calculado
            // Optimización: solo se calcula volatilidad/ganancia para monedas con
            // posición (las de saldo cero se filtran en la vista de todos modos).
            $tienePos = abs($pos) >= 0.005;
            $vol   = ($idm !== self::ID_BOB && $tienePos) ? $this->volatilidad($idm) : 0.0;
            $var   = $pos * $vol * self::Z_99;
            $riesgo[] = array(
                'cuenta'      => $m['cuenta_contable'],
                'moneda'      => $m['descripcion'],
                'abreviatura' => $m['abreviatura'],
                'posicion'    => $pos,
                'volatilidad' => $vol,
                'var'         => $var,
            );

            if ($idm === self::ID_BOB || !$tienePos) continue; // sin posición: no hay ganancia

            $bcb = $this->tcBcb($idm);
            $com = $this->comercial($idm);
            $saldoMe  = $bcb > 0 ? $pos / $bcb : 0.0;
            $ganReal  = $saldoMe * ($com['venta'] - $com['compra']);
            $ganCont  = $saldoMe * ($com['venta'] - $bcb);
            $totGanReal += $ganReal; $totGanCont += $ganCont;

            $ganancia[] = array(
                'abreviatura' => $m['abreviatura'],
                'moneda'      => $m['descripcion'],
                'saldo_me'    => $saldoMe,
                'bcb'         => $bcb,
                'compra'      => $com['compra'],
                'venta'       => $com['venta'],
                'gan_real'    => $ganReal,
                'gan_contable'=> $ganCont,
            );
        }

        // --- Tabla 2: posición cambiaria (ACTIVO / PASIVO M/E) ---
        $mapa = self::mapaPosicion();
        $activo = array(); $totActivo = 0.0;
        foreach ($mapa['activo'] as $ln) {
            $excl = !empty($ln['excluir']);
            $s = $excl ? 0.0 : $this->saldoLinea($ln);   // saldo deudor (positivo)
            $totActivo += $s;
            $activo[] = array('label' => $ln['label'], 'saldo' => $s,
                              'verificar' => !empty($ln['verificar']));
        }
        $pasivo = array(); $totPasivo = 0.0;
        foreach ($mapa['pasivo'] as $ln) {
            $excl = !empty($ln['excluir']);
            $s = $excl ? 0.0 : -$this->saldoLinea($ln);  // saldo acreedor -> mostrar positivo
            $totPasivo += $s;
            $pasivo[] = array('label' => $ln['label'], 'saldo' => $s,
                              'verificar' => !empty($ln['verificar']));
        }
        $posicionCambiaria = $totActivo - $totPasivo;

        // "posible ganancia/pérdida económica": impacto del VaR del USD (moneda de referencia)
        $usdVol = $this->volatilidad(1);
        $posibleGanancia = abs($posicionCambiaria) * $usdVol * self::Z_99;
        $posiblePerdida  = $posibleGanancia;

        return array(
            'periodo' => array('ini' => $this->ini, 'fin' => $this->fin, 'ejercicio' => $this->ejercicio),
            'saldos'  => $saldos,
            'total_saldos' => $totalSaldos,
            'posicion' => array(
                'activo' => $activo, 'total_activo' => $totActivo,
                'pasivo' => $pasivo, 'total_pasivo' => $totPasivo,
                'posicion_cambiaria' => $posicionCambiaria,
                'posible_ganancia' => $posibleGanancia,
                'posible_perdida'  => $posiblePerdida,
            ),
            'riesgo' => $riesgo,
            'ganancia' => $ganancia,
            'total_gan_real' => $totGanReal,
            'total_gan_contable' => $totGanCont,
        );
    }
}
