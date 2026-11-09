# Análisis de los reportes existentes (base de la API)

> Objetivo: documentar **qué hace cada reporte**, cómo obtiene los datos, qué lógica
> debe conservarse y cómo la API la **reutiliza sin duplicar**. La regla es que, bajo
> los mismos parámetros, la API devuelva **los mismos resultados** que el sistema PHP.

Ubicación del sistema PHP (única fuente de verdad): `C:\wamp64\www\bo_cgr_hostinger\reportes`.

## 1. Riesgos — `reportes/riesgo_cambiario/`

Panel de 6 informes + tablero ejecutivo. Lógica en `lib/` (motores por riesgo + `Panel` + `Dashboard`).

| Aspecto | Detalle |
|---|---|
| Informes | `cambiario, mercado, liquidez, operativo, plaft, seguridad` (`Panel::risks()`) |
| Datos | Motores `Riesgo*`/`Seguridad` (SQL **solo lectura** a `cgr` + `conta`) vía `Panel::generar()` |
| Parámetros | `preset` (mes/dia/rango) + `ini/fin/fecha` (resuelto en `index.php`/`descargar.php`) |
| Reglas de negocio | VaR = posición×volatilidad×2.326; volatilidad = stddev retornos T/C BCB; PLA/FT umbral/fraccionamiento/PEP/listas; SoD; etc. (validadas vs informe real dic-2025) |
| Documentos | **PDF** con mPDF (`descargar.php`) |
| Tablero | `Dashboard::resumenCache()` (semáforos + alertas, con caché) |

**Reutilización en la API** (sin duplicar):
- Datos → `Panel::generar($slug,$ini,$fin)['data']`.
- PDF → **`Panel::pdf($slug,$ini,$fin)`** (refactor controlado: se extrajo la generación
  de PDF de `descargar.php` a este método; `descargar.php` ahora también lo usa — verificado
  que el PDF resultante es idéntico: 116.810 bytes para el informe cambiario dic-2025).
- Tablero → `Dashboard::resumenCache()`.

## 2. Logs / Bitácoras — `reportes/auditoria/`

7 reportes de auditoría (00–07). **Ya separa datos y documentos** (patrón ideal para la API).

| Aspecto | Detalle |
|---|---|
| Reportes | 00 Intentos/Login, 01 Pistas Cajero, 02 Log Admin/Operador, 03 Otros Perfiles, 04 Log Detallado, 05 Pistas Conta, 06 Logs Sesión Conta, 07 Resumen Ejecutivo (`Reportes::catalog()`) |
| Datos | `Reportes::data($code,$periodo)` → `{report,title,db,periodo,label,sheets:[{name,columns,rows}]}` (igual que `datos.php`) |
| Parámetros | `periodo` (AAAA-MM); `reporte` (00–07 o `todos`) |
| Filtros | Búsqueda/orden/filtro por columna son **client-side** en el visor → se conservan en el frontend |
| Documentos | **Excel .xlsx** (`Reportes::build()->toString()`) y **ZIP mensual** (`Zip::build()`), sin PhpSpreadsheet/ZipArchive (compatible PHP 5.6) |

**Reutilización en la API**: `Reportes::data()`, `Reportes::build()`, `Reportes::fileName()`,
`Zip::build()`. El ZIP mantiene el nombre `REPORTES_AUDITORIA_AAAA_MM.zip`.

## 3. Operaciones — `reportes/api_dashboard.php` (+ `dashboard_gerencia.php`)

`dashboard_gerencia.php` es la UI (Chart.js); `api_dashboard.php` es su **backend JSON** ya existente.

| Aspecto | Detalle |
|---|---|
| Vistas (acciones) | `operativo, utilidades, agencias, live, cajeros, precios, cotizaciones, plazas, saldos, horas, margen, tendencias, comparativo` |
| Datos | PDO directo a `cgr` (cotizaciones, cambios, giros, agencias…) |
| Parámetros | `accion`, `desde`, `hasta` |

**Reutilización en la API**: `Backend::operations()` hace **include-and-capture** de
`api_dashboard.php` (fija `$_GET`, captura el JSON) — misma lógica, sin duplicar.

> ⚠️ **Hallazgo de seguridad (pre-existente):** `api_dashboard.php` tiene las credenciales de
> BD **hardcodeadas** (líneas 10-13). No las expone la API (quedan server-side), pero conviene
> moverlas a la configuración del proyecto (`reportes/*/config.php`) en un refactor controlado.

## Mapa reporte → endpoint

| Reporte PHP | Endpoint API |
|---|---|
| `Panel::risks()` | `GET /api/risks` |
| `Panel::generar()['data']` | `GET /api/risks/{slug}` |
| `descargar.php` (PDF) → `Panel::pdf()` | `GET /api/risks/{slug}/pdf` |
| `Dashboard::resumenCache()` | `GET /api/risks/dashboard` |
| `Reportes::catalog()` | `GET /api/logs` |
| `Reportes::data()` (`datos.php`) | `GET /api/logs/{code}` |
| `Reportes::build()` (`descargar.php`) | `GET /api/logs/{code}/excel` |
| `Zip::build()` (`descargar.php?reporte=todos`) | `GET /api/logs/excel/zip` |
| `api_dashboard.php` | `GET /api/operations/{action}` |

## Verificación de equivalencia (realizada)

| Endpoint | Resultado API | Esperado (sistema PHP) |
|---|---|---|
| `/api/risks/cambiario` (dic-2025) | `total_saldos = 539232.10` | 539.232,10 ✓ |
| `/api/risks/cambiario/pdf` | 116.810 bytes, `%PDF-` | idéntico a `descargar.php` ✓ |
| `/api/logs/00/excel` (2026-07) | 4.981 bytes, `PK` (.xlsx) | mismo formato/nombre ✓ |
| `/api/logs/excel/zip` (2026-07) | `REPORTES_AUDITORIA_2026_07.zip` (PK) | mismo nombre/contenido ✓ |
| `/api/operations/operativo` | datos por moneda | igual que `api_dashboard.php` ✓ |
