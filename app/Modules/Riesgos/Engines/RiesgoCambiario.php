<?php

namespace App\Modules\Riesgos\Engines;

use App\Modules\Shared\Database\RawConnection;

class RiesgoCambiario
{
    const Z_99 = 2.326;
    const ID_BOB = 2;

    public static function mapaPosicion()
    {
        return array(
            'activo' => array(
                array('label' => 'CAJA',                                              'code' => '11101201'),
                array('label' => 'BANCOS',                                            'like' => '113012%'),
                array('label' => 'INVERSIONES TEMPORARIAS',                           'like' => '11202%', 'verificar' => true),
                array('label' => 'SEGUROS PAGADOS POR ANTICIPADO M/E',                'code' => '14208201'),
                array('label' => 'COMISIONES POR COBRAR M/E',                         'code' => '14301201'),
                array('label' => 'OTRAS PARTIDAS PENDIENTES DE COBRO M/E',            'code' => '14399201'),
                array('label' => 'REMESAS EN TRANSITO POR COBRAR DE GAMBARTE CHILE M/E', 'code' => '18301202', 'excluir' => true, 'verificar' => true),
            ),
            'pasivo' => array(
                array('label' => 'ACREEDORES VARIOS M/E',       'like' => '242992%'),
                array('label' => 'REMESAS INTERNACIONALES M/E', 'code' => '24401202'),
                array('label' => 'FALLAS DE CAJA M/E',          'code' => '24402201'),
            ),
        );
    }

    private $ini;
    private $fin;
    private $ejercicio;

    public function __construct($ini, $fin)
    {
        $this->ini = $ini;
        $this->fin = $fin;
        $this->ejercicio = substr($fin, 0, 4);
    }

    public function ini()       { return $this->ini; }
    public function fin()       { return $this->fin; }
    public function ejercicio() { return $this->ejercicio; }

    public function monedas()
    {
        return RawConnection::select(
            "SELECT id_moneda, abreviatura, descripcion, cuenta_contable
             FROM moneda WHERE estado = 'A' AND cuenta_contable LIKE '11101%'
             GROUP BY cuenta_contable ORDER BY cuenta_contable",
            [], 'cgr'
        );
    }

    public function saldoBs($codsubcuenta)
    {
        return (float) RawConnection::scalar(
            "SELECT COALESCE(SUM(p.debe - p.haber), 0)
             FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento
             WHERE p.codsubcuenta = :cod AND a.codejercicio = :ej AND a.fecha <= :fin",
            array(':cod' => $codsubcuenta, ':ej' => $this->ejercicio, ':fin' => $this->fin), 'conta'
        );
    }

    public function saldoBsLike($like)
    {
        return (float) RawConnection::scalar(
            "SELECT COALESCE(SUM(p.debe - p.haber), 0)
             FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento
             WHERE p.codsubcuenta LIKE :like AND a.codejercicio = :ej AND a.fecha <= :fin",
            array(':like' => $like, ':ej' => $this->ejercicio, ':fin' => $this->fin), 'conta'
        );
    }

    public function saldoLinea($linea)
    {
        return isset($linea['code'])
            ? $this->saldoBs($linea['code'])
            : $this->saldoBsLike($linea['like']);
    }

    public function tcBcb($idMoneda)
    {
        if ((int) $idMoneda === self::ID_BOB) return 1.0;
        $v = RawConnection::scalar(
            "SELECT valor FROM cotizacion_oficial
             WHERE id_moneda = :m AND id_operacion = 1
               AND DATE(fec_crea_cotizacion_oficial) <= :fin
             ORDER BY fec_crea_cotizacion_oficial DESC LIMIT 1",
            array(':m' => $idMoneda, ':fin' => $this->fin), 'cgr'
        );
        return $v === null ? 0.0 : (float) $v;
    }

    public function comercial($idMoneda)
    {
        if ((int) $idMoneda === self::ID_BOB) return array('compra' => 1.0, 'venta' => 1.0);

        $bob = self::ID_BOB;
        $compra = RawConnection::scalar(
            "SELECT AVG(tipo_cambio) FROM cambio
             WHERE mon_rec = :m AND mon_ent = :bob AND tipo_cambio > 0
               AND fec_crea_cambio BETWEEN :ini AND :finf",
            array(':m' => $idMoneda, ':bob' => $bob,
                  ':ini' => $this->ini . ' 00:00:00', ':finf' => $this->fin . ' 23:59:59'), 'cgr'
        );
        $venta = RawConnection::scalar(
            "SELECT AVG(tipo_cambio_bob) FROM cambio
             WHERE mon_ent = :m AND mon_rec = :bob AND tipo_cambio_bob > 0
               AND fec_crea_cambio BETWEEN :ini AND :finf",
            array(':m' => $idMoneda, ':bob' => $bob,
                  ':ini' => $this->ini . ' 00:00:00', ':finf' => $this->fin . ' 23:59:59'), 'cgr'
        );

        return array(
            'compra' => $compra === null ? 0.0 : (float) $compra,
            'venta'  => $venta  === null ? 0.0 : (float) $venta,
        );
    }

    public function volatilidad($idMoneda)
    {
        if ((int) $idMoneda === self::ID_BOB) return 0.0;

        $rows = RawConnection::select(
            "SELECT DATE(fec_crea_cotizacion_oficial) f, valor FROM cotizacion_oficial
             WHERE id_moneda = :m AND id_operacion = 1
               AND DATE(fec_crea_cotizacion_oficial) BETWEEN :ini AND :fin
             ORDER BY fec_crea_cotizacion_oficial ASC",
            array(':m' => $idMoneda, ':ini' => $this->ini, ':fin' => $this->fin), 'cgr'
        );

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

    public static function stddev(array $x)
    {
        $n = count($x);
        if ($n < 2) return 0.0;
        $mean = array_sum($x) / $n;
        $sum = 0.0;
        foreach ($x as $v) { $d = $v - $mean; $sum += $d * $d; }
        return sqrt($sum / $n);
    }

    public function informe()
    {
        $monedas = $this->monedas();

        $saldos = array();
        $totalSaldos = 0.0;
        $saldoMap = array();
        foreach ($monedas as $m) {
            $s = $this->saldoBs($m['cuenta_contable']);
            $saldoMap[$m['cuenta_contable']] = $s;
            $totalSaldos += $s;
            $saldos[] = array(
                'cuenta'      => $m['cuenta_contable'],
                'moneda'      => $m['descripcion'],
                'abreviatura' => $m['abreviatura'],
                'monto'       => $s,
            );
        }

        $riesgo = array();
        $ganancia = array();
        $totGanReal = 0.0; $totGanCont = 0.0;
        foreach ($monedas as $m) {
            $idm   = (int) $m['id_moneda'];
            $pos   = $saldoMap[$m['cuenta_contable']];
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

            if ($idm === self::ID_BOB || !$tienePos) continue;

            $bcb = $this->tcBcb($idm);
            $com = $this->comercial($idm);
            $saldoMe  = $bcb > 0 ? $pos / $bcb : 0.0;
            $ganReal  = $saldoMe * ($com['venta'] - $com['compra']);
            $ganCont  = $saldoMe * ($com['venta'] - $bcb);
            $totGanReal += $ganReal; $totGanCont += $ganCont;

            $ganancia[] = array(
                'abreviatura'  => $m['abreviatura'],
                'moneda'       => $m['descripcion'],
                'saldo_me'     => $saldoMe,
                'bcb'          => $bcb,
                'compra'       => $com['compra'],
                'venta'        => $com['venta'],
                'gan_real'     => $ganReal,
                'gan_contable' => $ganCont,
            );
        }

        $mapa = self::mapaPosicion();
        $activo = array(); $totActivo = 0.0;
        foreach ($mapa['activo'] as $ln) {
            $excl = !empty($ln['excluir']);
            $s = $excl ? 0.0 : $this->saldoLinea($ln);
            $totActivo += $s;
            $activo[] = array('label' => $ln['label'], 'saldo' => $s, 'verificar' => !empty($ln['verificar']));
        }
        $pasivo = array(); $totPasivo = 0.0;
        foreach ($mapa['pasivo'] as $ln) {
            $excl = !empty($ln['excluir']);
            $s = $excl ? 0.0 : -$this->saldoLinea($ln);
            $totPasivo += $s;
            $pasivo[] = array('label' => $ln['label'], 'saldo' => $s, 'verificar' => !empty($ln['verificar']));
        }
        $posicionCambiaria = $totActivo - $totPasivo;

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
