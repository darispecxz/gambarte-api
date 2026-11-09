<?php

namespace App\Modules\Bancos\Extractos;

use App\Modules\Bancos\Extractos\Parsers\BisaParser;
use App\Modules\Bancos\Extractos\Parsers\GanaderoParser;

/**
 * Elige el parser que reconoce un extracto.
 *
 * Para habilitar un banco nuevo basta con añadir su parser a `parsers()`. Si
 * ningún parser reconoce el documento, el sistema NO adivina: devuelve null y el
 * flujo cae en el cierre manual de siempre.
 */
class DetectorExtracto
{
    /** @return ParserExtracto[] */
    public function parsers(): array
    {
        return [
            new GanaderoParser(),
            new BisaParser(),
        ];
    }

    /**
     * Devuelve el parser que reconoce el documento, o null.
     *
     * @param array $tokens fragmentos {x, y, texto} de ExtractorPosicional
     */
    public function detectar(array $tokens, string $textoPlano): ?ParserExtracto
    {
        foreach ($this->parsers() as $parser) {
            if ($parser->reconoce($tokens, $textoPlano)) {
                return $parser;
            }
        }
        return null;
    }

    /** Bancos soportados, para mostrarlos en la interfaz. */
    public function bancosSoportados(): array
    {
        return array_map(fn(ParserExtracto $p) => ['clave' => $p->clave(), 'nombre' => $p->nombre()], $this->parsers());
    }
}
