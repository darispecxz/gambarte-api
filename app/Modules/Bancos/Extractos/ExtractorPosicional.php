<?php

namespace App\Modules\Bancos\Extractos;

use Smalot\PdfParser\Parser;

/**
 * Extracción de texto CON POSICIÓN desde un PDF, en PHP puro.
 *
 * ── Por qué no basta `getText()` ─────────────────────────────────────────────
 * `\Smalot\PdfParser\Page::getText()` devuelve las cadenas en el orden interno
 * del flujo de contenido, sin coordenadas. En un extracto bancario eso pega
 * columnas que en el papel están separadas: "25/09/2608:38" (fecha+hora) o
 * "-300,0033.339,27" (importe+saldo). Intentar separarlas con expresiones
 * regulares produce parsers frágiles que se rompen con el primer cambio de
 * maquetación del banco.
 *
 * ── Qué usamos ───────────────────────────────────────────────────────────────
 * `Page::getDataTm()` entrega, por cada fragmento de texto, la matriz de texto
 * del PDF y la cadena:
 *
 *     [ [a, b, c, d, x, y], "texto" ]
 *
 * Los dos últimos valores de la matriz son la traslación, es decir la posición
 * del fragmento en puntos y con el origen en la esquina INFERIOR izquierda.
 * Para razonar como un humano (de arriba hacia abajo) convertimos:
 *
 *     x_desde_izquierda = x
 *     y_desde_arriba   = alto_pagina - y
 *
 * Con eso los parsers pueden agrupar por bandas horizontales y verticales, que
 * es exactamente lo que hace un lector humano al mirar el extracto.
 *
 * Todo esto corre en PHP sin binarios externos (`pdftotext`, ghostscript), que
 * es requisito para poder desplegar en el hosting compartido.
 */
class ExtractorPosicional
{
    /** Fragmento de texto con su posición. */
    private array $tokens = [];

    private float $altoPagina = 0.0;

    /**
     * @return array{tokens: array<int, array{x: float, y: float, texto: string}>, alto: float}
     */
    public function extraer(string $rutaPdf): array
    {
        if (!is_readable($rutaPdf)) {
            throw new \RuntimeException('No se puede leer el archivo del extracto.');
        }

        $parser = new Parser();
        $pdf = $parser->parseFile($rutaPdf);

        $this->tokens = [];
        $this->altoPagina = 0.0;

        foreach ($pdf->getPages() as $numPagina => $page) {
            // Alto de la página: necesitamos el MediaBox. Si no se puede leer,
            // usamos A4 vertical como aproximación y el orden vertical sigue
            // siendo correcto aunque el valor absoluto no lo sea.
            $alto = $this->altoDePagina($page);

            $datos = $page->getDataTm();
            if (!$datos) {
                continue;
            }

            foreach ($datos as $item) {
                if (!is_array($item) || count($item) < 2) {
                    continue;
                }
                $matriz = $item[0];
                $texto  = (string) $item[1];

                if (!is_array($matriz) || count($matriz) < 6) {
                    continue;
                }
                if (trim($texto) === '') {
                    continue;
                }

                $x = (float) $matriz[4];
                $y = (float) $matriz[5];

                $this->tokens[] = [
                    'pagina' => $numPagina + 1,
                    'x'      => round($x, 3),
                    'y'      => round($alto - $y, 3),
                    'texto'  => $texto,
                ];
            }
        }

        if (!$this->tokens) {
            throw new \RuntimeException(
                'No se pudo extraer texto del PDF. Puede ser un documento escaneado (imagen) '
                . 'y en ese caso necesita OCR, o un formato que aún no soportamos.'
            );
        }

        // Orden de lectura: por página, luego de arriba hacia abajo, luego de
        // izquierda a derecha.
        usort($this->tokens, function ($a, $b) {
            if ($a['pagina'] !== $b['pagina']) return $a['pagina'] <=> $b['pagina'];
            if (abs($a['y'] - $b['y']) > 0.6)     return $a['y'] <=> $b['y'];
            return $a['x'] <=> $b['x'];
        });

        return ['tokens' => $this->tokens, 'alto' => $this->altoPagina];
    }

    /**
     * Alto de la página. `getDataTm()` trabaja en coordenadas PDF (origen abajo),
     * así que sin este valor no podríamos ordenar de arriba hacia abajo.
     */
    private function altoDePagina($page): float
    {
        try {
            // El MediaBox vive en el diccionario de la página.
            $detalle = $page->getDetails();
            foreach (['MediaBox', 'CropBox'] as $clave) {
                if (!empty($detalle[$clave])) {
                    $box = $detalle[$clave];
                    if (is_string($box)) {
                        $box = preg_split('/[\s\[\]]+/', trim($box, "[] \t\n\r"));
                    }
                    $nums = array_values(array_filter((array) $box, fn($v) => is_numeric($v)));
                    if (count($nums) >= 4) {
                        return (float) $nums[3] - (float) $nums[1];
                    }
                }
            }
        } catch (\Throwable $e) {
            // seguimos con el valor por defecto
        }

        return 841.89; // A4 vertical
    }

    /**
     * Texto plano del token, normalizando el peculiar manejo de acentos del PDF.
     * Los generadores suelen emitir Latin-1 en las cadenas; `getDataTm()`
     * devuelve los bytes tal cual.
     */
    public static function normalizar(string $s): string
    {
        $s = str_replace(["\xc2\xa0", "\xa0"], ' ', $s);          // espacios duros
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        return trim($s);
    }

    /**
     * Todo el texto del documento sin separadores.
     *
     * Se usa para buscar rótulos de metadatos ("Moneda: Bs", "Cargar a: …") sin
     * depender de cómo el generador parta el texto en fragmentos: hay documentos
     * (Bisa) donde "Bs" llega como dos trozos, "B" y "s", y cualquier expresión
     * regular sobre el texto con saltos de línea fallaría.
     */
    public static function textoSinSeparadores(array $tokens): string
    {
        $s = '';
        foreach ($tokens as $t) {
            $s .= self::normalizar($t['texto']);
        }
        return $s;
    }

    /**
     * Busca un rótulo en el texto sin separadores y devuelve lo que sigue.
     * El patrón se escribe con espacios opcionales (`\s*`) entre palabras.
     */
    public static function valorTrasRotulo(array $tokens, string $rotulo, int $maxLargo = 40): ?string
    {
        $texto = self::textoSinSeparadores($tokens);
        $patron = '/' . str_replace(' ', '\s*', preg_quote($rotulo, '/')) . '\s*([^\s]{1,' . $maxLargo . '})/iu';

        if (preg_match($patron, $texto, $m)) {
            return $m[1];
        }
        return null;
    }
}
