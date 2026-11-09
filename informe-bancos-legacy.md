# Informe técnico — Módulo legacy BANCOS (Gambarte / CGR)

**Alcance:** `C:\wamp64\www\bo_cgr_hostinger\bancos` — reportes y pantallas de consulta.
**Modo:** solo lectura. No se modificó ningún archivo del módulo legacy.
**Fecha de análisis:** sobre el estado actual del árbol de archivos local.

---

## 0. Advertencias previas (discrepancias con el pedido)

Tres archivos mencionados en el pedido no existen o no coinciden:

| Pedido | Realidad |
|---|---|
| `model\revertrasp.php` | **No existe.** La función se llama `revertrasp()` y vive en `model\funciones.php:545-553`. El endpoint es `model\reversiontransf.php`. |
| `view\reportesp\index.php y rsp.php` | Existen, pero `reportesp` es un **Estado de Ganancias y Pérdidas**, no un reporte de saldos. Los saldos/presupuesto están en `reportepres`. |
| `model\transfedata.php` | Existe, pero **no es un modelo de datos**: es un endpoint que genera HTML de tabla (echo directo). |

Otros archivos del pedido que son variantes Excel de un mismo reporte: `rsp.php`, `rgpc.php`, `presanuexce.php`. Los tres son copias del `index.php` hermano con `header('Content-type: application/vnd.ms-excel')`.

**No hay dump de esquema disponible.** Busqué dumps SQL en todo el hosting (`C:\wamp64\www\bo_cgr_hostinger\**\*.sql`): solo hay 4 scripts de `cgr_core/sql` ajenos a bancos. Por lo tanto **no puedo verificar índices, tipos de columna ni claves foráneas**; todo lo que digo sobre columnas proviene de los `SELECT`/`INSERT` y de lo que el código consume. Lo marco explícitamente donde corresponde.

---

## 1. Arquitectura y flujo

### 1.1 Dos bases de datos, dos APIs de conexión distintas

`model\funciones.php:18-26` (`con()`) → BD operativa `u752765750_cgr` con **mysqli procedural**, credenciales hardcodeadas en el código (`funciones.php:19-22`).
`model\funciones.php:29-38` (`conectarcontabili()`) → BD contable `u752765750_conta` con **mysqli OO**, credenciales también hardcodeadas (`funciones.php:30-31`).

Las dos conexiones son **globales por convención implícita**: las funciones hacen `global $con;` o `global $conta;` (`funciones.php:42, 116, 138, 150...`). El llamador debe haber definido `$con` y/o `$conta` en el ámbito global antes de invocar. Si no lo hizo, la función falla en silencio (ver §7.7).

### 1.2 El flujo de una transferencia banco→banco

```
transfytransf/index.php  (alta, agencia origen)
   └─ AJAX → model/guardartrasp.php
        ├─ insert_traspaso(...)            → INSERT traspaso_cuenta_bancaria, estado='P'
        └─ insert_dat_reg_saldo_cb(-monto) → INSERT movimiento_cuenta_bancaria, conciliacion='ST'

recepciontra/index.php   (bandeja de la agencia destino)
   └─ AJAX → model/actualiza.php
        ├─ actuali(id,num,fec)             → UPDATE ... estado='A'
        ├─ insert_asiento(...)             → INSERT co_asientos  (BD contable)
        ├─ insert_dat_reg_saldo_cb(+monto) → INSERT movimiento_cuenta_bancaria, 'ET'
        ├─ insert_partidao(...)            → INSERT co_partidas (haber, origen)
        └─ insert_partidad(...)            → INSERT co_partidas (debe, destino)

transfytransf/index.php  (lista de pendientes propios de la agencia origen)
   └─ AJAX → model/reversiontransf.php → revertrasp(id) → estado='R' + saldo 'RT'
```

### 1.3 Observación estructural crítica

**Ningún archivo de `model/` llama a `session_start()` ni valida sesión, rol o agencia.** Verificado con grep sobre todo `model/*.php`: no hay una sola aparición de `session_start` ni de `$_SESSION`. Los únicos archivos que abren sesión son las vistas (`view/*/index.php:2`), y **ninguna vista hace control de permisos**: `$_SESSION['rol']` se usa solo para imprimirlo en el encabezado (p. ej. `reportetransfe/index.php:78`, `reportesp/index.php:155`, `reportegpc/index.php:145`, `reportepres/index.php:307`). El control de menú vive en el `menu.php` global (p. ej. `menu.php:60,67,74`), es decir, **solo controla qué enlaces se dibujan, no qué endpoints se pueden invocar**.

Consecuencia: `model/reversiontransf.php`, `model/actualiza.php`, `model/guardaringrexte.php`, `model/guardartrasp.php`, `model/transfedata.php` son **endpoints HTTP invocables sin autenticación**. En los que dependen de `$_SESSION` (p. ej. `reportetransfe/index.php:6` → `trans_agencias($_SESSION['id_agencia'])`) la ausencia de sesión produce una consulta SQL malformada, no un rechazo limpio.

### 1.4 Los reportes analizados no están enlazados en el menú

Grep sobre todo el hosting: las cadenas `reportetransfe`, `Busquedatransf`, `reportesp`, `reportepres`, `reportegpc` **no aparecen en ningún menú ni en ningún `incluye(...)`**. Son pantallas huérfanas, accesibles solo por URL directa. `ingresoExt` sí está (aunque en el bloque `/* ... */` comentado en `menu.php:81-90`; aparece activo solo por otras rutas del menú).

---

## 2. Qué muestra cada reporte

### 2.1 `view/reportetransfe/index.php` — Reporte de Transferencias Bancarias

- **Disparo:** se renderiza completo al abrir la página. No hay formulario de filtros.
- **Datos:** `trans_agencias($_SESSION['id_agencia'])` (`index.php:6`).
- **Título en pantalla:** `TRANSFERENCIA DE BANCO A BANCO` (`index.php:93`).
- **Columnas** (`index.php:103-112`): `AGE. ORI`, `ENT. ORI`, `NUM. CUENTA`, `AGE. DESTINO`, `ENT. DESTINO`, `NUM. CUENTA`, `MONTO`, `MONEDA`, `FECHA`, `ESTADO`.
- **Filas** (`index.php:118-134`): recorre el resultset y pinta `age_ori, entori, num_cuenta_origen, age_des, entdes, num_cuenta_destino, monto, abrev, fecha, estado`.
- **SQL** (`funciones.php:520-526`):
  `SELECT ... FROM traspaso_cuenta_bancaria WHERE agencia_destino=$age`
- **Negocio:** es la vista de **transferencias recibidas por la agencia de la sesión**. Muestra ambos extremos (origen y destino) para poder conciliar. No es un reporte filtrable: es un listado vivo.
- **Notas de implementación:**
  - **Sin filtro de fechas, sin filtro de estado y sin `LIMIT`**: devuelve todo el histórico de la agencia en cada carga (`funciones.php:522`).
  - **Sin `ORDER BY`**: el orden es el que devuelva el motor, no cronológico.
  - El `ESTADO` se imprime como **letra cruda** (`index.php:129` → `<?=$dat['estado'];?>`), es decir el usuario ve `P`/`A`/`R`, no una etiqueta.
  - La moneda se deduce con un subconsulta correlacionada que exige `cb.id_agencia=agencia_destino` (`funciones.php:522`, campo `abrev`); si no encuentra la cuenta en esa agencia, la celda `MONEDA` sale vacía.
  - Tiene 7 subconsultas correlacionadas por fila sobre `agencia`/`entidad_financiera`/`cuenta_bancaria` (`funciones.php:522`).
  - La tabla lleva `class="display dataTable no-footer"` e `id="reporte"` (`index.php:97`) pero **no hay inicialización DataTables**. `recepciontra/index.php:53` sí inicializa `$('#reporte').DataTable(...)`, pero `acciones.js` y `js/funciones.js` no lo hacen para esta vista. Resultado: se renderizan **todas** las filas de golpe, sin paginación ni buscador.
  - Si `$_SESSION['id_agencia']` es `0` (usuario central), la condición `agencia_destino=0` no devuelve nada útil.
  - El formulario (`index.php:96`) tiene `action="../../model/guardartrasp.php"` pero no contiene ningún campo de la transferencia: es un `<form>` residual que solo envuelve la tabla.

### 2.2 `view/Busquedatransf/index.php` — Búsqueda de transferencias

- **Filtros en pantalla** (`index.php:61-100`): `AGENCIA` (combo, default `TODOS` = `-1`), `ENTIDAD` (combo `TODOS` = `-1`), `MONEDA` (combo `TODOS` = `-1`), `DESDE` (date, default hoy), `HASTA` (date, default hoy).
- **Catálogos:** `obtener_agencia()` (`funciones.php:65-71`, todas las agencias activas), `buscar_entidad_act()` (`funciones.php:260-265`, entidades activas excluyendo `id_tipo_entidad_financiera=8`), `listarmoneda()` (`funciones.php:268-273`, solo monedas activas `id_moneda IN (1,2)` = USD y BOB).
- **Acciones:** botón `GENERAR` (`index.php:66`) y botón `IMPRIMIR` (`index.php:100`).
- **`GENERAR`** está cableado en `js/acciones.js:181-190`: toma `agencia, entidad, moneda, desde, hasta` y hace `POST` a `/bancos/model/transfedata.php`, pintando el resultado en el `div#dat` (`index.php:110`).
- **`IMPRIMIR` no tiene handler** en `js/acciones.js` ni en `js/funciones.js` locales. Esta vista no carga `impresion.js` (que solo engancha `.btn-print`, ver `js/impresion.js`), así que **el botón no hace nada** salvo que exista un handler en el `funciones.js` remoto compartido, que no está en este checkout (`https://cgr.owl-tech.net/js/funciones.js`). **No verificable aquí.**

### 2.3 `model/transfedata.php` — motor del buscador de transferencias

- Construye el `WHERE` concatenando strings según los tres combos (`transfedata.php:10-43`) y llama a `list_transf_des_has()` o `list_transf_dat()` (`funciones.php:300-313`).
- **Tabla que devuelve** (`transfedata.php:44-73`), con encabezados: `AI`, `AGENCIA O`, `ENTIDAD ORIGEN`, `AGENCIA D`, `ENTIDAD DESTINO`, `MONTO`, `FECHA`, `COD TRANSACCION`, `COM`.
  - `ENTIDAD ORIGEN` concatena `entori // num_cuenta_origen // abrevo` (`transfedata.php:63`).
  - `ENTIDAD DESTINO` concatena `entdes // num_cuenta_destino // abrevd` (`transfedata.php:65`).
  - `COD TRANSACCION` concatena `doc_ori - doc_des` (`transfedata.php:68`).
  - `COM` es un icono decorativo sin datos (`transfedata.php:69`).
- **Columnas del SQL** (`funciones.php:302` / `funciones.php:310`): `id`, `age_ori`, `entori`, `num_cuenta_origen`, `age_des`, `entdes`, `num_cuenta_destino`, `monto`, `abrevd`, `abrevo`, `fecha`, `doc_ori`, `doc_des`.
- **Columnas que NO expone:** el **estado** (`estado` no está en el `SELECT`) y el **usuario** que registró. Es decir: el buscador **no permite saber si una transferencia está pendiente, aceptada o revertida**, ni filtrar por ello. En `transfedata.php:44-73` la tabla solo muestra los dos números de documento, y `doc_des` es `NULL` mientras está pendiente (`funciones.php:116`, el `INSERT` pone `documento_destino` en `NULL`).
- **Sesgo de filtro importante:** el filtro de agencia aplica sobre **`agencia_origen`** (`transfedata.php:14, 25, 34`), no sobre destino. La etiqueta en pantalla dice solo `AGENCIA`. El reporte es de **transferencias emitidas**, no recibidas. Nótese que el reporte de §2.1 usa el criterio opuesto (`agencia_destino`), así que ambos reportes no son comparables entre sí.
- **`list_transf_des_has`** (`funciones.php:300-305`) se usa cuando los tres combos están en `TODOS`; ignora por completo agencia/entidad/moneda y filtra solo `DATE(fec_crea...) BETWEEN '$des' AND '$has'`.
- **Fraude funcional en la rama de moneda:** en la rama `age==-1 && ent==-1 && mon!=-1` se hace `$sentencia .= " num_cuenta_origen IN (...)"` sobre una variable **no inicializada** (`transfedata.php:32`). Funciona por accidente (PHP concatena sobre `null`) pero genera un `WHERE` sin primer término booleano: `WHERE num_cuenta_origen IN (...) AND DATE(...)`. Igual patrón en `transfedata.php:23`. No es un fallo visible hoy, pero es una bomba de tiempo si alguien reordena las ramas.

### 2.4 `view/reportesp/index.php` y `rsp.php` — Estado de Ganancias y Pérdidas comparativo

- **Título hardcodeado** (`index.php:170-172`): `ESTADO DE GANANCIAS Y PERDIDAS COMPARATIVO 2020-2019 / AL 31 DE DICIEMBRE DE 2020 / (EXPRESADO EN BOLIVIANOS)`.
- **Fuente:** `obtener_epigra_mon($cant)` en dos granularidades — `$cant=2` (`index.php:6`) y `$cant=1` (`index.php:7`).
- **SQL** (`funciones.php:529-534`):
  ```sql
  SELECT SUM(suma) AS total, csc, ejercicio FROM (
      SELECT SUM(p.debe-p.haber) AS suma, a.codejercicio AS ejercicio,
             p.codsubcuenta AS subcuenta, SUBSTRING(p.codsubcuenta,1,$cant) AS csc
      FROM co_asientos a, co_partidas p WHERE a.idasiento=p.idasiento GROUP BY 2,3
  ) v GROUP BY 2,3 ORDER BY 3 DESC
  ```
  Es decir: el reporte **no lee la BD operativa**, lee `co_asientos` + `co_partidas` de la BD contable y agrupa saldos por epígrafe de 1 y 2 dígitos.
- **Columnas:** `Cuentas`, una columna por ejercicio detectado dinámicamente, `Diferencia`, `(+/-)%` (`index.php:179-189`). La lista de ejercicios se construye a partir de `$disponi` (epígrafe `11` = Disponibilidad) en `index.php:181-186`.
- **Filas** (`index.php:193-683`), siguiendo la nomenclatura de la Circular SB/EFP (ASFI):
  - Ingresos Financieros (`51`), Gastos Financieros (`41`) → Resultado Financiero Bruto
  - Otros Ingresos Operativos (`54`) / Otros Gastos Operativos (`44`) → Resultado de Operación Neto
  - Recuperación de Activos Financieros (`53`) / Cargos por Incobrabilidad y Desvalorización (`43`) → Resultado después de Incobrables
  - Gastos de Administración (`45`) → Resultado de Operación Neto
  - Abonos por Diferencia de Cambio (`52`) / Cargos por Diferencia de Cambio (`42`) → Resultado después de Ajuste por Dif. de Cambio
  - Ingresos Extraordinarios (`57`) / Gastos Extraordinarios (`47`) → Resultado Neto del Ejercicio antes de Ajustes de Gestiones Anteriores
  - Ingresos de Gestiones Anteriores (`58`) / Gastos de Gestiones Anteriores (`48`) → Resultado antes de Impuestos y Ajustes
  - Ajuste Contable por Efecto de la Inflación, IUE, Resultado Neto de la Gestión
  - Los epígrafes se clasifican por comparación literal de enteros en `index.php:25-79`.
- **Filas que quedan estructuralmente vacías:** `RESULTADO ANTES DE IMPUESTOS` (`index.php:658-661`), `RESULTADO NETO DE LA GESTIÓN` (`index.php:682-685`) y las celdas de `Ajuste Contable por Efecto de la Inflación` (`index.php:638-657`) e `IUE` (`index.php:662-681`) leen **`$ingeant`** (Ingresos de Gestiones Anteriores, epígrafe `58`) y no un epígrafe propio. Es decir: **el ajuste por inflación y el IUE muestran el mismo número que "Ingreso de gestiones anteriores"**. Es un error de copia-pega, no un cálculo.
- **`rsp.php`:** idéntico en lógica (mismas líneas `rsp.php:6-7`, `26-97`), con `header(...ms-excel)` (`rsp.php:9-12`) y el mismo nombre de archivo de descarga `pron.xls` (`rsp.php:10`, igual que el de `presanuexce.php:6`). **No recibe ningún `$_POST`**: no se puede elegir gestión ni formato.
- **Bug transversal de las columnas de año:** el texto del encabezado dice 2020-2019 pero las columnas son dinámicas. Y la lógica alinea los ejercicios **por posición de array, no por año** (`$if($disp[2]==$gyear[$aux])` en `index.php:199,219,239,260,280,302,323,...`). Si un epígrafe no existe en un ejercicio, la posición se corre y **los montos se imprimen en la columna equivocada** (o `0.00`).
- **`$mondis` hardcodeado a 2020:** `if($disp[2]==2020) { $mondis=$disp[0]; }` (`index.php:204,224,245,265,286,308,328,348,374,394,420,440,460,488,508,528,558,578,598,630,651,674`). Si el epígrafe no tiene datos en 2020, `$mondis` queda en `0` y todas las celdas `%` de esa fila hacen **división por cero** (`index.php:210,230,251,271,291,314,334,354,380,400,426,446,466,494,514,534,564,584,604,636,656,680`).

### 2.5 `view/reportegpc/index.php` y `rgpc.php` — Estado de Situación Patrimonial comparativo

- **Título hardcodeado** (`index.php:160-162`): `ESTADO DE SITUACION PATRIMONIAL COMPARATIVO 2020-2019 / AL 31 DE DICIEMBRE DE 2020 / (EXPRESADO EN BOLIVIANOS)`.
- **Fuente:** `obtener_epigra_mon(2)` y `obtener_epigra_mon(1)` (`index.php:6-7`) — misma función que el reporte anterior.
- **Filas** (`index.php:194-533`): Activo → `Disponibilidad (11)`, `Inversiones Temporarias (12)`, `Otras cuentas por Cobrar (14)`, `Bienes de Uso (17)`, `Otros Activos (18)`, `TOTAL ACTIVO`. Pasivo → `Obligaciones con el público (21)`, `Otras cuentas por pagar (24)`, `Previsiones (25)`, `TOTAL PASIVO`. Patrimonio → `Capital Social (31)`, `Resultados Acumulados Gestiones Anteriores (35)`, `TOTAL PATRIMONIO`.
- **Columnas:** `Cuentas`, una por ejercicio, `Diferencia`, `(+/-)%` (`index.php:169-180`).
- Igual que `reportesp`, `TOTAL ACTIVO`/`TOTAL PASIVO`/`TOTAL PATRIMONIO` se calculan **sumando por posición de array**, no por año (`index.php:199-355`).
- **`rgpc.php`** = misma lógica + `header(...ms-excel)`.

### 2.6 `view/reportepres/index.php` y `presanuexce.php` — Presupuesto Anual de Operaciones

- **Título hardcodeado** (`index.php:321-324`): `PRESUPUESTO ANUAL DE OPERACIONES / GESTION 2020 - 2021`.
- **Fuente:** la misma `obtener_epigra_mon()` en **seis granularidades**: 1, 2, 3, 5, 6 y 8 (`index.php:7-12`).
- **Estructura en dos bloques:**
  - **INGRESOS** (`index.php:331-478`): columnas `RUBRO`, `DENOMINACION`, `MONTO` (2020), `MONTO` (2021). Filas: `INGRESOS FINANCIEROS` (`Intereses Disponibilidades Moneda Nacional` = `511011`), `Otros Ingresos Operativos`, `Ganancias por Operaciones de Cambio y Arbitraje M/E` (`542011`), `Ingresos Operativos Diversos`, `INGRESOS EXTRAORDINARIOS` (`Ingresos Extraordinarios M/N` = `571011`), `Ingresos por Formularios`, `Recuperacion por comisiones`, `Ingreso de gestiones anteriores` (`58`), `Diferencias de cambio de disponibilidades` (`52101`), `TOTAL`.
  - **GASTOS** (`index.php:479-1293`): columnas `PARTIDA`, `DENOMINACION`, `MONTO`, `MONTO`. Filas agrupadas en `GASTOS DE ADMINISTRACION` (Servicios Personales, Servicios No Personales, Materiales y Suministros, Otros Gastos Operativos, Impuestos Regalías y Tasas, Activos, Otros Gastos, Otras Pérdidas y Devoluciones) con ~70 cuentas analíticas codificadas a mano (`index.php:63-197`: `45102` Sueldos, `451061` Aguinaldos, `451031` Horas Extraordinarias, `242061` Aporte Solidario Patronal, `459131` Comunicaciones, `459031` Energía Eléctrica, `454041` Pasajes, `456011` Mantenimiento de inmuebles, `441991` Comisiones Diversas M/N, `445021`/`445022` Pérdidas por Cambio y Arbitraje M/N y M/E, `455021` IT, `45105` Viáticos, `45602` Mant. vehículos, `17501` Mant. equipo de computación, `45117` Refrigerios, `45118` Uniformes, `42401` Dif. cambio otras ctas por pagar, `45111` Desahucio, etc.).
- **Columnas por año:** solo dos, `for($i=0;$i<2;$i++)` (`index.php:352,368,512,...`). **La comparación por año está comentada** (`//if(2020==$gyear[$aux]){` en `index.php:355,371,511,531,547,...`), así que el reporte imprime **las dos primeras filas que devuelva la consulta**, sin verificar de qué ejercicio son. Si un epígrafe solo tiene un ejercicio, la celda "2020" queda vacía o se corre.
- **La columna `RUBRO` nunca se llena:** todos los `<td>` de rubro están vacíos (`<td></td>` antes de cada `DENOMINACION`; verificado con grep: no existe ningún `<td>` con contenido numérico de rubro en el archivo). El encabezado anuncia una columna que siempre está en blanco.
- **`presanuexce.php`** = misma lógica + `header(...ms-excel)` (`presanuexce.php:5-8`), descarga como `pron.xls` (`presanuexce.php:6`). Se invoca desde el botón Excel (`index.php:318`). **No recibe parámetros.**

### 2.7 Resumen de reportes

| Vista | Título real | Filtros | Fuente de datos | Formato |
|---|---|---|---|---|
| `reportetransfe/index.php` | Transferencia de Banco a Banco | **ninguno** | `traspaso_cuenta_bancaria` (por `agencia_destino`) | HTML |
| `Busquedatransf/index.php` + `model/transfedata.php` | Reporte de Transferencia de Banco a Banco | agencia, entidad, moneda, desde, hasta | `traspaso_cuenta_bancaria` (por `agencia_origen`) | HTML (botón Imprimir sin handler) |
| `recepciontra/index.php` | (bandeja de recepción) | ninguna (fijo `agencia_destino` de la sesión, `estado='P'`) | vista `v_traspaso_cuenta_bancaria` | HTML+DataTables |
| `reportesp/index.php` / `rsp.php` | Estado de Ganancias y Pérdidas comparativo | **ninguno** (título fijo 2020-2019) | `co_asientos`+`co_partidas` (BD contable) | HTML / XLS |
| `reportegpc/index.php` / `rgpc.php` | Estado de Situación Patrimonial comparativo | **ninguno** (título fijo 2020-2019) | `co_asientos`+`co_partidas` | HTML / XLS |
| `reportepres/index.php` / `presanuexce.php` | Presupuesto Anual de Operaciones | **ninguno** (título fijo 2020-2021, 2 columnas fijas) | `co_asientos`+`co_partidas` | HTML / XLS |

Adyacente no pedido: `view/presuanu/index.php` (28 KB) es solo HTML/JS sin una sola consulta SQL — pantalla muerta. `view/reportechequera/index.php` y `view/listachequera/index.php` son del submódulo de cheques.

---

## 3. Transferencias: qué se puede consultar y qué significa cada estado

### 3.1 Modelo de datos

Tabla `traspaso_cuenta_bancaria`, columnas usadas por el código (`funciones.php:116`):
`id_traspaso_cuenta_bancaria`, `ent_fin_origen`, `num_cuenta_origen`, `agencia_origen`, `documento_origen`, `ent_fin_destino`, `num_cuenta_destino`, `agencia_destino`, `documento_destino`, `monto_total`, `fec_crea_traspaso_cuenta_bancaria`, `fec_mod_traspaso_cuenta_bancaria`, `estado`.

`documento_destino` se inserta explícitamente como `NULL` al crear (`funciones.php:116`) y se llena al aceptar (`funciones.php:131`). Es el número de comprobante que la agencia destino teclea en `recepciontra/index.php:41`.

### 3.2 Estados

| Valor | Significado | Dónde se asigna |
|---|---|---|
| `P` | **Pendiente**: emitida por la agencia origen, descontada de su cuenta bancaria, esperando confirmación de la agencia destino. | `funciones.php:116` (`insert_traspaso`, `estado='P'`), `funciones.php:123` (bandeja), `funciones.php:539` (lista de reversibles) |
| `A` | **Aceptada**: la agencia destino confirmó con número de comprobante; se generó el asiento contable y se acreditó la cuenta destino. | `funciones.php:131` (`actuali`) |
| `R` | **Revertida**: anulada por la agencia origen. | `funciones.php:547` (`revertrasp`) |

**No existe un estado "anulada por el destino", ni "rechazada".** Una transferencia pendiente que el destino no reconoce no tiene flujo de rechazo: solo puede (a) quedar `P` indefinidamente, o (b) ser revertida por la agencia **origen**.

### 3.3 Qué se puede consultar realmente

- **Por destino y sin filtros** (todo el histórico): `reportetransfe/index.php`.
- **Por origen, rango de fechas, agencia, entidad y moneda**: `Busquedatransf/index.php` → `transfedata.php`. **No filtra por estado**, así que las revertidas aparecen mezcladas con las aceptadas.
- **Pendientes por recibir en mi agencia**: `recepciontra/index.php` (`trans_pendiente`, `funciones.php:121-126`, `agencia_destino = $age AND estado='P'`).
- **Pendientes emitidos por mi agencia, con opción de revertir**: `transfytransf/index.php:12,140-151` (`pendienteaceptar`, `funciones.php:537-542`, `agencia_origen=$idag AND estado='P'`).
- **Búsqueda por id individual**: `busca_traspaso($id)` (`funciones.php:276-281`), usada sólo internamente por `actualiza.php:12`.

**Consultas que NO existen** en este módulo: por número de comprobante/documento, por monto, por usuario que registró, por estado, ni por cuenta bancaria. `documento_origen`/`documento_destino` no se filtran en ninguna parte.

---

## 4. Anulación / reversión de transferencias

### 4.1 Cómo se dispara

1. `view/transfytransf/index.php:149`: `<a onclick="revertirtrans(<?=$key['id_traspaso_cuenta_bancaria']?>)">`.
2. `js/funciones.js:276-283`: `$.post('/bancos/model/reversiontransf.php', {id: id}, ...)` y `location.reload()`.
3. `model/reversiontransf.php:6-8`: `$id=$_POST['id']; revertrasp($id);`
4. `funciones.php:545-553`.

### 4.2 Qué valida

**Absolutamente nada.** El código completo es:

```php
// funciones.php:545-553
function revertrasp($id){
    global $con;
    $sentencia="UPDATE traspaso_cuenta_bancaria SET estado='R' WHERE id_traspaso_cuenta_bancaria=$id";
    mysqli_query($con,$sentencia);

    $sentenci="INSERT INTO saldo_cuenta_bancaria (SELECT agencia_origen, (...) AS tipocue, ent_fin_origen,
                num_cuenta_origen, (...) AS moneda, monto_total AS saldo,
                fec_crea_traspaso_cuenta_bancaria,'RT'
                FROM traspaso_cuenta_bancaria WHERE id_traspaso_cuenta_bancaria=$id)";
    mysqli_query($con,$sentenci);
    return $ejecutar;
}
```

No valida: estado actual, que la transferencia sea de la agencia del usuario, el rol, la moneda, el monto, ni que no se haya revertido antes. Nótese además que `return $ejecutar;` **retorna una variable que nunca se definió** (el `UPDATE` no se asigna a nada): devuelve `null` con un notice.

### 4.3 Efectos contables y sobre saldos

**Al crear la transferencia** (`guardartrasp.php:40-41`):
- `insert_traspaso(...)` → `traspaso_cuenta_bancaria`, `estado='P'`.
- `insert_dat_reg_saldo_cb($ageo,$tcb,$ento,$ctao,$moneda,-$monto,$usuario,'ST',$fec,$glosa)` → descuenta el monto de la cuenta **origen**.
- **No genera asiento contable.** El asiento se genera recién al aceptar.

**Al aceptar** (`actualiza.php`, invocado desde `recepciontra`):
- `actuali($id,$num,$fec)` → `estado='A'`, `documento_destino=$num` (`funciones.php:131`).
- `insert_asiento($ejercicio,$glosa,$montobob,$origeno,'T',$fec)` → `INSERT INTO co_asientos` (`funciones.php:143`), con `$montobob = $monto*6.86` si la moneda es USD (`actualiza.php:67`).
- `insert_dat_reg_saldo_cb($aged,...,+$monto,...,'ET',...)` → acredita la cuenta **destino** (`actualiza.php:87`).
- `insert_partidao(...)` (haber en la subcuenta origen) e `insert_partidad(...)` (debe en la subcuenta destino) sobre `co_partidas` (`actualiza.php:88-89`).

**Al revertir** (`revertrasp`, `funciones.php:545-553`):
- `estado='R'`.
- **Un solo** movimiento de saldo: `INSERT ... SELECT` que reingresa `+monto_total` a la cuenta **origen** con `conciliacion='RT'` (`funciones.php:550`).
- **Cero impacto en la contabilidad.** No se revierte ni borra el asiento de `co_asientos`, ni las partidas de `co_partidas`, ni se inserta un contra-asiento.

### 4.4 Qué pasa si la transferencia ya fue aceptada — **hallazgo crítico**

Analizando el flujo, la reversión de una transferencia ya `A` deja el sistema descuadrado en **tres** dimensiones simultáneas:

1. **Doble acreditación de fondos (fuga de dinero).** Al aceptar se acredita la cuenta destino con `+monto` y `'ET'` (`actualiza.php:87`). La reversión **solo** reingresa el monto a la cuenta **origen** (`funciones.php:550`); **nunca debita la cuenta destino**. Neto: el sistema registra el mismo dinero en las dos cuentas. No hay ningún `INSERT`/`UPDATE` de compensación al destino en `revertrasp`.
2. **Asiento contable huérfano.** El asiento y las partidas creados por `actualiza.php:69,88-89` quedan vigentes. El estado de la transferencia dice `R` (revertida) pero los estados financieros (`reportesp`, `reportegpc`, `reportepres`, todos alimentados por `co_asientos`+`co_partidas`) siguen mostrando una operación que operativamente no existe.
3. **Libro de saldos inconsistente.** La acreditación normal escribe en `movimiento_cuenta_bancaria` (`funciones.php:497`, dentro de `insert_dat_reg_saldo_cb`, pese al nombre de la función). La reversión escribe en `saldo_cuenta_bancaria` (`funciones.php:550`). Son **dos tablas de movimientos distintas**: el reingreso de la reversión no aparece en el mismo libro que el egreso original. Cualquier conciliación que lea una sola de las dos tablas verá un descuadre.

**Atenuante real, y su límite:** la interfaz solo ofrece el botón de revertir para transferencias en estado `P` de la propia agencia origen, porque `pendienteaceptar` filtra `estado='P'` (`funciones.php:539`) y `transfytransf/index.php:12`. Por lo tanto, el escenario de "revertir una ya aceptada" **no se alcanza desde la UI** — pero **sí se alcanza llamando directamente a `POST /bancos/model/reversiontransf.php`**, que no tiene autenticación ni validación (§1.3, §4.2). Basta conocer el `id`.

### 4.5 Otros problemas de la reversión

- **Reversible N veces.** El `INSERT ... SELECT` no comprueba el estado previo ni la existencia de un movimiento `'RT'` anterior. Dos clics (o dos POST) producen **dos reingresos** del mismo monto y un solo `estado='R'`. La UI recarga la página (`js/funciones.js:281`), pero un doble POST concurrente antes del reload duplica el crédito.
- **Sin trazabilidad.** No se guarda quién revirtió, cuándo, ni el motivo. `fec_mod_traspaso_cuenta_bancaria` **no** se actualiza en `revertrasp` (a diferencia de `actuali`, `funciones.php:131`). No hay auditoría posible.
- **`tipo_cuenta_bancaria` y `moneda` se re-derivan** por subconsulta sobre `cuenta_bancaria` en el momento de revertir (`funciones.php:550`). Si la cuenta fue modificada o desactivada entre la creación y la reversión, el movimiento se inserta con `tipocue`/`moneda` en `NULL` o incorrectos.
- **`fec_crea_saldo` de la reversión es la fecha original de la transferencia**, no la fecha de la reversión (`funciones.php:550` reutiliza `fec_crea_traspaso_cuenta_bancaria`). El reingreso queda fechado en el pasado, alterando cierres de días anteriores.
- **Nombre de función engañoso:** el endpoint es `reversiontransf.php` y la función `revertrasp()`; el archivo `revertrasp.php` no existe.

---

## 5. El "ingreso externo"

### 5.1 Qué es en el negocio

`view/ingresoExt/index.php` registra el **ingreso de dinero en efectivo a caja por un concepto ajeno a una operación de cambio o giro**. La única opción de concepto que ofrece el formulario es `FALLA DE CAJA` (`ingresoExt/index.php:83-86`, un `<select name="opciones">` con una sola `<option value="1">FALLA DE CAJA</option>`), y el texto libre `editar` (`index.php:93`) se concatena como detalle. En `guardaringrexte.php:24` se traduce `if($opc==1) $desopc="FALLA DE CAJA";`. Es, por tanto, la vía para **regularizar un faltante/sobrante de caja detectado** dejando el rastro en la contabilidad.

La glosa resultante (`guardaringrexte.php:25-29`):
`INGRESO POR EL SEÑOR/SEÑORA: <nombre> CON NUMERO DE COMPROBANTE: <numero> POR CONCEPTO DE: FALLA DE CAJA <editar> FECHA DE REGISTRO <fecha>`

### 5.2 Cómo se registra

Formulario (`ingresoExt/index.php:10-111`):
- `POST` a `/bancos/model/guardaringrexte.php` (`index.php:10`).
- Campos: `id_agencia` (hidden, de sesión, `index.php:11`), `usuario` (**hidden hardcodeado a `8`**, `index.php:12`), `genero` (radio 1/2, `index.php:17-18`), `monto_ing_ext`, `entiban` (entidad financiera de `buscar_entidad_act()`), `moneda` (1=USD, 2=BOB), `numcuen` (poblado por AJAX desde `model/numcuenta.php`, disparado en `js/acciones.js:41-43` al cambiar la moneda), `nombre`, `numero` (Nro. comprobante), `opciones`, `editar`, `codtransacc`.
- El método de pago (`guardaringrexte.php:23`) exige que **todos** los campos estén no vacíos, incluidos `entiban`, `numcuen` y `opciones`. Si falta uno: `echo "Existe datos vacios";` (`guardaringrexte.php:88`) — texto plano, sin `exit` ni código HTTP.
- Si la caja no existe para `(agencia, usuario, moneda)`, `obtener_caja_esp` (`funciones.php:685-690`) devuelve vacío, `$idcaja` queda indefinido y se inserta con `$idcaja` vacío → `INSERT INTO ingreso(id_caja,...) VALUES (,...)` → error SQL silencioso.

### 5.3 Qué tablas toca (orden de ejecución en `guardaringrexte.php`)

| # | Línea | Función (`funciones.php`) | Tabla | Efecto |
|---|---|---|---|---|
| 1 | `:37` | `ingreso_externo` (`:692-698`) | **`ingreso`** | `INSERT (id_caja, glosa, fec_crea_ingreso, fec_mod_ingreso=NULL, observaciones=NULL, estado='0')` |
| 2 | `:40` | `insert_saldo_caja_tmp` (`:563-569`) | **`saldo_caja_tmp`** | `(+monto, conciliacion='EX')` |
| 3 | `:42` | `num_cuen_ban` (`:331-337`) | `cuenta_bancaria` | `SELECT ... WHERE id_agencia IN($age) AND id_entidad_financiera=$ent AND id_moneda=$mon GROUP BY num_cuenta_bancaria` — **solo para obtener `id_tipo_cuenta_bancaria`** |
| 4 | `:48` | `insert_dat_reg_saldo_cb_tmp` (`:502-508`) | **`saldo_cuenta_bancaria_tmp`** | `(+monto, conciliacion='EX')` |
| 5 | `:49` | `listar_agencia` (`:73-79`) | `agencia` | para obtener `cod_agencia` |
| 6 | `:57` | `insert_asiento_gestion` (`:148-169`) | **`co_asientos_tmp`** (BD contable) | asiento, `tipodocumento='I'` |
| 7 | `:62` | `cuenta_ent` (`:283-289`) | `cuenta_bancaria`+`tipo_cuenta_bancaria` | para obtener `codsubcuenta` |
| 8 | `:67` | `subcuen_der` (`:291-297`) | **`co_subcuentas`** (BD contable) | para obtener `idsubcuenta` |
| 9 | `:72` | `insert_partidao_tmp` (`:220-234`) | **`co_partidas_tmp`** | haber en la subcuenta de la cuenta bancaria |
| 10 | `:73` | `insert_partidad_tmp` (`:236-249`) | **`co_partidas_tmp`** | debe contra **`'18302101'` hardcodeado** |

**Contrapartida contable hardcodeada**: la cuenta `18302101` está fija en el código (`guardaringrexte.php:73`). Cambiarla exige editar el fuente.

**Conversión de moneda fija**: `if($mon==1) $montoa=$monto*6.86; else $montoa=$monto;` (`guardaringrexte.php:54`). El tipo de cambio **6.86 está hardcodeado**, igual que en `funciones.php:177,179,192,194,226,229,242,245` y `actualiza.php:67`, y **no se lee de las tablas de cotizaciones** que el sistema sí tiene (existe el directorio `cotizaciones/` y `cotizaciones_monedas/` en el hosting). Toda la contabilidad en BOB de operaciones USD queda determinada por una constante en el código. **Debe validarse contra la política contable vigente**: la cotización oficial USD/BOB usada por el sistema en otras partes (y la que esperaría un auditor) es 6.96, no 6.86.

---

## 6. Tablas y columnas clave (derivadas de los SELECT/INSERT)

### 6.1 BD operativa `u752765750_cgr`

| Tabla / Vista | Columnas usadas | Referencias |
|---|---|---|
| `traspaso_cuenta_bancaria` | `id_traspaso_cuenta_bancaria`, `ent_fin_origen`, `num_cuenta_origen`, `agencia_origen`, `documento_origen`, `ent_fin_destino`, `num_cuenta_destino`, `agencia_destino`, `documento_destino`, `monto_total`, `fec_crea_traspaso_cuenta_bancaria`, `fec_mod_traspaso_cuenta_bancaria`, `estado` | `funciones.php:116, 131, 278, 302, 310, 522, 539, 547, 550` |
| **`v_traspaso_cuenta_bancaria`** (vista) | `id`, `abr_ori`, `abr_efo`, `num_cuenta_origen`, `abr_des`, `abr_efd`, `num_cuenta_destino`, `monto`, `abrev`, `fecha_crea` — **columnas inferidas del consumo en `recepciontra/index.php:32-40`** (la definición de la vista no está en el repo) | `funciones.php:123` |
| `cuenta_bancaria` | `id_agencia`, `id_tipo_cuenta_bancaria`, `id_entidad_financiera`, `id_moneda`, `num_cuenta_bancaria`, `codsubcuenta`, `estado` | `funciones.php:43, 59, 100, 108, 286, 334, 401, 497` |
| `entidad_financiera` | `id_entidad_financiera`, `nombre_corto`, `descripcion`, `abreviatura`, `estado`, `id_tipo_entidad_financiera` | `funciones.php:43, 51, 84, 262, 302, 522, 539` |
| `agencia` | `id_agencia`, `descripcion`, `abreviatura`, `cod_agencia`, `estado` | `funciones.php:67, 76, 302, 522` |
| `moneda` | `id_moneda`, `abreviatura`, `estado` | `funciones.php:43, 100, 108, 270, 393` |
| `tipo_cuenta_bancaria` | `id_tipo_cuenta_bancaria`, `descripcion`, `abreviatura`, `estado` | `funciones.php:43, 286, 326, 401` |
| `movimiento_cuenta_bancaria` | posicional, sin lista de columnas: `(id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria, id_moneda, saldo, cod_usuario, fec, conciliacion, glosa)` — orden inferido de `funciones.php:497` | `funciones.php:497` |
| `saldo_cuenta_bancaria` | posicional: `(id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria, id_moneda, saldo, fec_crea_saldo, conciliacion)` | `funciones.php:550` |
| `saldo_cuenta_bancaria_tmp` | `id_agencia`, `id_tipo_cuenta_bancaria`, `id_entidad_financiera`, `num_cuenta_bancaria`, `id_moneda`, `saldo`, `fec_crea_saldo`, `conciliacion` | `funciones.php:505` |
| `saldo_caja` / `saldo_caja_tmp` | `id_saldo_caja`, `id_caja`, `saldo`, `fec_crea_saldo`, `conciliacion` | `funciones.php:558, 566` |
| `caja` | `id_caja`, `id_agencia`, `cod_usuario`, `id_moneda` | `funciones.php:574, 687` |
| `ingreso` | `id_caja`, `glosa`, `fec_crea_ingreso`, `fec_mod_ingreso`, `observaciones`, `estado` | `funciones.php:695` |
| `usuario`, `trabajador`, `rol`, `rol_usuario`, `acceso` | — | `funciones.php:92, 318, 358, 433` |
| `chequera`, `cheque`, `registro_cheque`, `categoria_cheque` | (submódulo de cheques, fuera del foco) | `funciones.php:366, 417, 481, 679` |

### 6.2 BD contable `u752765750_conta`

| Tabla | Columnas | Referencias |
|---|---|---|
| `co_asientos` | posicional: `(codejercicio, ?, glosa, '', 0, fecha, '', idconcepto, importe, numero, tipodocumento)` — orden inferido de `funciones.php:143`; además `idasiento` (vía `MAX(idasiento)`, `funciones.php:165`) | `funciones.php:139, 143, 152` |
| `co_asientos_tmp` | posicional: `(codejercicio, NULL, glosa, '', 0, NOW()-4h, '', idconcepto, importe, numero, tipodocumento)` | `funciones.php:161, 212, 215` |
| `co_partidas` | posicional (22 valores): `(0,'',NULL,'BOB',NULL,codsubcuenta,NULL,debe,debeme,documento,NULL,haber,haberme,idasiento,id_origen,NULL,'',idsubcuenta,0,0,6.86,1,NULL)` | `funciones.php:182, 197` |
| `co_partidas_tmp` | posicional: **misma lista pero con `id_origen` en la posición 7** en lugar de la 15 | `funciones.php:232, 247` |
| `co_subcuentas` | `idsubcuenta`, `codsubcuenta`, `codejercicio` | `funciones.php:294` |

**Hueco detectado:** `INSERT INTO co_partidas VALUES(...)` (`funciones.php:182,197`) e `INSERT INTO co_partidas_tmp VALUES(...)` (`funciones.php:232,247`) pasan `'$id_origen'` en **posiciones distintas**. Con `INSERT` sin lista de columnas, esto solo es correcto si las dos tablas tienen el mismo orden de columnas; el código asume que **no** lo tienen. No puedo verificarlo sin el esquema (§0). Es un punto a auditar contra la BD real.

**Hueco detectado 2:** las dos tablas de asientos (`co_asientos` y `co_asientos_tmp`) se numeran con criterios distintos — `insert_asiento` filtra por `idconcepto`, `tipodocumento` y mes (`funciones.php:139`) y suma 1 (`:142`); `insert_asiento_gestion` filtra por `idconcepto`, `tipodocumento` y `YEAR(CURRENT_DATE())='$ejercicio'` (`:152`) y suma 1 (`:159`); `insert_asiento_tmp` **no filtra por concepto, documento ni período** (`:205`) y **no suma 1** (`:212`). Los tres escriben en tablas que comparten la clave lógica `(codejercicio, numero)` → riesgo de colisión de numeración.

---

## 7. Bugs y riesgos concretos

### 7.1 Inyección SQL — superficie muy amplia

Todos los `SELECT`/`INSERT`/`UPDATE` se construyen por concatenación de strings, con `mysqli_query` sin prepared statements ni escapado. Puntos concretos:

| Archivo:línea | Entrada | Consulta afectada |
|---|---|---|
| `model/transfedata.php:4-8` | `$_POST['age']`, `['ent']`, `['mon']`, `['des']`, `['has']` — **crudos** | `funciones.php:302` y `:310` (`BETWEEN '$des' AND '$has'`, `agencia_origen=$age`, `ent_fin_origen=$ent`, `id_moneda=$mon`) |
| `model/reversiontransf.php:6` → `funciones.php:547, 550` | `$_POST['id']` — crudo | `UPDATE ... WHERE id_traspaso_cuenta_bancaria=$id` y `INSERT ... SELECT ... WHERE id_traspaso_cuenta_bancaria=$id` |
| `model/actualiza.php:5-7` → `funciones.php:131` | `$_POST['id']`, `['num']`, `['usu']` | `UPDATE ... SET documento_destino='$num', estado='A' WHERE ...=$id` |
| `model/guardartrasp.php:5-16` → `funciones.php:116` | `agen_origen`, `agen_destino`, `entidad_*`, `cuenta_*`, `monto_reg`, `num_documento`, `fec_reg` — crudos | `INSERT INTO traspaso_cuenta_bancaria ... 'P'` |
| `model/guardaringrexte.php:6-19` → `funciones.php:161, 695` | `nombre`, `numero`, `editar`, `codtransacc` | `INSERT INTO ingreso`, y sobre todo `INSERT INTO co_asientos_tmp VALUES(...,'$glosa',...)` |
| `model/fechaper.php:4-6` → `funciones.php:334` | `$_POST['age']`, `['ent']`, `['mon']` | `... WHERE id_agencia IN($age) AND id_entidad_financiera=$ent AND id_moneda=$mon` |
| `model/actualizadat.php:5-10` → `funciones.php:489, 497` | `$_POST['id']`, `['che']`, `['age']`, `['ent']`, `['num']` | `UPDATE cheque ...`, `INSERT INTO movimiento_cuenta_bancaria ...` |

El más severo es **`funciones.php:131`**: es un `UPDATE` que **acepta una transferencia** y el campo inyectable es un literal entre comillas simple. Un `num` como `x', fec_mod_traspaso_cuenta_bancaria='2020-01-01' WHERE 1=1-- -` convierte el `UPDATE` en una actualización masiva de todas las transferencias a estado `'A'` y `documento_destino='x'`, sin contrapartida contable.

Segundo más severo: **`funciones.php:161`** (vía `guardaringrexte.php:26`) inyecta directamente en la **BD contable**, cruzando el límite de base de datos con `$glosa` construida a partir de `nombre`/`editar` sin escapar.

Tercero: **`funciones.php:550`** es un `INSERT ... SELECT` cuyo `WHERE` es inyectable; permite copiar filas arbitrarias de `traspaso_cuenta_bancaria` dentro de `saldo_cuenta_bancaria`.

Cuarto: **`funciones.php:302/310`** — las fechas `$des`/`$has` no se validan contra formato. `des=2020-01-01' OR '1'='1` es suficiente.

Ninguno de los archivos de `model/` usa `mysqli_real_escape_string`, `intval`, `filter_var` ni prepared statements. La única sanitización de entrada en todo el módulo es `str_replace(",","",...)` sobre el monto (`guardartrasp.php:11`) y `strtoupper()` sobre nombre/editar (`guardaringrexte.php:12,15`).

### 7.2 Ausencia total de autorización

Ya descrito en §1.3. Concretamente, endpoints mutantes sin ninguna comprobación de sesión, rol o agencia:

- `model/reversiontransf.php` — revierte cualquier transferencia por `id`.
- `model/actualiza.php` — **acepta** cualquier transferencia por `id` y genera el asiento contable.
- `model/guardaringrexte.php` — inserta un ingreso externo y un asiento. Nótese que el `usuario` (id de caja) llega por `POST` **desde un campo hidden** (`ingresoExt/index.php:12`, valor fijo `8`), así que la atribución del ingreso no depende de la sesión.
- `model/guardartrasp.php` — crea transferencias; el `usuario` también llega por `POST` (`guardartrasp.php:16` → `$_POST["usuariosis"]`), aunque aquí la vista sí lo toma de sesión (`transfytransf/index.php:103`). Aun así, **el servidor no lo verifica**: cualquiera puede enviar otro `usuariosis`.
- `model/actualizadat.php` — anula/cobra cheques.

Adicionalmente, ninguna vista valida `id_rol`, y los reportes contables (`reportesp`, `reportegpc`, `reportepres` y sus versiones XLS) exponen **el balance y el estado de resultados completo de la empresa** a cualquier usuario autenticado (o no) que conozca la URL, sin importar su rol ni su agencia. El menú los oculta, pero la URL no.

### 7.3 Reversiones sin control — resumen

Ver §4.4 y §4.5. En una frase: **revertir una transferencia aceptada duplica los fondos (se acredita el origen sin debitar el destino), deja el asiento contable vigente y escribe en un libro de saldos distinto del que usó la operación original**; además es repetible y no deja rastro de autor ni fecha.

### 7.4 Integridad contable

- **`insert_asiento_gestion` devuelve el id de la tabla equivocada.** Inserta en `co_asientos_tmp` (`funciones.php:161`) pero retorna `SELECT MAX(idasiento) id FROM co_asientos` (`funciones.php:165`). En `guardaringrexte.php:57-60` ese id se usa como `$idasiento` para las partidas (`guardaringrexte.php:72-73`), que se insertan en `co_partidas_tmp`. **Las partidas del ingreso externo quedan colgadas de un `idasiento` que no es el del asiento recién creado** (es el máximo de la otra tabla). Corrupción contable directa.
- **`$id_origen` no existe en `guardaringrexte.php`.** Las llamadas `insert_partidao_tmp($id_origen, ...)` (`guardaringrexte.php:72`) e `insert_partidad_tmp($id_origen, ...)` (`:73`) usan una variable **nunca definida** en ese archivo. Las funciones la escriben tal cual en la columna correspondiente (`funciones.php:232, 247`). Las partidas quedan sin origen.
- **`actualizadat.php:24` llama a `insert_dat_reg_saldo_cb` con 8 argumentos** cuando la función espera 10 (`funciones.php:495`). Además pasa `RT` **sin comillas**. Consecuencia: `$usu` recibe la constante `'RT'`, `$est` recibe el datetime y `$glosa` (arg 10) no se pasa. **El error_log de producción lo corrobora**: `Missing argument 10 for insert_dat_reg_saldo_cb(), called in .../actualizadat.php on line 24`.
- **Constantes sin comillas → fatal en PHP 8.** `actualiza.php:61` (`$esta=T;`) y `actualizadat.php:24` (`RT`). El error_log de producción registra `PHP Notice: Use of undefined constant T - assumed 'T' in .../actualiza.php on line 61`. En PHP 7 funcionaba por compatibilidad; **en PHP 8.x ambas líneas lanzan `Error: Undefined constant`** y abortan la aceptación de transferencias y la anulación de cheques. Riesgo directo ante cualquier actualización de PHP.
- **`cuenta_ent()` resuelve la subcuenta por sufijo ambiguo.** `funciones.php:286`: `WHERE cb.num_cuenta_bancaria LIKE '%$id'` — sin filtro de agencia ni de entidad, sin `LIMIT`. Si dos cuentas comparten los últimos dígitos (habitual entre agencias), el `foreach` del llamador (`actualiza.php:43-47`, `guardaringrexte.php:63-65`) se queda con **la última fila devuelta**, que puede ser de otra agencia/entidad. El `codsubcuenta` es lo que determina la partida contable: **se puede estar contabilizando en la cuenta equivocada**. Mismo patrón, con filtro de entidad pero sin agencia, en `abrev_mon` (`funciones.php:108`), y sin `LIMIT` en `subcuen_der` (`funciones.php:294`, `codsubcuenta LIKE '%$id'`).
- **`$ejercicio=date('Y')`** (`actualiza.php:8`, `guardaringrexte.php:18`) — si la subcuenta no está creada para el año en curso, `subcuen_der` devuelve vacío, `$idsubcueno` queda indefinido y la partida se inserta sin `idsubcuenta`.
- **Montos con separador de miles.** `guardartrasp.php:11` limpia las comas (`str_replace(",","",...)`), pero **`guardaringrexte.php:17` NO lo hace**: `$monto = $_POST["monto_ing_ext"]` crudo. El campo tiene clase `numero` (`ingresoExt/index.php:26`) y se formatea con `$('.numero').number(true, 2, '.', ',')` (`ingresoExt/index.php:116`) y `$("#monto_ing_ext").number(true,2)` (`js/acciones.js:24`) → el valor enviado es `"1,000.00"`. Ese string va a: `insert_saldo_caja_tmp` (`:40`), `insert_dat_reg_saldo_cb_tmp` (`:48`), `$monto*6.86` (`:54`) y `insert_partidao_tmp`/`insert_partidad_tmp` (`:72-73`). En MySQL un decimal entre comillas se trunca al prefijo numérico (**`1` en lugar de `1000`**); en PHP `"1,000.00"*6.86` da `6.86`. **Todo ingreso externo ≥ 1 000 registra un monto incorrecto**, y el error se propaga simultáneamente a caja, saldo bancario y contabilidad.
- **Sin transacciones.** Ninguna de las secuencias multi-tabla (`guardaringrexte.php:37-73`, `actualiza.php:11-89`, `revertrasp`) usa `START TRANSACTION`/`COMMIT` ni comprueba el retorno de `mysqli_query`. Si falla el paso 4, los pasos 1-3 quedan aplicados. Los mensajes de error existen (`guardaringrexte.php:79,82,85`) pero se emiten con `echo` y sin `exit`, así que el flujo continúa.
- **`INSERT` sin lista de columnas** en `movimiento_cuenta_bancaria` (`funciones.php:497`), `co_asientos` (`:143`), `co_asientos_tmp` (`:161, 212`), `co_partidas` (`:182, 197`), `co_partidas_tmp` (`:232, 247`) y `saldo_cuenta_bancaria` (`:550`). Cualquier cambio de esquema los rompe en silencio.

### 7.5 Reportes: corrección de los números

- **Alineación de años por posición de array** en `reportesp/index.php:199-680` y `reportegpc/index.php:199-548`. La comparación `if($disp[2]==$gyear[$aux])` es un parche sobre un supuesto de que todas las series tienen la misma longitud; cuando un epígrafe falta en un ejercicio, los montos se desplazan de columna. **Los totales y las variaciones pueden estar mal sin que nada lo advierta.**
- **Año base `2020` hardcodeado** para el cálculo del porcentaje: `if($disp[2]==2020) { $mondis=$disp[0]; }` en 22 lugares de `reportesp/index.php` (204, 224, 245, 265, 286, 308, 328, 348, 374, 394, 420, 440, 460, 488, 508, 528, 558, 578, 598, 630, 651, 674). Si un epígrafe no tiene datos en 2020, `$mondis=0` y las 22 celdas `%` hacen **división por cero** (en PHP 8, `DivisionByZeroError` fatal; en PHP 7, `INF`/`NAN`). El error_log de producción ya registra un `PHP Warning: Division by zero in .../funciones.php on line 296` (versión histórica).
- **Variable obsoleta `$disp` en los bloques de subtotal.** En cada fila "RESULTADO ..." el acumulado se hace con `$totdisp=$disp[0]+$totdisp;` (`reportesp/index.php:244, 307, 373, 419, 487, 557, 629`), pero `$disp` **no se asigna en esos bloques** (usan `$ingresofin*`, `$gastofin*`). `$disp` arrastra el valor del bloque anterior → **los acumulados de los subtotales son basura**.
- **Filas con la fuente equivocada**: `Ajuste Contable por Efecto de la Inflación` (`reportesp/index.php:643`) e `IUE` (`:667`) leen `$ingeant` (epígrafe 58, Ingresos de Gestiones Anteriores). Muestran el mismo monto que la fila "Ingreso de gestiones anteriores".
- **Filas vacías**: `RESULTADO ANTES DE IMPUESTOS` (`:658-661`) y `RESULTADO NETO DE LA GESTIÓN` (`:682-685`) tienen encabezado sin ninguna celda de valor.
- **Columna `RUBRO` siempre vacía** en `reportepres/index.php` (todos los `<td>` de rubro son `<td></td>`).
- **Fechas y títulos hardcodeados**: `reportesp/index.php:170-172` ("2020-2019", "AL 31 DE DICIEMBRE DE 2020"), `reportegpc/index.php:160-162` (idem), `reportepres/index.php:322-323` ("GESTION 2020 - 2021") y sus encabezados de columna `2020`/`2021` (`index.php:333-334, 481-482`). **Los reportes contables seguirán titulándose 2020 aunque se abran en 2026.**
- **`reportepres` fija 2 columnas** con `for($i=0;$i<2;$i++)` sin verificar el año (`index.php:352, 368, 512, ...`); la comprobación de ejercicio está comentada (`//if(2020==$gyear[$aux]){`). Un tercer ejercicio no se muestra.
- **`obtener_epigra_mon` sin filtro de ejercicio ni de agencia** (`funciones.php:529-534`): agrega **todos** los ejercicios y **todas** las agencias de la contabilidad, y hace `GROUP BY 2,3` sobre una subconsulta correlacionada completa. Los reportes no permiten segmentar por agencia ni por gestión.

### 7.6 Rendimiento (no puedo confirmar índices — ver §0)

Sin esquema no puedo afirmar si faltan índices, pero sí puedo señalar las consultas que los necesitan y que se ejecutan **sin `LIMIT` en cada carga de página**:

- `funciones.php:522` (`trans_agencias`): 7 subconsultas correlacionadas por fila + **sin `WHERE` de fecha, sin `estado`, sin `ORDER BY`, sin `LIMIT`** → tabla completa de la agencia en cada apertura de `reportetransfe`.
- `funciones.php:302/310`: 4 subconsultas correlacionadas por fila, `DATE(fec_crea_...) BETWEEN ...` (función sobre columna → **imposible usar un índice sobre la fecha**) y `LIKE '%...'` en `abrev_mon`/`cuenta_ent`/`subcuen_der` (**comodín inicial → índice inutilizable**).
- `funciones.php:529-534`: escanea y agrega `co_asientos × co_partidas` **completo, 6 veces por reporte** (1,2,3,5,6,8 en `reportepres/index.php:7-12`), y 2 veces en `reportesp`/`reportegpc`. Sin filtro de ejercicio. Sobre un histórico de varios años esto es un full scan repetido.
- `funciones.php:334` (`num_cuen_ban`): `id_agencia IN($age)` + `GROUP BY num_cuenta_bancaria` sin `LIMIT`.
- `funciones.php:590` (`listar_cheque_activo`): `count(...)` por chequera, llamada en bucle desde el submódulo de cheques.

Además, **`model/error_log` pesa 6,2 MB** y los `error_log` de las vistas suman >1,2 MB (`debitocheque` 320 KB, `transfytransf` 525 KB, `recepciontra` 505 KB). El 95 % son `mysqli_connect(): Headers and client library minor version mismatch in .../funciones.php on line 23` — **se escribe una línea de log en cada request**, incluyendo cada respuesta AJAX. Es un problema de disco/IO y, peor, **enmascara los errores reales**.

### 7.7 Errores silenciosos y modo de fallo

- `mysqli_query` **nunca** se comprueba en `funciones.php` ni en los modelos. Un `INSERT` que falla no produce error visible: la vista sigue adelante (p. ej. `guardartrasp.php:41` y luego `:53` sube el archivo y redirige a `/index.php` como si todo hubiera funcionado).
- `fechaper.php:10` hace `mysqli_fetch_assoc($cuent)` sobre el resultset y luego `foreach ($dat as ...)` (`fechaper.php:13`) emitiendo los valores **en el orden físico de las columnas de `cuenta_bancaria`**, separados por `|`. El consumidor JS lee posiciones fijas: `respuesta[3]` como número de cuenta y `respuesta[1]` como tipo de cuenta (`js/acciones.js:34-36`). **El significado depende del orden de las columnas en la tabla**: cualquier `ALTER TABLE` que agregue o reordene una columna rompe el alta de transferencias sin ningún error explícito.
- `funciones.php:146` (`insert_asiento`): el `foreach` sobre `$numer` supone que `co_asientos` tiene filas; si no, `$nume` queda indefinido → `$nume=1` con notice. El error_log registra `Invalid argument supplied for foreach() in .../funciones.php on line 146, 164, 165`.
- `funciones.php:545-553` (`revertrasp`): `return $ejecutar;` devuelve una variable inexistente.
- `model/replegal.php` **está roto sintácticamente**: línea 14 es `$fec=buscar_fec_apertura` — sin paréntesis y sin `;`. La función `buscar_fec_apertura` **no existe** en `funciones.php`. El `error_log` de producción lo confirma: `PHP Fatal error: Call to undefined function buscar_fec_apertura() in .../fechaper.php on line 14` (versión histórica del archivo) y `Cannot use [] for reading in .../fechaper.php on line 20`. `replegal.php` no está referenciado por ningún JS (el AJAX usa `model/obtencion_datos.php`), así que es **código muerto roto**.
- **`actualizadat.php` usa `header('Location: /bancos/view/debitocheque/')` (`:37`) después de haber emitido posiblemente un `echo`** si el `if` de `$esta=="N"` no se cumple: no hay salida, pero el `echo "..."` de error en otros modelos sí precede al `header`.

### 7.8 Compatibilidad y mantenibilidad

- El código declara `$conta = new mysqli(...)` y luego usa `mysqli_query($conta, ...)` sobre el objeto (`funciones.php:153, 163, 166, 206, 213, 216, 233, 248, 295, 532`) mientras en otras usa `$conta->query(...)` (`:140, 144, 183, 198`). Mezcla de estilos; funciona pero indica capas superpuestas.
- `funciones.php:3-15` y `js/acciones.js:1-18`, `js/funciones.js:1-14` incluyen el mismo bloque de broma ("solo Dios y Yo sabíamos cómo funcionaba... Total_Horas_Perdidas_Aqui = 0"). Es un indicador cultural, pero también documenta que el módulo se mantuvo por copia-pega sin revisión.
- `funciones.php` mezcla la capa de conexión (con credenciales), la de acceso a datos (operativa y contable) y la de generación de asientos en **699 líneas**. No hay separación entre el submódulo de transferencias, el de cheques y los reportes contables.
- Credenciales de dos bases de datos **en texto plano en el repositorio** (`funciones.php:19-22` y `:30-31`), incluidas en el árbol que se despliega al hosting.
- `reportesp/rsp.php:10` y `reportepres/presanuexce.php:6` descargan **ambos** como `pron.xls`; `rgpc.php` es la única con otro nombre. Confuso para el usuario.
- `js/acciones.js:93, 100, 103` usan rutas **relativas** (`bancos/model/cuentaban.php`) mientras otras usan absolutas (`/bancos/model/...`). El `reportetransfe/index.php` las llama desde `/bancos/view/reportetransfe/`, donde la ruta relativa no resuelve → los handlers `#entidad_origen`/`#entidad_destino`/`#cuenta_destino` fallarían en esa vista (si existieran esos campos, que no existen: es código compartido copiado en todas las vistas).
- `reportesp/index.php`, `reportegpc/index.php` y `reportetransfe/index.php` cargan jQuery y todo el stack de plugins **desde el dominio absoluto `https://cgr.owl-tech.net`** (`reportetransfe/index.php:17-35`): cualquier vista depende de que ese host externo sirva los assets. Si el módulo se migra, los reportes se rompen.
- `recepciontra/index.php:36` y otras vistas usan `strftime()` (`reportesp/index.php:149`, `reportegpc/index.php:139`, `reportepres/index.php:301`), **eliminada en PHP 8.1**. Con `strftime` deprecada, esos encabezados desaparecen con un `Deprecated` y, en PHP 9, fallarán.

---

## 8. Lo que NO pude verificar (y por qué)

Para no especular, listo explícitamente los límites de este análisis:

1. **Índices y claves.** No hay dump de esquema (§0). No puedo afirmar qué índices existen ni cuáles faltan. Lo que sí puedo afirmar es la forma de las consultas (§7.6): `DATE(columna)`, `LIKE '%...'` y ausencia de `LIMIT` impiden usar índices b-tree normales **si es que existen**.
2. **Definición de la vista `v_traspaso_cuenta_bancaria`.** Las columnas que cito las deduje del consumo en `recepciontra/index.php:32-40`, no de la definición.
3. **Orden real de columnas** de `movimiento_cuenta_bancaria`, `saldo_cuenta_bancaria`, `co_asientos`, `co_asientos_tmp`, `co_partidas`, `co_partidas_tmp` y `cuenta_bancaria`. Todas se usan con `INSERT ... VALUES` sin lista de columnas, así que la correspondencia es una **suposición** que debe validarse contra la BD. En particular, el distinto posicionamiento de `$id_origen` entre `co_partidas` y `co_partidas_tmp` (§6.2) es un hallazgo **a confirmar**.
4. **El handler del botón `IMPRIMIR`** de `Busquedatransf` (§2.2). No está en los JS locales; podría estar en el `funciones.js` remoto compartido, que no está en este checkout.
5. **Los directorios `reportes/`, `registro_transferencia/` y `cgr_core/`** del hosting pueden contener reportes relacionados con bancos. No los analicé porque el pedido acota el alcance a `bancos/`, pero conviene revisarlos antes de decidir si este módulo se retira.
6. **El esquema de la tabla `ingreso`**: no puedo confirmar si `estado='0'` (`funciones.php:695`) es un estado "anulado" o "pendiente"; el `'0'` se inserta literal como string y no hay ninguna otra referencia a `ingreso.estado` en el módulo. **No sé qué significa.**
7. **La cotización oficial USD/BOB vigente** para la empresa. Solo afirmo el hecho verificable: el sistema **hardcodea 6.86** en 10 puntos y no consulta las tablas de cotizaciones que el hosting sí tiene.
8. Las líneas del `error_log` corresponden a **versiones anteriores** de los archivos (las líneas citadas no siempre coinciden con el contenido actual). Las uso como evidencia de que el **patrón** de fallo ocurrió en producción, no como evidencia del estado actual de esa línea exacta. Lo indico en cada caso.

---

## 9. Priorización sugerida

| Prioridad | Hallazgo | Archivo:línea |
|---|---|---|
| **P0** | Reversión de transferencia aceptada: acredita el origen sin debitar el destino y no revierte el asiento → duplica fondos | `funciones.php:545-553`, `actualiza.php:69,87` |
| **P0** | Endpoints mutantes sin autenticación (revertir, aceptar, ingreso externo, cheques) | `model/reversiontransf.php:6`, `model/actualiza.php:5`, `model/guardaringrexte.php:6`, `model/actualizadat.php:5` |
| **P0** | Inyección SQL en `UPDATE ... SET documento_destino='$num', estado='A'` (aceptación masiva de transferencias) | `funciones.php:131` ← `actualiza.php:6` |
| **P0** | Ingreso externo con monto ≥ 1 000 se registra truncado (no se quitan las comas del formateo) | `guardaringrexte.php:17` vs `ingresoExt/index.php:26,116` |
| **P0** | Partidas del ingreso externo colgadas de un `idasiento` de la tabla equivocada | `funciones.php:161` vs `:165`; `guardaringrexte.php:57-72` |
| **P1** | `$id_origen` inexistente en las partidas del ingreso externo | `guardaringrexte.php:72-73` |
| **P1** | Subcuenta contable resuelta por `LIKE '%<cuenta>'` sin agencia ni entidad → contabilización en cuenta ajena | `funciones.php:286`, `:294`; `actualiza.php:18-19` |
| **P1** | Constantes sin comillas (`T`, `RT`): fatal en PHP 8 | `actualiza.php:61`, `actualizadat.php:24` (evidencia en `model/error_log`) |
| **P1** | Reversión repetible, sin autor, sin fecha y con fecha de saldo retroactiva | `funciones.php:547-550` |
| **P1** | Reportes contables: alineación de años por posición + `$mondis` en 0 → división por cero y columnas corridas | `reportesp/index.php:199-680` |
| **P1** | Acumulados de subtotales con `$disp` obsoleto | `reportesp/index.php:244,307,373,419,487,557,629` |
| **P1** | Inyección SQL en los filtros del buscador de transferencias | `transfedata.php:4-8` → `funciones.php:302,310` |
| **P1** | `insert_dat_reg_saldo_cb` con 8 argumentos (columnas corridas) | `actualizadat.php:24` vs `funciones.php:495` |
| **P2** | Sin transacciones ni comprobación de errores en secuencias multi-tabla | `guardaringrexte.php:37-73`, `actualiza.php:11-89` |
| **P2** | `reportetransfe` sin filtros, sin orden, sin `LIMIT`, sin DataTables | `funciones.php:522`, `reportetransfe/index.php:97` |
| **P2** | Ajuste por inflación e IUE muestran el epígrafe 58 | `reportesp/index.php:643,667` |
| **P2** | Títulos y años hardcodeados (2020/2019/2021) en los 3 reportes contables | `reportesp/index.php:170-172`, `reportegpc/index.php:160-162`, `reportepres/index.php:322-334` |
| **P2** | Columna `RUBRO` siempre vacía | `reportepres/index.php` (todas las filas) |
| **P2** | Tipo de cambio 6.86 hardcodeado, desacoplado del módulo de cotizaciones | `funciones.php:177,179,192,194,226,229,242,245`, `actualiza.php:67`, `guardaringrexte.php:54` |
| **P2** | `error_log` de 6,2 MB; se escribe una línea por request (mismatch de versión) | `model/error_log`; `funciones.php:23,36` |
| **P3** | `strftime()` (removida en PHP 8.1) | `recepciontra/index.php:36`, `reportesp/index.php:149`, `reportegpc/index.php:139`, `reportepres/index.php:301` |
| **P3** | `replegal.php` roto sintácticamente y código muerto | `replegal.php:14` |
| **P3** | Credenciales de dos BD en texto plano | `funciones.php:19-22,30-31` |
| **P3** | `INSERT` sin lista de columnas en 10 tablas | `funciones.php:143,161,182,197,212,232,247,497,550` |
| **P3** | Layout de `fechaper.php` dependiente del orden físico de columnas | `fechaper.php:10-17` ↔ `js/acciones.js:34-36` |
| **P3** | UI: estado mostrado como letra `P`/`A`/`R`; acción `IMPRIMIR` sin handler; reportes no enlazados en el menú | `reportetransfe/index.php:129`, `Busquedatransf/index.php:100` |
