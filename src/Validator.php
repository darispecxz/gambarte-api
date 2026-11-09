<?php
/**
 * Validator — validación de parámetros de entrada.
 * Lanza excepciones con prefijo "__validation__" que el Router traduce a HTTP 422.
 *
 * El resolvePeriodo() replica EXACTAMENTE la lógica de período de
 * reportes/riesgo_cambiario/index.php y descargar.php, para que la API devuelva
 * los mismos resultados que el sistema PHP bajo los mismos parámetros.
 */

namespace CgrApi;

class Validator
{
    private static function fail($msg) { throw new \RuntimeException('__validation__' . $msg); }

    public static function isDate($s)
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;
    }

    public static function isPeriod($s)
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}$/', $s);
    }

    public static function period($q, $key = 'periodo')
    {
        $v = isset($q[$key]) ? trim($q[$key]) : '';
        if (!self::isPeriod($v)) self::fail("Parámetro '$key' inválido (use AAAA-MM).");
        return $v;
    }

    public static function inList($v, array $list, $label)
    {
        if (!in_array($v, $list, true)) {
            self::fail("Valor inválido para '$label'. Permitidos: " . implode(', ', $list) . '.');
        }
        return $v;
    }

    /**
     * Resuelve [ini, fin] a partir de preset/ini/fin/fecha, idéntico al sistema PHP.
     * preset: mes (default) | dia | rango.
     */
    public static function resolvePeriodo(array $q)
    {
        $preset = isset($q['preset']) ? $q['preset'] : 'mes';
        $hoy = date('Y-m-d');

        if ($preset === 'dia') {
            $f = (isset($q['fecha']) && self::isDate($q['fecha'])) ? $q['fecha'] : $hoy;
            return array($f, $f);
        }
        if ($preset === 'rango') {
            $i = (isset($q['ini']) && self::isDate($q['ini'])) ? $q['ini'] : date('Y-m-01');
            $f = (isset($q['fin']) && self::isDate($q['fin'])) ? $q['fin'] : $hoy;
            if ($i > $f) { $t = $i; $i = $f; $f = $t; }
            return array($i, $f);
        }
        // mes (default): del 1º del mes de 'fin' hasta 'fin'
        $f = (isset($q['fin']) && self::isDate($q['fin'])) ? $q['fin'] : $hoy;
        $i = date('Y-m-01', strtotime($f));
        return array($i, $f);
    }

    /** Rango de fecha-hora para operaciones (api_dashboard: desde / hasta). */
    public static function resolveRango(array $q)
    {
        $desde = (isset($q['dateFrom']) && self::isDate($q['dateFrom'])) ? $q['dateFrom']
               : ((isset($q['desde']) && self::isDate(substr($q['desde'], 0, 10))) ? $q['desde'] : date('Y-m-01'));
        $hasta = (isset($q['dateTo']) && self::isDate($q['dateTo'])) ? $q['dateTo'] . ' 23:59:59'
               : ((isset($q['hasta'])) ? $q['hasta'] : date('Y-m-d') . ' 23:59:59');
        return array($desde, $hasta);
    }
}
