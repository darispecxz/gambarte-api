<?php

use App\Modules\Cajero\Controllers\CajeroController;
use App\Modules\Cajero\Controllers\CajeroCambioController;
use App\Modules\Cajero\Controllers\CajeroMovimientosController;
use App\Modules\Cajero\Controllers\CajeroGiroController;
use App\Modules\Cajero\Controllers\CajeroRemesaController;
use Illuminate\Support\Facades\Route;

Route::prefix('cajero')->middleware(['jwt', 'role:1,2,4,6'])->group(function () {
    // Lectura
    Route::get('/mis-cajas',        [CajeroController::class, 'misCajas']);
    Route::get('/denominaciones',   [CajeroController::class, 'denominaciones']);
    Route::get('/disponibilidad',   [CajeroController::class, 'disponibilidad']);

    // Sesión de caja
    Route::post('/apertura',        [CajeroController::class, 'apertura']);
    Route::post('/cierre',          [CajeroController::class, 'cierre']);
    Route::post('/cierre-con-apertura', [CajeroController::class, 'cierreConApertura']);

    // Facturación / SIAT
    Route::get('/estado-siat',      [CajeroCambioController::class, 'estadoSiat']);

    // Cambio de moneda
    Route::get('/cotizaciones',     [CajeroCambioController::class, 'cotizaciones']);
    Route::get('/buscar-cliente',      [CajeroCambioController::class, 'buscarCliente']);
    Route::post('/actualizar-cliente', [CajeroCambioController::class, 'actualizarCliente']);
    Route::get('/catalogos',        [CajeroCambioController::class, 'catalogos']);
    Route::post('/registrar-cambio', [CajeroCambioController::class, 'registrarCambio']);
    Route::post('/calcular-cambio', [CajeroCambioController::class, 'calcularCambio']);
    Route::get('/comprobante/{id}', [CajeroCambioController::class, 'comprobante']);

    // Historial
    Route::get('/historial',        [CajeroCambioController::class, 'historial']);
    Route::get('/historial/{id}',   [CajeroCambioController::class, 'historialDetalle'])
         ->where('id', '[0-9]+');
    Route::get('/factura-cambio-pdf/{id}', [CajeroCambioController::class, 'facturaPdf'])
         ->where('id', '[0-9]+');

    // Remesas
    Route::post('/verificar-uif-remesa',    [CajeroRemesaController::class, 'verificarUif']);
    Route::get('/comisiones-preferenciales', [CajeroRemesaController::class, 'comisionesPreferenciales']);
    Route::get('/tc-oficial-venta',         [CajeroRemesaController::class, 'tcOficialVenta']);
    Route::get('/bancos-chile',            [CajeroRemesaController::class, 'bancosChile']);
    Route::get('/formas-pago-chile',       [CajeroRemesaController::class, 'formasPagoChile']);
    Route::get('/formas-pago-cobro',       [CajeroRemesaController::class, 'formasPagoCobro']);
    Route::get('/destinos',                [CajeroRemesaController::class, 'destinos']);
    Route::get('/buscar-destinatario',     [CajeroRemesaController::class, 'buscarDestinatario']);
    Route::get('/destinatarios-remitente', [CajeroRemesaController::class, 'destinatariosPorRemitente']);
    Route::get('/cotizacion-chile',        [CajeroRemesaController::class, 'cotizacionChile']);
    Route::post('/calcular-comision',      [CajeroRemesaController::class, 'calcularComision']);
    Route::post('/cotizacion-remesa',      [CajeroRemesaController::class, 'crearCotizacion']);
    Route::get('/buscar-cotizacion-remesa', [CajeroRemesaController::class, 'buscarCotizacion']);
    Route::post('/registrar-remesa',       [CajeroRemesaController::class, 'registrarRemesa']);
    Route::get('/comprobante-remesa/{id}', [CajeroRemesaController::class, 'comprobanteRemesa'])
         ->where('id', '[0-9]+');
    Route::get('/comprobante-remesa-pdf/{id}', [CajeroRemesaController::class, 'comprobantePdf'])
         ->where('id', '[0-9]+');
    Route::get('/factura-remesa-pdf/{id}', [CajeroRemesaController::class, 'facturaPdf'])
         ->where('id', '[0-9]+');
    Route::get('/comprobante-envio-chile-pdf/{codigo}', [CajeroRemesaController::class, 'comprobanteEnvioChilePdf'])
         ->where('codigo', '[0-9]+');
    Route::get('/historial-remesa',       [CajeroRemesaController::class, 'historialRemesa']);
    Route::get('/cotizaciones-remesa',    [CajeroRemesaController::class, 'listarCotizaciones']);

    // Pago de remesas (Chile → Bolivia)
    Route::get('/remesas-pendientes',      [CajeroRemesaController::class, 'pendientesPago']);
    Route::get('/buscar-remesa/{codigo}',   [CajeroRemesaController::class, 'buscarRemesa'])
         ->where('codigo', '[0-9]+');
    Route::post('/preprocesar-remesas',     [CajeroRemesaController::class, 'preprocesarRemesas']);
    Route::post('/pagar-remesa',           [CajeroRemesaController::class, 'pagarRemesa']);
    Route::get('/comprobante-pago-remesa/{id}', [CajeroRemesaController::class, 'comprobantePagoRemesa'])
         ->where('id', '[0-9]+');
    Route::get('/comprobante-pago-remesa-pdf/{id}', [CajeroRemesaController::class, 'comprobantePagoRemesaPdf'])
         ->where('id', '[0-9]+');
    Route::post('/enviar-comprobante-pago-remesa', [CajeroRemesaController::class, 'enviarComprobantePagoEmail']);
    Route::get('/reporte-remesas',         [CajeroRemesaController::class, 'reporteRemesas']);
    Route::post('/confirmar-preproceso',   [CajeroRemesaController::class, 'confirmarPreproceso']);
    Route::post('/subir-boleta-pago-remesa', [CajeroRemesaController::class, 'subirBoletaPagoRemesa']);
    Route::get('/preprocesos-activos',     [CajeroRemesaController::class, 'preprocesosActivos']);
    Route::post('/sync-chile-retry',       [CajeroRemesaController::class, 'syncChileRetry']);

    // Giros nacionales
    Route::post('/verificar-uif-giro',      [CajeroGiroController::class, 'verificarUif']);
    Route::post('/calcular-comision-giro',   [CajeroGiroController::class, 'calcularComision']);
    Route::post('/registrar-giro',           [CajeroGiroController::class, 'registrar']);
    Route::post('/pagar-giro',               [CajeroGiroController::class, 'pagar']);
    Route::post('/devolver-giro',            [CajeroGiroController::class, 'devolver']);
    Route::get('/buscar-giro/{codigo}',      [CajeroGiroController::class, 'buscar'])
         ->where('codigo', '[0-9]+');
    Route::get('/giros-pendientes',          [CajeroGiroController::class, 'pendientes']);
    Route::get('/historial-giro',            [CajeroGiroController::class, 'historial']);
    Route::get('/agencias-destino',          [CajeroGiroController::class, 'agenciasDestino']);
    Route::get('/entidades-financieras',     [CajeroGiroController::class, 'entidadesFinancieras']);
    Route::get('/destinatarios-giro',        [CajeroGiroController::class, 'destinatariosPorRemitente']);
    Route::get('/beneficiario-final',        [CajeroGiroController::class, 'buscarBeneficiarioFinal']);
    Route::get('/comprobante-giro-pdf/{id}', [CajeroGiroController::class, 'comprobantePdf'])
         ->where('id', '[0-9]+');
    Route::get('/factura-giro-pdf/{id}',     [CajeroGiroController::class, 'facturaPdf'])
         ->where('id', '[0-9]+');

    // Movimientos de caja
    Route::post('/solicitar-fondos',       [CajeroMovimientosController::class, 'solicitarFondos']);
    Route::post('/traspaso-boveda',        [CajeroMovimientosController::class, 'traspasoBoveda']);
    Route::post('/traspaso-cajero',        [CajeroMovimientosController::class, 'traspasoCajero']);
    Route::post('/transferencia-boveda',   [CajeroMovimientosController::class, 'transferenciaBoveda']);
    Route::post('/transferencia-caja-agencia', [CajeroMovimientosController::class, 'transferenciaCajaAgencia']);
    Route::post('/egreso',                 [CajeroMovimientosController::class, 'egreso']);
    Route::post('/ingreso',                [CajeroMovimientosController::class, 'ingreso']);
    Route::post('/transaccion-bancaria',   [CajeroMovimientosController::class, 'transaccionBancaria']);
    Route::get('/cajas-destino',           [CajeroMovimientosController::class, 'cajasDestino']);
    Route::get('/bovedas-disponibles',     [CajeroMovimientosController::class, 'bovedasDisponibles']);
    Route::post('/subir-comprobante-egreso', [CajeroMovimientosController::class, 'subirComprobanteEgreso']);
    Route::post('/subir-comprobante-ingreso', [CajeroMovimientosController::class, 'subirComprobanteIngreso']);
    Route::post('/subir-comprobante-transaccion-bancaria', [CajeroMovimientosController::class, 'subirComprobanteTransaccionBancaria']);
    Route::get('/egresos-pendientes',      [CajeroMovimientosController::class, 'egresosPendientes']);
    Route::get('/movimientos-caja',        [CajeroMovimientosController::class, 'movimientosCaja']);
    Route::get('/cuentas-bancarias-cobro', [CajeroMovimientosController::class, 'cuentasBancariasCobro']);

    // Egreso / Ingreso autorizado (código contable del contador)
    Route::get('/buscar-asiento-autorizado',      [CajeroMovimientosController::class, 'buscarAsientoAutorizado']);
    Route::post('/completar-egreso-autorizado',   [CajeroMovimientosController::class, 'completarEgresoAutorizado']);
    Route::post('/completar-ingreso-autorizado',  [CajeroMovimientosController::class, 'completarIngresoAutorizado']);
});

// Aprobación de egresos — accesible por cajeros (ver sus propios) y contadores (aprobar/rechazar)
Route::prefix('cajero')->middleware(['jwt', 'role:1,2,3,4,6,7,8'])->group(function () {
    Route::post('/aprobar-egreso',   [CajeroMovimientosController::class, 'aprobarEgreso']);
    Route::post('/rechazar-egreso',  [CajeroMovimientosController::class, 'rechazarEgreso']);
});
