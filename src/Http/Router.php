<?php
/**
 * Router — micro-router sin dependencias (estructura estilo Slim para facilitar
 * una futura migración a Slim 4). Resuelve la ruta, aplica CORS, ejecuta el
 * handler y captura cualquier excepción devolviendo un error seguro (sin
 * stack traces ni detalles de base de datos hacia el frontend).
 */

namespace CgrApi\Http;

use CgrApi\Env;

class Router
{
    private $routes = array();
    private $debug;

    public function __construct($debug = false) { $this->debug = $debug; }

    public function get($pattern, $handler)  { $this->add('GET', $pattern, $handler); }
    public function post($pattern, $handler) { $this->add('POST', $pattern, $handler); }

    private function add($method, $pattern, $handler)
    {
        // /api/risks/{slug}/pdf -> regex con grupos nombrados
        $names = array();
        $regex = preg_replace_callback('/\{([a-zA-Z_]+)\}/', function ($m) use (&$names) {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = array(
            'method' => $method,
            'regex'  => '#^' . $regex . '/?$#',
            'names'  => $names,
            'handler'=> $handler,
        );
    }

    private function cors()
    {
        $allowed = Env::get('CORS_ALLOWED_ORIGINS', '*');
        $origin  = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        if ($allowed === '*') {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '') {
            $list = array_map('trim', explode(',', $allowed));
            if (in_array($origin, $list, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            }
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }

    public function run()
    {
        $this->cors();
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($method === 'OPTIONS') { http_response_code(204); return; }

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $base = Env::get('API_BASE_PATH', '');
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        if ($path === '' || $path === false) $path = '/';

        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;
            if (preg_match($r['regex'], $path, $m)) {
                array_shift($m);
                $params = array();
                foreach ($r['names'] as $i => $name) {
                    $params[$name] = urldecode($m[$i]);
                }
                try {
                    call_user_func($r['handler'], $params, $_GET);
                } catch (\Throwable $e) {
                    $this->handleError($e);
                } catch (\Exception $e) { // PHP 5.6
                    $this->handleError($e);
                }
                return;
            }
        }
        ApiResponse::error('Recurso no encontrado.', 404);
    }

    private function handleError($e)
    {
        // Registro interno (no se expone al cliente).
        error_log('[CgrApi] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (strpos($e->getMessage(), '__validation__') === 0) {
            ApiResponse::error(substr($e->getMessage(), 14), 422);
            return;
        }
        $msg = $this->debug ? $e->getMessage() : 'Ocurrió un error al procesar la solicitud.';
        ApiResponse::error($msg, 500);
    }
}
