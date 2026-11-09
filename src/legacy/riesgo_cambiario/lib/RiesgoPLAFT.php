<?php
/**
 * RiesgoPLAFT.php — Monitoreo de Prevención de Legitimación de Ganancias Ilícitas,
 * Financiamiento del Terrorismo y de la Proliferación (PLA/FT/FP).
 *
 * Genera alertas sobre las operaciones de cambio del período:
 *   1. Operaciones sobre umbral.
 *   2. Fraccionamiento (structuring): varias operaciones bajo umbral, mismo
 *      cliente y día, que en conjunto igualan o superan el umbral.
 *   3. Clientes de alta frecuencia.
 *   4. Coincidencias con la base de PEP.
 *   5. Coincidencias con listas de riesgo (sanciones / observados).
 *
 * IMPORTANTE: los umbrales son PROVISIONALES y configurables; deben ajustarse a
 * la normativa UIF vigente y al manual de prevención de la entidad. El cruce se
 * hace por número de documento y es preliminar: requiere confirmación manual del
 * Oficial de Cumplimiento (posibles homónimos / falsos positivos).
 */

namespace RiesgoCambiario;

require_once __DIR__ . '/Db.php';

class RiesgoPLAFT
{
    // --- Parámetros provisionales (ajustar a normativa UIF / manual interno) ---
    const UMBRAL_BOB   = 70000;   // ~ USD 10.000 — umbral de operación relevante
    const FRACC_MINOPS = 3;       // # mínimo de operaciones para sospecha de fraccionamiento
    const FREQ_OPS     = 15;      // # operaciones en el período => alta frecuencia
    const LIMITE_LISTA = 40;      // máximo de filas a listar por sección

    private $ini, $fin, $iniDt, $finDt;

    public function __construct($ini, $fin)
    {
        $this->ini = $ini; $this->fin = $fin;
        $this->iniDt = $ini . ' 00:00:00';
        $this->finDt = $fin . ' 23:59:59';
    }

    private function rango() { return array(':ini' => $this->iniDt, ':fin' => $this->finDt); }

    /** Operaciones individuales sobre el umbral. */
    public function umbral()
    {
        $tot = Db::row('cgr',
            "SELECT COUNT(*) n, COALESCE(SUM(total_operacion_bob),0) monto
             FROM cambio WHERE fec_crea_cambio BETWEEN :ini AND :fin AND total_operacion_bob >= :u",
            array_merge($this->rango(), array(':u' => self::UMBRAL_BOB)));
        $lista = Db::q('cgr',
            "SELECT c.num_doc_identidad, ag.descripcion agencia, c.id_operacion,
                    c.total_operacion_bob monto, c.fec_crea_cambio fecha
             FROM cambio c LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin AND c.total_operacion_bob >= :u
             ORDER BY c.total_operacion_bob DESC LIMIT " . self::LIMITE_LISTA,
            array_merge($this->rango(), array(':u' => self::UMBRAL_BOB)));
        return array('n' => (int) $tot['n'], 'monto' => (float) $tot['monto'], 'lista' => $lista);
    }

    /** Fraccionamiento: >= FRACC_MINOPS ops/día, suma >= umbral, cada una < umbral. */
    public function fraccionamiento()
    {
        $lista = Db::q('cgr',
            "SELECT c.num_doc_identidad, DATE(c.fec_crea_cambio) dia,
                    COUNT(*) ops, SUM(c.total_operacion_bob) suma, MAX(c.total_operacion_bob) maxop
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin AND c.num_doc_identidad <> ''
             GROUP BY c.num_doc_identidad, DATE(c.fec_crea_cambio)
             HAVING ops >= :min AND suma >= :u AND maxop < :u
             ORDER BY suma DESC LIMIT " . self::LIMITE_LISTA,
            array_merge($this->rango(), array(':min' => self::FRACC_MINOPS, ':u' => self::UMBRAL_BOB)));
        // conteo total (sin límite de filas)
        $n = (int) Db::val('cgr',
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM cambio c
                WHERE c.fec_crea_cambio BETWEEN :ini AND :fin AND c.num_doc_identidad <> ''
                GROUP BY c.num_doc_identidad, DATE(c.fec_crea_cambio)
                HAVING COUNT(*) >= :min AND SUM(c.total_operacion_bob) >= :u AND MAX(c.total_operacion_bob) < :u
             ) t",
            array_merge($this->rango(), array(':min' => self::FRACC_MINOPS, ':u' => self::UMBRAL_BOB)));
        return array('n' => $n, 'lista' => $lista);
    }

    /** Clientes de alta frecuencia (>= FREQ_OPS operaciones en el período). */
    public function frecuencia()
    {
        $lista = Db::q('cgr',
            "SELECT c.num_doc_identidad, COUNT(*) ops, SUM(c.total_operacion_bob) suma
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin AND c.num_doc_identidad <> ''
             GROUP BY c.num_doc_identidad HAVING ops >= :f
             ORDER BY ops DESC LIMIT " . self::LIMITE_LISTA,
            array_merge($this->rango(), array(':f' => self::FREQ_OPS)));
        return array('n' => count($lista), 'lista' => $lista);
    }

    /** Operaciones de clientes con coincidencia en la base de PEP. */
    public function pep()
    {
        $lista = Db::q('cgr',
            "SELECT c.num_doc_identidad, COUNT(*) ops, SUM(c.total_operacion_bob) suma
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin
               AND c.num_doc_identidad IN (SELECT num_doc_identidad FROM pep WHERE estado = 'A')
             GROUP BY c.num_doc_identidad ORDER BY suma DESC LIMIT " . self::LIMITE_LISTA,
            $this->rango());
        $tot = Db::row('cgr',
            "SELECT COUNT(*) ops, COUNT(DISTINCT c.num_doc_identidad) clientes
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin
               AND c.num_doc_identidad IN (SELECT num_doc_identidad FROM pep WHERE estado = 'A')",
            $this->rango());
        return array('ops' => (int) $tot['ops'], 'clientes' => (int) $tot['clientes'], 'lista' => $lista);
    }

    /** Operaciones de clientes con coincidencia en listas de riesgo (sanciones/observados). */
    public function listas()
    {
        $lista = Db::q('cgr',
            "SELECT c.num_doc_identidad, COUNT(*) ops, SUM(c.total_operacion_bob) suma
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin
               AND c.num_doc_identidad IN (SELECT numero_documento FROM personas_riesgos)
             GROUP BY c.num_doc_identidad ORDER BY suma DESC LIMIT " . self::LIMITE_LISTA,
            $this->rango());
        $tot = Db::row('cgr',
            "SELECT COUNT(*) ops, COUNT(DISTINCT c.num_doc_identidad) clientes
             FROM cambio c
             WHERE c.fec_crea_cambio BETWEEN :ini AND :fin
               AND c.num_doc_identidad IN (SELECT numero_documento FROM personas_riesgos)",
            $this->rango());
        return array('ops' => (int) $tot['ops'], 'clientes' => (int) $tot['clientes'], 'lista' => $lista);
    }

    public function informe()
    {
        $totOps = (int) Db::val('cgr',
            "SELECT COUNT(*) FROM cambio WHERE fec_crea_cambio BETWEEN :ini AND :fin", $this->rango());
        $montoOps = (float) Db::val('cgr',
            "SELECT COALESCE(SUM(total_operacion_bob),0) FROM cambio WHERE fec_crea_cambio BETWEEN :ini AND :fin", $this->rango());

        $umbral = $this->umbral();
        $fracc  = $this->fraccionamiento();
        $frec   = $this->frecuencia();
        $pep    = $this->pep();
        $listas = $this->listas();

        // Semáforo: listas = crítico; fraccionamiento/umbral/pep = observación.
        if ($listas['ops'] > 0)                 $sem = 'bad';
        elseif ($fracc['n'] > 0 || $umbral['n'] > 0 || $pep['ops'] > 0) $sem = 'warn';
        else                                    $sem = 'ok';

        return array(
            'periodo' => array('ini' => $this->ini, 'fin' => $this->fin),
            'parametros' => array(
                'umbral' => self::UMBRAL_BOB, 'fracc_minops' => self::FRACC_MINOPS, 'freq_ops' => self::FREQ_OPS),
            'total_operaciones' => $totOps, 'monto_operaciones' => $montoOps,
            'umbral' => $umbral, 'fraccionamiento' => $fracc, 'frecuencia' => $frec,
            'pep' => $pep, 'listas' => $listas,
            'semaforo' => $sem,
        );
    }
}
