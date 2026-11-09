<?php

namespace App\Modules\Bancos\Extractos\Parsers;

use App\Modules\Bancos\Extractos\ExtractorPosicional;
use App\Modules\Bancos\Extractos\ParserExtracto;

/**
 * Extracto de cuenta de Banco Bisa (E-Banking / "Estado de Cuenta").
 *
 * ── Forma del documento ──────────────────────────────────────────────────────
 * A4 vertical, una sola tabla, cabecera:
 *
 *   FechaHora | Nro.Chq | Descripción | Importe | Saldo | Info. Complementaria | Sucursal | Nro.Ref. | Nro.Lote
 *
 * Diferencias importantes respecto de Ganadero:
 *
 *  1. LA FECHA VA PEGADA A LA HORA en el mismo fragmento ("25/09/2608:38"), así
 *     que hay que separarlas: la fecha son los primeros 8 caracteres.
 *  2. LOS IMPORTES YA VIENEN CON SIGNO ("-300,00" es un cargo, "100.000,00" un
 *     abono). Eso es más cómodo que Ganadero, donde el signo lo determina la
 *     columna (DÉBITO o CRÉDITO).
 *  3. CADA FILA TRAE EL SALDO CORRIDO, lo que permite reconstruir la secuencia y
 *     verificar que el parseo es correcto.
 *  4. EL PIE DECLARA "Total Créditos" y "Total Débitos", y la cabecera declara
 *     "Saldo Inicial". Con esos tres valores se comprueba la integridad:
 *
 *         saldo_inicial - total_debitos + total_creditos == último saldo corrido
 *
 *     Si no cuadra, alguna línea no se leyó y la conciliación NO puede darse por
 *     buena.
 *  5. LA PRIMERA FILA NO ES UN MOVIMIENTO sino el saldo de arranque: viene como
 *     "Saldo Inicial" sin fecha ni importe. Se detecta y se usa como saldo
 *     inicial en vez de contarlo como movimiento.
 */
class BisaParser extends ParserExtracto
{
    public function clave(): string { return 'bisa'; }
    public function nombre(): string { return 'Banco Bisa'; }

    public function reconoce(array $tokens, string $textoPlano): bool
    {
        $t = mb_strtoupper($textoPlano);
        $tieneBanco = str_contains($t, 'E-BANKING BISA') || str_contains($t, 'BANCO BISA')
                   || str_contains($t, 'ESTADO DE CUENTA');
        $tieneRotulos = str_contains($t, 'SALDO INICIAL')
                     && (str_contains($t, 'TOTAL CRÉDITOS') || str_contains($t, 'TOTAL CREDITOS'));

        return $tieneBanco && $tieneRotulos;
    }

    public function parsear(array $tokens, string $textoPlano): array
    {
        $advertencias = [];
        $texto = $textoPlano;
        $filas = $this->enFilas($tokens);

        // La cabecera de este extracto viene repartida en dos líneas: en la
        // primera están "Fecha Hora … Importe Saldo", y rótulos como "Chq" o
        // "Lote" caen en la segunda. Por eso la columna de fecha se reconoce por
        // el rótulo "Fecha" (los datos sí vienen pegados como "25/09/2608:38").
        $cab = $this->localizarCabecera($filas, [
            'fechahora'   => ['FECHA', 'FECHAHORA'],
            'chq'         => ['CHQ'],
            'descripcion' => ['DESCRIPCIÓN', 'DESCRIPCION'],
            'importe'     => ['IMPORTE'],
            'saldo'       => ['SALDO'],
            'ref'         => ['REF.'],
        ], 2);

        if ($cab === null) {
            throw new \RuntimeException('No se encontró la cabecera de la tabla en el extracto de Banco Bisa.');
        }

        $col = $cab['columnas'];
        foreach (['fechahora', 'importe', 'saldo'] as $req) {
            if (!isset($col[$req])) {
                throw new \RuntimeException('El extracto de Banco Bisa no trae la columna ' . mb_strtoupper($req) . '.');
            }
        }

        // ── Metadatos ──
        // Se leen fila por fila y por rango de X. En este documento la cabecera
        // tiene DOS columnas de datos en la misma línea (rótulos en x≈83 y x≈403;
        // valores en x≈198 y x≈524) y además trocea palabras ("Bs" llega como un
        // solo fragmento aquí, pero "Activa" como "Activ"+"a"). Buscar sobre todo
        // el documento pegaría el valor con el rótulo vecino.
        $ladoIzq = [150, 380];
        $ladoDer = [480, 1e9];

        $numeroCuenta = $this->valorEnFila($this->localizarFila($filas, 'Cargar a:'), ...$ladoIzq);

        $saldoInicial = $this->aNumero(
            $this->valorEnFila($this->localizarFila($filas, 'Saldo Inicial:'), ...$ladoDer)
        );

        $filaFechas = $this->localizarFila($filas, 'Desde:');
        $desde = $this->aFecha($this->valorEnFila($filaFechas, ...$ladoIzq));
        $hasta = $this->aFecha($this->valorEnFila($filaFechas, ...$ladoDer));

        $moneda = null;
        $vmon = $this->valorEnFila($this->localizarFila($filas, 'Moneda:'), ...$ladoDer);
        if ($vmon !== null) {
            if (stripos($vmon, 'Bs') === 0)                                      { $moneda = 'BOB'; }
            elseif (stripos($vmon, 'USD') === 0 || stripos($vmon, 'Sus') === 0)  { $moneda = 'USD'; }
        }

        // ── Anchos de referencia ──
        // Este extracto NO se puede repartir usando la posición de los rótulos:
        // están tan juntos (Fecha@91, Hora@106) que ninguna regla derivada de la
        // cabecera separa bien las columnas. Se usan bandas fijas de la plantilla
        // del documento, que sí es estable, y se deja la cabecera únicamente para
        // reconocer el formato. Las bandas salen de las posiciones reales de los
        // datos en este modelo de estado de cuenta:
        //
        //   fecha+hora 80-104 | chq 104-135 | descripción 135-205
        //   importe 205-238   | saldo 238-300 | resto (descripción, sucursal) 300-515
        //   ref (Nro. Ref. + Nro. Lote) 515+
        //
        // El corte en 515 sale de la posición real de los números de referencia;
        // el texto descriptivo (por ejemplo "REMESA GMB 7750038: SALAZAR …") nunca
        // llega tan a la derecha.
        $bandas = [
            'fechahora'   => [0,   104],
            'chq'         => [104, 135],
            'descripcion' => [135, 205],
            'importe'     => [205, 238],
            'saldo'       => [238, 300],
            'resto'       => [300, 515],
            'ref'         => [515, 1e9],
        ];

        // Límites (borde izquierdo) para el reparto por traslape.
        $limites = [];
        $rangos  = [];
        foreach ($bandas as $nom => [$izq, $der]) {
            $limites[$nom] = $izq;
            $rangos[$nom]  = [$izq, $der];
        }

        // ── Recorrer las filas de la tabla ──
        $lineas = [];
        $descartadas = 0;

        for ($i = $cab['indice'] + 1; $i < count($filas); $i++) {
            $c = $this->repartirEnColumnas($filas[$i]['celdas'], $limites, [], $rangos);

            $crudo = trim(($c['fechahora'] ?? '') . ' ' . ($c['saldo'] ?? ''));

            // Fila de "Saldo Inicial": fija el arrastre y no es un movimiento.
            if (preg_match('/saldo\s+inicial/i', $crudo)) {
                $v = $this->aNumero($c['saldo'] ?? null);
                if ($v !== null) { $saldoInicial = $v; }
                continue;
            }

            // Fila de totales del pie.
            if (preg_match('/total\s+(cr[eé]ditos|d[eé]bitos)/i', $crudo)) {
                continue;
            }

            $importe = $this->aNumero($c['importe'] ?? null);
            $saldo   = $this->aNumero($c['saldo'] ?? null);

            if ($importe === null) {
                // Sin importe no es un movimiento. Puede ser una continuación de
                // descripción de la fila anterior; en ese caso se anexa.
                if (!empty($lineas)) {
                    $extra = trim($c['descripcion'] ?? '');
                    if ($extra !== '' && !preg_match('/^[\s\-]*$/', $extra)) {
                        $lineas[count($lineas) - 1]['descripcion'] =
                            trim($lineas[count($lineas) - 1]['descripcion'] . ' ' . $extra);
                    }
                }
                continue;
            }

            // La fecha y la hora vienen pegadas: "25/09/2608:38".
            $fechaHora = trim($c['fechahora'] ?? '');
            $fecha = null;
            $hora  = null;
            if (preg_match('#^(\d{2}/\d{2}/\d{2,4})\s*(\d{1,2}:\d{2})?#', $fechaHora, $mm)) {
                $fecha = $this->aFecha($mm[1]);
                $hora  = $mm[2] ?? null;
            }

            if ($fecha === null) {
                // Sin fecha no se puede conciliar por día; se descarta avisando.
                $descartadas++;
                continue;
            }

            $lineas[] = [
                'fecha'       => $fecha,
                'hora'        => $hora,
                'canal'       => null,
                // El número de operación del banco (Nro. Ref.) es el mejor
                // candidato a documento: identifica la transacción.
                'documento'   => ($c['ref'] ?? '') !== '' ? $c['ref'] : null,
                'referencia'  => null,
                'descripcion' => trim(preg_replace('/\s+/u', ' ', $c['descripcion'] ?? '')),
                'monto'       => $importe,
                'saldo'       => $saldo,
            ];
        }

        if ($descartadas) {
            $advertencias[] = "Se descartaron {$descartadas} línea(s) con importe pero sin fecha reconocible.";
        }
        if (!$lineas) {
            $advertencias[] = 'No se reconoció ninguna línea de movimiento en el extracto.';
        }

        // ── Totales declarados y control de integridad ──
        $totalCreditos = null;
        $totalDebitos  = null;
        if (preg_match('/Total\s+Cr[eé]ditos:\s*([0-9.,]+)/i', $texto, $m)) { $totalCreditos = $this->aNumero($m[1]); }
        if (preg_match('/Total\s+D[eé]bitos:\s*([0-9.,]+)/i', $texto, $m))  { $totalDebitos  = $this->aNumero($m[1]); }

        $sumaCreditos = 0.0;
        $sumaDebitos  = 0.0;
        foreach ($lineas as $l) {
            if (($l['monto'] ?? 0) > 0) $sumaCreditos += $l['monto'];
            else                        $sumaDebitos  += abs($l['monto']);
        }

        // Saldo final: el saldo corrido de la última línea. Si el banco declaró
        // totales, se prefiere el cálculo declarado porque no depende de haber
        // leído bien la última fila.
        $saldoFinal = null;
        if ($lineas) {
            $ultima = end($lineas);
            $saldoFinal = $ultima['saldo'];
        }
        if ($saldoInicial !== null && $totalCreditos !== null && $totalDebitos !== null) {
            $calculado = $saldoInicial + $totalCreditos - $totalDebitos;
            if ($saldoFinal !== null && abs($calculado - $saldoFinal) > 0.05) {
                $advertencias[] = sprintf(
                    'El saldo corrido final (%.2f) no coincide con el declarado: '
                    . 'saldo inicial %.2f + créditos %.2f - débitos %.2f = %.2f.',
                    $saldoFinal, $saldoInicial, $totalCreditos, $totalDebitos, $calculado
                );
            }
            $saldoFinal = $calculado;
        }

        if ($totalCreditos !== null && abs($sumaCreditos - $totalCreditos) > 0.05) {
            $advertencias[] = sprintf(
                'Los créditos leídos (%.2f) no coinciden con los declarados (%.2f).',
                $sumaCreditos, $totalCreditos
            );
        }
        if ($totalDebitos !== null && abs($sumaDebitos - $totalDebitos) > 0.05) {
            $advertencias[] = sprintf(
                'Los débitos leídos (%.2f) no coinciden con los declarados (%.2f).',
                $sumaDebitos, $totalDebitos
            );
        }

        return [
            'desde'          => $desde,
            'hasta'          => $hasta,
            'moneda'         => $moneda,
            'numero_cuenta'  => $numeroCuenta,
            'saldo_inicial'  => $saldoInicial,
            'saldo_final'    => $saldoFinal,
            'total_debitos'  => round($sumaDebitos, 4),
            'total_creditos' => round($sumaCreditos, 4),
            'lineas'         => $lineas,
            'advertencias'   => $advertencias,
        ];
    }
}
