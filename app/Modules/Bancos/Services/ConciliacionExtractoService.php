<?php

namespace App\Modules\Bancos\Services;

use App\Modules\Bancos\Extractos\DetectorExtracto;
use App\Modules\Bancos\Extractos\ExtractorPosicional;

/**
 * Conciliación de un extracto bancario contra el libro OBEL.
 *
 * El extracto (PDF del banco) se parsea a líneas normalizadas y cada una se
 * busca entre los movimientos de la cuenta en OBEL (`movimiento_cuenta_bancaria`).
 * El resultado clasifica cada línea en:
 *
 *   · conciliado    — hay un movimiento OBEL con misma fecha e importe (y, si el
 *                     extracto trae referencia, también coincide en la glosa).
 *   · dudoso        — coincide fecha + importe, pero la referencia del extracto no
 *                     aparece en la glosa OBEL: conviene revisarlo a mano.
 *   · no_registrado — el banco lo muestra pero OBEL no lo tiene; es candidato a
 *                     registrarse con `registrar()`.
 *
 * Además se informan los movimientos que OBEL tiene pero el extracto no (solo
 * como referencia; no se tocan).
 *
 * El importe se compara CON SIGNO: tanto el parser como `movimiento_cuenta_bancaria`
 * usan positivo = abono, negativo = cargo.
 */
class ConciliacionExtractoService
{
    /**
     * Tipos de acción permitidos al registrar una línea del extracto en OBEL.
     * La clave es el código de conciliación que se graba en el libro; se reúsan
     * los del legacy para no romper los reportes contables existentes.
     */
    public const TIPOS_ACCION = [
        'DE' => 'Depósito',
        'SE' => 'Retiro',
        'TF' => 'Transferencia',
        'QC' => 'Cobro QR',
        'CC' => 'Cobro de cheque',
        'AJ' => 'Ajuste / comisión',
    ];

    /**
     * Cuenta de contrapartida contable por tipo de acción (codsubcuenta del plan
     * de cuentas en `conta`). El asiento del ajuste se arma banco ↔ esta cuenta.
     *
     * Rellenar con los códigos reales del plan contable. Mientras un tipo quede
     * vacío, al registrarlo se asienta SOLO el movimiento bancario y se informa
     * que el asiento contable quedó pendiente de configurar su cuenta.
     */
    public const CONTRAPARTIDA = [
        'DE' => '',
        'SE' => '',
        'TF' => '',
        'QC' => '',
        'CC' => '',
        'AJ' => '',
    ];

    public function __construct(private BankingOpsService $ops) {}

    /** Bancos con parser disponible (para la interfaz). */
    public function bancosSoportados(): array
    {
        return (new DetectorExtracto())->bancosSoportados();
    }

    /** Catálogo de tipos de acción para el registro. */
    public function tiposAccion(): array
    {
        $out = [];
        foreach (self::TIPOS_ACCION as $codigo => $nombre) {
            $out[] = ['codigo' => $codigo, 'nombre' => $nombre];
        }
        return $out;
    }

    /**
     * Parsea el PDF y lo concilia contra OBEL.
     *
     * @param array $cuenta  {id_agencia, id_entidad, id_tipo_cuenta, num_cuenta, id_moneda, entidad?, moneda?}
     */
    public function conciliar(int $idAgencia, array $cuenta, string $rutaPdf): array
    {
        $ext    = (new ExtractorPosicional())->extraer($rutaPdf);
        $tokens = $ext['tokens'];
        $texto  = ExtractorPosicional::textoSinSeparadores($tokens);

        $parser = (new DetectorExtracto())->detectar($tokens, $texto);
        if ($parser === null) {
            throw new \RuntimeException(
                'No reconocemos el banco de este extracto. Por ahora solo se admiten Banco Ganadero y Banco Bisa.'
            );
        }

        $datos  = $parser->parsear($tokens, $texto);
        $lineasExtracto = $datos['lineas'] ?? [];

        // Rango de fechas para acotar la consulta OBEL (157 mil filas).
        [$desde, $hasta] = $this->rangoFechas($datos, $lineasExtracto);

        $movs = $this->ops->movimientos($idAgencia, [
            'cuenta'  => $cuenta['num_cuenta'] ?? null,
            'entidad' => $cuenta['id_entidad'] ?? null,
            'moneda'  => $cuenta['id_moneda'] ?? null,
            'desde'   => $desde,
            'hasta'   => $hasta,
        ], 3000);

        $usados = [];
        $lineas = [];
        $resumen = ['total' => 0, 'conciliados' => 0, 'dudosos' => 0, 'no_registrados' => 0];

        foreach ($lineasExtracto as $ln) {
            [$estado, $idx] = $this->emparejar($ln, $movs, $usados);
            if ($idx !== null) { $usados[$idx] = true; }

            $resumen['total']++;
            $resumen[$estado === 'conciliado' ? 'conciliados' : ($estado === 'dudoso' ? 'dudosos' : 'no_registrados')]++;

            $lineas[] = [
                'fecha'       => $ln['fecha'] ?? null,
                'hora'        => $ln['hora'] ?? null,
                'descripcion' => $ln['descripcion'] ?? null,
                'referencia'  => $ln['referencia'] ?? null,
                'documento'   => $ln['documento'] ?? null,
                'monto'       => isset($ln['monto']) ? (float) $ln['monto'] : null,
                'saldo'       => isset($ln['saldo']) ? (float) $ln['saldo'] : null,
                'estado'      => $estado,
                'obel'        => $idx !== null ? $this->resumenMov($movs[$idx]) : null,
            ];
        }

        // Movimientos OBEL que el extracto no muestra (solo informativo).
        $soloObel = [];
        foreach ($movs as $i => $m) {
            if (!isset($usados[$i])) { $soloObel[] = $this->resumenMov($m); }
        }

        return [
            'banco'    => ['clave' => $parser->clave(), 'nombre' => $parser->nombre()],
            'extracto' => [
                'desde'          => $datos['desde']          ?? $desde,
                'hasta'          => $datos['hasta']          ?? $hasta,
                'moneda'         => $datos['moneda']         ?? null,
                'numero_cuenta'  => $datos['numero_cuenta']  ?? null,
                'saldo_inicial'  => $datos['saldo_inicial']  ?? null,
                'saldo_final'    => $datos['saldo_final']    ?? null,
                'total_debitos'  => $datos['total_debitos']  ?? null,
                'total_creditos' => $datos['total_creditos'] ?? null,
            ],
            'advertencias' => $datos['advertencias'] ?? [],
            'resumen'      => $resumen + ['solo_obel' => count($soloObel)],
            'lineas'       => $lineas,
            'solo_obel'    => $soloObel,
            'tipos_accion' => $this->tiposAccion(),
        ];
    }

    /**
     * Registra una línea del extracto en el libro OBEL.
     *
     * Graba la fila en `movimiento_cuenta_bancaria` con el código de conciliación
     * elegido y una glosa que conserva el motivo, la razón y la referencia del
     * extracto para trazabilidad.
     *
     * @param array $cuenta {id_agencia, id_entidad, id_tipo_cuenta, num_cuenta, id_moneda}
     * @param array $linea  {fecha, monto, descripcion, referencia, documento}
     */
    public function registrar(int $idAgencia, array $cuenta, array $linea, string $tipoAccion, string $motivo, ?string $razon, int $codUsuario): array
    {
        if (!isset(self::TIPOS_ACCION[$tipoAccion])) {
            throw new \RuntimeException('El tipo de acción no es válido.');
        }
        if (trim($motivo) === '') {
            throw new \RuntimeException('El motivo es obligatorio.');
        }
        if (!isset($linea['monto']) || !is_numeric($linea['monto'])) {
            throw new \RuntimeException('La línea del extracto no tiene un importe válido.');
        }

        $monto = round((float) $linea['monto'], 2);

        $partes = ['CONCILIACION EXTRACTO', trim($motivo)];
        if (!empty($razon))               { $partes[] = trim($razon); }
        if (!empty($linea['referencia'])) { $partes[] = 'REF ' . $linea['referencia']; }
        if (!empty($linea['descripcion'])) { $partes[] = $linea['descripcion']; }
        $glosa = mb_substr(implode(' | ', $partes), 0, 250);

        $contra = self::CONTRAPARTIDA[$tipoAccion] ?? '';

        $contab = $this->ops->registrarAjusteConciliacion([
            'id_agencia'              => (int) ($cuenta['id_agencia'] ?? $idAgencia),
            'id_tipo_cuenta_bancaria' => (int) ($cuenta['id_tipo_cuenta'] ?? 0),
            'id_entidad_financiera'   => (int) ($cuenta['id_entidad'] ?? 0),
            'num_cuenta_bancaria'     => (string) ($cuenta['num_cuenta'] ?? ''),
            'id_moneda'               => (int) ($cuenta['id_moneda'] ?? 0),
            'codsubcuenta'            => (string) ($cuenta['codsubcuenta'] ?? ''),
        ], $monto, $tipoAccion, $codUsuario, $glosa, (string) \Illuminate\Support\Str::uuid(), $contra);

        return [
            'registrado'   => true,
            'monto'        => $monto,
            'conciliacion' => $tipoAccion,
            'contabilidad' => $contab,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Internos
    // ─────────────────────────────────────────────────────────────────

    /**
     * Empareja una línea del extracto con un movimiento OBEL por fecha e importe.
     *
     * @return array{0: 'conciliado'|'dudoso'|'no_registrado', 1: int|null}
     */
    private function emparejar(array $ln, array $movs, array $usados): array
    {
        $fecha = $ln['fecha'] ?? null;
        $monto = isset($ln['monto']) ? (float) $ln['monto'] : null;
        if ($fecha === null || $monto === null) {
            return ['no_registrado', null];
        }

        $candidatos = [];
        foreach ($movs as $i => $m) {
            if (isset($usados[$i])) { continue; }
            if (substr((string) $m['fecha'], 0, 10) !== $fecha) { continue; }
            if (abs(((float) $m['monto']) - $monto) > 0.005) { continue; }
            $candidatos[] = $i;
        }

        if (!$candidatos) {
            return ['no_registrado', null];
        }

        // Si el extracto trae referencia, se intenta confirmarla dentro de la glosa
        // OBEL (que no tiene columna de referencia propia). Si no aparece, queda
        // como dudoso para revisión manual.
        $ref = $this->normalizar($ln['referencia'] ?? '');
        $doc = $this->normalizar($ln['documento'] ?? '');

        if ($ref !== '' || $doc !== '') {
            foreach ($candidatos as $i) {
                $glosa = $this->normalizar($movs[$i]['glosa'] ?? '');
                if (($ref !== '' && str_contains($glosa, $ref)) || ($doc !== '' && str_contains($glosa, $doc))) {
                    return ['conciliado', $i];
                }
            }
            // Coincide fecha + importe, pero no se pudo confirmar la referencia.
            return ['dudoso', $candidatos[0]];
        }

        // El extracto no trae referencia: fecha + importe basta.
        return ['conciliado', $candidatos[0]];
    }

    private function resumenMov(array $m): array
    {
        return [
            'fecha'        => $m['fecha'] ?? null,
            'monto'        => isset($m['monto']) ? (float) $m['monto'] : null,
            'conciliacion' => $m['conciliacion'] ?? null,
            'tipo'         => $m['tipo'] ?? null,
            'glosa'        => $m['glosa'] ?? null,
            'usuario'      => $m['usuario'] ?? null,
        ];
    }

    /** Rango de fechas del extracto, con reserva a los extremos de las líneas. */
    private function rangoFechas(array $datos, array $lineas): array
    {
        $desde = $datos['desde'] ?? null;
        $hasta = $datos['hasta'] ?? null;

        if ($desde === null || $hasta === null) {
            $fechas = array_values(array_filter(array_map(fn($l) => $l['fecha'] ?? null, $lineas)));
            if ($fechas) {
                sort($fechas);
                $desde = $desde ?? $fechas[0];
                $hasta = $hasta ?? end($fechas);
            }
        }
        return [$desde, $hasta];
    }

    /** Normaliza para comparar referencias: mayúsculas, solo alfanumérico. */
    private function normalizar(string $s): string
    {
        $s = mb_strtoupper(ExtractorPosicional::normalizar($s), 'UTF-8');
        return preg_replace('/[^A-Z0-9]/', '', $s) ?? '';
    }
}
