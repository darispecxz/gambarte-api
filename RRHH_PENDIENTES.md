# Módulo RRHH — Pendientes e integración

## Estado actual

El módulo fue creado con la estructura completa (backend + frontend + migración SQL) pero opera **solo en modo lectura** contra la base de datos. No se crearon, insertaron ni modificaron tablas en la DB real.

---

## 1. Migración SQL pendiente de ejecución

**Archivo:** `database/migrations/002_cgr_rrhh.sql`

Contiene 14 tablas nuevas que deben crearse en la base `cgr`:

| Tabla | Propósito |
|---|---|
| `rrhh_parametro_gestion` | Parámetros normativos versionados por año (SMN, % aportes, etc.) |
| `rrhh_escala_bono_antiguedad` | Escala del DS 21060 por gestión |
| `rrhh_escala_vacaciones` | Escala de vacaciones por antigüedad |
| `rrhh_empleado` | Legajo digital del empleado |
| `rrhh_cargo` | Catálogo de cargos |
| `rrhh_contrato` | Contratos laborales |
| `rrhh_planilla` | Cabecera de planilla mensual |
| `rrhh_planilla_detalle` | Detalle por empleado por mes |
| `rrhh_liquidacion` | Finiquitos / liquidaciones |
| `rrhh_asistencia` | Marcaciones diarias |
| `rrhh_vacacion` | Solicitudes de vacación y licencias |
| `rrhh_capacitacion` | Programas de capacitación |
| `rrhh_capacitacion_empleado` | Inscripciones de empleados |
| `rrhh_feriado` | Calendario de feriados por gestión |
| `rrhh_evaluacion` | Evaluaciones de desempeño |

**Acción requerida:** Ejecutar el SQL en la base de datos `cgr` (idealmente en un entorno de staging primero).

---

## 2. Datos que necesitan carga inicial (MOCK)

### 2.1 Empleados (`rrhh_empleado`)
Los datos de empleados actualmente están solo en la tabla `trabajador` (nombre básico). Se necesita:
- Migrar datos de `trabajador` → `rrhh_empleado` (CI, datos completos)
- O crear una carga inicial con los datos reales del personal

### 2.2 Cargos (`rrhh_cargo`)
Crear el catálogo de cargos/puestos de la empresa con sus áreas y niveles jerárquicos.

### 2.3 Contratos (`rrhh_contrato`)
Registrar los contratos vigentes de cada empleado (tipo, fecha inicio, sueldo base, cargo asignado, agencia).

### 2.4 Asistencia (`rrhh_asistencia`)
Integrar con el sistema de marcaciones existente (si existe) o implementar registro manual.

### 2.5 Feriados departamentales
La migración incluye feriados nacionales de 2026 pero faltan:
- Feriados departamentales (varía por sede de cada agencia)
- Feriados de gestiones anteriores (si se necesitan cálculos retroactivos)

---

## 3. Endpoints que faltan (escritura)

El módulo actual es **solo lectura** (GET). Para ser funcional necesita endpoints POST/PUT/DELETE:

### Prioridad alta
- `POST /rrhh/empleados` — Crear empleado
- `PUT /rrhh/empleados/{id}` — Actualizar empleado
- `POST /rrhh/contratos` — Crear contrato
- `PUT /rrhh/contratos/{id}` — Actualizar/finalizar contrato
- `POST /rrhh/planillas` — Generar planilla definitiva (actualmente solo preview)
- `POST /rrhh/liquidaciones` — Crear liquidación (actualmente solo simulación)
- `PUT /rrhh/liquidaciones/{id}/aprobar` — Aprobar liquidación

### Prioridad media
- `POST /rrhh/asistencia` — Registrar marcación
- `POST /rrhh/vacaciones` — Solicitar vacación/licencia
- `PUT /rrhh/vacaciones/{id}/aprobar` — Aprobar/rechazar
- `POST /rrhh/capacitaciones` — Crear programa de capacitación
- `POST /rrhh/capacitaciones/{id}/inscribir` — Inscribir empleados
- `PUT /rrhh/parametros/{id}` — Actualizar parámetro normativo

### Prioridad baja
- `POST /rrhh/evaluaciones` — Crear evaluación de desempeño
- CRUD de cargos, feriados

---

## 4. Motor de cálculo — Validaciones pendientes

### Planilla (`PlanillaService`)
- [ ] Validar que el sueldo no sea menor al SMN vigente
- [ ] Implementar cálculo de aguinaldo (diciembre o proporcional)
- [ ] Implementar provisión mensual de aguinaldo e indemnización (1/12 del sueldo mensual)
- [ ] Doble aguinaldo: el parámetro existe pero la lógica solo está en liquidaciones
- [ ] RC-IVA: validar manejo de facturas presentadas (crédito fiscal del empleado)
- [ ] Aporte Nacional Solidario: verificar tramos exactos con normativa vigente
- [ ] Generar boletas de pago individuales (PDF)

### Liquidación (`LiquidacionService`)
- [ ] Validar que la indemnización use el promedio correcto (últimos 3 meses o sueldo indemnizable según jurisprudencia)
- [ ] Manejar caso de empleados con menos de 90 días (sin derecho a indemnización)
- [ ] Incluir multa del 30% por pago tardío de finiquito (Art. 9 DS 28699)
- [ ] Generar formulario de finiquito en formato del Ministerio de Trabajo

---

## 5. Integraciones con otros módulos

### 5.1 Contabilidad
- [ ] Asiento contable automático al aprobar planilla:
  - Débito: Sueldos y Salarios, Cargas Sociales
  - Crédito: AFP por pagar, CNS por pagar, RC-IVA por pagar, Sueldos por pagar
- [ ] Provisiones mensuales de aguinaldo (1/12) e indemnización

### 5.2 Cumplimiento / PLA-FT
- [ ] Vincular capacitaciones obligatorias de PLA/FT con el módulo de cumplimiento
- [ ] Reportar personal capacitado en PLA/FT para informes a la UIF

### 5.3 Gobierno Corporativo / Organigrama
- [ ] Alimentar el organigrama existente desde los cargos y la estructura de RRHH
- [ ] Segregación de funciones: verificar que roles/cargos no violen matrices definidas en gobierno corporativo

### 5.4 Auditoría
- [ ] Registrar en el log de auditoría cada acción sobre planillas y liquidaciones
- [ ] Trazabilidad de cambios de sueldo y cargo

---

## 6. Reportes regulatorios pendientes

- [ ] Planilla tributaria para el SIN (formato específico del Servicio de Impuestos Nacionales)
- [ ] Formularios AFP: declaración mensual de aportes a la Gestora Pública
- [ ] Formulario CNS: declaración de aportes a la Caja Nacional de Salud
- [ ] Formulario de finiquito (formato Ministerio de Trabajo)
- [ ] Certificado de trabajo
- [ ] Reporte de personal para ASFI (si aplica a Gambarte como entidad supervisada)

---

## 7. Funcionalidades de UI pendientes

- [ ] Formularios de creación/edición (actualmente todo es solo visualización)
- [ ] Exportación a Excel de planillas y liquidaciones
- [ ] Impresión de boletas de pago
- [ ] Upload de documentos al legajo del empleado
- [ ] Notificaciones/alertas automáticas (contratos por vencer, capacitaciones pendientes)
- [ ] Gráficas en el dashboard (rotación, distribución salarial, etc.)
- [ ] Filtro por agencia en todas las vistas

---

## 8. Parámetros que deben actualizarse anualmente

Cada año el administrador debe verificar y actualizar en la tabla `rrhh_parametro_gestion`:

| Parámetro | Fuente |
|---|---|
| `SMN` (Salario Mínimo Nacional) | Decreto Supremo anual |
| `DOBLE_AGUINALDO` | Decreto anual según crecimiento PIB |
| `APORTE_LAB_*` y `APORTE_PAT_*` | Ley 065 y resoluciones de la Gestora Pública |
| `RCIVA_*` | Ley 843 y resoluciones del SIN |
| `BONO_ANT_BASE_SMN*` | DS 21060 (generalmente estable) |

También actualizar las escalas de bono de antigüedad y vacaciones (copiando de la gestión anterior si no hay cambios).

---

## 9. Seguridad y permisos

- [ ] Crear rol `RRHH_ADMIN` con acceso completo al módulo
- [ ] Crear rol `RRHH_CONSULTA` con acceso solo lectura
- [ ] Restringir acceso a información salarial (solo RRHH y gerencia)
- [ ] Los empleados regulares no deben ver planillas de otros empleados
- [ ] Auditar todo acceso a datos sensibles (salarios, liquidaciones)
