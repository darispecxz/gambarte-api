<?php

namespace App\Modules\Bancos\Extractos;

/**
 * Parser de extractos: una implementación por banco.
 *
 * El objetivo es convertir el PDF en una lista de líneas normalizadas:
 *
 *     ['fecha' => 'Y-m-d', 'hora' => 'HH:MM', 'descripcion' => '...',
 *      'referencia' => '...', 'documento' => '...', 'monto' => -300.00,
 *      'saldo' => 33339.27]
 *
 * `monto` va CON SIGNO: positivo cuando el banco abona, negativo cuando carga.
 * Esa convención coincide con `movimiento_cuenta_bancaria`, donde el importe de
 * un depósito entra en positivo y el de un pago sale en negativo, de modo que la
 * conciliación puede comparar importes directamente.
 *
 * Para que un banco nuevo se soporte sólo hay que añadir una subclase y
 * registrarla en `DetectorExtracto`. Si un banco no tiene parser, el sistema NO
 * intenta adivinar: cae en la lógica actual de cierre manual.
 */
abstract class ParserExtracto
{
    /**
     * Ancho medio de un carácter, en puntos. Se usa para estimar dónde termina un
     * fragmento de texto y decidir si el siguiente es continuación de la misma
     * palabra. El valor sale de medir los extractos reales de Bisa y Ganadero
     * (fuente de ~9 pt).
     */
    protected const ANCHO_CARACTER = 4.6;

    /** Separación, en puntos, que ya se considera un espacio entre palabras. */
    protected const ESPACIO_MINIMO = 0.9;

    /** Clave interna del banco ('ganadero', 'bisa'). */
    abstract public function clave(): string;

    /** Nombre para mostrar. */
    abstract public function nombre(): string;

    /**
     * ¿Este parser reconoce el documento?
     *
     * @param array $tokens fragmentos con posición ({x, y, texto})
     */
    abstract public function reconoce(array $tokens, string $textoPlano): bool;

    /**
     * @return array{
     *   desde: ?string, hasta: ?string, moneda: ?string, numero_cuenta: ?string,
     *   saldo_inicial: ?float, saldo_final: ?float,
     *   total_debitos: ?float, total_creditos: ?float,
     *   lineas: array<int, array>, advertencias: array<int, string>
     * }
     */
    abstract public function parsear(array $tokens, string $textoPlano): array;

    // ─────────────────────────────────────────────────────────────────
    //  Utilidades compartidas
    // ─────────────────────────────────────────────────────────────────

    /**
     * Agrupa los fragmentos en filas visuales.
     *
     * Se usa tolerancia en Y porque un mismo renglón del PDF puede venir en dos
     * fragmentos con Y ligeramente distinta (distintos tamaños de fuente).
     *
     * @return array<int, array{y: float, celdas: array<int, array{x: float, texto: string}>}>
     */
    protected function enFilas(array $tokens, float $tolerancia = 2.0): array
    {
        $filas = [];

        foreach ($tokens as $t) {
            $colocado = false;
            foreach ($filas as &$f) {
                if (abs($f['y'] - $t['y']) <= $tolerancia) {
                    $f['celdas'][] = ['x' => $t['x'], 'texto' => $t['texto']];
                    $colocado = true;
                    break;
                }
            }
            unset($f);

            if (!$colocado) {
                $filas[] = ['y' => $t['y'], 'celdas' => [['x' => $t['x'], 'texto' => $t['texto']]]];
            }
        }

        foreach ($filas as &$f) {
            usort($f['celdas'], fn($a, $b) => $a['x'] <=> $b['x']);
        }
        unset($f);

        usort($filas, fn($a, $b) => $a['y'] <=> $b['y']);

        return $filas;
    }

    /**
     * Une los fragmentos de una misma celda.
     *
     * Algunos generadores (Bisa es el caso) emiten el texto troceado: "REMESA"
     * llega como "REMES" + "A". La fusión se hace SIEMPRE dentro de la banda de
     * una columna ya identificada, nunca entre columnas, porque en ese documento
     * los rótulos vecinos están a tan pocos puntos que ningún umbral global podría
     * distinguir "Fecha"+"Hora" (dos columnas) de "F"+"echa" (una palabra).
     *
     * El ancho estimado de cada fragmento decide si el siguiente trozo continúa
     * la palabra o empieza otra.
     *
     * @param array<int, array{x: float, texto: string}> $celdas
     * @return array<int, array{x: float, texto: string}>
     */
    protected function fusionarDentroDeBanda(array $celdas): array
    {
        $out = [];

        foreach ($celdas as $c) {
            if (!$out) { $out[] = $c; continue; }

            $ultimo = &$out[count($out) - 1];
            $anchoUltimo = mb_strlen($ultimo['texto']) * self::ANCHO_CARACTER;
            $separacion = $c['x'] - ($ultimo['x'] + $anchoUltimo);

            if ($separacion < 2.2) {
                $uneConEspacio = $separacion > self::ESPACIO_MINIMO;
                $ultimo['texto'] = $ultimo['texto'] . ($uneConEspacio ? ' ' : '') . $c['texto'];
            } else {
                $out[] = $c;
            }
            unset($ultimo);
        }

        return $out;
    }

    /**
     * Localiza la fila de cabecera de la tabla buscando un conjunto de rótulos
     * en la misma banda vertical, y devuelve las posiciones X de esas columnas.
     *
     * La comparación es por SUBCADENA sobre el texto normalizado (sin acentos ni
     * puntuación) porque hay generadores que juntan rótulos vecinos en un solo
     * fragmento: el extracto de Bisa entrega "ImporteSaldoInfo. Complementaria"
     * como un único fragmento y no habría forma de reconocer "IMPORTE" con una
     * comparación exacta.
     *
     * Apoyarse en la cabecera real del documento (en vez de en constantes fijas)
     * es lo que hace que el parser aguante pequeños cambios de maquetación.
     *
     * @param array<string, array<int, string>> $rotulos clave => lista de rótulos aceptados
     * @return array{indice: int, y: float, columnas: array<string, float>}|null
     */
    protected function localizarCabecera(array $filas, array $rotulos, int $minimo = 3): ?array
    {
        $mejor = null;
        $mejorPuntaje = 0;

        foreach ($filas as $i => $fila) {
            $columnas = [];

            foreach ($rotulos as $clave => $aceptados) {
                $buscados = array_map([self::class, 'normalizarRotulo'], $aceptados);
                $buscados = array_values(array_filter($buscados));

                // Se prueba el texto de cada celda y también la concatenación de
                // celdas contiguas, porque hay generadores que trocean las
                // palabras: en el extracto de Bisa el rótulo "Fecha" llega como
                // "F" + "echa" y "Hora" como "Hor" + "a".
                $n = count($fila['celdas']);
                for ($a = 0; $a < $n; $a++) {
                    $acumulado = '';
                    for ($b = $a; $b < min($n, $a + 3); $b++) {
                        $acumulado .= self::normalizarRotulo($fila['celdas'][$b]['texto']);
                        if ($acumulado === '') { continue; }

                        foreach ($buscados as $rot) {
                            if (str_contains($acumulado, $rot)) {
                                $x = $fila['celdas'][$a]['x'];
                                if (!isset($columnas[$clave]) || $x < $columnas[$clave]) {
                                    $columnas[$clave] = $x;
                                }
                                break 3;
                            }
                        }
                    }
                }
            }

            if (count($columnas) > $mejorPuntaje) {
                $mejorPuntaje = count($columnas);
                $mejor = ['indice' => $i, 'y' => $fila['y'], 'columnas' => $columnas];
            }
        }

        if ($mejor === null || $mejorPuntaje < $minimo) {
            return null;
        }

        return $mejor;
    }

    /**
     * Normaliza un rótulo para comparar: mayúsculas, sin acentos y sin
     * puntuación, de modo que "Nro. Ref." y "NROREF" sean equivalentes.
     */
    protected static function normalizarRotulo(string $s): string
    {
        $s = ExtractorPosicional::normalizar($s);
        $s = mb_strtoupper($s, 'UTF-8');
        $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U']);
        return preg_replace('/[^A-Z0-9]/', '', $s) ?? '';
    }

    /**
     * Asigna a cada celda la columna que le corresponde.
     *
     * Cada celda va a UNA sola columna: se busca el traslape entre el ancho de la
     * celda y el rango de cada columna, y gana el mayor. Repartir por el borde
     * izquierdo no sirve, porque una descripción larga que empieza en su columna
     * invade la banda numérica y terminaría clasificada como importe.
     *
     * @param array<int, array{x: float, texto: string}> $celdas
     * @param array<string, float>  $limites borde izquierdo por columna
     * @param array<string, float>  $ancho   ancho de referencia por columna (opcional)
     * @param array<string, array{0: float, 1: float}> $rangos rango explícito, si se conoce
     * @return array<string, string>
     */
    protected function repartirEnColumnas(array $celdas, array $limites, array $ancho = [], array $rangos = []): array
    {
        asort($limites);
        $nombres = array_keys($limites);

        // Rango de cada columna.
        $rango = [];
        foreach ($nombres as $k => $nom) {
            if (isset($rangos[$nom])) {
                $rango[$nom] = [$rangos[$nom][0], $rangos[$nom][1]];
                continue;
            }
            $izq = $limites[$nom];
            $der = isset($nombres[$k + 1]) ? $limites[$nombres[$k + 1]] : $izq + 1e9;

            if (!empty($ancho[$nom])) {
                $der = min($der, $izq + $ancho[$nom]);
            }
            $rango[$nom] = [$izq, $der];
        }

        // Primero cada fragmento va a UNA columna: se busca el traslape entre el
        // ancho del fragmento y el rango de la columna, y gana el mayor. Repartir
        // por el borde izquierdo no sirve, porque una descripción larga que empieza
        // en su columna invade la banda numérica y acabaría clasificada como importe.
        $porColumna = array_fill_keys($nombres, []);

        foreach ($celdas as $c) {
            $texto = ExtractorPosicional::normalizar($c['texto']);
            $x0 = $c['x'];
            $x1 = $x0 + max(4.0, mb_strlen($texto) * self::ANCHO_CARACTER);

            $destino = null;
            $mejor = 0.0;
            foreach ($rango as $nom => [$izq, $der]) {
                $solape = min($x1, $der) - max($x0, $izq);
                if ($solape > $mejor) {
                    $mejor = $solape;
                    $destino = $nom;
                }
            }

            if ($destino === null) {
                continue;
            }

            $porColumna[$destino][] = ['x' => $x0, 'texto' => $texto];
        }

        // Después se fusionan los trozos dentro de cada columna.
        $out = [];
        foreach ($porColumna as $nom => $lista) {
            if (!$lista) { $out[$nom] = ''; continue; }
            usort($lista, fn($a, $b) => $a['x'] <=> $b['x']);
            $unidos = $this->fusionarDentroDeBanda($lista);
            $out[$nom] = trim(preg_replace('/\s+/u', ' ', implode(' ', array_column($unidos, 'texto'))));
        }

        return $out;
    }

    /**
     * Busca una fila cuyo texto contenga el rótulo indicado.
     *
     * Es la forma fiable de leer los metadatos del encabezado del extracto
     * ("Saldo Inicial:", "Cargar a:", "Moneda:"): buscando por concatenación de
     * todo el documento el valor se pega con el rótulo siguiente y sale basura
     * del estilo "6942240021Nombre".
     *
     * @return array{y: float, celdas: array<int, array{x: float, texto: string}>}|null
     */
    protected function localizarFila(array $filas, string $rotulo): ?array
    {
        $buscado = self::normalizarRotulo($rotulo);
        if ($buscado === '') return null;

        foreach ($filas as $fila) {
            $acum = '';
            foreach ($fila['celdas'] as $c) {
                $acum .= self::normalizarRotulo($c['texto']);
            }
            if (str_contains($acum, $buscado)) {
                return $fila;
            }
        }
        return null;
    }

    /**
     * Primer fragmento de una fila cuya X caiga en el rango indicado.
     *
     * @param array{y: float, celdas: array<int, array{x: float, texto: string}>}|null $fila
     */
    protected function valorEnFila(?array $fila, float $desde = 0.0, float $hasta = 1e9): ?string
    {
        if ($fila === null) return null;

        foreach ($fila['celdas'] as $c) {
            if ($c['x'] >= $desde && $c['x'] < $hasta) {
                $t = ExtractorPosicional::normalizar($c['texto']);
                if ($t !== '') return $t;
            }
        }
        return null;
    }

    /**
     * Convierte un importe en formato boliviano a float.
     * Acepta "1.234,56", "-300,00", "3,048.00", "187.00" y "(1.234,56)".
     */
    protected function aNumero(?string $s): ?float
    {
        if ($s === null) return null;
        $s = trim($s);
        if ($s === '' || $s === '-' || $s === '—') return null;

        $negativo = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) { $negativo = true; $s = $m[1]; }
        if (str_starts_with($s, '-')) { $negativo = true; $s = substr($s, 1); }
        if (str_starts_with($s, '+')) { $s = substr($s, 1); }

        $s = preg_replace('/[^0-9.,]/', '', $s);
        if ($s === '') return null;

        $ultimaComa = strrpos($s, ',');
        $ultimoPunto = strrpos($s, '.');

        if ($ultimaComa !== false && $ultimoPunto !== false) {
            // El separador decimal es el que aparece más a la derecha.
            if ($ultimaComa > $ultimoPunto) {
                $s = str_replace('.', '', $s);   // 1.234,56 -> 1234,56
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);   // 3,048.00 -> 3048.00
            }
        } elseif ($ultimaComa !== false) {
            // Sólo comas: si hay una y le siguen 1-2 dígitos, es decimal.
            $partes = explode(',', $s);
            if (count($partes) === 2 && strlen($partes[1]) <= 2) {
                $s = $partes[0] . '.' . $partes[1];
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($ultimoPunto !== false) {
            $partes = explode('.', $s);
            if (count($partes) === 2 && strlen($partes[1]) <= 2) {
                // decimal normal
            } else {
                $s = str_replace('.', '', $s);
            }
        }

        if (!is_numeric($s)) return null;
        $v = (float) $s;
        return $negativo ? -$v : $v;
    }

    /** Convierte fecha del extracto a 'Y-m-d'. */
    protected function aFecha(?string $s): ?string
    {
        if ($s === null) return null;
        $s = trim($s);
        if ($s === '') return null;

        // dd/mm/yyyy o dd/mm/yy
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $s, $m)) {
            [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($a < 100) $a += 2000;
            if (!checkdate($mes, $d, $a)) return null;
            return sprintf('%04d-%02d-%02d', $a, $mes, $d);
        }

        // yyyy-mm-dd
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m)) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
            return $s;
        }

        return null;
    }

    /** ¿La cadena es una fecha reconocible del extracto? */
    protected function esFecha(string $s): bool
    {
        return $this->aFecha($s) !== null;
    }
}
