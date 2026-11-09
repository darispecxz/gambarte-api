<?php

namespace App\Modules\Facturacion;

class SiatUtilidades
{
    private const NIT = '335846029';
    private const CODIGO_MODALIDAD = 1;

    private const DOC_CGR_CI   = 1;
    private const DOC_CGR_PAS  = 2;
    private const DOC_CGR_CEX1 = 3;
    private const DOC_CGR_NIT  = 4;
    private const DOC_CGR_RUN  = 5;
    private const DOC_CGR_CEX2 = 6;
    private const DOC_CGR_DNI  = 7;

    private const DOC_SIN_CI   = 1;
    private const DOC_SIN_CEX  = 2;
    private const DOC_SIN_PAS  = 3;
    private const DOC_SIN_OTRO = 4;
    private const DOC_SIN_NIT  = 5;

    public static function generarCuf(
        string $fechaRaw,
        int $sucursal,
        int $tipoEmision,
        int $tipoFactura,
        int $tipoDocumentoSector,
        int $numeroFactura,
        int $puntoVenta,
        string $codigoControl
    ): string {
        $cuf = '';
        $cuf .= self::completeCero(self::NIT, 13);
        $cuf .= self::completeCero($fechaRaw, 17);
        $cuf .= self::completeCero((string) $sucursal, 4);
        $cuf .= self::completeCero((string) self::CODIGO_MODALIDAD, 1);
        $cuf .= self::completeCero((string) $tipoEmision, 1);
        $cuf .= self::completeCero((string) $tipoFactura, 1);
        $cuf .= self::completeCero((string) $tipoDocumentoSector, 2);
        $cuf .= self::completeCero((string) $numeroFactura, 10);
        $cuf .= self::completeCero((string) $puntoVenta, 4);
        $cuf .= self::calculaDigitoMod11($cuf, 1, 9, false);
        $cuf = self::dec2hex($cuf) . $codigoControl;

        return strtoupper($cuf);
    }

    public static function fechaHoraRaw(string $fechaHora): string
    {
        $date = strtotime($fechaHora);
        return date('YmdHis', $date) . '000';
    }

    public static function fechaHoraSIN(string $fechaHora): string
    {
        $date = date('Y-m-d H:i:s.', strtotime($fechaHora)) . '000';
        return str_replace(' ', 'T', $date);
    }

    public static function documentoIdentidadCGRtoSIN(int $codigoCGR): int
    {
        return match ($codigoCGR) {
            self::DOC_CGR_CI   => self::DOC_SIN_CI,
            self::DOC_CGR_CEX1,
            self::DOC_CGR_CEX2 => self::DOC_SIN_CEX,
            self::DOC_CGR_PAS  => self::DOC_SIN_PAS,
            self::DOC_CGR_NIT  => self::DOC_SIN_NIT,
            self::DOC_CGR_RUN,
            self::DOC_CGR_DNI  => self::DOC_SIN_OTRO,
            default            => 0,
        };
    }

    public static function generarCodigoCliente(string $razonSocial, string $documento): string
    {
        $res = '';
        $palabras = explode(' ', trim($razonSocial));
        $reemplazos = [
            'ñ' => 'n', 'Ñ' => 'N',
            'á' => 'a', 'Á' => 'A', 'é' => 'e', 'É' => 'E',
            'í' => 'i', 'Í' => 'I', 'ó' => 'o', 'Ó' => 'O',
            'ú' => 'u', 'Ú' => 'U',
        ];
        foreach ($palabras as $palabra) {
            if ($palabra === '') continue;
            $palabra = str_replace(array_keys($reemplazos), array_values($reemplazos), $palabra);
            $res .= mb_strtoupper(mb_substr($palabra, 0, 1));
        }
        return $res . '-' . $documento;
    }

    public static function getCIComplemento(string $documento, int $tipoDocIdentidad): array
    {
        $documento = str_replace(' ', '', $documento);
        $ci = null;
        $complemento = null;
        $isCIConComplemento = substr_count($documento, '-') > 0 && $tipoDocIdentidad === self::DOC_CGR_CI;
        $isCEX = str_starts_with($documento, 'E-');
        if ($isCIConComplemento && !$isCEX) {
            [$ci, $complemento] = explode('-', $documento, 2);
        }
        return [$ci, $complemento];
    }

    private static function completeCero(string $str, int $maxChar): string
    {
        return str_pad($str, $maxChar, '0', STR_PAD_LEFT);
    }

    private static function calculaDigitoMod11(string $cadena, int $numDig, int $limMult, bool $x10): string
    {
        if (!$x10) {
            $numDig = 1;
        }
        for ($n = 1; $n <= $numDig; $n++) {
            $suma = 0;
            $mult = 2;
            for ($i = strlen($cadena) - 1; $i >= 0; $i--) {
                $suma += ($mult * (int) $cadena[$i]);
                if (++$mult > $limMult) {
                    $mult = 2;
                }
            }
            if ($x10) {
                $dig = (($suma * 10) % 11) % 10;
            } else {
                $dig = $suma % 11;
            }
            if ($dig == 10) {
                $cadena .= '1';
            } elseif ($dig == 11) {
                $cadena .= '0';
            } else {
                $cadena .= (string) $dig;
            }
        }
        return substr($cadena, -$numDig);
    }

    private static function dec2hex(string $number): string
    {
        $hexValues = '0123456789ABCDEF';
        $hexVal = '';
        while ($number !== '0' && $number !== '') {
            $hexVal = $hexValues[(int) bcmod($number, '16')] . $hexVal;
            $number = bcdiv($number, '16', 0);
        }
        return $hexVal ?: '0';
    }
}
