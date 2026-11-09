<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class MockDataService
{
    private static ?bool $tablesExist = null;

    public static function tablesExist(): bool
    {
        if (self::$tablesExist !== null) return self::$tablesExist;

        try {
            RawConnection::select("SELECT 1 FROM rrhh_parametro_gestion LIMIT 1");
            self::$tablesExist = true;
        } catch (\Exception $e) {
            self::$tablesExist = false;
        }

        return self::$tablesExist;
    }

    public static function parametros(int $gestion): array
    {
        return [
            ['id' => 1, 'gestion' => $gestion, 'codigo' => 'SMN', 'valor' => 3300.00, 'descripcion' => 'Salario Mínimo Nacional (Bs)', 'norma_referencia' => 'DS 5516 / RM 088/26', 'vigente_desde' => "{$gestion}-05-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'gestion' => $gestion, 'codigo' => 'APORTE_LAB_AFP', 'valor' => 1.71, 'descripcion' => '% Aporte laboral AFP', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'gestion' => $gestion, 'codigo' => 'APORTE_LAB_RIESGO_COMUN', 'valor' => 1.71, 'descripcion' => '% Riesgo Común', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'gestion' => $gestion, 'codigo' => 'APORTE_LAB_COMISION', 'valor' => 0.50, 'descripcion' => '% Comisión AFP', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'gestion' => $gestion, 'codigo' => 'APORTE_LAB_SOLIDARIO', 'valor' => 0.50, 'descripcion' => '% Aporte Solidario del Asegurado', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'gestion' => $gestion, 'codigo' => 'APORTE_LAB_NACIONAL_SOL', 'valor' => 1.00, 'descripcion' => '% Aporte Nacional Solidario (>Bs13000)', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'gestion' => $gestion, 'codigo' => 'APORTE_LAB_NAC_SOL2', 'valor' => 5.00, 'descripcion' => '% Aporte Nacional Solidario (>Bs25000)', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8, 'gestion' => $gestion, 'codigo' => 'APORTE_LAB_NAC_SOL3', 'valor' => 10.00, 'descripcion' => '% Aporte Nacional Solidario (>Bs35000)', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'gestion' => $gestion, 'codigo' => 'APORTE_PAT_CNS', 'valor' => 10.00, 'descripcion' => '% Aporte patronal CNS', 'norma_referencia' => 'Ley 924', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 10, 'gestion' => $gestion, 'codigo' => 'APORTE_PAT_RIESGO_PROF', 'valor' => 1.71, 'descripcion' => '% Riesgo Profesional', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'gestion' => $gestion, 'codigo' => 'APORTE_PAT_PROVIVIENDA', 'valor' => 2.00, 'descripcion' => '% Pro-vivienda', 'norma_referencia' => 'DS 21637', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'gestion' => $gestion, 'codigo' => 'APORTE_PAT_SOLIDARIO', 'valor' => 3.00, 'descripcion' => '% Aporte patronal solidario', 'norma_referencia' => 'Ley 065', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 13, 'gestion' => $gestion, 'codigo' => 'RCIVA_ALICUOTA', 'valor' => 13.00, 'descripcion' => '% Alícuota RC-IVA', 'norma_referencia' => 'Ley 843', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 14, 'gestion' => $gestion, 'codigo' => 'RCIVA_MIN_EXENTO_SMN', 'valor' => 4.00, 'descripcion' => 'Múltiplo de SMN exento de RC-IVA', 'norma_referencia' => 'Ley 843', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 15, 'gestion' => $gestion, 'codigo' => 'DOBLE_AGUINALDO', 'valor' => 0.00, 'descripcion' => '1=Aplica doble aguinaldo, 0=No', 'norma_referencia' => 'DS (anual)', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 16, 'gestion' => $gestion, 'codigo' => 'BONO_ANT_BASE_SMN', 'valor' => 3.00, 'descripcion' => 'Nro SMN base bono antigüedad (no productiva)', 'norma_referencia' => 'DS 21060', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 17, 'gestion' => $gestion, 'codigo' => 'BONO_ANT_BASE_SMN_PROD', 'valor' => 1.00, 'descripcion' => 'Nro SMN base bono antigüedad (productiva)', 'norma_referencia' => 'DS 21060', 'vigente_desde' => "{$gestion}-01-01", 'vigente_hasta' => null, 'created_at' => now(), 'updated_at' => now()],
        ];
    }

    public static function escalaBono(int $gestion): array
    {
        return [
            ['id' => 1, 'gestion' => $gestion, 'anios_desde' => 2, 'anios_hasta' => 4, 'porcentaje' => 5.00],
            ['id' => 2, 'gestion' => $gestion, 'anios_desde' => 5, 'anios_hasta' => 7, 'porcentaje' => 11.00],
            ['id' => 3, 'gestion' => $gestion, 'anios_desde' => 8, 'anios_hasta' => 10, 'porcentaje' => 18.00],
            ['id' => 4, 'gestion' => $gestion, 'anios_desde' => 11, 'anios_hasta' => 14, 'porcentaje' => 26.00],
            ['id' => 5, 'gestion' => $gestion, 'anios_desde' => 15, 'anios_hasta' => 19, 'porcentaje' => 34.00],
            ['id' => 6, 'gestion' => $gestion, 'anios_desde' => 20, 'anios_hasta' => 24, 'porcentaje' => 42.00],
            ['id' => 7, 'gestion' => $gestion, 'anios_desde' => 25, 'anios_hasta' => null, 'porcentaje' => 50.00],
        ];
    }

    public static function escalaVacaciones(int $gestion): array
    {
        return [
            ['id' => 1, 'gestion' => $gestion, 'anios_desde' => 1, 'anios_hasta' => 4, 'dias_habiles' => 15],
            ['id' => 2, 'gestion' => $gestion, 'anios_desde' => 5, 'anios_hasta' => 9, 'dias_habiles' => 20],
            ['id' => 3, 'gestion' => $gestion, 'anios_desde' => 10, 'anios_hasta' => null, 'dias_habiles' => 30],
        ];
    }

    public static function feriados(int $gestion): array
    {
        return [
            ['id' => 1, 'gestion' => $gestion, 'fecha' => "{$gestion}-01-01", 'nombre' => 'Año Nuevo', 'tipo' => 'NACIONAL', 'departamento' => null],
            ['id' => 2, 'gestion' => $gestion, 'fecha' => "{$gestion}-01-22", 'nombre' => 'Día del Estado Plurinacional', 'tipo' => 'NACIONAL', 'departamento' => null],
            ['id' => 3, 'gestion' => $gestion, 'fecha' => "{$gestion}-05-01", 'nombre' => 'Día del Trabajo', 'tipo' => 'NACIONAL', 'departamento' => null],
            ['id' => 4, 'gestion' => $gestion, 'fecha' => "{$gestion}-06-21", 'nombre' => 'Año Nuevo Aymara', 'tipo' => 'NACIONAL', 'departamento' => null],
            ['id' => 5, 'gestion' => $gestion, 'fecha' => "{$gestion}-08-06", 'nombre' => 'Día de la Independencia', 'tipo' => 'NACIONAL', 'departamento' => null],
            ['id' => 6, 'gestion' => $gestion, 'fecha' => "{$gestion}-11-02", 'nombre' => 'Día de Todos los Santos', 'tipo' => 'NACIONAL', 'departamento' => null],
            ['id' => 7, 'gestion' => $gestion, 'fecha' => "{$gestion}-12-25", 'nombre' => 'Navidad', 'tipo' => 'NACIONAL', 'departamento' => null],
        ];
    }

    public static function dashboard(): array
    {
        return [
            'total_activos' => 24,
            'por_tipo_contrato' => [
                ['tipo_contrato' => 'INDEFINIDO', 'total' => 18],
                ['tipo_contrato' => 'PLAZO_FIJO', 'total' => 4],
                ['tipo_contrato' => 'TELETRABAJO', 'total' => 2],
            ],
            'por_area' => [
                ['area' => 'Operaciones', 'total' => 10],
                ['area' => 'Administración', 'total' => 5],
                ['area' => 'Cumplimiento', 'total' => 3],
                ['area' => 'Contabilidad', 'total' => 3],
                ['area' => 'Sistemas', 'total' => 2],
                ['area' => 'Gerencia', 'total' => 1],
            ],
            'contratos_prox_vencer' => [
                ['id' => 1, 'fecha_fin' => date('Y-m-d', strtotime('+12 days')), 'tipo_contrato' => 'PLAZO_FIJO', 'empleado' => 'Mamani Quispe Juan Carlos', 'cargo' => 'Cajero', 'agencia' => 'La Paz Centro', 'dias_restantes' => 12],
                ['id' => 2, 'fecha_fin' => date('Y-m-d', strtotime('+35 days')), 'tipo_contrato' => 'PLAZO_FIJO', 'empleado' => 'Flores Condori María Elena', 'cargo' => 'Asistente Contable', 'agencia' => 'El Alto', 'dias_restantes' => 35],
                ['id' => 3, 'fecha_fin' => date('Y-m-d', strtotime('+52 days')), 'tipo_contrato' => 'PLAZO_FIJO', 'empleado' => 'Choque Huanca Roberto', 'cargo' => 'Operador POS', 'agencia' => 'Cochabamba', 'dias_restantes' => 52],
            ],
            'vacaciones_pendientes' => [
                ['id' => 1, 'tipo' => 'VACACION', 'fecha_inicio' => date('Y-m-d', strtotime('+5 days')), 'fecha_fin' => date('Y-m-d', strtotime('+15 days')), 'dias' => 10, 'estado' => 'SOLICITADA', 'empleado' => 'Torrez Vargas Ana Lucía'],
                ['id' => 2, 'tipo' => 'VACACION', 'fecha_inicio' => date('Y-m-d', strtotime('+10 days')), 'fecha_fin' => date('Y-m-d', strtotime('+17 days')), 'dias' => 5, 'estado' => 'SOLICITADA', 'empleado' => 'Ríos Alanoca Pedro'],
            ],
            'capacitaciones_obligatorias_pendientes' => 2,
        ];
    }

    public static function empleados(): array
    {
        $base = [
            ['id' => 1, 'ci' => '4523189', 'ci_expedido' => 'LP', 'nombres' => 'Carlos Alberto', 'apellido_paterno' => 'Gutiérrez', 'apellido_materno' => 'Fernández', 'genero' => 'M', 'estado' => 'A', 'tipo_contrato' => 'INDEFINIDO', 'cargo' => 'Gerente General', 'area' => 'Gerencia', 'nivel' => 'Gerencia', 'agencia' => 'La Paz Centro', 'sueldo_base' => 18000, 'fecha_inicio' => '2015-03-01'],
            ['id' => 2, 'ci' => '6781234', 'ci_expedido' => 'LP', 'nombres' => 'María Elena', 'apellido_paterno' => 'Flores', 'apellido_materno' => 'Condori', 'genero' => 'F', 'estado' => 'A', 'tipo_contrato' => 'PLAZO_FIJO', 'cargo' => 'Asistente Contable', 'area' => 'Contabilidad', 'nivel' => 'Operativo', 'agencia' => 'El Alto', 'sueldo_base' => 4500, 'fecha_inicio' => '2024-06-01'],
            ['id' => 3, 'ci' => '8912345', 'ci_expedido' => 'CB', 'nombres' => 'Juan Carlos', 'apellido_paterno' => 'Mamani', 'apellido_materno' => 'Quispe', 'genero' => 'M', 'estado' => 'A', 'tipo_contrato' => 'PLAZO_FIJO', 'cargo' => 'Cajero', 'area' => 'Operaciones', 'nivel' => 'Operativo', 'agencia' => 'La Paz Centro', 'sueldo_base' => 3800, 'fecha_inicio' => '2025-01-15'],
            ['id' => 4, 'ci' => '3456789', 'ci_expedido' => 'LP', 'nombres' => 'Ana Lucía', 'apellido_paterno' => 'Torrez', 'apellido_materno' => 'Vargas', 'genero' => 'F', 'estado' => 'A', 'tipo_contrato' => 'INDEFINIDO', 'cargo' => 'Oficial de Cumplimiento', 'area' => 'Cumplimiento', 'nivel' => 'Jefatura', 'agencia' => 'La Paz Centro', 'sueldo_base' => 12000, 'fecha_inicio' => '2018-08-01'],
            ['id' => 5, 'ci' => '7654321', 'ci_expedido' => 'SC', 'nombres' => 'Pedro', 'apellido_paterno' => 'Ríos', 'apellido_materno' => 'Alanoca', 'genero' => 'M', 'estado' => 'A', 'tipo_contrato' => 'INDEFINIDO', 'cargo' => 'Jefe de Sistemas', 'area' => 'Sistemas', 'nivel' => 'Jefatura', 'agencia' => 'La Paz Centro', 'sueldo_base' => 10500, 'fecha_inicio' => '2019-02-15'],
            ['id' => 6, 'ci' => '2345678', 'ci_expedido' => 'LP', 'nombres' => 'Roberto', 'apellido_paterno' => 'Choque', 'apellido_materno' => 'Huanca', 'genero' => 'M', 'estado' => 'A', 'tipo_contrato' => 'PLAZO_FIJO', 'cargo' => 'Operador POS', 'area' => 'Operaciones', 'nivel' => 'Operativo', 'agencia' => 'Cochabamba', 'sueldo_base' => 3500, 'fecha_inicio' => '2025-07-01'],
            ['id' => 7, 'ci' => '5678901', 'ci_expedido' => 'LP', 'nombres' => 'Carla Paola', 'apellido_paterno' => 'Espinoza', 'apellido_materno' => 'Mendoza', 'genero' => 'F', 'estado' => 'A', 'tipo_contrato' => 'INDEFINIDO', 'cargo' => 'Contadora General', 'area' => 'Contabilidad', 'nivel' => 'Jefatura', 'agencia' => 'La Paz Centro', 'sueldo_base' => 11000, 'fecha_inicio' => '2017-04-01'],
            ['id' => 8, 'ci' => '9012345', 'ci_expedido' => 'OR', 'nombres' => 'Luis Fernando', 'apellido_paterno' => 'Vargas', 'apellido_materno' => 'Pinto', 'genero' => 'M', 'estado' => 'A', 'tipo_contrato' => 'TELETRABAJO', 'cargo' => 'Desarrollador', 'area' => 'Sistemas', 'nivel' => 'Operativo', 'agencia' => 'La Paz Centro', 'sueldo_base' => 8500, 'fecha_inicio' => '2022-01-10'],
        ];

        return array_map(function ($e) {
            return array_merge($e, [
                'cod_trab' => null, 'fecha_nacimiento' => null, 'estado_civil' => null,
                'telefono' => null, 'email_personal' => null, 'nua_cua' => null,
                'nro_asegurado_cns' => null, 'foto_url' => null,
                'id_contrato' => $e['id'], 'fecha_fin' => null, 'estado_contrato' => 'VIGENTE',
            ]);
        }, $base);
    }

    public static function capacitaciones(): array
    {
        return [
            ['id' => 1, 'titulo' => 'Prevención de Legitimación de Ganancias Ilícitas', 'tipo' => 'PLA_FT', 'fecha_inicio' => '2026-09-15', 'fecha_fin' => '2026-09-16', 'horas_duracion' => 16, 'es_obligatoria' => 1, 'estado' => 'PROGRAMADA', 'inscritos' => 24, 'aprobados' => 0],
            ['id' => 2, 'titulo' => 'Atención al Consumidor Financiero', 'tipo' => 'ATENCION_CONSUMIDOR', 'fecha_inicio' => '2026-07-10', 'fecha_fin' => '2026-07-10', 'horas_duracion' => 8, 'es_obligatoria' => 1, 'estado' => 'COMPLETADA', 'inscritos' => 20, 'aprobados' => 18],
            ['id' => 3, 'titulo' => 'Seguridad de la Información y Ciberseguridad', 'tipo' => 'SEGURIDAD', 'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-06', 'horas_duracion' => 12, 'es_obligatoria' => 0, 'estado' => 'PROGRAMADA', 'inscritos' => 8, 'aprobados' => 0],
            ['id' => 4, 'titulo' => 'Normativa ASFI para Casas de Cambio', 'tipo' => 'REGULATORIA', 'fecha_inicio' => '2026-05-20', 'fecha_fin' => '2026-05-21', 'horas_duracion' => 16, 'es_obligatoria' => 0, 'estado' => 'COMPLETADA', 'inscritos' => 15, 'aprobados' => 14],
            ['id' => 5, 'titulo' => 'Primeros Auxilios y Evacuación', 'tipo' => 'SEGURIDAD', 'fecha_inicio' => '2026-11-12', 'fecha_fin' => null, 'horas_duracion' => 4, 'es_obligatoria' => 0, 'estado' => 'PROGRAMADA', 'inscritos' => 0, 'aprobados' => 0],
        ];
    }

    public static function liquidaciones(): array
    {
        return [
            ['id' => 1, 'id_empleado' => 10, 'empleado' => 'Gonzales Pérez Mario', 'ci' => '4567890', 'cargo' => 'Cajero', 'fecha_desvinculacion' => '2026-06-30', 'motivo' => 'RENUNCIA', 'anios_trabajados' => 2.5, 'sueldo_promedio' => 3800, 'indemnizacion' => 9500, 'desahucio' => 0, 'aguinaldo_proporcional' => 1900, 'vacaciones_pendientes' => 8, 'monto_vacaciones' => 1013.33, 'doble_aguinaldo_prop' => 0, 'total_liquidacion' => 12413.33, 'total_descuentos' => 0, 'liquido_final' => 12413.33, 'estado' => 'PAGADA'],
            ['id' => 2, 'id_empleado' => 11, 'empleado' => 'Apaza Condori Silvia', 'ci' => '5678901', 'cargo' => 'Asistente Administrativo', 'fecha_desvinculacion' => '2026-08-15', 'motivo' => 'DESPIDO_INJUSTIFICADO', 'anios_trabajados' => 4.2, 'sueldo_promedio' => 4200, 'indemnizacion' => 17640, 'desahucio' => 12600, 'aguinaldo_proporcional' => 2800, 'vacaciones_pendientes' => 12, 'monto_vacaciones' => 1680, 'doble_aguinaldo_prop' => 0, 'total_liquidacion' => 34720, 'total_descuentos' => 0, 'liquido_final' => 34720, 'estado' => 'PENDIENTE'],
        ];
    }

    public static function gestiones(): array
    {
        return [
            ['gestion' => 2026],
            ['gestion' => 2025],
        ];
    }

    public static function planillas(): array
    {
        return [];
    }
}
