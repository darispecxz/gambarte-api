<?php

namespace App\Modules\Bancos\Controllers;

use App\Modules\Bancos\Services\BankingOpsService;
use App\Modules\Bancos\Services\BancoChequeService;
use App\Modules\Bancos\Services\BancoSesionService;
use App\Modules\Bancos\Services\BancoAdjuntoService;
use App\Modules\Bancos\Services\ConciliacionExtractoService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operaciones de bancos del POS de cajeros.
 *
 * La seguridad NO se delega al frontend: cada acción valida la agencia del
 * token contra la del recurso. El legacy no comprobaba nada de esto y sus
 * endpoints eran invocables sin sesión.
 */
class BankingOpsController extends ApiController
{
    private BankingOpsService $service;
    private BancoChequeService $cheques;
    private BancoSesionService $sesion;
    private BancoAdjuntoService $adjuntos;
    private ConciliacionExtractoService $conciliacion;

    public function __construct(
        BankingOpsService $service,
        BancoChequeService $cheques,
        BancoSesionService $sesion,
        BancoAdjuntoService $adjuntos,
        ConciliacionExtractoService $conciliacion
    ) {
        $this->service      = $service;
        $this->cheques      = $cheques;
        $this->sesion       = $sesion;
        $this->adjuntos     = $adjuntos;
        $this->conciliacion = $conciliacion;
    }

    private function agencia(Request $request): int
    {
        $claims = $request->attributes->get('jwt_claims');
        return (int) $claims['agencia']['id_agencia'];
    }

    private function usuario(Request $request): int
    {
        $claims = $request->attributes->get('jwt_claims');
        return (int) $claims['user']['cod_usuario'];
    }

    /** Listado completo de agencias (los traspasos son inter-agencia). */
    public function agencias(Request $request): JsonResponse
    {
        $rows = $this->service->agencias();
        return $this->ok(['total' => count($rows), 'agencias' => $rows]);
    }

    /** Cuentas con saldo. `agencia` opcional permite ver las de otras agencias (destino). */
    public function cuentas(Request $request): JsonResponse
    {
        $idAgencia = $this->agencia($request);
        $todas = (bool) $request->query('todas', false);

        // Para elegir destino hacen falta las cuentas de todas las agencias;
        // para girar, solo las propias. Se devuelven todas con el flag `propia`.
        if (!$todas) {
            $cuentas = array_values(array_filter(
                $this->service->cuentas($idAgencia),
                function ($c) use ($idAgencia) { return (int) $c['id_agencia'] === $idAgencia; }
            ));
        } else {
            $cuentas = $this->service->cuentas($idAgencia);
        }

        return $this->ok(['agencia' => $idAgencia, 'total' => count($cuentas), 'cuentas' => $cuentas]);
    }

    /** Traspasos en los que participa la agencia. */
    public function traspasos(Request $request): JsonResponse
    {
        $idAgencia = $this->agencia($request);
        $estado = $request->filled('estado') ? trim($request->query('estado')) : null;
        $desde  = $this->optionalFecha($request, 'desde');
        $hasta  = $this->optionalFecha($request, 'hasta');

        $rows = $this->service->traspasos($idAgencia, $estado, $desde, $hasta);

        return $this->ok([
            'agencia' => $idAgencia,
            'total'   => count($rows),
            'traspasos' => $rows,
        ]);
    }

    /** Bandeja de recepción: pendientes de aceptar por esta agencia. */
    public function pendientes(Request $request): JsonResponse
    {
        $idAgencia = $this->agencia($request);
        $rows = $this->service->traspasosPendientes($idAgencia);

        return $this->ok(['agencia' => $idAgencia, 'total' => count($rows), 'traspasos' => $rows]);
    }

    public function registrar(Request $request): JsonResponse
    {
        $result = $this->service->registrarTraspaso(
            $request->all(),
            $this->usuario($request),
            $this->agencia($request),
            $request->ip()
        );

        return $result['success']
            ? $this->ok($result['data'], $result['message'])
            : $this->error($result['message'], 422);
    }

    public function aceptar(Request $request, int $id): JsonResponse
    {
        $documento = trim((string) $request->input('documento', ''));
        if ($documento === '') {
            return $this->error('El número de comprobante es requerido.', 400);
        }

        $result = $this->service->aceptarTraspaso(
            $id, $this->usuario($request), $this->agencia($request), $documento, $request->ip()
        );

        return $result['success']
            ? $this->ok($result['data'], $result['message'])
            : $this->error($result['message'], 422);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $result = $this->service->rechazarTraspaso(
            $id,
            $this->usuario($request),
            $this->agencia($request),
            trim((string) $request->input('motivo', '')),
            $request->ip()
        );

        return $result['success']
            ? $this->ok($result['data'], $result['message'])
            : $this->error($result['message'], 422);
    }

    public function revertir(Request $request, int $id): JsonResponse
    {
        $result = $this->service->revertirTraspaso(
            $id,
            $this->usuario($request),
            $this->agencia($request),
            trim((string) $request->input('motivo', '')),
            $request->ip()
        );

        return $result['success']
            ? $this->ok($result['data'], $result['message'])
            : $this->error($result['message'], 422);
    }

    /** Historial de depósitos y retiros. */
    public function depositos(Request $request): JsonResponse
    {
        $desde = $this->optionalFecha($request, 'desde');
        $hasta = $this->optionalFecha($request, 'hasta');
        $rows = $this->service->depositos($this->agencia($request), $desde, $hasta);

        return $this->ok(['total' => count($rows), 'depositos' => $rows]);
    }

    /**
     * Libro de movimientos bancarios con filtros.
     * Sin filtros devuelve los últimos movimientos, que es lo útil por defecto.
     */
    public function movimientos(Request $request): JsonResponse
    {
        $filtros = [
            'desde'        => $request->filled('desde') ? trim($request->query('desde')) : null,
            'hasta'        => $request->filled('hasta') ? trim($request->query('hasta')) : null,
            'cuenta'       => $request->filled('cuenta') ? trim($request->query('cuenta')) : null,
            'entidad'      => $request->filled('entidad') ? (int) $request->query('entidad') : null,
            'moneda'       => $request->filled('moneda') ? (int) $request->query('moneda') : null,
            'conciliacion' => $request->filled('conciliacion') ? trim($request->query('conciliacion')) : null,
            'monto_min'    => $request->filled('monto_min') ? $request->query('monto_min') : null,
            'monto_max'    => $request->filled('monto_max') ? $request->query('monto_max') : null,
            'texto'        => $request->filled('texto') ? trim($request->query('texto')) : null,
        ];

        // Validación de fechas si vienen.
        foreach (['desde', 'hasta'] as $k) {
            if ($filtros[$k] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros[$k])) {
                return $this->error("El parámetro '{$k}' debe tener formato AAAA-MM-DD.", 422);
            }
        }

        $limite = max(1, min(2000, (int) $request->query('limite', 300)));
        $movs = $this->service->movimientos($this->agencia($request), $filtros, $limite);

        // Para el estado de cuenta (una cuenta concreta y un rango fechado),
        // los saldos inicial/final se calculan contra todo el libro de la cuenta.
        // Así no dependen de que Apertura/Cierre aparezcan dentro de la página.
        $saldoPeriodo = null;
        if ($filtros['cuenta'] && $filtros['entidad'] && $filtros['moneda'] && $filtros['desde'] && $filtros['hasta']) {
            try {
                $agencia = $this->agencia($request);
                $diaAnterior = (new \DateTimeImmutable($filtros['desde']))->modify('-1 day')->format('Y-m-d');
                $saldoPeriodo = [
                    'desde' => $filtros['desde'],
                    'hasta' => $filtros['hasta'],
                    'saldo_inicial' => $this->service->saldoCuentaAlCorte($agencia, $filtros['entidad'], $filtros['cuenta'], $filtros['moneda'], $diaAnterior),
                    'saldo_final' => $this->service->saldoCuentaAlCorte($agencia, $filtros['entidad'], $filtros['cuenta'], $filtros['moneda'], $filtros['hasta']),
                ];
            } catch (\Throwable $e) {
                return $this->error('No se pudieron calcular los saldos del período.', 422);
            }
        }

        return $this->ok([
            'total'    => count($movs),
            'totales'  => $this->service->movimientosTotales($movs),
            'saldo_periodo' => $saldoPeriodo,
            'movimientos' => $movs,
        ]);
    }

    /**
     * Deposita efectivo de una caja en una cuenta, o retira del banco a la caja.
     * `tipo`: D = depósito, R = retiro.
     */
    public function deposito(Request $request): JsonResponse
    {
        $tipo     = strtoupper(trim((string) $request->input('tipo', '')));
        $idCaja   = (int) $request->input('id_caja');
        $monto    = (float) $request->input('monto');
        $uuid     = trim((string) $request->input('uuid', ''));

        if (!$idCaja) return $this->error('id_caja es requerido.', 400);
        if ($monto <= 0) return $this->error('El monto debe ser mayor a 0.', 400);
        if ($uuid === '') return $this->error('El identificador de la operación (uuid) es requerido.', 400);

        $cuentaClave = [
            'id_agencia'     => $request->input('id_agencia_cuenta', $this->agencia($request)),
            'id_entidad'     => $request->input('id_entidad'),
            'id_tipo_cuenta' => $request->input('id_tipo_cuenta'),
            'num_cuenta'     => $request->input('num_cuenta'),
            'id_moneda'      => $request->input('id_moneda'),
        ];
        foreach (['id_entidad', 'id_tipo_cuenta', 'num_cuenta', 'id_moneda'] as $req) {
            if (empty($cuentaClave[$req])) {
                return $this->error("El campo '{$req}' de la cuenta bancaria es requerido.", 400);
            }
        }

        $result = $this->service->registrarDepositoRetiro(
            $tipo,
            $idCaja,
            $this->agencia($request),
            $this->usuario($request),
            $cuentaClave,
            $monto,
            $request->input('referencia'),
            $request->input('glosa'),
            $request->input('comprobante_path'),
            $uuid,
            $request->ip()
        );

        return $result['success']
            ? $this->ok($result['data'], $result['message'])
            : $this->error($result['message'], 422);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Cheques
    // ═══════════════════════════════════════════════════════════════════

    public function chequeras(Request $request): JsonResponse
    {
        $rows = $this->cheques->chequeras($this->agencia($request));
        return $this->ok(['total' => count($rows), 'chequeras' => $rows]);
    }

    public function cheques(Request $request): JsonResponse
    {
        $idChequera = (int) $request->query('id_chequera');
        $estado = $request->filled('estado') ? strtoupper(trim($request->query('estado'))) : null;

        if (!$idChequera) {
            return $this->error('id_chequera es requerido.', 400);
        }
        $rows = $this->cheques->cheques($idChequera, $estado);
        return $this->ok(['total' => count($rows), 'cheques' => $rows]);
    }

    /** Cheques en circulación de la agencia (bandeja de trabajo). */
    public function chequesAgencia(Request $request): JsonResponse
    {
        $estado = $request->filled('estado') ? strtoupper(trim($request->query('estado'))) : null;
        $rows = $this->cheques->chequesAgencia($this->agencia($request), $estado);
        return $this->ok(['total' => count($rows), 'cheques' => $rows]);
    }

    public function chequesCatalogos(Request $request): JsonResponse
    {
        return $this->ok([
            'categorias' => $this->cheques->categorias(),
            'destinos'   => $this->cheques->destinosEnvio($this->agencia($request)),
        ]);
    }

    public function emitirCheque(Request $request): JsonResponse
    {
        $result = $this->cheques->emitir($request->all(), $this->usuario($request), $this->agencia($request), $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function enviarCheque(Request $request): JsonResponse
    {
        $result = $this->cheques->enviar(
            (int) $request->input('id_chequera'),
            (int) $request->input('id_cheque'),
            (int) $request->input('id_agencia_destino'),
            $request->filled('id_categoria') ? (int) $request->input('id_categoria') : null,
            $request->input('observaciones'),
            $this->usuario($request),
            $this->agencia($request),
            $request->ip()
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function enviosPendientes(Request $request): JsonResponse
    {
        $rows = $this->cheques->enviosPendientes($this->agencia($request));
        return $this->ok(['total' => count($rows), 'envios' => $rows]);
    }

    public function recibirCheque(Request $request, int $id): JsonResponse
    {
        $result = $this->cheques->recibir($id, $this->usuario($request), $this->agencia($request), $request->ip());
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function rechazarEnvio(Request $request, int $id): JsonResponse
    {
        $result = $this->cheques->rechazarEnvio(
            $id, $this->usuario($request), $this->agencia($request),
            trim((string) $request->input('motivo', '')), $request->ip()
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function cobrarCheque(Request $request): JsonResponse
    {
        $result = $this->cheques->cobrar(
            (int) $request->input('id_chequera'),
            (int) $request->input('id_cheque'),
            (int) $request->input('id_caja'),
            $this->usuario($request),
            $this->agencia($request),
            $request->input('tercero'),
            $request->ip()
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function anularCheque(Request $request): JsonResponse
    {
        $result = $this->cheques->anular(
            (int) $request->input('id_chequera'),
            (int) $request->input('id_cheque'),
            trim((string) $request->input('motivo', '')),
            $this->usuario($request),
            $this->agencia($request),
            $request->ip()
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function revertirAnulacion(Request $request): JsonResponse
    {
        $result = $this->cheques->revertirAnulacion(
            (int) $request->input('id_chequera'),
            (int) $request->input('id_cheque'),
            trim((string) $request->input('motivo', '')),
            $this->usuario($request),
            $this->agencia($request),
            $request->ip()
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Apertura / cierre de banca
    // ═══════════════════════════════════════════════════════════════════

    public function estadoBanca(Request $request): JsonResponse
    {
        return $this->ok($this->sesion->estado($this->agencia($request)));
    }

    public function abrirBanca(Request $request): JsonResponse
    {
        $cuentas = $request->input('cuentas', []);
        $montos  = $request->input('montos', []);
        $glosas  = $request->input('glosas', []);
        if (!is_array($cuentas)) {
            return $this->error("El campo 'cuentas' debe ser una lista.", 400);
        }
        if (!is_array($montos)) { $montos = []; }
        if (!is_array($glosas)) { $glosas = []; }

        $result = $this->sesion->abrir(
            $this->agencia($request), $cuentas, $this->usuario($request), $request->ip(), $glosas, $montos
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    public function cerrarBanca(Request $request): JsonResponse
    {
        $cuentas = $request->input('cuentas', []);
        $montos  = $request->input('montos', []);
        $glosas  = $request->input('glosas', []);
        if (!is_array($cuentas)) {
            return $this->error("El campo 'cuentas' debe ser una lista.", 400);
        }
        if (!is_array($montos)) { $montos = []; }
        if (!is_array($glosas)) { $glosas = []; }

        $result = $this->sesion->cerrar(
            $this->agencia($request), $cuentas, $montos, $this->usuario($request), $request->ip(), $glosas
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Comprobantes adjuntos
    // ═══════════════════════════════════════════════════════════════════

    /** Sube el comprobante de un traspaso. */
    public function subirComprobanteTraspaso(Request $request, int $id): JsonResponse
    {
        return $this->subirAdjunto($request, 'traspaso', $id);
    }

    /** Sube el comprobante de un depósito/retiro. */
    public function subirComprobanteDeposito(Request $request, int $id): JsonResponse
    {
        return $this->subirAdjunto($request, 'deposito', $id);
    }

    /** Sube el comprobante de un envío de cheque. */
    public function subirComprobanteEnvio(Request $request, int $id): JsonResponse
    {
        return $this->subirAdjunto($request, 'cheque_envio', $id);
    }

    private function subirAdjunto(Request $request, string $tipo, int $id): JsonResponse
    {
        $file = $request->file('comprobante');
        if (!$file) {
            return $this->error('El archivo del comprobante es requerido.', 400);
        }

        $result = $this->adjuntos->subir($tipo, $id, $file, $this->usuario($request), $this->agencia($request));
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    /** Sube una cara del cheque (anverso o reverso). */
    public function subirImagenCheque(Request $request): JsonResponse
    {
        $file = $request->file('imagen');
        if (!$file) {
            return $this->error('La imagen del cheque es requerida.', 400);
        }

        $result = $this->adjuntos->subirCheque(
            (int) $request->input('id_chequera'),
            (int) $request->input('id_cheque'),
            strtolower(trim((string) $request->input('cara', ''))),
            $file,
            $this->usuario($request),
            $this->agencia($request)
        );
        return $result['success'] ? $this->ok($result['data'], $result['message']) : $this->error($result['message'], 422);
    }

    /** Adjuntos (anverso/reverso) de un cheque. */
    public function adjuntosCheque(Request $request): JsonResponse
    {
        $idChequera = (int) $request->query('id_chequera');
        $numero     = (int) $request->query('id_cheque');
        if (!$idChequera || !$numero) {
            return $this->error('id_chequera e id_cheque son requeridos.', 400);
        }

        return $this->ok($this->adjuntos->adjuntosCheque($idChequera, $numero));
    }

    // ── Conciliación por extractos bancarios ───────────────────────────
    /** Catálogos para la pantalla de conciliación (bancos soportados, tipos de acción). */
    public function conciliacionCatalogos(Request $request): JsonResponse
    {
        return $this->ok([
            'bancos'       => $this->conciliacion->bancosSoportados(),
            'tipos_accion' => $this->conciliacion->tiposAccion(),
        ]);
    }

    /** Sube un extracto (PDF) y lo concilia contra el libro OBEL. */
    public function conciliarExtracto(Request $request): JsonResponse
    {
        $idAgencia = $this->agencia($request);

        $file = $request->file('extracto');
        if (!$file) {
            return $this->error('El archivo del extracto (PDF) es requerido.', 400);
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            return $this->error('El extracto debe ser un archivo PDF.', 422);
        }
        if ($file->getSize() > 8 * 1024 * 1024) {
            return $this->error('El archivo supera el máximo permitido (8 MB).', 422);
        }

        $cuenta = $this->cuentaDeRequest($request, $idAgencia);
        if ($cuenta['num_cuenta'] === '') {
            return $this->error('Seleccione la cuenta bancaria a conciliar.', 422);
        }

        try {
            $res = $this->conciliacion->conciliar($idAgencia, $cuenta, $file->getRealPath());
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->ok($res);
    }

    /** Registra en OBEL una línea del extracto que no estaba conciliada. */
    public function registrarConciliacion(Request $request): JsonResponse
    {
        $idAgencia = $this->agencia($request);
        $cuenta = $this->cuentaDeRequest($request, $idAgencia);
        if ($cuenta['num_cuenta'] === '') {
            return $this->error('Falta la cuenta bancaria.', 422);
        }

        $linea  = (array) $request->input('linea', []);
        $tipo   = (string) $request->input('tipo_accion', '');
        $motivo = (string) $request->input('motivo', '');
        $razon  = $request->input('razon');

        try {
            $res = $this->conciliacion->registrar(
                $idAgencia, $cuenta, $linea, $tipo, $motivo,
                $razon !== null ? (string) $razon : null,
                $this->usuario($request)
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->ok($res, 'Movimiento registrado y conciliado.');
    }

    /** Arma el descriptor de cuenta a partir del request. */
    private function cuentaDeRequest(Request $request, int $idAgencia): array
    {
        return [
            'id_agencia'     => (int) $request->input('id_agencia', $idAgencia),
            'id_entidad'     => (int) $request->input('id_entidad', 0),
            'id_tipo_cuenta' => (int) $request->input('id_tipo_cuenta', 0),
            'num_cuenta'     => trim((string) $request->input('num_cuenta', '')),
            'id_moneda'      => (int) $request->input('id_moneda', 0),
            'codsubcuenta'   => trim((string) $request->input('codsubcuenta', '')),
        ];
    }

    /**
     * Descarga un comprobante.
     *
     * El POS y la API están en dominios distintos, así que el navegador no puede
     * abrir el archivo por ruta: se sirve por este endpoint, validando que la
     * ruta esté dentro de la carpeta de comprobantes (evita traversal).
     */
    public function descargarComprobante(Request $request)
    {
        $ruta = (string) $request->query('path', '');
        $ruta = str_replace('\\', '/', $ruta);

        if ($ruta === '' || strpos($ruta, '..') !== false || strpos($ruta, 'comprobantes/bancos/') !== 0) {
            abort(400, 'Ruta de comprobante inválida.');
        }

        $absoluta = storage_path('app/' . $ruta);
        if (!is_file($absoluta)) {
            abort(404, 'Comprobante no encontrado.');
        }

        return response()->file($absoluta, [
            'Content-Disposition' => 'inline; filename="' . basename($absoluta) . '"',
        ]);
    }
}
