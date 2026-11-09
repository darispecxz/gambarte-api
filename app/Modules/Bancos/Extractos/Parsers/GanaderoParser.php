<?php

namespace App\Modules\Bancos\Extractos\Parsers;

use App\Modules\Bancos\Extractos\ExtractorPosicional;
use App\Modules\Bancos\Extractos\ParserExtracto;

/**
 * Extracto de cuenta de Banco Ganadero (banca por Internet).
 *
 * ── Forma del documento ──────────────────────────────────────────────────────
 * A4 apaisado, una sola tabla, cabecera:
 *
 *   FECHA | HORA | OFICINA/CANAL | TRANSACCIÓN | DESCRIPCIÓN | DÉBITO | CRÉDITO | SALDO
 *
 * Dos detalles que condicionan el parseo:
 *
 *  1. LA DESCRIPCIÓN OCUPA VARIAS LÍNEAS. Por ejemplo "TRANSF. QR ACH Nro. …"
 *     sigue en el renglón siguiente con el nombre del beneficiario y en un
 *     tercero con el canal. Esas continuaciones NO traen fecha ni importes.
 *  2. LOS IMPORTES VAN EN UN RENGLÓN APARTE. En el PDF los tres importes
 *     (débito, crédito, saldo) se imprimen en su propia banda vertical,
 *     separada de la fecha/hora/descripción. Por eso no se puede "leer la fila":
 *     hay que asociar cada bloque de importes con la transacción abierta.
 *
 * La estrategia es la de un lector: se recorre de arriba hacia abajo y cada vez
 * que aparece una fecha en la columna FECHA se abre una transacción nueva. Las
 * líneas posteriores sin fecha son continuación de la descripción, hasta topar
 * con el renglón de importes, que se asigna a la transacción abierta.
 *
 * ── Control de integridad ────────────────────────────────────────────────────
 * El extracto trae "TOTAL SALDO ACTUAL". Tras parsear se comprueba que
 * saldo_inicial + créditos − débitos (y también el saldo corrido de la última
 * línea) coincidan con ese total. Si no cuadra, el parser lo reporta como
 * advertencia y la conciliación no se marca como CONCILIADO: antes de dar por
 * buena una conciliación hay que estar seguro de haber leído todo el extracto.
 */
class GanaderoParser extends ParserExtracto
{
    public function clave(): string { return 'ganadero'; }
    public function nombre(): string { return 'Banco Ganadero'; }

    public function reconoce(array $tokens, string $textoPlano): bool
    {
        $t = mb_strtoupper($textoPlano);
        $tieneBanco = str_contains($t, 'BANCO GANADERO') || str_contains($t, 'GANADERO S.A.');
        $tieneRotulos = str_contains($t, 'OFICINA/CANAL') && str_contains($t, 'TOTAL SALDO ACTUAL');

        return $tieneBanco && $tieneRotulos;
    }

    public function parsear(array $tokens, string $textoPlano): array
    {
        $advertencias = [];
        $filas = $this->enFilas($tokens);

        $cab = $this->localizarCabecera($filas, [
            'fecha'       => ['FECHA'],
            'hora'        => ['HORA'],
            'canal'       => ['OFICINA/CANAL'],
            'transaccion' => ['TRANSACCIÓN', 'TRANSACCION'],
            'descripcion' => ['DESCRIPCIÓN', 'DESCRIPCION'],
            'debito'      => ['DÉBITO', 'DEBITO'],
            'credito'     => ['CRÉDITO', 'CREDITO'],
            'saldo'       => ['SALDO'],
        ]);

        if ($cab === null) {
            throw new \RuntimeException('No se encontró la cabecera de la tabla en el extracto de Banco Ganadero.');
        }

        $col = $cab['columnas'];
        foreach (['fecha', 'descripcion', 'saldo'] as $req) {
            if (!isset($col[$req])) {
                throw new \RuntimeException('El extracto de Banco Ganadero no trae la columna ' . mb_strtoupper($req) . '.');
            }
        }

        // ── Metadatos del documento ──
        $texto = $textoPlano;
        $numeroCuenta = null;
        if (preg_match('/NRO\.\s*DE\s*CUENTA:\s*\R?\s*([0-9]{6,20})/i', $texto, $m)) {
            $numeroCuenta = $m[1];
        }
        $desde = $hasta = null;
        if (preg_match('/DEL\s+(\d{2}\/\d{2}\/\d{4})\s+AL\s+(\d{2}\/\d{2}\/\d{4})/i', $texto, $m)) {
            $desde = $this->aFecha($m[1]);
            $hasta = $this->aFecha($m[2]);
        }
        $moneda = stripos($texto, 'BOLIVIANOS') !== false ? 'BOB'
                : (stripos($texto, 'DOLARES') !== false ? 'USD' : null);

        // ── Anchos de referencia (de la cabecera) ──
        // Los rótulos son cortos y las columnas de texto son anchas; con estos
        // anchos el reparto por traslape clasifica bien cada fragmento.
        $ancho = [
            'fecha'       => 62,
            'hora'        => 52,
            'canal'       => 108,
            'transaccion' => 88,
        ];
        if (isset($col['descripcion'])) { $ancho['descripcion'] = 900; }

        // Límites del bloque numérico: puntos medios entre rótulos, para no
        // robarle celdas a la columna vecina.
        $xDebito  = $col['debito']  ?? ($col['descripcion'] + 200);
        $xCredito = $col['credito'] ?? ($xDebito + 60);
        $xSaldo   = $col['saldo'];

        $limDebito  = $xDebito - 70;
        $limCredito = ($xDebito + $xCredito) / 2;
        $limSaldo   = ($xCredito + $xSaldo) / 2;

        $rangos = [
            'debito'  => [$limDebito, $limCredito],
            'credito' => [$limCredito, $limSaldo],
            'saldo'   => [$limSaldo, $limSaldo + 1e9],
        ];

        // ── Recolectar todas las filas con su clasificación ──
        $registros = [];   // filas de transacción y continuaciones, tras la cabecera
        for ($i = $cab['indice'] + 1; $i < count($filas); $i++) {
            $celdas = $this->repartirEnColumnas($filas[$i]['celdas'], $col, $ancho, $rangos);

            $registros[] = [
                'y'      => $filas[$i]['y'],
                'celdas' => $celdas,
                'fecha'  => $this->aFecha($celdas['fecha'] ?? ''),
                'nros'   => [
                    'debito'  => $this->aNumero($celdas['debito']  ?? null),
                    'credito' => $this->aNumero($celdas['credito'] ?? null),
                    'saldo'   => $this->aNumero($celdas['saldo']   ?? null),
                ],
                'esImporte' => ($this->aNumero($celdas['debito'] ?? null) !== null
                             || $this->aNumero($celdas['credito'] ?? null) !== null),
            ];
        }

        // ── Agrupar: cada transacción abarca desde su fila con fecha hasta la
        //    siguiente fila con fecha (exclusive). ──
        $lineas = [];
        $bloques = [];
        $actual = null;
        foreach ($registros as $reg) {
            if ($reg['fecha'] !== null) {
                if ($actual !== null) { $bloques[] = $actual; }
                $actual = ['cab' => $reg, 'cuerpo' => []];
                continue;
            }
            if ($actual !== null) {
                $actual['cuerpo'][] = $reg;
            } else {
                // Antes de la primera fecha: puede ser el renglón de "Saldo anterior"
                $bloques[] = ['cab' => null, 'cuerpo' => [$reg]];
            }
        }
        if ($actual !== null) { $bloques[] = $actual; }

        $saldoInicial = null;

        foreach ($bloques as $bloque) {
            $cab2 = $bloque['cab'];
            // OJO: el renglón de importes puede ser el MISMO que trae la fecha
            // (es lo habitual en este extracto). Por eso se escanea el bloque
            // completo —cabecera incluida— y no sólo su "cuerpo"; si se dejara
            // fuera la cabecera, ninguna transacción tendría importe.
            $material = $cab2 !== null ? array_merge([$cab2], $bloque['cuerpo']) : $bloque['cuerpo'];

            // Bloque previo a la primera transacción: "Saldo anterior".
            if ($cab2 === null) {
                foreach ($material as $reg) {
                    $desc = mb_strtolower($reg['celdas']['descripcion'] ?? '');
                    if (str_contains($desc, 'saldo anterior') || str_contains($desc, 'saldo inicial')) {
                        $saldoInicial = $reg['nros']['saldo'];
                    }
                }
                continue;
            }

            $descripcion = $cab2['celdas']['descripcion'] ?? '';
            $monto = null;
            $saldo = null;

            foreach ($material as $reg) {
                if ($reg['esImporte'] && $monto === null) {
                    $credito = $reg['nros']['credito'];
                    $debito  = $reg['nros']['debito'];

                    if ($credito !== null && abs($credito) > 0) {
                        $monto = abs($credito);
                    } elseif ($debito !== null && abs($debito) > 0) {
                        $monto = -abs($debito);
                    } else {
                        $monto = 0.0;
                    }
                    $saldo = $reg['nros']['saldo'];
                    continue;
                }

                // Renglones de continuación: sólo aportan texto descriptivo.
                if ($reg !== $cab2) {
                    $extra = trim($reg['celdas']['descripcion'] ?? '');
                    if ($extra !== '') {
                        $descripcion .= ($descripcion === '' ? '' : ' ') . $extra;
                    }
                }
            }

            if ($monto === null) {
                continue;   // fila sin importe: no es un movimiento
            }

            $lineas[] = [
                'fecha'       => $cab2['fecha'],
                'hora'        => $cab2['celdas']['hora'] ?: null,
                'canal'       => $cab2['celdas']['canal'] ?: null,
                'documento'   => $cab2['celdas']['transaccion'] ?: null,
                'referencia'  => $cab2['celdas']['transaccion'] ?: null,
                'descripcion' => trim(preg_replace('/\s+/u', ' ', $descripcion)),
                'monto'       => $monto,
                'saldo'       => $saldo,
            ];
        }

        if (!$lineas) {
            $advertencias[] = 'No se reconoció ninguna línea de movimiento en el extracto.';
        }

        // ── Totales declarados y control de integridad ──
        $totalSaldoDeclarado = null;
        if (preg_match('/TOTAL\s+SALDO\s+ACTUAL:\s*([0-9.,]+)/i', $texto, $m)) {
            $totalSaldoDeclarado = $this->aNumero($m[1]);
        }

        $sumaCreditos = 0.0;
        $sumaDebitos  = 0.0;
        foreach ($lineas as $l) {
            if (($l['monto'] ?? 0) > 0) $sumaCreditos += $l['monto'];
            else                        $sumaDebitos  += abs($l['monto']);
        }

        $calculado = ($saldoInicial ?? 0.0) + $sumaCreditos - $sumaDebitos;

        if ($totalSaldoDeclarado !== null && abs($calculado - $totalSaldoDeclarado) > 0.05) {
            $advertencias[] = sprintf(
                'El extracto no cuadra: saldo inicial %.2f + créditos %.2f - débitos %.2f = %.2f, '
                . 'pero el banco declara %.2f. Puede haber líneas no leídas.',
                $saldoInicial ?? 0.0, $sumaCreditos, $sumaDebitos, $calculado, $totalSaldoDeclarado
            );
        }

        if (!$lineas && $totalSaldoDeclarado === null) {
            $advertencias[] = 'No se reconoció ninguna línea de movimiento ni un saldo declarado.';
        }

        return [
            'desde'          => $desde,
            'hasta'          => $hasta,
            'moneda'         => $moneda,
            'numero_cuenta'  => $numeroCuenta,
            'saldo_inicial'  => $saldoInicial,
            'saldo_final'    => $totalSaldoDeclarado,
            'total_debitos'  => round($sumaDebitos, 4),
            'total_creditos' => round($sumaCreditos, 4),
            'lineas'         => $lineas,
            'advertencias'   => $advertencias,
        ];
    }
}
