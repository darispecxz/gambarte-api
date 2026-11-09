<?php
/**
 * Env — cargador mínimo de variables de entorno desde un archivo .env
 * (sin dependencias). No sobreescribe variables ya presentes en el entorno.
 */

namespace CgrApi;

class Env
{
    private static $loaded = false;

    public static function load($path)
    {
        if (self::$loaded) return;
        self::$loaded = true;
        if (!is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // quita comillas envolventes
            if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'")) {
                $val = substr($val, 1, -1);
            }
            if (getenv($key) === false) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
            }
        }
    }

    public static function get($key, $default = null)
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }

    public static function bool($key, $default = false)
    {
        $v = self::get($key, null);
        if ($v === null) return $default;
        return in_array(strtolower($v), array('1', 'true', 'yes', 'on'), true);
    }
}
