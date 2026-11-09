<?php
/**
 * ApiResponse — respuestas consistentes para toda la API.
 *
 * JSON:  { "success": bool, "data": mixed, "message": string|null, "errors": [] }
 * Documentos (PDF/xlsx/zip): stream binario con Content-Type/Content-Disposition.
 */

namespace CgrApi\Http;

class ApiResponse
{
    public static function json($payload, $httpCode = 200)
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public static function ok($data, $message = null)
    {
        self::json(array(
            'success' => true, 'data' => $data, 'message' => $message, 'errors' => array(),
        ), 200);
    }

    public static function error($message, $httpCode = 400, $errors = array())
    {
        self::json(array(
            'success' => false, 'data' => null, 'message' => $message, 'errors' => $errors,
        ), $httpCode);
    }

    /**
     * Envía un documento binario.
     * @param string $disposition 'inline' (visualizar) o 'attachment' (descargar)
     */
    public static function binary($bin, $contentType, $filename, $disposition = 'attachment')
    {
        $disposition = ($disposition === 'inline') ? 'inline' : 'attachment';
        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
    }
}
