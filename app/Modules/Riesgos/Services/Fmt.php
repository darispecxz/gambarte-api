<?php

namespace App\Modules\Riesgos\Services;

class Fmt
{
    private static $meses = array(1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
        7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre');

    public static function n($v, $dec = 2)
    {
        return number_format((float) $v, $dec, ',', '.');
    }

    public static function nd($v, $dec = 2)
    {
        if (abs((float) $v) < 0.005) return '-';
        return self::n($v, $dec);
    }

    public static function pct($v, $dec = 2)
    {
        return number_format((float) $v * 100, $dec, ',', '.') . '%';
    }

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
