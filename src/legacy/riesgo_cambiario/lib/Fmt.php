<?php
/**
 * Fmt.php — Helpers de formato compartidos por todos los informes del panel.
 */

namespace RiesgoCambiario;

class Fmt
{
    private static $meses = array(1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
        7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre');

    /** Número estilo boliviano: 1.234,56 */
    public static function n($v, $dec = 2)
    {
        return number_format((float) $v, $dec, ',', '.');
    }

    /** Como n() pero muestra guion cuando es (casi) cero. */
    public static function nd($v, $dec = 2)
    {
        if (abs((float) $v) < 0.005) return '-';
        return self::n($v, $dec);
    }

    /** Porcentaje: recibe fracción (0.23 -> "23,00%"). */
    public static function pct($v, $dec = 2)
    {
        return number_format((float) $v * 100, $dec, ',', '.') . '%';
    }

    /** Porcentaje: recibe valor ya en % (23 -> "23,00%"). */
    public static function pctv($v, $dec = 2)
    {
        return number_format((float) $v, $dec, ',', '.') . '%';
    }

    public static function fechaLarga($ymd)
    {
        $t = strtotime($ymd);
        return (int) date('j', $t) . ' de ' . self::$meses[(int) date('n', $t)] . ' de ' . date('Y', $t);
    }

    public static function fechaCorta($ymd)
    {
        return date('d/m/Y', strtotime($ymd));
    }
}
