<?php

namespace App\Modules\Riesgos\Engines;

use App\Modules\Shared\Database\RawConnection;

class RiesgoLiquidez
{
    public static function rubrosDisponibilidad()
    {
        return array(
            array('label' => 'Caja M/N',    'like' => '111011%', 'clase' => 'efectivo', 'moneda' => 'MN'),
            array('label' => 'Caja M/E',    'like' => '111012%', 'clase' => 'efectivo', 'moneda' => 'ME'),
            array('label' => 'Bancos M/N',  'like' => '113011%', 'clase' => 'bancos',   'moneda' => 'MN'),
            array('label' => 'Bancos M/E',  'like' => '113012%', 'clase' => 'bancos',   'moneda' => 'ME'),
            array('label' => 'Encaje legal','like' => '1120%',   'clase' => 'encaje',   'moneda' => 'MN'),
        );
    }

    public static function rubrosObligaciones()
    {
        return array(
            array('label' => 'Giros al exterior por pagar M/E',      'like' => '24401203', 'moneda' => 'ME'),
            array('label' => 'Remesas internacionales por pagar M/E','like' => '24401202', 'moneda' => 'ME'),
            array('label' => 'Costo de corresponsalía por pagar M/E','like' => '24299202', 'moneda' => 'ME'),
        );
    }

    private $fin, $ejercicio;

    public function __construct($ini, $fin)
    {
        $this->fin = $fin;
        $this->ejercicio = substr($fin, 0, 4);
    }

    private function saldoDeudor($like)
    {
        return (float) RawConnection::scalar(
            "SELECT COALESCE(SUM(p.debe - p.haber),0) s
             FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento
             WHERE p.codsubcuenta LIKE :like AND a.codejercicio = :ej AND a.fecha <= :fin",
            array(':like' => $like, ':ej' => $this->ejercicio, ':fin' => $this->fin), 'conta'
        );
    }

    private function saldoAcreedor($like)
    {
        return -$this->saldoDeudor($like);
    }

    public function informe()
    {
        $disp = array();
        $totDisp = 0.0; $totMN = 0.0; $totME = 0.0; $efectivo = 0.0; $bancos = 0.0;
        foreach (self::rubrosDisponibilidad() as $r) {
            $s = $this->saldoDeudor($r['like']);
            $totDisp += $s;
            if ($r['moneda'] === 'ME') $totME += $s; else $totMN += $s;
            if ($r['clase'] === 'efectivo') $efectivo += $s;
            if ($r['clase'] === 'bancos')   $bancos   += $s;
            $disp[] = array('label' => $r['label'], 'saldo' => $s, 'moneda' => $r['moneda'], 'clase' => $r['clase']);
        }

        $obl = array();
        $totObl = 0.0; $totOblME = 0.0;
        foreach (self::rubrosObligaciones() as $r) {
            $s = $this->saldoAcreedor($r['like']);
            $totObl += $s;
            if ($r['moneda'] === 'ME') $totOblME += $s;
            $obl[] = array('label' => $r['label'], 'saldo' => $s, 'moneda' => $r['moneda']);
        }

        $cobertura   = $totObl   > 0 ? $totDisp / $totObl   : null;
        $coberturaME = $totOblME > 0 ? $totME   / $totOblME : null;
        $brecha      = $totDisp - $totObl;
        $brechaME    = $totME   - $totOblME;
        $pctEfectivo = $totDisp != 0 ? $efectivo / $totDisp : 0.0;

        $sem = self::semaforo($cobertura);

        $ctas = RawConnection::select(
            "SELECT p.codsubcuenta, s.descripcion, SUM(p.debe - p.haber) saldo
             FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento
             JOIN co_subcuentas s ON s.codsubcuenta = p.codsubcuenta AND s.codejercicio = :ej
             WHERE p.codsubcuenta LIKE '1130%' AND a.codejercicio = :ej AND a.fecha <= :fin
             GROUP BY p.codsubcuenta HAVING saldo > 0.005
             ORDER BY saldo DESC",
            array(':ej' => $this->ejercicio, ':fin' => $this->fin), 'conta'
        );

        $totBancos = 0.0; foreach ($ctas as $c) $totBancos += (float) $c['saldo'];
        $hhi = 0.0; $conc = array();
        foreach ($ctas as $c) {
            $part = $totBancos > 0 ? (float) $c['saldo'] / $totBancos : 0.0;
            $hhi += $part * $part;
            $conc[] = array('cuenta' => $c['codsubcuenta'], 'descripcion' => $c['descripcion'],
                            'saldo' => (float) $c['saldo'], 'part' => $part);
        }

        return array(
            'periodo' => array('fin' => $this->fin, 'ejercicio' => $this->ejercicio),
            'disponibilidad' => $disp,
            'tot_disponibilidad' => $totDisp, 'tot_mn' => $totMN, 'tot_me' => $totME,
            'efectivo' => $efectivo, 'bancos' => $bancos, 'pct_efectivo' => $pctEfectivo,
            'obligaciones' => $obl, 'tot_obligaciones' => $totObl, 'tot_obligaciones_me' => $totOblME,
            'cobertura' => $cobertura, 'cobertura_me' => $coberturaME,
            'brecha' => $brecha, 'brecha_me' => $brechaME,
            'semaforo' => $sem,
            'concentracion' => $conc, 'tot_bancos' => $totBancos, 'hhi' => $hhi,
        );
    }

    public static function semaforo($cob)
    {
        if ($cob === null) return 'ok';
        if ($cob >= 1.5) return 'ok';
        if ($cob >= 1.0) return 'warn';
        return 'bad';
    }
}
