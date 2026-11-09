<?php

namespace App\Modules\Riesgos\Engines;

use App\Modules\Shared\Database\RawConnection;

class RiesgoPLAFT
{
    const LIMITE_LISTA = 40;

    const SLUG_UMBRAL_BOB   = 'plaft_umbral_bob';
    const SLUG_FRACC_MINOPS = 'plaft_fraccionamiento_minops';
    const SLUG_FREQ_OPS     = 'plaft_frecuencia_ops';

    const DEFAULT_UMBRAL_BOB   = 70000;
    const DEFAULT_FRACC_MINOPS = 3;
    const DEFAULT_FREQ_OPS     = 15;

    private $ini, $fin, $iniDt, $finDt;
    private $umbralBob, $fraccMinops, $freqOps;

    public function __construct($ini, $fin)
    {
        $this->ini = $ini; $this->fin = $fin;
        $this->iniDt = $ini . ' 00:00:00';
        $this->finDt = $fin . ' 23:59:59';
        $this->cargarParametros();
    }

    private function cargarParametros(): void
    {
        $slugs = [self::SLUG_UMBRAL_BOB, self::SLUG_FRACC_MINOPS, self::SLUG_FREQ_OPS];
        $rows = RawConnection::select(
            "SELECT slug, valor FROM parametros WHERE estado = 'A' AND slug IN ('" . implode("','", $slugs) . "')",
            [], 'cgr'
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['slug']] = $r['valor'];
        }
        $this->umbralBob  = (float) ($map[self::SLUG_UMBRAL_BOB]   ?? self::DEFAULT_UMBRAL_BOB);
        $this->fraccMinops = (int)  ($map[self::SLUG_FRACC_MINOPS] ?? self::DEFAULT_FRACC_MINOPS);
        $this->freqOps     = (int)  ($map[self::SLUG_FREQ_OPS]     ?? self::DEFAULT_FREQ_OPS);
    }

    private function rango() { return array(':ini' => $this->iniDt, ':fin' => $this->finDt); }

    public function umbral()
    {
        $tot = RawConnection::selectOne(
            "SELECT COUNT(*) n, COALESCE(SUM(total_operacion_bob),0) monto
             FROM cambio WHERE fec_crea_cambio BETWEEN :ini AND :fin AND total_operacion_bob >= :u",
            array_merge($this->rango(), array(':u' => $this->umbralBob)), 'cgr'
        );
        $lista = RawConnection::select(
            "SELECT c.num_doc_identidad, ag.descripcion agencia, c.id_operacion,
                    c.total_operacion_bob monto, c.fec_crea_cambio fecha
             FROM cambio c LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin AND c.total_operacion_bob >= :u
             ORDER BY c.total_operacion_bob DESC LIMIT " . self::LIMITE_LISTA,
            array_merge($this->rango(), array(':u' => $this->umbralBob)), 'cgr'
        );
        return array('n' => (int) $tot['n'], 'monto' => (float) $tot['monto'], 'lista' => $lista);
    }

    public function fraccionamiento()
    {
        $lista = RawConnection::select(
            "SELECT c.num_doc_identidad, DATE(c.fec_crea_cambio) dia,
                    COUNT(*) ops, SUM(c.total_operacion_bob) suma, MAX(c.total_operacion_bob) maxop
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin AND c.num_doc_identidad <> ''
             GROUP BY c.num_doc_identidad, DATE(c.fec_crea_cambio)
             HAVING ops >= :min AND suma >= :u AND maxop < :u
             ORDER BY suma DESC LIMIT " . self::LIMITE_LISTA,
            array_merge($this->rango(), array(':min' => $this->fraccMinops, ':u' => $this->umbralBob)), 'cgr'
        );
        $n = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM cambio c
                WHERE c.fec_crea_cambio BETWEEN :ini AND :fin AND c.num_doc_identidad <> ''
                GROUP BY c.num_doc_identidad, DATE(c.fec_crea_cambio)
                HAVING COUNT(*) >= :min AND SUM(c.total_operacion_bob) >= :u AND MAX(c.total_operacion_bob) < :u
             ) t",
            array_merge($this->rango(), array(':min' => $this->fraccMinops, ':u' => $this->umbralBob)), 'cgr'
        );
        return array('n' => $n, 'lista' => $lista);
    }

    public function frecuencia()
    {
        $lista = RawConnection::select(
            "SELECT c.num_doc_identidad, COUNT(*) ops, SUM(c.total_operacion_bob) suma
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin AND c.num_doc_identidad <> ''
             GROUP BY c.num_doc_identidad HAVING ops >= :f
             ORDER BY ops DESC LIMIT " . self::LIMITE_LISTA,
            array_merge($this->rango(), array(':f' => $this->freqOps)), 'cgr'
        );
        return array('n' => count($lista), 'lista' => $lista);
    }

    public function pep()
    {
        $lista = RawConnection::select(
            "SELECT c.num_doc_identidad, COUNT(*) ops, SUM(c.total_operacion_bob) suma
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin
               AND c.num_doc_identidad IN (SELECT num_doc_identidad FROM pep WHERE estado = 'A')
             GROUP BY c.num_doc_identidad ORDER BY suma DESC LIMIT " . self::LIMITE_LISTA,
            $this->rango(), 'cgr'
        );
        $tot = RawConnection::selectOne(
            "SELECT COUNT(*) ops, COUNT(DISTINCT c.num_doc_identidad) clientes
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin
               AND c.num_doc_identidad IN (SELECT num_doc_identidad FROM pep WHERE estado = 'A')",
            $this->rango(), 'cgr'
        );
        return array('ops' => (int) $tot['ops'], 'clientes' => (int) $tot['clientes'], 'lista' => $lista);
    }

    public function listas()
    {
        $lista = RawConnection::select(
            "SELECT c.num_doc_identidad, COUNT(*) ops, SUM(c.total_operacion_bob) suma
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin
               AND c.num_doc_identidad IN (SELECT numero_documento FROM personas_riesgos)
             GROUP BY c.num_doc_identidad ORDER BY suma DESC LIMIT " . self::LIMITE_LISTA,
            $this->rango(), 'cgr'
        );
        $tot = RawConnection::selectOne(
            "SELECT COUNT(*) ops, COUNT(DISTINCT c.num_doc_identidad) clientes
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin
               AND c.num_doc_identidad IN (SELECT numero_documento FROM personas_riesgos)",
            $this->rango(), 'cgr'
        );
        return array('ops' => (int) $tot['ops'], 'clientes' => (int) $tot['clientes'], 'lista' => $lista);
    }

    public function informe()
    {
        $totOps = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM cambio WHERE fec_crea_cambio BETWEEN :ini AND :fin",
            $this->rango(), 'cgr'
        );
        $montoOps = (float) RawConnection::scalar(
            "SELECT COALESCE(SUM(total_operacion_bob),0) FROM cambio WHERE fec_crea_cambio BETWEEN :ini AND :fin",
            $this->rango(), 'cgr'
        );

        $umbral = $this->umbral();
        $fracc  = $this->fraccionamiento();
        $frec   = $this->frecuencia();
        $pep    = $this->pep();
        $listas = $this->listas();

        if ($listas['ops'] > 0)                                          $sem = 'bad';
        elseif ($fracc['n'] > 0 || $umbral['n'] > 0 || $pep['ops'] > 0) $sem = 'warn';
        else                                                             $sem = 'ok';

        return array(
            'periodo' => array('ini' => $this->ini, 'fin' => $this->fin),
            'parametros' => array(
                'umbral' => $this->umbralBob, 'fracc_minops' => $this->fraccMinops, 'freq_ops' => $this->freqOps),
            'total_operaciones' => $totOps, 'monto_operaciones' => $montoOps,
            'umbral' => $umbral, 'fraccionamiento' => $fracc, 'frecuencia' => $frec,
            'pep' => $pep, 'listas' => $listas,
            'semaforo' => $sem,
        );
    }
}
