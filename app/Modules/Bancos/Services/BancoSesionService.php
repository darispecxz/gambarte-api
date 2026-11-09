<?php

namespace App\Modules\Bancos\Services;

use App\Modules\Shared\Database\RawConnection as DB;

/**
 * Apertura y cierre de banca (arqueo diario de cuentas bancarias).
 *
 * ── Contexto ─────────────────────────────────────────────────────────────────
 * El legacy NO tiene apertura/cierre de banca como tal: `cierre_banco.php` es un
 * reporte ("Operaciones en Bancos"). Lo que sí existe es el arqueo dentro de
 * `movimiento_cuenta_bancaria`, columna `conciliacion`:
 *
 *   'A' → apertura  (glosa "APERTURA")
 *   'C' → cierre    (glosa "CIERRE")
 *
 * En ambos casos `monto` es el saldo ABSOLUTO de la cuenta, no un delta. Eso es
 * lo que asume `BankingBalanceService`: saldo = último arqueo + movimientos
 * posteriores. Verificado contra datos reales (cuenta 6942240013: cierre de
 * 188.930,69 el 15-feb-2024, sin movimientos posteriores → saldo 188.930,69).
 *
 * Y cada cuenta guarda en SU moneda: las de moneda 1 (USD) cierran con valores
 * como 43,69 y las de moneda 2 (BOB) promedian 90.479. No hay conversión: el
 * legacy convierte sólo al MOSTRAR (`$saldo / cotizacion_oficial()`), no al
 * guardar. Aquí se guarda igual, en moneda nativa.
 *
 * ── Qué se corrigió respecto del legacy ──────────────────────────────────────
 * 1. EL CIERRE GUARDA LO CONTADO, NO LO ESPERADO.
 *    Éste es el punto central del arqueo. Si al cerrar se guarda el saldo que
 *    calcula el sistema, la diferencia con lo contado se pierde, el arrastre del
 *    día siguiente nace "cuadrado" por construcción y el descuadre nunca se
 *    detecta ni se arrastra. Aquí se persiste el importe contado (que pasa a ser
 *    el ancla real del día siguiente) y la diferencia queda registrada en la
 *    glosa, de modo que es auditable.
 * 2. SE EXIGE GLOSA CUANDO HAY DIFERENCIA.
 *    El legacy cerraba en silencio. Si lo contado no cuadra, el cajero debe
 *    explicar por qué; el texto se guarda junto al importe.
 * 3. SE AVISA CUANDO FALTA EL CIERRE ANTERIOR.
 *    El legacy tenía un "CIERRE AUTOMATICO" comentado que inventaba el cierre
 *    del día previo. En vez de inventar importes, se informa del hueco y se deja
 *    que el arqueo de hoy sea el ancla: nunca se fabrica un saldo.
 *
 * ── Agrupación ───────────────────────────────────────────────────────────────
 * `estado()` devuelve las cuentas agrupadas POR ENTIDAD FINANCIERA, que es como
 * trabaja el cajero: el banco entrega un extracto por entidad y se abre/cierra
 * la banca entera de ese banco, no cuenta por cuenta. Si hay varias monedas se
 * mantienen como grupos separados, porque cada una es un arqueo independiente.
 */
class BancoSesionService
{
    /** Conciliaciones del libro bancario que representan un arqueo. */
    private const CONC_APERTURA = 'A';
    private const CONC_CIERRE   = 'C';

    /** Tolerancia de descuadre: por debajo se considera cuadrado. */
    private const TOLERANCIA = 0.01;

    /** Longitud máxima de la glosa almacenable. */
    private const GLOSA_MAX = 100;

    private BankingBalanceService $balance;

    public function __construct(BankingBalanceService $balance)
    {
        $this->balance = $balance;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Estado
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Estado de la banca de la agencia: cuentas abiertas hoy, agrupadas por
     * entidad financiera y moneda, con su saldo de apertura, su saldo actual y
     * el último arqueo anterior (para referenciar el arrastre).
     */
    public function estado(int $idAgencia): array
    {
        $hoy = date('Y-m-d');

        $cuentas = DB::select("
            SELECT cb.id_agencia, cb.id_entidad_financiera, cb.id_tipo_cuenta_bancaria,
                   cb.num_cuenta_bancaria, cb.id_moneda, cb.codsubcuenta,
                   ef.nombre_corto AS entidad, m.abreviatura AS moneda
            FROM cuenta_bancaria cb
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = cb.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            WHERE cb.estado = 'A' AND cb.id_agencia = :ag
            ORDER BY ef.nombre_corto, m.abreviatura, cb.num_cuenta_bancaria
        ", [':ag' => $idAgencia]);

        $out = [];
        $faltantes = 0;

        foreach ($cuentas as $c) {
            $params = $this->claveParams($c);

            $apertura = DB::selectOne("
                SELECT monto, fec_crea_movimiento_cuenta_bancaria AS fecha, glosa
                FROM movimiento_cuenta_bancaria
                WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                  AND num_cuenta_bancaria=:num AND id_moneda=:mon
                  AND conciliacion = 'A' AND DATE(fec_crea_movimiento_cuenta_bancaria) = :hoy
                ORDER BY fec_crea_movimiento_cuenta_bancaria DESC LIMIT 1
            ", $params + [':hoy' => $hoy]);

            $cierre = DB::selectOne("
                SELECT monto, fec_crea_movimiento_cuenta_bancaria AS fecha, glosa
                FROM movimiento_cuenta_bancaria
                WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                  AND num_cuenta_bancaria=:num AND id_moneda=:mon
                  AND conciliacion = 'C' AND DATE(fec_crea_movimiento_cuenta_bancaria) = :hoy
                ORDER BY fec_crea_movimiento_cuenta_bancaria DESC LIMIT 1
            ", $params + [':hoy' => $hoy]);

            // Arqueo anterior a hoy: es el arrastre con el que la cuenta llega.
            $anterior = DB::selectOne("
                SELECT monto, conciliacion, fec_crea_movimiento_cuenta_bancaria AS fecha, glosa
                FROM movimiento_cuenta_bancaria
                WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                  AND num_cuenta_bancaria=:num AND id_moneda=:mon
                  AND conciliacion IN ('A','C')
                  AND DATE(fec_crea_movimiento_cuenta_bancaria) < :hoy
                ORDER BY fec_crea_movimiento_cuenta_bancaria DESC LIMIT 1
            ", $params + [':hoy' => $hoy]);

            // Lo que el sistema espera tener hoy según el libro.
            $delSistema = $this->balance->saldoCuenta(
                (int) $c['id_agencia'], (int) $c['id_entidad_financiera'],
                $c['num_cuenta_bancaria'], (int) $c['id_moneda']
            );

            $montoApertura = $apertura ? (float) $apertura['monto'] : null;
            $montoCierre   = $cierre ? (float) $cierre['monto'] : null;

            $estado = !$apertura ? 'sin_abrir' : ($cierre ? 'cerrada' : 'abierta');
            if (!$anterior) { $faltantes++; }

            // Descuadre del cierre de hoy: lo contado menos lo que el sistema
            // esperaba en ese momento.
            $diferencia = null;
            if ($cierre) {
                $diferencia = round($montoCierre - $this->esperadoAlCerrar($c, $cierre, $apertura, $montoApertura), 4);
            }

            $out[] = [
                'id_agencia'      => (int) $c['id_agencia'],
                'id_entidad'      => (int) $c['id_entidad_financiera'],
                'id_tipo_cuenta'  => (int) $c['id_tipo_cuenta_bancaria'],
                'num_cuenta'      => $c['num_cuenta_bancaria'],
                'id_moneda'       => (int) $c['id_moneda'],
                'entidad'         => $c['entidad'],
                'moneda'          => $c['moneda'],
                'clave'           => $this->claveCuenta($c),
                'estado'          => $estado,
                // Saldo actual del libro = ancla (apertura de hoy si existe) + movimientos.
                'saldo_actual'    => $delSistema,
                'saldo_apertura'  => $montoApertura,
                'hora_apertura'   => $apertura['fecha'] ?? null,
                'saldo_cierre'    => $montoCierre,
                'hora_cierre'     => $cierre['fecha'] ?? null,
                'glosa_apertura'  => $apertura['glosa'] ?? null,
                'glosa_cierre'    => $cierre['glosa'] ?? null,
                'diferencia'      => $diferencia,
                'descuadrado'     => $diferencia !== null && abs($diferencia) > self::TOLERANCIA,
                // Arrastre previo: sirve de referencia al abrir.
                'saldo_anterior'      => $anterior ? (float) $anterior['monto'] : null,
                'fecha_anterior'      => $anterior['fecha'] ?? null,
                'tipo_anterior'       => $anterior['conciliacion'] ?? null,
                'sin_arqueo_anterior' => !$anterior,
            ];
        }

        return [
            'fecha'    => $hoy,
            'agencia'  => $idAgencia,
            'cuentas'  => $out,
            'grupos'   => $this->agrupar($out),
            'resumen'  => [
                'total'        => count($out),
                'sin_abrir'    => count(array_filter($out, fn($c) => $c['estado'] === 'sin_abrir')),
                'abiertas'     => count(array_filter($out, fn($c) => $c['estado'] === 'abierta')),
                'cerradas'     => count(array_filter($out, fn($c) => $c['estado'] === 'cerrada')),
                'descuadradas' => count(array_filter($out, fn($c) => $c['descuadrado'])),
                'sin_arqueo_anterior' => $faltantes,
                'esta_abierta' => count(array_filter($out, fn($c) => $c['estado'] === 'abierta')) > 0,
            ],
        ];
    }

    /**
     * Agrupa las cuentas por entidad financiera. Si una entidad tiene cuentas en
     * varias monedas se emite un grupo por moneda, porque el arqueo de una cuenta
     * en USD y otra en BOB son independientes.
     */
    private function agrupar(array $cuentas): array
    {
        $grupos = [];
        foreach ($cuentas as $c) {
            $k = $c['id_entidad'] . ':' . $c['id_moneda'];
            if (!isset($grupos[$k])) {
                $grupos[$k] = [
                    'id_entidad'  => $c['id_entidad'],
                    'entidad'     => $c['entidad'],
                    'id_moneda'   => $c['id_moneda'],
                    'moneda'      => $c['moneda'],
                    'cuentas'     => [],
                    'claves'      => [],
                    'saldo_total' => 0.0,
                    // Contadores por grupo: se acumulan aquí mismo para no
                    // recorrer de nuevo la lista completa (y no mezclar cuentas
                    // de otros grupos al calcular el estado).
                    '_n'          => 0,
                    '_abiertas'   => 0,
                    '_cerradas'   => 0,
                ];
            }
            $grupos[$k]['cuentas'][]    = $c['clave'];
            $grupos[$k]['claves'][]     = $c['clave'];
            $grupos[$k]['saldo_total'] += (float) $c['saldo_actual'];
            $grupos[$k]['_n']++;
            if ($c['estado'] === 'abierta') $grupos[$k]['_abiertas']++;
            if ($c['estado'] === 'cerrada') $grupos[$k]['_cerradas']++;
        }

        foreach ($grupos as $k => $g) {
            $n = $g['_n'];
            $abiertas = $g['_abiertas'];
            $cerradas = $g['_cerradas'];

            // Estado del grupo: permite un botón único por banco.
            if ($abiertas === 0 && $cerradas === 0) $estadoGrupo = 'sin_abrir';
            elseif ($cerradas === $n)               $estadoGrupo = 'cerrada';
            elseif ($abiertas === $n)               $estadoGrupo = 'abierta';
            else                                    $estadoGrupo = 'parcial';

            $grupos[$k]['estado']       = $estadoGrupo;
            $grupos[$k]['total']        = $n;
            $grupos[$k]['abiertas']     = $abiertas;
            $grupos[$k]['cerradas']     = $cerradas;
            $grupos[$k]['saldo_total']  = round((float) $g['saldo_total'], 4);
            $grupos[$k]['puede_abrir']  = $estadoGrupo === 'sin_abrir';
            $grupos[$k]['puede_cerrar'] = $estadoGrupo === 'abierta' || $estadoGrupo === 'parcial';
            unset($grupos[$k]['_n'], $grupos[$k]['_abiertas'], $grupos[$k]['_cerradas']);
        }

        return array_values($grupos);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Apertura
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Abre la banca de las cuentas indicadas.
     *
     * El importe de apertura es el saldo que calcula el sistema, salvo que el
     * cajero informe lo que contó (`$montos` por clave). En ambos casos se guarda
     * un importe absoluto: la apertura fija el ancla del día.
     *
     * @param array $cuentas claves "agencia|entidad|tipo|numCuenta|moneda"
     * @param array $glosas  glosa libre por clave (opcional)
     * @param array $montos  saldo contado por clave (opcional)
     */
    public function abrir(int $idAgencia, array $cuentas, int $codUsuario, string $ip, array $glosas = [], array $montos = []): array
    {
        if (!$cuentas) {
            return ['success' => false, 'message' => 'Debe seleccionar al menos una cuenta.'];
        }

        $hoy = date('Y-m-d');
        $abiertas = [];
        $omitidas = [];
        $faltantes = [];
        $detalle = [];

        try {
            $resultado = DB::transaction(function () use ($idAgencia, $cuentas, $codUsuario, $hoy, $glosas, $montos, &$abiertas, &$omitidas, &$faltantes, &$detalle) {
                foreach ($cuentas as $clave) {
                    $c = $this->resolverCuenta($idAgencia, $clave);
                    if (!$c) { $omitidas[] = 'cuenta no encontrada'; continue; }

                    $params = $this->claveParams($c);
                    $k = $this->claveCuenta($c);

                    $ya = DB::selectOne("
                        SELECT 1 FROM movimiento_cuenta_bancaria
                        WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                          AND num_cuenta_bancaria=:num AND id_moneda=:mon
                          AND conciliacion = 'A' AND DATE(fec_crea_movimiento_cuenta_bancaria) = :hoy LIMIT 1
                    ", $params + [':hoy' => $hoy]);
                    if ($ya) { $omitidas[] = $k . ' (ya abierta)'; continue; }

                    // Sin arqueo previo el arrastre es desconocido: se informa,
                    // no se inventa un saldo.
                    $anterior = DB::selectOne("
                        SELECT 1 FROM movimiento_cuenta_bancaria
                        WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                          AND num_cuenta_bancaria=:num AND id_moneda=:mon
                          AND conciliacion IN ('A','C')
                          AND DATE(fec_crea_movimiento_cuenta_bancaria) < :hoy LIMIT 1
                    ", $params + [':hoy' => $hoy]);
                    if (!$anterior) { $faltantes[] = $k; }

                    $saldo = $this->balance->saldoCuenta(
                        (int) $c['id_agencia'], (int) $c['id_entidad_financiera'],
                        $c['num_cuenta_bancaria'], (int) $c['id_moneda']
                    );

                    $contado = isset($montos[$k]) && $montos[$k] !== null && $montos[$k] !== ''
                        ? round((float) $montos[$k], 4)
                        : $saldo;

                    $this->insertarArqueo($c, $contado, self::CONC_APERTURA, $this->glosa($glosas, $k, 'APERTURA'), $codUsuario);

                    $abiertas[] = $k;
                    $detalle[] = [
                        'clave'      => $k,
                        'num_cuenta' => $c['num_cuenta_bancaria'],
                        'moneda'     => $c['moneda'],
                        'importe'    => $contado,
                        'sistema'    => $saldo,
                        'diferencia' => round($contado - $saldo, 4),
                    ];
                }

                if (!$abiertas) {
                    throw new \RuntimeException('No se abrió ninguna cuenta. ' . implode(', ', array_slice($omitidas, 0, 3)));
                }

                $msg = 'Banca abierta: ' . count($abiertas) . ' cuenta(s).';
                if ($omitidas) { $msg .= ' Omitidas: ' . count($omitidas) . '.'; }
                if ($faltantes) { $msg .= ' Atención: ' . count($faltantes) . ' cuenta(s) sin arqueo anterior.'; }

                return [
                    'success' => true,
                    'message' => $msg,
                    'data' => [
                        'abiertas'  => $abiertas,
                        'detalle'   => $detalle,
                        'omitidas'  => $omitidas,
                        'faltantes' => $faltantes,
                        'fecha'     => $hoy,
                    ],
                ];
            });
        } catch (\RuntimeException $e) {
            // El motivo real (cuenta no encontrada, ya abierta…) es lo que el
            // cajero necesita ver, no un error 500.
            return ['success' => false, 'message' => $e->getMessage(), 'data' => ['omitidas' => $omitidas]];
        }

        return $resultado;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Cierre
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cierra la banca de las cuentas indicadas.
     *
     * `$montos` es lo CONTADO por el cajero (clave => importe). Ese importe es el
     * que se guarda como saldo de cierre —no el que calcula el sistema— porque es
     * el ancla real del día siguiente y lo que permite arrastrar el descuadre.
     *
     * Si lo contado no coincide con lo esperado hay que indicar un motivo en
     * `$glosas`; de lo contrario el cierre se rechaza. Cerrar "cuadrado" cuando
     * no lo está es justamente lo que impedía detectar diferencias.
     */
    public function cerrar(int $idAgencia, array $cuentas, array $montos, int $codUsuario, string $ip, array $glosas = []): array
    {
        if (!$cuentas) {
            return ['success' => false, 'message' => 'Debe seleccionar al menos una cuenta.'];
        }

        $hoy = date('Y-m-d');
        $cerradas = [];
        $detalle = [];
        $omitidas = [];
        $sinMotivo = [];

        try {
            $resultado = DB::transaction(function () use ($idAgencia, $cuentas, $montos, $codUsuario, $hoy, $glosas, &$cerradas, &$detalle, &$omitidas, &$sinMotivo) {
                // Primera pasada: validar. Así no se cierra nada a medias si una
                // cuenta descuadra y falta el motivo en otra.
                $plan = [];
                foreach ($cuentas as $clave) {
                    $c = $this->resolverCuenta($idAgencia, $clave);
                    if (!$c) { $omitidas[] = 'cuenta no encontrada'; continue; }

                    $params = $this->claveParams($c);
                    $k = $this->claveCuenta($c);

                    $apertura = DB::selectOne("
                        SELECT 1 FROM movimiento_cuenta_bancaria
                        WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                          AND num_cuenta_bancaria=:num AND id_moneda=:mon
                          AND conciliacion = 'A' AND DATE(fec_crea_movimiento_cuenta_bancaria) = :hoy LIMIT 1
                    ", $params + [':hoy' => $hoy]);
                    if (!$apertura) { $omitidas[] = $k . ' (sin apertura hoy)'; continue; }

                    $ya = DB::selectOne("
                        SELECT 1 FROM movimiento_cuenta_bancaria
                        WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                          AND num_cuenta_bancaria=:num AND id_moneda=:mon
                          AND conciliacion = 'C' AND DATE(fec_crea_movimiento_cuenta_bancaria) = :hoy LIMIT 1
                    ", $params + [':hoy' => $hoy]);
                    if ($ya) { $omitidas[] = $k . ' (ya cerrada)'; continue; }

                    $esperado = $this->balance->saldoCuenta(
                        (int) $c['id_agencia'], (int) $c['id_entidad_financiera'],
                        $c['num_cuenta_bancaria'], (int) $c['id_moneda']
                    );

                    $informado = isset($montos[$k]) && $montos[$k] !== null && $montos[$k] !== '';
                    $contado = $informado ? round((float) $montos[$k], 4) : $esperado;
                    $diferencia = round($contado - $esperado, 4);

                    $motivo = trim((string) ($glosas[$k] ?? ''));
                    if (abs($diferencia) > self::TOLERANCIA && $motivo === '') {
                        $sinMotivo[] = $k;
                        continue;
                    }

                    $plan[] = compact('c', 'k', 'esperado', 'contado', 'diferencia', 'motivo');
                }

                if ($sinMotivo) {
                    throw new \RuntimeException(
                        'Hay ' . count($sinMotivo) . ' cuenta(s) con diferencia sin motivo. '
                        . 'Indique el motivo del descuadre antes de cerrar.'
                    );
                }

                if (!$plan) {
                    throw new \RuntimeException('No se cerró ninguna cuenta. ' . implode(', ', array_slice($omitidas, 0, 3)));
                }

                // Segunda pasada: registrar.
                foreach ($plan as $p) {
                    $c = $p['c'];
                    $diferencia = $p['diferencia'];

                    $glosa = $this->glosaCierre($p['motivo'], $p['esperado'], $p['contado'], $diferencia);

                    // Se guarda lo CONTADO: es el saldo real y el ancla de mañana.
                    $this->insertarArqueo($c, $p['contado'], self::CONC_CIERRE, $glosa, $codUsuario);

                    $cerradas[] = $p['k'];
                    $detalle[] = [
                        'clave'      => $p['k'],
                        'num_cuenta' => $c['num_cuenta_bancaria'],
                        'moneda'     => $c['moneda'],
                        'esperado'   => $p['esperado'],
                        'contado'    => $p['contado'],
                        'diferencia' => $diferencia,
                        'descuadrado' => abs($diferencia) > self::TOLERANCIA,
                        'motivo'     => $p['motivo'] !== '' ? $p['motivo'] : null,
                        'glosa'      => $glosa,
                    ];
                }

                $conDif = array_filter($detalle, fn($d) => $d['descuadrado']);
                $msg = 'Banca cerrada: ' . count($cerradas) . ' cuenta(s).';
                if ($conDif) { $msg .= ' ' . count($conDif) . ' con diferencia.'; }
                if ($omitidas) { $msg .= ' Omitidas: ' . count($omitidas) . '.'; }

                return [
                    'success' => true,
                    'message' => $msg,
                    'data' => [
                        'cerradas' => $cerradas,
                        'detalle'  => $detalle,
                        'omitidas' => $omitidas,
                        'fecha'    => $hoy,
                    ],
                ];
            });
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => ['omitidas' => $omitidas, 'sin_motivo' => $sinMotivo]];
        }

        return $resultado;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Internos
    // ═══════════════════════════════════════════════════════════════════

    /** Inserta una fila de arqueo ('A' o 'C') con importe absoluto. */
    private function insertarArqueo(array $c, float $monto, string $conciliacion, string $glosa, int $codUsuario): void
    {
        $ok = DB::execute("
            INSERT INTO movimiento_cuenta_bancaria
                (id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria,
                 id_moneda, monto, cod_usuario, fec_crea_movimiento_cuenta_bancaria, conciliacion, glosa)
            VALUES (:ag, :tcb, :ent, :num, :mon, :monto, :usr, NOW(), :conc, :glosa)
        ", [
            ':ag' => (int) $c['id_agencia'], ':tcb' => (int) $c['id_tipo_cuenta_bancaria'],
            ':ent' => (int) $c['id_entidad_financiera'], ':num' => $c['num_cuenta_bancaria'],
            ':mon' => (int) $c['id_moneda'], ':monto' => round($monto, 4),
            ':usr' => $codUsuario, ':conc' => $conciliacion,
            ':glosa' => mb_substr($glosa, 0, self::GLOSA_MAX),
        ]);
        if ($ok === 0) {
            throw new \RuntimeException('No se pudo registrar el arqueo de la cuenta ' . $c['num_cuenta_bancaria'] . '.');
        }
    }

    /** Glosa de apertura: la del cajero si la hay, si no "APERTURA". */
    private function glosa(array $glosas, string $clave, string $defecto): string
    {
        $g = trim((string) ($glosas[$clave] ?? ''));
        return $g === '' ? $defecto : $g;
    }

    /**
     * Glosa de cierre. Si cuadra es "CIERRE"; si no, deja constancia del motivo
     * y de los importes, de modo que el descuadre quede auditable en el libro.
     */
    private function glosaCierre(string $motivo, float $esperado, float $contado, float $diferencia): string
    {
        if (abs($diferencia) <= self::TOLERANCIA) {
            return $motivo === '' ? 'CIERRE' : 'CIERRE ' . $motivo;
        }

        return sprintf(
            'CIERRE dif %.2f (esp %.2f / cont %.2f): %s',
            $diferencia, $esperado, $contado, $motivo
        );
    }

    /**
     * Saldo que el sistema esperaba al momento del cierre de hoy.
     *
     * Se recalcula a partir del arqueo de apertura de hoy más los movimientos
     * posteriores a esa apertura, sin contar el propio cierre. Así la diferencia
     * queda expresada contra el estado real del libro en ese instante.
     */
    private function esperadoAlCerrar(array $c, array $cierre, ?array $apertura, ?float $montoApertura): float
    {
        $params = $this->claveParams($c);

        $desde = $apertura['fecha'] ?? null;
        if ($desde === null) {
            // Sin apertura hoy, el ancla es el último arqueo anterior a hoy.
            $anterior = DB::selectOne("
                SELECT monto, fec_crea_movimiento_cuenta_bancaria AS fecha
                FROM movimiento_cuenta_bancaria
                WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                  AND num_cuenta_bancaria=:num AND id_moneda=:mon
                  AND conciliacion IN ('A','C')
                  AND DATE(fec_crea_movimiento_cuenta_bancaria) < :hoy
                ORDER BY fec_crea_movimiento_cuenta_bancaria DESC LIMIT 1
            ", $params + [':hoy' => date('Y-m-d')]);

            if (!$anterior) {
                // Sin referencias: sólo los movimientos del día.
                $suma = (float) DB::scalar("
                    SELECT COALESCE(SUM(monto), 0) FROM movimiento_cuenta_bancaria
                    WHERE id_agencia=:ag AND id_entidad_financiera=:ent
                      AND num_cuenta_bancaria=:num AND id_moneda=:mon
                      AND conciliacion NOT IN ('A','C')
                      AND DATE(fec_crea_movimiento_cuenta_bancaria) = :hoy
                ", $params + [':hoy' => date('Y-m-d')]);
                return round($suma, 4);
            }

            $montoApertura = (float) $anterior['monto'];
            $desde = $anterior['fecha'];
        }

        // Movimientos posteriores al ancla y hasta el instante del cierre.
        // El límite superior es imprescindible: si se omitiera, cualquier
        // movimiento registrado después del cierre (o el propio cierre de días
        // posteriores ya contabilizado) inflaría el esperado y la diferencia
        // saldría mal.
        $hasta = $cierre['fecha'] ?? null;

        $suma = (float) DB::scalar("
            SELECT COALESCE(SUM(monto), 0) FROM movimiento_cuenta_bancaria
            WHERE id_agencia=:ag AND id_entidad_financiera=:ent
              AND num_cuenta_bancaria=:num AND id_moneda=:mon
              AND conciliacion NOT IN ('A','C')
              AND fec_crea_movimiento_cuenta_bancaria > :desde
              AND fec_crea_movimiento_cuenta_bancaria <= :hasta
        ", $params + [':desde' => $desde, ':hasta' => $hasta]);

        return round((float) $montoApertura + $suma, 4);
    }

    /** Clave única de una cuenta: agencia|entidad|tipo|numCuenta|moneda. */
    private function claveCuenta(array $c): string
    {
        return $c['id_agencia'] . '|' . $c['id_entidad_financiera'] . '|'
             . $c['id_tipo_cuenta_bancaria'] . '|' . $c['num_cuenta_bancaria'] . '|' . $c['id_moneda'];
    }

    /** Resuelve una cuenta desde su clave. */
    private function resolverCuenta(int $idAgencia, $clave): ?array
    {
        if ($clave instanceof \stdClass) {
            $clave = json_decode(json_encode($clave), true);
        }
        if (is_array($clave)) {
            $clave = implode('|', [
                $clave['id_agencia'] ?? '', $clave['id_entidad'] ?? '', $clave['id_tipo_cuenta'] ?? '',
                $clave['num_cuenta'] ?? '', $clave['id_moneda'] ?? '',
            ]);
        }
        $partes = explode('|', (string) $clave);
        if (count($partes) !== 5) return null;

        [$ag, $ent, $tcb, $num, $mon] = $partes;
        if ((int) $ag !== $idAgencia) return null;

        return DB::selectOne("
            SELECT cb.*, ef.nombre_corto AS entidad, m.abreviatura AS moneda
            FROM cuenta_bancaria cb
            INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = cb.id_entidad_financiera
            INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
            WHERE cb.id_agencia=:ag AND cb.id_entidad_financiera=:ent
              AND cb.id_tipo_cuenta_bancaria=:tcb AND cb.num_cuenta_bancaria=:num
              AND cb.id_moneda=:mon AND cb.estado='A'
            LIMIT 1
        ", [':ag' => (int) $ag, ':ent' => (int) $ent, ':tcb' => (int) $tcb, ':num' => $num, ':mon' => (int) $mon]);
    }

    private function claveParams(array $c): array
    {
        return [
            ':ag'  => (int) $c['id_agencia'],
            ':ent' => (int) $c['id_entidad_financiera'],
            ':num' => $c['num_cuenta_bancaria'],
            ':mon' => (int) $c['id_moneda'],
        ];
    }
}
