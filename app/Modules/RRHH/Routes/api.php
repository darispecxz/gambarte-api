<?php

use App\Modules\RRHH\Controllers\CapacitacionController;
use App\Modules\RRHH\Controllers\ContratoController;
use App\Modules\RRHH\Controllers\CsbpController;
use App\Modules\RRHH\Controllers\DocumentoController;
use App\Modules\RRHH\Controllers\EvaluacionController;
use App\Modules\RRHH\Controllers\FiniquitoController;
use App\Modules\RRHH\Controllers\GestoraController;
use App\Modules\RRHH\Controllers\HorarioController;
use App\Modules\RRHH\Controllers\LiquidacionController;
use App\Modules\RRHH\Controllers\PlanillaController;
use App\Modules\RRHH\Controllers\PlanillaTributariaController;
use App\Modules\RRHH\Controllers\RrhhController;
use App\Modules\RRHH\Controllers\VacacionController;
use Illuminate\Support\Facades\Route;

Route::prefix('rrhh')->middleware(['jwt', 'role:2,3'])->group(function () {

    Route::get('/dashboard',                    [RrhhController::class, 'dashboard']);
    Route::get('/catalogos/cargos',             [RrhhController::class, 'catalogoCargos']);
    Route::get('/catalogos/agencias',           [RrhhController::class, 'catalogoAgencias']);
    Route::get('/empleados',                    [RrhhController::class, 'empleados']);
    Route::get('/empleados/{id}',               [RrhhController::class, 'empleadoDetalle'])->where('id', '[0-9]+');
    Route::get('/empleados/{id}/vacaciones',    [RrhhController::class, 'saldoVacaciones'])->where('id', '[0-9]+');
    Route::get('/parametros',                   [RrhhController::class, 'parametros']);
    Route::post('/parametros',                  [RrhhController::class, 'crearParametro']);
    Route::put('/parametros/{id}',              [RrhhController::class, 'actualizarParametro'])->where('id', '[0-9]+');
    Route::get('/gestiones',                    [RrhhController::class, 'gestiones']);
    Route::get('/feriados',                     [RrhhController::class, 'feriados']);
    Route::post('/feriados',                    [RrhhController::class, 'crearFeriado']);
    Route::put('/feriados/{id}',                [RrhhController::class, 'actualizarFeriado'])->where('id', '[0-9]+');
    Route::delete('/feriados/{id}',             [RrhhController::class, 'eliminarFeriado'])->where('id', '[0-9]+');

    // Contratos
    Route::get('/contratos',                    [ContratoController::class, 'listar']);
    Route::get('/contratos/{id}',               [ContratoController::class, 'detalle'])->where('id', '[0-9]+');
    Route::post('/contratos',                   [ContratoController::class, 'crear']);
    Route::put('/contratos/{id}',               [ContratoController::class, 'actualizar'])->where('id', '[0-9]+');
    Route::post('/contratos/{id}/rescindir',    [ContratoController::class, 'rescindir'])->where('id', '[0-9]+');

    // Planillas
    Route::get('/planillas',                    [PlanillaController::class, 'listar']);
    Route::get('/planillas/preview',            [PlanillaController::class, 'preview']);
    Route::get('/planillas/asistencia',         [PlanillaController::class, 'asistenciaResumen']);
    Route::get('/planillas/control-jornada',    [PlanillaController::class, 'controlJornada']);
    Route::post('/planillas/asistencia',        [PlanillaController::class, 'registrarAsistencia']);
    Route::post('/planillas/generar',           [PlanillaController::class, 'generar']);
    Route::get('/planillas/asistencia/{id}',    [PlanillaController::class, 'asistenciaDetalle'])->where('id', '[0-9]+');
    Route::get('/planillas/calcular/{id}',      [PlanillaController::class, 'calcularEmpleado'])->where('id', '[0-9]+');
    Route::get('/planillas/{id}',               [PlanillaController::class, 'detalle'])->where('id', '[0-9]+');

    // Liquidaciones
    Route::get('/liquidaciones',                [LiquidacionController::class, 'listar']);
    Route::get('/liquidaciones/simular',        [LiquidacionController::class, 'simular']);
    Route::get('/liquidaciones/{id}',           [LiquidacionController::class, 'detalle'])->where('id', '[0-9]+');

    // Capacitaciones
    Route::get('/capacitaciones',               [CapacitacionController::class, 'listar']);
    Route::get('/capacitaciones/cumplimiento',  [CapacitacionController::class, 'cumplimiento']);
    Route::post('/capacitaciones',              [CapacitacionController::class, 'crear']);
    Route::get('/capacitaciones/{id}',          [CapacitacionController::class, 'detalle'])->where('id', '[0-9]+');
    Route::put('/capacitaciones/{id}',          [CapacitacionController::class, 'actualizar'])->where('id', '[0-9]+');
    Route::post('/capacitaciones/{id}/inscribir', [CapacitacionController::class, 'inscribir'])->where('id', '[0-9]+');
    Route::put('/capacitaciones/{id}/calificar/{idEmpleado}', [CapacitacionController::class, 'calificar'])->where(['id' => '[0-9]+', 'idEmpleado' => '[0-9]+']);

    // Evaluaciones
    Route::get('/evaluaciones',                 [EvaluacionController::class, 'listar']);
    Route::get('/evaluaciones/{id}',            [EvaluacionController::class, 'detalle'])->where('id', '[0-9]+');
    Route::post('/evaluaciones',                [EvaluacionController::class, 'crear']);
    Route::put('/evaluaciones/{id}',            [EvaluacionController::class, 'actualizar'])->where('id', '[0-9]+');

    // Vacaciones
    Route::get('/vacaciones',                   [VacacionController::class, 'resumen']);
    Route::get('/vacaciones/cronograma',        [VacacionController::class, 'cronograma']);
    Route::post('/vacaciones/solicitar',        [VacacionController::class, 'solicitar']);
    Route::post('/vacaciones/{id}/aprobar',     [VacacionController::class, 'aprobar'])->where('id', '[0-9]+');
    Route::post('/vacaciones/{id}/rechazar',    [VacacionController::class, 'rechazar'])->where('id', '[0-9]+');

    // Documentos
    Route::get('/documentos/plantillas',        [DocumentoController::class, 'plantillas']);
    Route::get('/documentos/generados',         [DocumentoController::class, 'generados']);
    Route::post('/documentos/generar',          [DocumentoController::class, 'generar']);

    // CSBP (Caja Nacional de Salud)
    Route::get('/csbp/dashboard',               [CsbpController::class, 'dashboard']);
    Route::get('/csbp/altas',                   [CsbpController::class, 'altas']);
    Route::get('/csbp/bajas',                   [CsbpController::class, 'bajas']);
    Route::post('/csbp/altas',                  [CsbpController::class, 'crearAlta']);
    Route::put('/csbp/altas/{id}',              [CsbpController::class, 'actualizarAlta'])->where('id', '[0-9]+');
    Route::post('/csbp/bajas',                  [CsbpController::class, 'crearBaja']);
    Route::get('/csbp/aportes',                 [CsbpController::class, 'aportes']);
    Route::post('/csbp/aportes',                [CsbpController::class, 'crearAporte']);
    Route::get('/csbp/altas/contrato/{id}',     [CsbpController::class, 'altasPorContrato'])->where('id', '[0-9]+');
    Route::get('/csbp/bajas/contrato/{id}',     [CsbpController::class, 'bajasPorContrato'])->where('id', '[0-9]+');
    Route::get('/csbp/aportes/resumen',         [CsbpController::class, 'resumenAnual']);
    Route::get('/csbp/aportes/{id}',            [CsbpController::class, 'aporteDetalle'])->where('id', '[0-9]+');

    // Gestoras AFP
    Route::get('/gestoras',                     [GestoraController::class, 'listar']);
    Route::post('/gestoras',                    [GestoraController::class, 'crear']);
    Route::put('/gestoras/{id}',                [GestoraController::class, 'actualizar'])->where('id', '[0-9]+');
    Route::get('/gestoras/contrato/{id}',       [GestoraController::class, 'porContrato'])->where('id', '[0-9]+');
    Route::get('/gestoras/contribuciones',      [GestoraController::class, 'contribuciones']);
    Route::post('/gestoras/contribuciones',     [GestoraController::class, 'crearContribucion']);
    Route::get('/gestoras/contribuciones/resumen', [GestoraController::class, 'resumen']);
    Route::get('/gestoras/contribuciones/{id}', [GestoraController::class, 'contribucionDetalle'])->where('id', '[0-9]+');
    Route::get('/gestoras/sin-gestora',         [GestoraController::class, 'sinGestora']);

    // Finiquitos
    Route::get('/finiquitos',                   [FiniquitoController::class, 'listar']);
    Route::get('/finiquitos/calcular',          [FiniquitoController::class, 'calcular']);
    Route::post('/finiquitos/guardar',          [FiniquitoController::class, 'guardar']);
    Route::get('/finiquitos/{id}',              [FiniquitoController::class, 'detalle'])->where('id', '[0-9]+');

    // Planilla Tributaria (RC-IVA)
    Route::get('/tributaria',                   [PlanillaTributariaController::class, 'listar']);
    Route::get('/tributaria/calcular',          [PlanillaTributariaController::class, 'calcular']);
    Route::get('/tributaria/empleado/{id}',     [PlanillaTributariaController::class, 'detalle'])->where('id', '[0-9]+');
    Route::get('/tributaria/empleado/{id}/anual', [PlanillaTributariaController::class, 'resumenAnual'])->where('id', '[0-9]+');

    // Horarios
    Route::get('/horarios',                     [HorarioController::class, 'listar']);
    Route::post('/horarios',                    [HorarioController::class, 'crear']);
    Route::put('/horarios/{id}',                [HorarioController::class, 'actualizar'])->where('id', '[0-9]+');
    Route::delete('/horarios/{id}',             [HorarioController::class, 'desactivar'])->where('id', '[0-9]+');
    Route::post('/horarios/asignar',            [HorarioController::class, 'asignar']);
    Route::get('/horarios/asignaciones',        [HorarioController::class, 'asignaciones']);
    Route::get('/horarios/sin-horario',         [HorarioController::class, 'sinHorario']);
    Route::get('/horarios/nombre/{nombre}',     [HorarioController::class, 'porNombre']);
    Route::get('/horarios/contrato/{id}',       [HorarioController::class, 'porContrato'])->where('id', '[0-9]+');
    Route::get('/horarios/contrato/{id}/resumen', [HorarioController::class, 'resumenSemanal'])->where('id', '[0-9]+');
});
