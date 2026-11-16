# Control de arqueos de caja

## Preparación

Ejecutar `database/migrations/021_control_arqueos_caja.sql` sobre la conexión `cgr` antes de habilitar la sección. El script crea dos tablas nuevas y no modifica las tablas operativas existentes. La conexión configurada en este entorno no es local; la migración no se ejecutó durante el desarrollo.

## API

Todas las rutas requieren JWT y el rol `2` (`ADMINISTRADOR`), que corresponde al acceso exclusivo del área de Sistemas en esta aplicación.

- `GET /api/accounting/cash-audit?fecha=AAAA-MM-DD&agencia=ID`: resumen, cajas, estados, seguimiento y conciliación contable global (esta última solo sin filtro de agencia).
- `GET /api/accounting/cash-audit/{idCaja}?fecha=AAAA-MM-DD`: acta, denominaciones declaradas, seguimiento, saldos y movimientos.
- `POST /api/accounting/cash-audit/{idCaja}/followups`: cuerpo `{fecha, estado, responsable, fechaCompromiso, observacion}`; `estado` puede ser `en_investigacion`, `accion_pendiente` o `resuelto`. Cada envío crea un registro histórico.
- `GET /api/accounting/cash-audit/pdf?fecha=AAAA-MM-DD&agencia=ID`: descarga directa de `application/pdf` generado en el servidor. El parámetro `agencia` es opcional.

El contado del informe es automático: corresponde al cierre registrado por el sistema. La diferencia se calcula como `saldo teórico - cierre declarado`; no existe un registro manual de conteo en este módulo. Se aplica una tolerancia absoluta de `1,00` por moneda: el intervalo inclusivo de `-1,00` a `+1,00` se considera conciliado y solo valores fuera de ese rango generan faltante o sobrante.

El PDF incluye portada, resultados por moneda, conciliación contable global, hallazgos, actas por caja, denominaciones e historiales. Incluye líneas para firmas. **No aplica firmas electrónicas** ni presenta los nombres capturados como firmas.
