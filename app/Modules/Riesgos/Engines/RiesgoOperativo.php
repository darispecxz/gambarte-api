<?php

namespace App\Modules\Riesgos\Engines;

use App\Modules\Shared\Database\RawConnection;

class RiesgoOperativo
{
    const FALLA_FALTANTE_WARN = 500;   const FALLA_FALTANTE_BAD = 2000;
    const BILLETE_WARN        = 1;     const BILLETE_BAD        = 3;
    const ANUL_TASA_WARN      = 5;     const ANUL_TASA_BAD      = 15;

    private $ini, $fin, $iniDt, $finDt;

    public function __construct($ini, $fin)
    {
        $this->ini = $ini;
        $this->fin = $fin;
        $this->iniDt = $ini . ' 00:00:00';
        $this->finDt = $fin . ' 23:59:59';
    }

    public function fallas()
    {
        $tot = RawConnection::selectOne(
            "SELECT COUNT(*) n,
                    COALESCE(SUM(CASE WHEN diferencia < 0 THEN -diferencia ELSE 0 END),0) faltante,
                    COALESCE(SUM(CASE WHEN diferencia > 0 THEN diferencia ELSE 0 END),0) sobrante
             FROM falla_caja WHERE fecha BETWEEN :ini AND :fin",
            array(':ini' => $this->ini, ':fin' => $this->fin), 'cgr'
        );

        $porAg = RawConnection::select(
            "SELECT ag.descripcion agencia, COUNT(*) n,
                    COALESCE(SUM(CASE WHEN f.diferencia < 0 THEN -f.diferencia ELSE 0 END),0) faltante,
                    COALESCE(SUM(CASE WHEN f.diferencia > 0 THEN f.diferencia ELSE 0 END),0) sobrante
             FROM falla_caja f
             JOIN caja c    ON c.id_caja = f.id_caja
             JOIN agencia ag ON ag.id_agencia = c.id_agencia
             WHERE f.fecha BETWEEN :ini AND :fin
             GROUP BY ag.id_agencia ORDER BY faltante DESC, n DESC",
            array(':ini' => $this->ini, ':fin' => $this->fin), 'cgr'
        );

        $n = (int) $tot['n'];
        $faltante = (float) $tot['faltante'];
        $sobrante = (float) $tot['sobrante'];
        return array(
            'n' => $n, 'faltante' => $faltante, 'sobrante' => $sobrante,
            'neto' => $sobrante - $faltante,
            'por_agencia' => $porAg,
            'semaforo' => $faltante >= self::FALLA_FALTANTE_BAD ? 'bad'
                        : ($faltante >= self::FALLA_FALTANTE_WARN ? 'warn' : 'ok'),
        );
    }

    public function billetes()
    {
        $eventos = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM billete_falso WHERE fec_crea_billete_falso BETWEEN :ini AND :fin",
            array(':ini' => $this->iniDt, ':fin' => $this->finDt), 'cgr'
        );

        $porMon = RawConnection::select(
            "SELECT co.id_moneda, m.abreviatura,
                    COALESCE(SUM(d.cantidad),0) billetes,
                    COALESCE(SUM(d.cantidad * co.valor),0) monto
             FROM billete_falso b
             JOIN detalle_billete_falso d ON d.id_billete_falso = b.id_billete_falso
             LEFT JOIN corte co ON co.id_corte = d.id_corte
             LEFT JOIN moneda m ON m.id_moneda = co.id_moneda
             WHERE b.fec_crea_billete_falso BETWEEN :ini AND :fin
             GROUP BY co.id_moneda ORDER BY monto DESC",
            array(':ini' => $this->iniDt, ':fin' => $this->finDt), 'cgr'
        );

        $porAg = RawConnection::select(
            "SELECT ag.descripcion agencia, COUNT(DISTINCT b.id_billete_falso) eventos,
                    COALESCE(SUM(d.cantidad),0) billetes,
                    COALESCE(SUM(d.cantidad * co.valor),0) monto
             FROM billete_falso b
             LEFT JOIN detalle_billete_falso d ON d.id_billete_falso = b.id_billete_falso
             LEFT JOIN corte co ON co.id_corte = d.id_corte
             JOIN agencia ag ON ag.id_agencia = b.id_agencia
             WHERE b.fec_crea_billete_falso BETWEEN :ini AND :fin
             GROUP BY ag.id_agencia ORDER BY eventos DESC, monto DESC",
            array(':ini' => $this->iniDt, ':fin' => $this->finDt), 'cgr'
        );

        return array(
            'eventos' => $eventos,
            'por_moneda' => $porMon,
            'por_agencia' => $porAg,
            'semaforo' => $eventos >= self::BILLETE_BAD ? 'bad'
                        : ($eventos >= self::BILLETE_WARN ? 'warn' : 'ok'),
        );
    }

    public function anulaciones()
    {
        $porEstado = RawConnection::select(
            "SELECT estado, COUNT(*) n FROM ao_solicitud_anulacion
             WHERE created_at BETWEEN :ini AND :fin GROUP BY estado ORDER BY n DESC",
            array(':ini' => $this->iniDt, ':fin' => $this->finDt), 'cgr'
        );

        $total = 0; $ejecutadas = 0;
        foreach ($porEstado as $e) {
            $total += (int) $e['n'];
            if (strcasecmp($e['estado'], 'EJECUTADO') === 0) $ejecutadas += (int) $e['n'];
        }

        $tiempoProm = (float) RawConnection::scalar(
            "SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)),0)
             FROM ao_solicitud_anulacion
             WHERE created_at BETWEEN :ini AND :fin AND estado = 'EJECUTADO'",
            array(':ini' => $this->iniDt, ':fin' => $this->finDt), 'cgr'
        );

        $totalOps = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM cambio WHERE fec_crea_cambio BETWEEN :ini AND :fin",
            array(':ini' => $this->iniDt, ':fin' => $this->finDt), 'cgr'
        );

        $tasaMil = $totalOps > 0 ? ($ejecutadas / $totalOps) * 1000 : 0.0;

        return array(
            'total' => $total, 'ejecutadas' => $ejecutadas, 'por_estado' => $porEstado,
            'tiempo_prom_min' => $tiempoProm, 'total_operaciones' => $totalOps, 'tasa_mil' => $tasaMil,
            'semaforo' => $tasaMil >= self::ANUL_TASA_BAD ? 'bad'
                        : ($tasaMil >= self::ANUL_TASA_WARN ? 'warn' : 'ok'),
        );
    }

    private static function peor($sems)
    {
        if (in_array('bad', $sems, true))  return 'bad';
        if (in_array('warn', $sems, true)) return 'warn';
        return 'ok';
    }

    public function informe()
    {
        $fallas = $this->fallas();
        $billetes = $this->billetes();
        $anul = $this->anulaciones();
        return array(
            'periodo'  => array('ini' => $this->ini, 'fin' => $this->fin),
            'fallas'   => $fallas, 'billetes' => $billetes, 'anulaciones' => $anul,
            'semaforo_global' => self::peor(array($fallas['semaforo'], $billetes['semaforo'], $anul['semaforo'])),
        );
    }
}
