# Informe técnico — Módulo legacy BANCOS / CHEQUERAS (Gambarte Bolivia S.R.L. — sistema CGR)

**Fecha:** sesión de análisis
**Módulo:** `C:\wamp64\www\bo_cgr_hostinger\bancos`
**Modo:** SOLO LECTURA. No se modificó ningún archivo del sistema legacy.

## 0. Alcance, método y nivel de evidencia

**Leído completo:** `model/funciones.php` (699 líneas), `model/chequeasig.php`, `guardarcheque.php`, `regcheque.php`, `num_cheque.php`, `movicheq.php`, `cargar_cheq_estado.php`, `actualizadat.php`, `actualiza.php`, `cuentaban.php`, `numcuenta.php`, `obtencion_datos.php`, `entidad.php`, `fecha.php`, `fechaper.php`, `idenmoneda.php`, `complnombre.php`, `conectar.php`, `funci.php`, `subeArch.php`; vistas `nuevocheque`, `debitocheque`, `enviocheque`, `recepcheque`, `regcheque`, `listachequera` (+`verdocadj.php`), `reportechequera`, `asignbanco`; `js/acciones.js`, `js/funciones.js`; `menu.php` (sección Chequera); `completa_cobro_cheque.php` y `registro_cobro_cheque.php` (raíz, porque completan el ciclo); `reportes/contabilidad/lib/Service.php` y `BankService.php` (solo lo relativo a cheques).

**Verificación contra datos reales:** el workspace tiene un espejo local de la BD legacy (`.env` → `DB_CGR_HOST=localhost`, `DB_CGR_PORT=3307`, `dbname=u752765750_cgr`). Ejecuté **únicamente consultas `SELECT`/`SHOW`/`information_schema`** con scripts temporales (ya eliminados) para confirmar esquema, PKs y distribuciones de datos. Cuando una afirmación proviene solo del código y no pude confirmarla contra datos, lo digo explícitamente.

**Marcas de evidencia usadas en este informe:**
- `[CÓDIGO]` — verificado leyendo el archivo (cito `archivo:línea`).
- `[BD]` — verificado con consulta a la BD espejo (doy el dato).
- `[LOG]` — verificado en los `error_log` incluidos en el propio módulo.
- `[INFERENCIA]` — deducción razonada, no confirmada.

---

## 1. Mapa real de pantallas: 4 de las 8 pantallas indicadas no hacen lo que su nombre sugiere

| Pantalla | Archivo | Backend real | Qué hace realmente | Estado |
|---|---|---|---|---|
| `nuevocheque` | `view/nuevocheque/index.php:31` | `model/regcheque.php` | Alta de chequera + generación de N filas de cheque | Funciona |
| `debitocheque` | `view/debitocheque/index.php:26` | `model/guardarcheque.php` | Registro/emisión de un cheque de una chequera existente | Funciona parcialmente |
| `listachequera` | `view/listachequera/index.php:6` | `lista_registro_cheque()` + `model/movicheq.php` | Reporte de movimientos de cheques | Funciona |
| `reportechequera` | `view/reportechequera/index.php:5` | `listar_chequeras()` / `listar_cheque_activo()` | Disponibilidad por chequera | Funciona |
| `asignbanco` | `view/asignbanco/index.php:24` | **ninguno** | Formulario sin `action`, sin `name`, sin handler JS | **MUERTA** |
| `enviocheque` | `view/enviocheque/index.php` | `model/guardartrasp.php` | Es **"TRANSFERENCIA DE BANCO A BANCO"**, no tiene nada de cheques | No es cheques |
| `recepcheque` | `view/recepcheque/index.php` | `model/guardartrasp.php` | **Archivo idéntico byte a byte** a `enviocheque/index.php` (mismo SHA-256 `AD624C24…`) | No es cheques |
| `regcheque` | `view/regcheque/index.php:56` | `action="#"` | Maqueta HTML con `name=""` en todos los campos; no envía nada | **MUERTA** |

[BD/CÓDIGO] El menú real del sistema solo enlaza 4 de ellas: `menu.php:452-456` → *Nueva Chequera*, *Registra Cheque*, *Reporte*, *Disponibilidad*.
**Conclusión:** en este checkout **no existe ningún flujo de "envío" ni "recepción" de cheques**. Si el requerimiento del negocio habla de enviar cheques entre agencias, esa funcionalidad no está implementada aquí (lo que existe es traslado de fondos entre cuentas bancarias, `traspaso_cuenta_bancaria`).

---

## 2. Esquema real de datos (verificado contra la BD espejo)

### 2.1 Tablas y columnas (tipos reales de `information_schema`)

**`chequera`** (83 filas `[BD]`)
```
id_chequera            bigint(20)   PK, auto_increment
id_entidad_financiera  int(11)      NOT NULL  (índice NO único)
id_moneda              int(11)      NOT NULL  (índice NO único)
id_agencia             int(11)      NOT NULL  (índice NO único)
num_cuenta             varchar(20)  NOT NULL  (índice NO único)
cantidad               int(11)      NOT NULL
numero_ini             varchar(20)  NOT NULL
fec_crea_chequera      datetime     NOT NULL
fec_mod_chequera       datetime     NULL
estado                 char(1)      NOT NULL
id_tipo_doc_identidad  int(11)      NOT NULL   ← representante legal
num_doc_identidad      varchar(20)  NOT NULL   ← representante legal
id_expedicion           int(11)     NOT NULL   ← representante legal
```
**No existe columna de vencimiento** (el campo "Fecha vence chequera" del formulario está `disabled`, `view/nuevocheque/index.php:138`, y nunca se persiste). **No existe columna de N° de factura ni de descripción**, aunque el modelo las recibe.

**`cheque`** (8.021 filas `[BD]`)
```
id_chequera      bigint(20)  PK (1/2)
id_cheque        bigint(20)  PK (2/2)   ← el número de cheque es un entero
fec_crea_cheque  datetime    NOT NULL
fec_mod_cheque   datetime    NULL
estado           char(1)     NOT NULL
```
Índice adicional no único sobre `id_cheque`.

**`registro_cheque`** (3.185 filas `[BD]`)
```
id_cheque            bigint(20)     PK (1/4)
num_cuenta_bn        varchar(20)    PK (2/4)
id_entidad           int(11)        PK (3/4)
id_agencia           int(11)        PK (4/4)
concepto_obs         varchar(1000)  NULL
cod_trab             bigint(20)     NULL
monto                decimal(16,6)  NULL
fec_crea_adj_cheque  datetime       NULL
id_categoria_cheque  int(11)        NULL
```
**La columna `num_corre` NO EXISTE en ninguna tabla de la BD** `[BD]` (consulta a `information_schema.COLUMNS` con `COLUMN_NAME LIKE '%num_corre%'` → 0 filas). Esto es la causa raíz del bug **B-7**.

**`cuenta_bancaria`** (161 filas `[BD]`) — PK compuesta: `(id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria)`. Otras columnas usadas: `id_moneda`, `codsubcuenta varchar(15)`, `estado char(1)`, y su **propio representante legal** (`id_tipo_doc_identidad`, `num_doc_identidad`, `id_expedicion`).

**`categoria_cheque`** (4 filas `[BD]`): `id_categoria_cheque` PK, `descripcion`, `abreviatura varchar(3)`, `fec_crea_categoria_cheque`, `estado`.
Contenido real `[BD]`: `1=FONDEO (FND)`, `2=TRANSFERENCIA DE OTRA AGENCIA (TOA)`, `3=FALLA DE CAJA (CFC)`, `4=TRANSFERENCIA EXTERNA (CLIENTE) (TEX)`.

**`movimiento_cuenta_bancaria`** (157.612 filas `[BD]`): `(id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria, id_moneda, monto decimal(14,4), cod_usuario, fec_crea_movimiento_cuenta_bancaria, conciliacion char(2), glosa text)`. **No tiene ninguna referencia al cheque** (ni `id_cheque` ni `id_chequera`): la única pista es el texto libre de `glosa` (`… CON CHEQUE #0038`).

**`saldo_cuenta_bancaria`**: `(id_agencia, id_tipo_cuenta_bancaria, id_entidad_financiera, num_cuenta_bancaria, id_moneda, saldo decimal(14,4), fec_crea_saldo, conciliacion char(2))`.
Valores de `conciliacion` presentes `[BD]`: `DR 14499, ET 116, ST 108, A 28, RT 16, SR 1`. **`saldo_cuenta_bancaria_tmp`** es análoga.

**`saldo_caja`** (usada por el cobro de cheque, fuera de `bancos/`): `id_saldo_caja`, `id_caja`, `saldo decimal(14,4)`, `fec_crea_saldo`, `conciliacion char(2)`. **Tampoco referencia el cheque.**

**Vistas:** `v_cheque` y `v_cheque_cobrado` (VIEW `[BD]`). Definición de `v_cheque` `[BD]`:
```sql
SELECT a.id_cheque, a.estado, b.id_chequera, b.id_agencia, b.id_entidad_financiera,
       e.abreviatura, b.id_moneda, b.num_cuenta, r.monto, r.id_categoria_cheque, r.concepto_obs
FROM cheque a JOIN chequera b JOIN entidad_financiera e JOIN registro_cheque r
WHERE a.id_chequera=b.id_chequera AND b.id_entidad_financiera=e.id_entidad_financiera
  AND a.id_cheque=r.id_cheque AND e.id_entidad_financiera=r.id_entidad
  AND r.id_agencia=b.id_agencia AND r.num_cuenta_bn=b.num_cuenta
  AND a.estado='A'          -- ← estado 'A' HARDCODEADO en la vista
```
`v_cheque` devuelve hoy **2 filas** `[BD]`; `v_cheque_cobrado`, 2.909.

### 2.2 Cómo se relacionan (no hay FKs; todo por texto)
```
entidad_financiera ──1:N── cuenta_bancaria ──(num_cuenta_bancaria varchar20)──┐
        │                                                                     │ (igualdad de texto,
        └──1:N── chequera ──1:N── cheque                                nunca por id)
                     │  (id_chequera)                                        │
                     └────────────── registro_cheque (PK: id_cheque+num_cuenta_bn
                                                       +id_entidad+id_agencia)
```
- `chequera` **no tiene FK ni columna `id_cuenta_bancaria`**: el vínculo con `cuenta_bancaria` es el **texto** `chequera.num_cuenta ↔ cuenta_bancaria.num_cuenta_bancaria` (`funciones.php:624`, `:668`, `:286`).
- `registro_cheque` **no referencia `id_chequera`**: el vínculo con la chequera se reconstruye por `id_cheque + num_cuenta_bn + id_agencia` (definición de `v_cheque`).
- `chequera` también guarda representante legal, que ya existe en `cuenta_bancaria` → **dos fuentes de verdad para el mismo dato**.
- `[BD]` no hay chequeras huérfanas (`num_cuenta` siempre existe en `cuenta_bancaria`), pero nada en el esquema lo impide.

### 2.3 Distribución real de estados `[BD]`
`cheque.estado`: **C = 4.775**, **I = 2.970**, **N = 274**, **A = 2**.
Última modificación de un cheque: `2024-10-31 14:28:01`; última creación: `2024-01-02`.
`chequera.estado`: **A = 83** (todas; nunca se cierra/inactiva una chequera).
Los 2.970 cheques en `I` comparten exactamente el mismo `fec_mod_cheque` (`2021-05-26 12:31:17`) → carga masiva, no gestión individual `[BD]`.

---

## 3. Ciclo de vida de un cheque y máquina de estados

### 3.1 Significado de los estados
Dos fuentes en el código coinciden:
- `view/debitocheque/index.php:104-108` (bloque comentado): `A=ACTIVO`, `I=INACTIVO`, `C=COBRADO`, `N=NULO`.
- `funciones.php:602-609` y `:646-653` (`CASE`): `C → 'Cobrado'`, `A → 'Aceptado'`, `N → 'Nulo'` (no contempla `I`).

En los hechos: **`I` = cheque aún no emitido (disponible en la chequera)**, **`A` = emitido/entregado**, **`C` = cobrado (debitado del banco)**, **`N` = anulado**.

### 3.2 Diagrama de transiciones

```
                    (1) alta de chequera: N filas nuevas
                    ┌───────────────────────────────────────────┐
                    ▼                                           │
                 [ I ]  inactivo/no emitido                      │
                    │                                           │
      (2) emisión   │  guardarcheque.php:80                     │
      (debitocheque)▼  actualizacion_cheque(...,'A')            │
                 [ A ]  emitido  ────────(4) cobro──────────► [ C ]
                    │        completa_cobro_cheque.php:17        cobrado
      (3) anulación │        (ÚNICO punto con guarda estado='A')
      actualizadat.php:14
                    ▼
                 [ N ]  anulado   ──(5) reversión──►  ✗ NO EXISTE
```
| # | Transición | Quién la ejecuta | Guarda de estado | Saldo/contabilidad |
|---|---|---|---|---|
| 1 | (nada) → `I` | `regcheque.php:31-34` → `insertar_cheques()` `funciones.php:417` | No | No |
| 2 | `I` → `A` | `view/debitocheque` → `guardarcheque.php:80` | **NINGUNA**: no verifica el estado previo ni que el cheque pertenezca a la chequera | Solo si `$vertp==1` (inalcanzable desde la UI, ver §6) |
| 3 | cualquier → `N` | `js/funciones.js:109-121` → `model/actualizadat.php:14` → `funciones.php:489` | **NINGUNA**: `UPDATE cheque SET estado='$es' WHERE id_chequera=$id AND id_cheque=$ch` | Intenta revertir; **falla siempre** (bug B-7) |
| 4 | `A` → `C` | `registro_cobro_cheque.php` → `completa_cobro_cheque.php:17` | **SÍ** (`AND estado='A'`) | `movimiento_cuenta_bancaria` 'CC' negativo + `saldo_caja` 'IC' + asiento contable |
| 5 | `N`/`C` → `A` | — | — | **No existe reversión** en el módulo |

### 3.3 Flujo paso a paso

**(a) Alta de la chequera** — `view/nuevocheque/index.php` → `POST /bancos/model/regcheque.php`
`regcheque.php:18` `insertar_chequera(...)` → `funciones.php:366`:
```sql
INSERT INTO chequera(id_entidad_financiera,id_moneda,id_agencia,num_cuenta,cantidad,numero_ini,
                     fec_crea_chequera,estado,id_tipo_doc_identidad,num_doc_identidad,id_expedicion)
VALUES ($entfi,$moned,$agenc,$numcu,$canti,$codin,'$fecap','A',$docid,$numre,$exped)
```
`estado='A'` **es el estado de la chequera, no de los cheques.** Luego `regcheque.php:31-34`:
```php
for($i=1;$i<=$cant;$i++){ insertar_cheques($LastId,$ini); $ini++; }
```
→ `funciones.php:417` inserta cada cheque con `estado='I'`. Se sube el PDF de la factura a `../chequera/AAAA-MM-DD/<nrofact>.<ext>` (`regcheque.php:36-58`).

**(b) Emisión** — `view/debitocheque/index.php` → `POST /bancos/model/guardarcheque.php`
Secuencia real (`guardarcheque.php`):
1. `:63` `insert_dat_reg_cheque(...)` → `funciones.php:481` inserta en `registro_cheque` **(sin verificar nada)**.
2. `:66-78` solo si `$vertp==1` → asiento en `co_asientos_tmp`/`co_partidas_tmp` (ver §6).
3. `:80` `actualizacion_cheque($ageo,$min,$estado)` → `funciones.php:489` pone el cheque en `'A'`.
4. `:83-117` mueve las imágenes anverso/reverso a `../cheque/AAAA-MM-DD/<cuenta>_<nrocheque>_an|re.<ext>`.

Número de cheque sugerido: `num_cheque.php:23` → `listar_numero()` `funciones.php:473` = `SELECT MIN(id_cheque) FROM cheque WHERE estado='I' AND id_chequera=$id`. **Es solo una sugerencia de UI**: `guardarcheque.php:9` toma `$min=$_POST['chenum']` sin validar.

**El "envío" y la "recepción"** del cheque, como tales, no existen en el código (§1): entre la emisión (`A`) y el cobro (`C`) no hay ningún paso intermedio registrado, ni estado que lo represente.

**(c) Anulación** — badges naranjas de `chequeasig.php:38` / `cargar_cheq_estado.php:35` → `js/funciones.js:109` `anulacion()` → `POST model/actualizadat.php` (`estado='N'`).
`actualizadat.php:14` actualiza el cheque y `:23-24` intenta registrar la reversión del saldo, pero **esa parte nunca funciona** (B-7).

**(d) Cobro (fuera de `bancos/`)** — pantalla `registro_ingreso.php:47` → `registro_cobro_cheque.php` (lista `v_cheque`, es decir cheques en `'A'` con `registro_cheque`) → `completa_cobro_cheque.php`.

---

## 4. Qué es "asignar banco" (`asignbanco`)

`view/asignbanco/index.php` es una pantalla **inconclusa y no operativa**:
- Título: *"ASIGNAR UNA ENTIDAD BANCARIA A UN CAJERO"* (`:21`) — es decir, la intención es asignar **entidad bancaria ↔ cajero**, no chequera ↔ entidad.
- `:4` `$obtent = obtener_entidad(1)` → **agencia 1 hardcodeada**, ignora la agencia de la sesión.
- `:24` `<form ... action="#">` y **ningún** `<select>` tiene atributo `name` (`:35, :42`) → no se envía nada.
- `:84` el botón *asignar* (`id="cambio_<idenfi>"`) **no tiene handler**: el único handler de `$("button")` está comentado en `js/acciones.js:54-70`.
- No está enlazada desde `menu.php` (`[CÓDIGO]` `menu.php:448-460`).
- No existe ninguna tabla ni consulta de asignación banco↔cajero en el módulo.

**Cómo se vincula realmente una chequera con la entidad financiera:**
1. Al crear la chequera se guarda `chequera.id_entidad_financiera` (select `#entiban`, `view/nuevocheque/index.php:78`, que se llena con `obtener_entidad()`/`obtener_entidad_che()`).
2. La cuenta se elige de `cuenta_bancaria` (que sí tiene `id_entidad_financiera` + `id_agencia` + `id_moneda`), vía `numcuenta.php:13` → `num_cuen_ban()` `funciones.php:334`.
3. El "responsable" de la chequera es el **representante legal** guardado en `chequera.id_tipo_doc_identidad/num_doc_identidad/id_expedicion` y explotado por `busqueda_chequera_2/3` (`funciones.php:447-460`) y `busqueda_trabajador()` (`funciones.php:383`).
4. La asignación de **cajeros** se resuelve por `obtenusuage()` (`funciones.php:316`) y `obtener_usuario()` (`:90`), no por `asignbanco`.

---

## 5. Registro de un cheque: validaciones existentes y ausentes

### 5.1 Lo que sí existe (casi todo en el cliente)
| Validación | Dónde | ¿Servidor? |
|---|---|---|
| Formato de monto (`jquery.number`) | `js/acciones.js:22` (`#montoche`) | **No** |
| N° de cheque de solo lectura | `view/debitocheque/index.php:100` (`readonly`) | **No** |
| Botones deshabilitados hasta elegir chequera | `js/acciones.js:272-290` | **No** |
| Checkbox "Bienes y Servicios" | `view/debitocheque/index.php:79` (`disabled`) | Irrelevante (nunca se envía) |
| Monto en letras | `js/funciones.js:292-308` (`literal()`) | No |

### 5.2 Lo que **no** existe (verificado línea por línea en `guardarcheque.php:1-80`)
```php
$tra=$_POST['replegal'];   $mon=$_POST['moneda'];   $tp=$_POST['tp_verifi'];
$min=$_POST['chenum'];     $ageo=$_POST["id_che"];   $monto=str_replace(",","",$_POST["montoche"]);
$agencia=$_POST["id_agencia"]; $numero=$_POST["numero"]; $numcue=$_POST["numcuen"];
$entban=$_POST["entiban"]; $moneda=$_POST["moneda"]; $idtipocb=$_POST["tipocuenban"];
$catcheque=$_POST["id_categoria"]; $tperso=...; $descrip=...
```
- **No** se verifica que el cheque (`$min`) exista en `cheque` para esa chequera (`$ageo`).
- **No** se verifica el estado previo del cheque (se puede "emitir" un cheque ya `C` o `N`).
- **No** se verifica que `$numcue`/`$entban`/`$agencia` correspondan a la chequera indicada.
- **No** se valida que `$monto` sea numérico, positivo, ni distinto de 0 (solo se quitan comas, `:18`).
- **No** hay validación de rango `[numero_ini, numero_ini+cantidad-1]` en ningún lugar del módulo.
- **No** hay detección de duplicados ni idempotencia (no hay token/estado).
- **No** hay comprobación de `$_SESSION` ni de rol (ver B-3).
- **`$numero = $_POST["numero"]` nunca se usa.**

### 5.3 Validaciones del alta de chequera
`regcheque.php:5-16` recibe `$canti`, `$codin`, `$numcu`, `$fecap`… **sin validar nada**. `for($i=1;$i<=$cant;$i++)` (`:31`) con `$cant` del POST: un `cantidad` grande inserta millones de filas (DoS) y el retorno de `insertar_cheques()` se **ignora** (`:32`).

Hallazgos de integridad en los datos reales `[BD]`:
- 2 chequeras tienen **más filas de cheque que `cantidad`**: `chequera 41` (ini=445, cant=148 → 149 filas, rango real 37..592) y `chequera 71` (ini=149, cant=48 → 149 filas, rango real 48..196). La chequera 71 contiene los números 48..196, es decir **solapa 48..148 con la chequera 48** (ini=1, cant=148) de la misma cuenta/agencia.
- Consecuencia: **101 números de cheque están duplicados en dos chequeras de la misma cuenta y agencia** (101 grupos, 101 filas extra), todos ellos con la segunda copia aún en estado `I` (ninguno en `C`/`N`). Ver B-14.

### 5.4 Persistencia incompleta
`regcheque.php:12,16,39` recibe `$descr` (descripción del registro) y `$nrofa` (N° de factura); `insertar_chequera()` (`funciones.php:364-366`) **no los usa y no existen columnas para ellos** `[BD]` → **se pierde el respaldo documental de la compra de la chequera**. El nombre del archivo adjunto sí usa `$nrofa` (sin sanear): `regcheque.php:39,46,53`.

---

## 6. Débito de cheque: efecto en saldos y asiento contable

### 6.1 Lo que hace el módulo `bancos` (pantalla "Registra Cheque")
- **No toca saldos**: la llamada está comentada, `guardarcheque.php:65`:
  `//insert_dat_reg_saldo_cb($agencia,$idtipocb,$entban,$numcue,$moneda,(-$monto),SC);`
- **No genera asiento** en la práctica: el bloque contable está dentro de `if($vertp==1)` (`:66`), y `$vertp` se calcula en `:11-15` a partir de `$_POST['tp_verifi']`, pero el checkbox que envía ese campo está **`disabled`** en la vista (`view/debitocheque/index.php:79`) → el navegador nunca lo envía → `$vertp` **siempre es 0**. El bloque `:67-78` es **código muerto desde la UI**.
- Ese bloque, además, escribe en tablas **borrador**: `insert_asiento_tmp()` → `co_asientos_tmp` (`funciones.php:212`) y `insert_partidao_tmp/insert_partidad_tmp` → `co_partidas_tmp` (`:232`, `:247`). No son el libro oficial.
- Conclusión: **el registro de un cheque en `bancos` no mueve saldos ni contabiliza.** Su único efecto es la fila en `registro_cheque`, el `UPDATE cheque.estado` y las imágenes.

### 6.2 Lo que hace el core al cobrar el cheque (el débito real)
`completa_cobro_cheque.php` (raíz), invocado desde `registro_ingreso.php:47`:
1. `:11` lee `v_cheque` filtrando `estado='A'` (la vista solo devuelve cheques `A` con `registro_cheque`).
2. `:17` `UPDATE cheque SET estado='C', fec_mod_cheque='…' WHERE id_cheque=… AND id_chequera=… AND estado='A'` → **única guarda de estado de todo el ciclo**.
3. `:19-20` `INSERT INTO saldo_caja … 'IC'` (**Ingreso Cheque** → acredita la caja; etiqueta confirmada en `reportes/contabilidad/lib/BankService.php:1094`).
4. `:28-31` `INSERT INTO movimiento_cuenta_bancaria … $salida … 'CC'` con `$salida = $monto*(-1)` (`:28`) → **debita la cuenta bancaria**.
5. `:36-70` asiento contable **completo** en `co_asientos` + dos `co_partidas` (debe: caja `11101201`/`11101101`; haber: la subcuenta del banco; `tipodocumento='T'`), valorizando con `cotizacion_oficial(1,1,$db)` (`:35`), y finalmente `UPDATE co_asientos SET importe = SUM(partidas.debe)` (`:69`).

`[BD]` La huella de este camino existe y funciona: `movimiento_cuenta_bancaria.conciliacion='CC'` → 2.006 filas, con montos negativos y glosas del tipo `RETIRO DE EFECTIVO DE LA CC 4011028815 BANCO MERCANTIL PARA AGENCIA COCHABAMBA CENTRAL CON CHEQUE #…`; última: `2024-02-15 17:09:50`.

### 6.3 Problemas verificados en el débito
- **Sin trazabilidad**: ni `saldo_caja` ni `movimiento_cuenta_bancaria` guardan `id_cheque`/`id_chequera` (esquema en §2.1) `[BD]`. El único vínculo es el texto de la glosa. No se puede auditar ni conciliar programáticamente un cheque contra su movimiento.
- **Descuadre de etiquetas**: `[BD]` hay **2.808** filas `saldo_caja` con `'IC'` pero solo **2.006** `movimiento_cuenta_bancaria` con `'CC'`. Como el `INSERT` de caja (`:20`) se ejecuta **antes** del de banco (`:30`) y ambos terminan con `die()` en caso de error, una diferencia de **802 filas** es compatible con cobros que acreditaron caja y **no** debitaron el banco. `[INFERENCIA]` (no hay clave común para emparejarlas; también podría deberse a versiones previas del script).
- **Sin transacción**: los 5 pasos anteriores (UPDATE del cheque, caja, banco, asiento, partidas) no están dentro de una transacción (`[CÓDIGO]`: no hay `begin_transaction`/`commit`/`rollback` en el módulo `bancos` ni en `completa_cobro_cheque.php`) → cualquier fallo intermedio deja el sistema descuadrado.
- **`estado='A'` casi inexistente**: `[BD]` solo **2** cheques están en `A`, y `v_cheque` (que filtra `estado='A'` en su propia definición) devuelve **2 filas**. Es decir, la pantalla de cobro del core está hoy prácticamente vacía y **4.775 cheques están en `C`**: la mayor parte de los `C` no pudo haber pasado por `completa_cobro_cheque.php`, que exige estado `A`. Además `v_cheque_cobrado` (= `C` con `registro_cheque`) tiene 2.909 filas, contra 4.775 cheques `C` reales. `[INFERENCIA]` hay marcas de `C`/`N` hechas fuera de este código (carga inicial/migración: `cheque.fec_crea_cheque` empieza en `2019-11-07` mientras `registro_cheque` empieza en `2021-05-03`; no puedo determinarlo solo con el código).
- **Reversión de la anulación: no funciona** (B-7).

---

## 7. Reportes de chequera

### 7.1 `Reporte` — Movimiento de Chequeras (`view/listachequera/index.php`)
- **Carga inicial sin filtros**: `:6` llama a `lista_registro_cheque()` (todas las agencias, todos los estados ≠ `I`), aunque el formulario muestra `desde=hoy`, `hasta=hoy` (`:29-30`) y "TODOS". Los filtros **solo** se aplican al pulsar *GENERAR* (`js/acciones.js:315-320` → `model/movicheq.php` → `movicheqesp()` `funciones.php:640`).
- **Columnas** (`:68-81`): N°, Anverso, Reverso (adjuntos), N° Cheque, Fecha Activado, Fecha Cobrado, Estado, Moneda, Monto, Entidad Bancaria, Agencia, Descripción.
- **Bug de fechas**: en la carga inicial `fechaAdj` y `fechaCre` son **el mismo campo** (`funciones.php:600-601`, ambos `rc.fec_crea_adj_cheque`), de modo que "Fecha Activado" y "Fecha Cobrado" muestran el mismo valor. En la versión filtrada sí difieren (`:644-645`: `fechaAdj=rc.fec_crea_adj_cheque`, `fechaCre=che.fec_mod_cheque`), pero `che.fec_mod_cheque` es la **última modificación** (también cambia al anular), no necesariamente la fecha de cobro.
- Solo muestra cheques con `estado != 'I'` (`:625`, `:669`): los cheques no emitidos no aparecen.
- Adjuntos: se resuelven por convención de nombre probando `.pdf` y cayendo a `.jpg` (`:86-100`, y `movicheq.php:59-71` con un número distinto de `../`), y se sirven vía `verdocadj.php` (ver B-2).

### 7.2 `Disponibilidad` (`view/reportechequera/index.php`)
- Fuente: `listar_chequeras()` `funciones.php:580-582`:
  `SELECT * FROM chequera c WHERE c.id_chequera IN (SELECT id_chequera FROM cheque WHERE estado='A' OR estado='I')`
  → **una chequera cuyos cheques estén todos en `C`/`N` desaparece del reporte**.
- Columnas (`:63-71`): N°, Representante Legal, Agencia, Entidad, Cuenta, Moneda, Fecha Apertura, Cantidad, **Disponibles**.
- "Disponibles" = `listar_cheque_activo()` `funciones.php:590`: `count(*) WHERE estado='I' AND id_chequera=$che`. **No descuenta los cheques `N` ni valida contra `cantidad`.**
- **Rendimiento y exactitud**: dentro del `foreach` hace 5 consultas por fila (`:76, :81, :86, :91, :96`) — N+1 — y usa `foreach` para asignar una variable única (`$descr,$mone,$trab,$cheqact,$banco`) **sin reinicializarla** (`:77-99`): si una consulta no devuelve filas, se imprime el valor de la **fila anterior**.

### 7.3 Reportes disponibles para exportar
El botón EXCEL está comentado (`view/reportechequera/index.php:51-53`) y `descargarExcel()` (`js/funciones.js:259-273`) apunta a `presanuexce.php`, que no pertenece a chequeras. **No hay exportación operativa** de estos reportes.

---

## 8. BUGS Y RIESGOS

### Críticos (seguridad)

**B-1. Inyección SQL generalizada (concatenación, cero prepared statements).**
No hay un solo `mysqli_real_escape_string`/`prepare` en el módulo. Puntos concretos:
- `funciones.php:366` — `INSERT INTO chequera … VALUES ($entfi,$moned,$agenc,$numcu,$canti,$codin,…)`: `$numcu` (`num_cuenta`) y `$codin` (`numero_ini`) llegan del POST **sin comillas ni escape**.
- `funciones.php:481` — `… VALUES ($num,$cue,$ent,$age,'$obs',$cod,$mon,…)`: `$mon` es el **monto** del POST, sin comillas.
- `funciones.php:286` (`LIKE '%$id'`), `:108` (`LIKE '%$cuba'`), `:43, :51, :100, :334, :401, :457, :465, :514`.
- `model/movicheq.php:14,16,20,24,28,32` — `$desde,$hasta,$estado,$moneda,$agencia,$entidad` del POST entran directos al `WHERE`.
- `model/actualizadat.php:15` → `funciones.php:514` con `$numcue,$entban,$agen` del POST.
- `model/chequeasig.php:17` → `funciones.php:449` con datos derivados de `$_POST['rep']`.
Ejemplo real de ruptura funcional (no solo seguridad): `busqueda_chequera_2` compara `c.num_cuenta=$numcue` **sin comillas** (`funciones.php:449`) mientras `chequera.num_cuenta` es `varchar(20)` `[BD]` → con cuentas alfanuméricas la consulta falla o compara mal.

**B-2. XSS reflejado y acceso libre en `view/listachequera/verdocadj.php`.**
`:2` `$archiv=base64_decode($_GET['archivo'])` y `:12` `<object id="boleta" data="<?=$archiv?>">`. El archivo **no llama a `session_start()`** ni valida sesión/rol/pertenencia, y no restringe el esquema: con `?archivo=<base64 de http://…>` se embebe contenido arbitrario (XSS/clickjacking) y se puede intentar leer rutas del servidor. Se invoca desde `js/funciones.js:286-290`.

**B-3. Cero control de autorización en los modelos.**
Ningún archivo de `bancos/model/` llama a `session_start()` ni comprueba `$_SESSION`/rol (verificado con búsqueda: la única aparición de `$_SESSION` es `guardarcheque.php:126`, dentro de un `<title>`, y **sin sesión iniciada** → siempre vacío). Además `bancos/` **no tiene `.htaccess` ni `index.php` de guardia**. Cualquiera puede invocar por POST `/bancos/model/guardarcheque.php`, `/regcheque.php`, `/actualizadat.php`, `/chequeasig.php`, `/movicheq.php`, etc.

**B-4. Subida de archivos sin restricción de extensión ni saneo de nombre → escritura arbitraria / RCE.**
- `guardarcheque.php:34` `$nombre=$numcue."_".$min;` y `:38` `$tipoArchivo = strtolower(pathinfo($_FILES["adjuntaran"]["name"],PATHINFO_EXTENSION));` → la extensión la controla el atacante: subir `x.php` produce `…_an.php` dentro del webroot (`:86`, `:93`).
- `regcheque.php:39` `$nombre=$_POST['nrofact'];` → **path traversal** directo en `:46`/`:53`.
- `mkdir(...,0777,true)` (`guardarcheque.php:84,103`; `regcheque.php:44`).

**B-5. Credenciales en texto plano y código de conexión muerto.**
`funciones.php:19-22` y `:30-33` (usuario/contraseña de las 2 BD). `model/conectar.php:8` y `model/funci.php:8` crean la conexión con `root`/vacío y base `mvc`, **ignorando** las constantes declaradas arriba.

**B-6. `error_log` gigantes y públicos dentro del webroot.**
`bancos/model/error_log` (6,2 MB), `bancos/view/debitocheque/error_log` (320 KB), `recepciontra` (505 KB), `transfytransf` (525 KB), etc., con rutas absolutas del servidor y errores internos. Son descargables si el servidor no bloquea la extensión.

### Altos (integridad de datos / lógica)

**B-7. La anulación de un cheque NUNCA revierte el saldo (columna inexistente).**
`model/actualizadat.php:15` llama a `busca_reg_cheque($id,$numcue,$entban,$agen)` (`funciones.php:512-517`), cuyo `WHERE` empieza con **`rc.num_corre=$id`**… pero la columna **`num_corre` no existe en ninguna tabla de la BD** `[BD]`. Consecuencias encadenadas:
1. La consulta falla siempre → `foreach($bus as $rob)` (`actualizadat.php:17`) no itera → `$idtipocb, $moneda, $monto` quedan indefinidos.
2. La reversión de `:24` se ejecuta con valores vacíos y **con la firma desalineada** (ver B-8) → no inserta un movimiento válido.
3. **Evidencia:** `[BD]` no hay ninguna fila en `movimiento_cuenta_bancaria` con `conciliacion='RT'` (0) ni con `conciliacion=''` (0), y `[LOG]` el `error_log` del módulo contiene **centenares** de `PHP Warning: Invalid argument supplied for foreach() … /model/actualizadat.php on line 17` (registros desde 2021 hasta 2022, en producción).
→ **Anular un cheque cambia el estado a `N` pero deja el débito bancario y el asiento contable intactos.** El cheque anulado sigue "cobrado" en la contabilidad.

**B-8. Llamada a `insert_dat_reg_saldo_cb()` con 8 argumentos de 10 → columnas desalineadas.**
`actualizadat.php:24`: `insert_dat_reg_saldo_cb($agen,$idtipocb,$entban,$numcue,$moneda,$monto,RT,$fec);`
Firma (`funciones.php:495`): `($age,$tcb,$ent,$nuc,$mon,$sal,$usu,$est,$fec,$glosa)`; `INSERT` posicional en `:497`.
Resultado del mapeo: `cod_usuario` recibe el literal `'RT'`, `conciliacion` recibe `''` (la fecha va a la columna de fecha por casualidad) y `glosa` queda vacía. `[LOG]` confirmado: `Missing argument 9 for insert_dat_reg_saldo_cb() … actualizadat.php on line 24` y `Missing argument 10`. Comparar con la llamada correcta de `actualiza.php:87` (10 argumentos).

**B-9. Constantes indefinidas usadas como valores.**
- `guardarcheque.php:67` `$estado=C;` y `:70` `insert_asiento_tmp($ejercicio,$glosa,$monto,E,$min);`
- `actualiza.php:61` `$esta=T;` y `movicheq.php:19` `if($estado!=T){`
- `actualizadat.php:24` `RT`
`[LOG]` `error_log`: `PHP Notice: Use of undefined constant T - assumed 'T' … actualiza.php on line 61`. En PHP 7 producen avisos y se interpretan como cadenas; **en PHP 8.x son `Error` fatal** (se invierte el flujo: `$estado=` en `guardarcheque.php:67` mataría el proceso, y `movicheq.php:19` rompería el reporte filtrado). El proyecto local corre PHP 7.2.4; migrar de versión exige corregir esto primero.

**B-10. Variables sin definir que corrompen el asiento contable.**
- `guardarcheque.php:47`: `$subcuend=subcuen_der($codsubd,$ejercicio);` usa **`$codsubd`**, que no existe (la correcta es `$codsubo`, asignada en `:45`). `[LOG]` confirmado: `Undefined variable: codsubd … guardarcheque.php on line 47`. Con `$codsubd` vacío, el `LIKE '%$id'` de `subcuen_der()` (`funciones.php:294`) se convierte en `LIKE '%'` → devuelve **todas** las subcuentas del ejercicio y el `foreach` de `:50-52` se queda con **la última** → `idsubcuenta` arbitraria.
- `guardarcheque.php:77`: `insert_partidad_tmp($origend,…)` usa **`$origend`**, que nunca se define (solo existe `$origeno`, `:59`) → cuenta de destino del asiento vacía.
- `:65` el movimiento de banco del alta está **comentado**, y las partidas van a tablas `_tmp` (ver §6.1).

**B-11. `SELECT MAX(id_chequera)` para obtener el id recién insertado (carrera + corrupción).**
`funciones.php:369-371` y `regcheque.php:20-22`. Dos altas simultáneas pueden obtener el mismo `MAX` y **asociar los cheques a la chequera equivocada** (debería usarse `mysqli_insert_id`). Evidencia compatible `[BD]`: 3 chequeras donde `MIN(id_cheque) <> numero_ini` y 2 chequeras con más filas que `cantidad`, incluida la chequera 71 (ini=149, cant=48) que contiene 149 cheques abarcando 48..196 y **solapa la chequera 48**. `[INFERENCIA]`: el mecanismo exacto de esos registros históricos no se puede reconstruir solo con el código.

**B-12. Correlativos contables sin bloqueo.**
`insert_asiento()` (`funciones.php:139-145`): `SELECT MAX(numero) … ` + `INSERT` → números de asiento duplicables bajo concurrencia. Y devuelve `$conta->insert_id` (aquí sí). En cambio `insert_asiento_tmp()` (`:205-217`) hace `SELECT MAX(idasiento) FROM co_asientos_tmp` en vez de `insert_id` → **en concurrencia toma el asiento de otra sesión**; e `insert_asiento_gestion()` (`:165-168`) consulta `MAX(idasiento) FROM co_asientos` (¡tabla equivocada!) y devuelve un `mysqli_result`, no un id.

**B-13. Emisión sin guarda de estado ni idempotencia (doble emisión).**
`guardarcheque.php` no comprueba el estado previo del cheque: un doble clic, un reintento del navegador o un POST manual insertan **otra** fila en `registro_cheque` y vuelven a poner el cheque en `A`. La PK de `registro_cheque` `[BD]` es `(id_cheque, num_cuenta_bn, id_entidad, id_agencia)`, así que el segundo intento falla por clave duplicada… **y el resultado de `insert_dat_reg_cheque()` no se comprueba en ningún punto** (`guardarcheque.php:63`): la UI igual muestra "Se ha completado el registro exitosamente" (`:150`) si el adjunto se movió (la condición de éxito real es `if($respuesta==1)`, `:121`).

**B-14. Duplicación de números de cheque en la misma cuenta (dato real).**
`[BD]` **101 números de cheque existen en dos chequeras de la misma cuenta y agencia** (p. ej. cuenta `10000040972156`, agencia 6: el cheque 48 está en la chequera 48 y en la 71). El esquema lo permite: `cheque` tiene PK `(id_chequera, id_cheque)` y `chequera` no tiene ningún índice único sobre `(id_entidad_financiera,id_moneda,id_agencia,num_cuenta,numero_ini)`. Hoy las segundas copias están en `I`, pero el "Disponibles" del reporte las cuenta dos veces y la UI permite emitir cualquiera de ellas.

**B-15. Anulación de estados inválidos (`UPDATE` sin `WHERE estado`).**
`funciones.php:489` no filtra por estado y `actualizadat.php:7` toma `$esta=$_POST["estado"]` sin validar contra una lista blanca. Se puede pasar `C`→`N` (**anular un cheque ya cobrado**, dejando el movimiento `CC` y el asiento hechos, y sin reversión por B-7) o `N`→`A`, y también `N`→`C` (marcar como cobrado un cheque anulado sin tocar saldos). La UI solo ofrece el enlace para cheques `A` (`chequeasig.php:38`), pero el endpoint no lo exige.

**B-16. Llamadas con aridad incorrecta (funciones que reciben menos argumentos).**
- `chequeasig.php:17`: `busqueda_chequera_2($idtido,$nudoid,$ideexp,$mon)` → **4 de 6** argumentos. El SQL resultante es `… AND c.num_cuenta= AND c.id_entidad_financiera=…` → error de sintaxis → `$idc` es `false` → `foreach` falla → `$idche` indefinido. `[LOG]` confirmado en producción: `Missing argument 5 for busqueda_chequera_2(), called in …/chequeasig.php on line 17` (y 6), repetido durante meses. En PHP 7 degrada la lista "TODOS"; **en PHP 8 es `ArgumentCountError` fatal**.
- `num_cheque.php:6-7` y `chequeasig.php:6-7` leen `$_POST['fecha']`/`['mon']` que no siempre se envían (`js/acciones.js:238` solo manda `rep`) → `[LOG]` `Undefined index: fecha … num_cheque.php on line 6`, seguido de `foreach` sobre `false`.

**B-17. HTML roto en listados de cheques.**
`chequeasig.php:49-65` (y `cargar_cheq_estado.php:46-62`): al superar 20 celdas se emite `</tr>` y se reinicia `$x`, pero **nunca se emite el `<tr>` de apertura** siguiente → el navegador reestructura la tabla y los cheques posteriores al 20 quedan fuera de fila. Además cada `<td>` repite `id="id_che"` e `id="id_cheque"` (`chequeasig.php:38-63`), IDs duplicados en el DOM.

**B-18. Estado de la UI inconsistente.**
- `view/debitocheque/index.php:146`: `<input type="hidden" name="id_cheque" id="id_cheque" val=""/>` — el atributo es `val`, no `value`: el campo nunca tiene valor (aunque `adjuntarCheque()` lo fija por JS).
- `:148`: `<table …><table>` (etiqueta de apertura sin cerrar).
- El hidden `chenum` solo se rellena al cambiar `#fec_aper` (`js/acciones.js:272-275`) y `#numer` al cambiar `#replegal` (`:238-240`): si el usuario no cambia la fecha, `chenum` viaja vacío y `guardarcheque.php:9` emite con `$min=''` (el `INSERT` en `registro_cheque` quedará con `id_cheque=0`/error y el `UPDATE` del cheque no afectará ninguna fila).
- El filtro "por estado" está **muerto**: `js/acciones.js:245` engancha `$("#tipcuenta")`, pero **ningún elemento con ese id existe en las vistas** → `model/cargar_cheq_estado.php` no se invoca nunca desde la UI.
- El diálogo de anulación (`js/funciones.js:53-85`) titula "Está apunto de Anular este cheque" y su botón alternativo *Cobrar* está comentado (`:70-76`) → desde el módulo no hay forma de marcar `C` manualmente.

**B-19. `INSERT` posicionales sin lista de columnas.**
`funciones.php:143,161,182,197,212,232,247,497,550` y `completa_cobro_cheque.php:30,41,55,66`. Cualquier columna nueva o reordenada en `co_asientos`, `co_partidas`, `movimiento_cuenta_bancaria`, etc. **corrompe datos silenciosamente**. Agravante: `co_partidas` y `co_partidas_tmp` reciben el mismo número de valores pero con la columna 7 distinta (`NULL` en `:182` vs `'$id_origen'` en `:232`), lo que evidencia que las dos tablas ya divergen.

**B-20. Heurística frágil para debe/haber.**
`funciones.php:174-181,189-196,223-230,239-246`: el signo se decide con `substr($codsubcuenta,-3,1)==1`. Un cambio en el plan de cuentas invierte debe y haber sin ningún error visible.

**B-21. Tipo de cambio fijo `6.86` en las partidas.**
`funciones.php:177,179,192,194,226,228,242,244`. El core usa `cotizacion_oficial()` (`completa_cobro_cheque.php:35`). Dos caminos contables con tipos de cambio distintos → descuadre.

**B-22. `mkdir`/`file_exists` no atómico y bloque duplicado.**
`guardarcheque.php:83-98` y `:102-117` repiten la misma lógica; `[LOG]` hay avisos reales `mkdir(): File exists … guardarcheque.php on line 84` por uso concurrente.

**B-23. Reporte con variables "pegajosas" y N+1.**
`view/reportechequera/index.php:76-99` (ver §7.2) y `view/listachequera/index.php` cargando sin filtros (`:6`).

**B-24. `busqueda_chequera()` sin condición de join y `GROUP BY` posicional.**
`funciones.php:439-444`: `FROM chequera c, cheque ch` **sin unir** c con ch (producto cartesiano) y `GROUP BY 11,12,13` por posición → con `ONLY_FULL_GROUP_BY` falla, y los valores devueltos por columna no agrupada son arbitrarios. Su consumidor `obtencion_datos.php:11-13` usa esas columnas para identificar al representante legal → **puede preseleccionar el representante equivocado**. El mismo patrón posicional aparece en `:51` y `:449`.

**B-25. `$idche` sobrescrito (última chequera gana).**
`chequeasig.php:22-24` (y `num_cheque.php:19-21`): si el representante tiene más de una chequera, el `foreach` deja **la última** y se listan/numeran cheques de una chequera distinta a la elegida.

**B-26. Búsqueda por igualdad exacta en `datetime`.**
`funciones.php:457`: `fec_crea_chequera='$fecha'` contra una columna `datetime` `[BD]`; el valor proviene del `<option>` de `fecha.php:20` (que muestra `date('Y-d-m', strtotime(...))`, **formato año-día-mes**, distinto del `Y-m-d` que se envía como value). Cualquier discrepancia de formato hace que **el selector de chequeras aparezca vacío sin error**.

**B-27. Confusión de rutas relativas en los adjuntos.**
`listachequera/index.php:86-100` usa `../../../bancos/cheque/…` y `movicheq.php:59-71` usa `../../bancos/cheque/…` para el mismo recurso (distinto número de niveles `../` desde dos rutas de script distintas; si una resuelve, la otra no).

**B-28. `utf8_encode` y `TRIM(UPPER(...))`.**
`funciones.php:497` y `completa_cobro_cheque.php:30,55,66`: `utf8_encode` está **deprecado en PHP 8.2** y además corrompe texto si la conexión ya es `utf8mb4`.

---

## 9. Hallazgos cross-módulo relevantes para la migración (`cgrapi`)

Estos no están en `bancos/` pero afectan directamente a la lectura de datos de cheques por el sistema nuevo:

**C-1. La métrica `cobro_cheque` del sistema nuevo cuenta las etiquetas equivocadas.**
`reportes/contabilidad/lib/Service.php:260-261`:
```sql
SUM(CASE WHEN sc.conciliacion IN ('EE','RB') THEN sc.saldo ELSE 0 END) AS cobro_cheque
```
donde `sc` es **`saldo_caja`** (`:284`). Pero `[CÓDIGO]` el cobro de un cheque escribe **`'IC'`** (`completa_cobro_cheque.php:19`) y así lo etiqueta el propio sistema nuevo en `BankService.php:1094` (`'IC' => 'Ingreso Cheque'`). `'EE'` lo escribe `registra_movimiento_caja.php:19` y `'RB'` lo escribe `revierte_deposito_cuenta.php:14` (reversión de depósito). `[BD]` `saldo_caja`: `EE=1163` (2019-07-12 … 2021-09-01), `RB=137`, **`IC=2808`** (2021-05-03 … 2024-02-15). Es decir: **el KPI "cobro_cheque" del reporte nuevo suma movimientos que no son cobros de cheque y omite los 2.808 que sí lo son.**

**C-2. `v_cheque` filtra `estado='A'` dentro de la vista** `[BD]`, y hoy devuelve **2 filas** de 8.021 cheques. Cualquier pantalla nueva que dependa de `v_cheque` (como `registro_cobro_cheque.php`) verá el sistema prácticamente vacío, mientras 4.775 cheques están en `C` y 2.970 en `I`.

**C-3. Trazabilidad imposible cheque ↔ contabilidad.** Ni `movimiento_cuenta_bancaria` ni `saldo_caja` guardan `id_cheque`/`id_chequera` (`[BD]`, §2.1). Para reconstruir el débito de un cheque solo se dispone del texto de `glosa` (`… CON CHEQUE #0038`) y de `fec_mod_cheque`. Cualquier conciliación cheque↔banco↔caja en el sistema nuevo requiere **parseo de texto** o un cambio de esquema.

**C-4. `saldo_cuenta_bancaria.conciliacion`** `[BD]` = `DR, ET, ST, A, RT, SR` — no existe `'EE'` ni `'CC'` (esas viven en `movimiento_cuenta_bancaria`). Conviene no mezclar ambas tablas al mapear conciliaciones.

---

## 10. Resumen ejecutivo de riesgos

| ID | Riesgo | Severidad | Evidencia |
|---|---|---|---|
| B-7 | Anular un cheque no revierte el débito ni el asiento (columna `num_corre` inexistente) | **Crítica** | `funciones.php:514`, `actualizadat.php:15`, `[BD]`, `[LOG]` |
| B-1 | Inyección SQL en todo el módulo | **Crítica** | `funciones.php:366,481,286,108,…`, `movicheq.php:14-32` |
| B-3 | Sin autenticación/autorización en ningún modelo | **Crítica** | `bancos/model/*` (sin `session_start`) |
| B-4 | Upload sin filtro de extensión + path traversal (`nrofact`) | **Crítica** | `guardarcheque.php:34,38`; `regcheque.php:39` |
| B-2 | XSS reflejado en `verdocadj.php` | **Crítica** | `verdocadj.php:2,12` |
| B-5/B-6 | Credenciales en claro; `error_log` públicos de 6 MB | **Alta** | `funciones.php:19-33`; `bancos/model/error_log` |
| B-8/B-10 | Asiento de anulación y de emisión con columnas/variables incorrectas | **Alta** | `actualizadat.php:24`; `guardarcheque.php:47,77` |
| B-13/B-15 | Sin guarda de estado: doble emisión, `C`→`N`, `N`→`C` | **Alta** | `guardarcheque.php:63-80`; `funciones.php:489` |
| B-11/B-12/B-14 | Carreras (`MAX(id)`, correlativos) y 101 números de cheque duplicados | **Alta** | `funciones.php:369,139,205`; `[BD]` |
| B-9/B-16/B-24/B-26 | Código que romperá al subir de PHP y funciones mal invocadas | **Media-Alta** | `guardarcheque.php:67`; `chequeasig.php:17`; `funciones.php:441` |
| B-19/B-20/B-21 | `INSERT` posicionales, debe/haber por heurística, TC fijo 6.86 | **Media-Alta** | `funciones.php:182-247` |
| C-1 | El reporte nuevo calcula `cobro_cheque` con etiquetas equivocadas | **Alta (migración)** | `Service.php:260` vs `completa_cobro_cheque.php:19` |
| C-3 | Sin trazabilidad cheque↔banco↔caja | **Alta (migración)** | esquema `[BD]` |

**Ningún flujo del módulo usa transacciones de BD** (`[CÓDIGO]`: no hay `begin_transaction`/`commit`/`rollback` en `bancos/model/` ni en `completa_cobro_cheque.php`), y varias operaciones escriben en dos bases distintas (`u752765750_cgr` y `u752765750_conta`, `funciones.php:18-38`).

---

## 11. Lo que NO pude determinar (sin especular)

1. **Causa histórica exacta** de la chequera 71 (149 filas, rango 48..196, `cantidad=48`) y de la 41; el patrón es compatible con B-11 pero el código no permite reconstruir el momento.
2. **Origen de los 1.866 cheques en `C`** que no aparecen en `v_cheque_cobrado` (4.775 `C` vs 2.909 en la vista): no se deduce del código; parece carga/migración previa (`cheque.fec_crea_cheque` desde 2019-11 mientras `registro_cheque` arranca 2021-05).
3. **Emparejamiento de los 802 `saldo_caja 'IC'` sin `movimiento_cuenta_bancaria 'CC'`**: sin clave común no se puede confirmar si son cobros parcialmente fallidos o versiones antiguas del script.
4. **Si la anulación llegó a funcionar alguna vez**: `num_corre` no existe hoy; pudo existir en un esquema anterior.
5. **Rol/permisos reales** de `$_SESSION` (`id_agencia`, `id_rol`) y el guardia de sesión del front controller: asumí lo que muestra `menu.php:448-460`. No hay `.htaccess` en `bancos/`.
6. **Sensibilidad a mayúsculas del include**: `completa_cobro_cheque.php:2` hace `include("config.php")` mientras el archivo del repo es `Config.php` (en Windows funciona; en Linux sería un fallo). Los datos muestran que **no** está fallando en producción (0 fechas vacías en 2.006 movimientos `CC`), así que `$fec_reg_ope` (`Config.php:111`) sí se está cargando. Lo dejo como observación, **no** como bug.
7. El **esquema físico completo** más allá de las tablas listadas (p. ej. `co_asientos`, `co_partidas`, `trabajador`, `agencia`) no lo inspeccioné tabla por tabla: usé solo las columnas que el código referencia.
