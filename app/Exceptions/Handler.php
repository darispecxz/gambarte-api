<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [];

    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    public function register()
    {
        $this->renderable(function (NotFoundHttpException $e) {
            $msg = $e->getMessage() ?: 'Recurso no encontrado.';
            return self::apiError($msg, 404);
        });

        $this->renderable(function (ValidationException $e) {
            return self::apiError(
                $e->getMessage(),
                422,
                $e->errors()
            );
        });

        $this->renderable(function (Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                return self::apiError($e->getMessage(), $e->getStatusCode());
            }

            $msg = config('app.debug')
                ? $e->getMessage()
                : 'Ocurrió un error al procesar la solicitud.';

            return self::apiError($msg, 500);
        });
    }

    private static function apiError(string $message, int $code, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => $errors,
        ], $code, [], JSON_UNESCAPED_UNICODE);
    }
}
