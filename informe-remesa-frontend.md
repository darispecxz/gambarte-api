# Informe técnico — Frontend Angular 19 de Remesas (Gambarte/CGR POS)

**Alcance analizado**
- `D:\GitHub\GambartePOS\src\app\features\remesa\remesa.component.ts` (2 264 líneas)
- `D:\GitHub\GambartePOS\src\app\features\historial-remesa\historial-remesa.component.ts` (745 líneas)
- `D:\GitHub\GambartePOS\src\app\core\remesa.service.ts` (338 líneas)
- `D:\GitHub\GambartePOS\src\app\shared\recibo-remesa\recibo-remesa.component.ts` (145 líneas)
- Contraste con backend: `D:\GitHub\cgrapi\app\Modules\Cajero\Services\CajeroRemesaService.php` (3 223 líneas), `...\Controllers\CajeroRemesaController.php`, `...\Routes\api.php`

**Rutas y API**: `/remesa` y `/historial-remesa` (`app.routes.ts:24,32`), navegación en `shell.component.ts:48,58` (sin control de rol en el cliente). Base de API `http://127.0.0.1:8080/api` (`src/environments/environment.ts:3`); el servicio concatena `/cajero` (`remesa.service.ts:202`).

---

## 1. Flujo completo de una remesa

El wizard es un `type Step = 'cliente' | 'destinatario' | 'operacion' | 'resultado'` (`remesa.component.ts:15`), renderizado con `@if (step === ...)`. Indicador visual de 4 pasos en `remesa.component.ts:35-51`.

### Paso 0 — Carga inicial (`ngOnInit`, líneas 1640-1669)
`forkJoin` de 7 llamadas simultáneas (líneas 1641-1648): `destinos`, `catalogos` (vía `CambioService`, compartido con el módulo de cambio), `comisionesPreferenciales`, `bancosChile`, `formasPagoChile`, `formasPagoCobro`, `cuentasBancariasCobro`. **Si una sola falla, falla todo el paso** (`error` en 1660-1663) y el cajero queda sin destinos ni catálogos. También se registra el autocálculo con `debounceTime(600)` (líneas 1666-1668).

### Paso 1 — Remitente (`step === 'cliente'`, líneas 63-296)
1. Selección de **Tipo Documento** (`clienteDoc.tipoDoc`, por defecto `'1'`, línea 1486), **Nro. Documento** y **Expedición** (líneas 69-93).
2. Búsqueda de cliente: `buscarCliente()` (1677-1708) se dispara en `(blur)` del documento (línea 81) y en `(change)` de expedición (línea 86). Llama a `CambioService.buscarCliente(doc, tipoDoc, expedicion)` → `GET /cajero/buscar-cliente` (`cambio.service.ts:29-35`, ruta en `api.php:25`).
3. **Si existe**: `this.cliente = {...found}`, `clienteEncontrado = true`, se oculta el formulario (1686-1689) y sólo se muestra una tarjeta de confirmación (104-110) con nombre, celular y domicilio.
4. **Si no existe**: `clienteEncontrado = false`, `showClienteForm = true`, se precargan tipo doc / nro doc / expedición y se aplica el departamento por defecto del catálogo (1690-1701). El formulario completo está en 119-285: nombres*, apellido paterno*, apellido materno, celular*, fecha de nacimiento*, sexo, país, lugar de nacimiento, estado civil, ocupación, actividad económica*, departamento, ciudad, zona, domicilio*, tel. dom., tel. trab., NIT, email; más una sección colapsable "Datos de Empleo" (261-283).
5. Detalle a notar: el select de **Ciudad** está atado a `id_ciudad_pcc` (`remesa.component.ts:220`) y `cliente.id_ciudad`, pero el **backend ignora este valor** (ver §8.1).
6. `Continuar` sólo se habilita si `clienteReady` (1589-1596): nombres, apellido paterno, celular, expedición ≠ 0, fecha de nacimiento, domicilio. **No se exige** actividad económica, ciudad, sexo, país, ocupación ni NIT.
7. `goToDestinatario()` (1737-1752): normaliza el documento a mayúsculas, cambia a `step = 'destinatario'` y pide los **destinatarios frecuentes** del remitente.

### Paso 2 — Destinatario (`step === 'destinatario'`, líneas 299-409)
1. Chips de **destinatarios anteriores** (310-327) poblados por `destinatariosPorRemitente` (`GET /cajero/destinatarios-remitente`, `remesa.service.ts:223-229`).
2. `+ Nuevo` limpia a `emptyDestinatario()` (1785-1789, 2244-2251).
3. Formulario de destinatario: nombres*, apellido paterno*, apellido materno, documento, celular, domicilio, email (329-363). **No hay validación de documento** ni exigencia de NIT/CI.
4. **Forma de pago en Chile** (366-394): `Método de Pago*` desde `formasPagoChile`. Sólo si `formaPagoChile === 2` aparecen **Banco Destino*** y **Nro. Cuenta*** (377-392). El `2` está **hardcodeado** en la plantilla, no derivado de `FormaPagoChile.requiere_cuenta` (`remesa.service.ts:115-119`) pese a que ese campo existe en el contrato.
5. `destinatarioReady` (1598-1602): nombres, apellido paterno, y —si `formaPagoChile === 2`— banco ≠ 0 y cuenta no vacía.

### Paso 3 — Operación (`step === 'operacion'`, líneas 412-928)
1. **Aplicar cotización** (422-440): input de código + `buscarCotizacion()` → `GET /cajero/buscar-cotizacion-remesa` (1968-1989).
2. **Moneda a Enviar** (`1` = USD, `2` = BOB), deshabilitada si hay cotización aplicada (445-449). **No existe opción CLP** aunque `monedaAbr` mapea `'3' → 'CLP'` (1585).
3. **Destino** desde `/cajero/destinos` (452-459) y **Modo de Ingreso** (461-467): `directo`, `equiv_clp`, `equiv_usd` (este último deshabilitado si la moneda es USD).
4. **Monto** + **Tipo de Comisión** (`0` = Habitual, resto = id de comisión preferencial) + **Incluir Comisión** (radio Sí/No) + **Registrar compra USD** (sólo visible con USD) con opción "Facturar con NIT" (470-522).
5. Autocálculo con debounce de 600 ms ante cualquier cambio de moneda/destino/monto/comisión/incluir (1798-1843, 1666-1668) y botón manual "Recalcular Comisión" (525-533).
6. **Estado UIF** (536-567) y **alertas** (570-590) según la respuesta de `verificar-uif-remesa`.
7. **Origen de Fondos*** y **Destino de Fondos*** (593-604) — textareas, mínimo 5 caracteres (validado sólo por el guard `canRegister`, 1621).
8. **Formulario PCC-08** condicional (606-657).
9. **Desglose de comisión** (660-750) con `Por Entregar [CLP]` destacado (684-687).
10. Botones **Cotizar** (758-767 → `generarCotizacion()`) y **Registrar Remesa** (768-772 → `abrirConfirmDialog()`).

### Diálogo de confirmación (778-927)
Resumen (782-871) + **Forma de Cobro al Cliente** con múltiples métodos y cuentas bancarias BOB (874-912) + Confirmar (916-923). `abrirConfirmDialog()` (1939-1944) **autocompleta** `metodosPago[0].monto = total_operacion` sólo si hay un único método.

### Paso 4 — Resultado (931-1008)
Tarjeta con Nro. remesa, código, correlativo, fecha, remitente, beneficiario, doc. beneficiario, agencia, destino (`CHILE - {destino}` hardcodeado en línea 949), pago en Chile, banco Chile, monto recibido, `Por Entregar [CLP]`, equivalente USD (calculado en la plantilla: `resultado.total_clp / resultado.tipo_cambio_clp`, línea 963), comisión, total BOB; secciones de **Factura Electrónica** (972-983) y **Compra USD** (984-996); botones **Comprobante** y **Factura** en modal PDF (998-1005) y **Nueva Remesa** (1006 → `nuevaRemesa()`, 2146-2186).

**Nota**: `nuevaRemesa()` no resetea `tcOficialVenta` (2162-2174), dato que persiste entre operaciones.

### Emisión de comprobante/factura
No hay impresión desde el DOM del POS: los dos PDF los **genera el backend** (`GET /cajero/comprobante-remesa-pdf/{id}` y `/factura-remesa-pdf/{id}`, `api.php:55-58`), se descargan como `Blob` (`remesa.service.ts:317-323`), se convierten a object URL y se inyectan en un `<iframe>` saneado con `bypassSecurityTrustResourceUrl` (`remesa.component.ts:2199-2205`); "Imprimir" hace `iframe.contentWindow.print()` (2214-2221).

---

## 2. Cotización y cálculo de comisión

### 2.1 El cálculo NO es del frontend
Toda la aritmética vive en el backend. El frontend **muestra** `ComisionResult` (`remesa.service.ts:39-51`) y lo **reenvía como snapshot** en `registrar-remesa` (`remesa.component.ts:2046`, `comision: this.comisionResult`).

**Hallazgo central (riesgo alto)**: `registrarRemesa` del backend **no recalcula nada**; persiste los valores que manda el POS:

```php
// CajeroRemesaService.php:1065-1078
$comisionBob = (float) ($comisionData['bob']['comision_total'] ?? 0);
$comisionX   = (float) ($comisionData['moneda_x']['comision_total'] ?? 0);
$totalBob    = (float) ($comisionData['bob']['total_operacion'] ?? 0);
$totalX      = (float) ($comisionData['moneda_x']['total_operacion'] ?? 0);
...
$montoEnviadoBob = (float) ($comisionData['monto_enviado_bob'] ?? 0);
$montoEnviadoX   = (float) ($comisionData['monto_enviado_x'] ?? $monto);
```
La única validación de monto es `$monto < 1` (`CajeroRemesaController.php:216-218`) y un tope `monto_maximo_remesa` (`CajeroRemesaService.php:1050-1053`). **No hay recálculo ni cotejo contra `calcularComision`**, aunque la función existe (`CajeroRemesaService.php:588`). Es decir: **sí pueden divergir**, y un cliente manipulado o una versión antigua del POS puede fijar la comisión a voluntad. Existe un request equivalente y seguro (`/calcular-comision`) que no se usa en el registro.

### 2.2 Tipos de cambio

| Concepto | Origen backend | Línea |
|---|---|---|
| T.C. oficial **venta** USD/BOB | `cotizacion` (id_operacion=2, id_agencia) | `2905-2913` |
| T.C. oficial **compra** USD/BOB | `cotizacion_oficial` (id_operacion=1) | `2915-2923` |
| T.C. oficial venta (expuesto) | `cotizacion_oficial` (id_operacion=2) → `GET /cajero/tc-oficial-venta` | `215-222`, `2925-2933` |
| T.C. **paralelo** BOB/USD | `cotizacion` (id_operacion=4, estado `'I'`) | `2935-2942` |
| **Cotización Chile (CLP/USD)** | API externa `CHILE_API_URL` (`cgr.gambarte.cl`) con caché 4 h | `226-263`, `265-283` |
| Dolarización UIF | `cotizacion_oficial` id_operacion=1 | `476-489` |

La cotización de Chile se obtiene de `{CHILE_API_URL}/ws/ws.php?t=base64("1|{idDestino}")` con timeout de 5 s (`226-263`); si falla, cae a la tabla `cotizacion_chile_cache` con TTL de 4 horas (`265-283`). Si tampoco hay caché, `success=false` y el endpoint responde **503** (`CajeroRemesaController.php:134-136`).

### 2.3 Fórmulas exactas (USD — caso normal)

`comisionHabitualUSD` (`CajeroRemesaService.php:609-726`). Parámetros: `porcentaje_iva`, `porcentaje_it` (tabla `parametros`), ITF por moneda desde tabla `itf` con fallback 0.3 (`2944-2949`), y el tramo de comisión desde la tabla de comisiones:

```php
// 619-620
$comisionEntity = $this->getComisionRemesa($idAgencia, $idMoneda, 4, $montoEnviado);
$comisionTotal  = round((float) $comisionEntity['total_comision'], 4);
// 630-648
$comisionX  = ($montoEnviado * $comisionTotal) / 100;
$comisionBob = $comisionX * $cotOficial;
$ivaBob = round(($comisionX * $cotOficial * $ivaPct) / 100, 2);
$ivaX   = round($ivaBob / $cotOficial, 2);
$itBob  = round(($itPct * $comisionBob) / 100, 2);
$itX    = round($itBob / $cotOficial, 2);
$itfX   = round(($montoEnviado * $itfPct) / 100, 2);      // ITF sobre el monto enviado
$itfBob = round($itfX * $cotOficialCompra, 2);            // usa T.C. de COMPRA
$gambarteBob    = round(($comisionBob / $comisionTotal) * $gambartePorc, 2);
$corresponsalBob= round(($comisionBob / $comisionTotal) * $corresponsalPorc, 2);
$montoEnviadoBob= round($montoEnviado * $cotOficial, 2);
```
Nota: `$ivaBob` e `$itBob` se calculan **sobre base redondeada a 2 decimales**, no sobre el porcentaje directo.

Total y signo según `incluir_comision` (`650-661`):
```php
$totalOpX = round($montoEnviado + ($incluirComision
    ? -$gambarteX - $corresponsalX
    : $gambarteX + $corresponsalX + $itX + $ivaX + $itfX), 2);
$totalOpBob = round($montoEnviadoBob + ($incluirComision
    ? -$gambarteBob - $corresponsalBob
    : $gambarteBob + $corresponsalBob + $itBob + $ivaBob + $itfBob), 2);
```
`incluir_comision = 1` ⇒ la comisión **se descuenta del monto** (el cliente entrega `monto_enviado_bob` y en destino llega menos); `= 0` ⇒ la comisión **se suma**. Coherente con `monto_enviado_x = $incluirComision ? $totalOpX : $montoEnviado` (`718`).

Reparto IT/Gambarte/corresponsal según el tramo `limite_importe_exterior` (`663-688`). Detalle **inconsistente**: en la rama `$idMoneda === 1` (USD) el `else` (línea 682-687) es idéntico al `else` de la rama `2` y **anula la comisión de Gambarte** (`$gambarteBob = 0`), mientras el `if` de la línea 677 **sí** se la asigna. Con el umbral típico, un envío USD **por encima** del límite imputa todo a Gambarte; **por debajo**, todo al corresponsal. Además, dentro de la rama `$idMoneda === 1` se sobreescribe `$corresponsalPorc` con `= 0` (línea 681) tras haberlo incrementado en 680 (código muerto).

Redondeo asimétrico: `bob.total_operacion` usa `round(..., 1)` (706, 996) mientras `moneda_x` usa `round(..., 2)` (715, 1005).

### 2.4 BOB (`comisionHabitualBOB`, 853-932)
```php
// 861-880
$comisionEntity = $this->getComisionRemesa($idAgencia, $idMoneda, 4, $montoEnviado / $cotOficialCompra);
$comisionBob      = round(($montoEnviado * $comisionTotal) / 100, 2);
$itBob            = round(($itPct * $comisionBob) / 100, 2);
$comisionTotalIt  = $comisionBob + $itBob;                      // el IT se SUMA a la "comisión total"
$ivaBob           = round(($comisionTotalIt * $ivaPct) / 100, 2); // IVA sobre comisión+IT
$gambarteBob      = round(($comisionTotalIt / $comisionTotal) * $porcentaje_gambarte, 2);
$corresponsalBob  = round(($comisionTotalIt / $comisionTotal) * $porcentaje_corresponsal, 2);
$totalOpBob = round($montoEnviadoBob + ($incluirComision
    ? -$gambarteBob - $corresponsalBob
    : $gambarteBob + $corresponsalBob + $ivaBob), 2);
$itBob = round(($itPct * ($gambarteBob + $corresponsalBob)) / 100, 2);  // IT RECALCULADO
```
Tres particularidades frente al camino USD: (a) el IVA incluye el IT en la base; (b) el IT **se recalcula** al final sobre el reparto, por lo que **no cuadra con la identidad `total = monto + reparto + IVA + IT`**; (c) **BOB no aplica ITF**: `'itf' => null` (911, 920) y el frontend oculta la fila (`remesa.component.ts:731`). El reparto vuelve a reasignar `gambarteBob`/`corresponsalBob` según `totalOpBob / cotOficialCompra > rangoLimite` (885-893).

### 2.5 Preferencial (`comisionPreferencial`, 934-1011)
```php
// 948-965
$comisionTotal = round((float) ($pref['porcentaje_comision_preferencial'] ?? 0), 4);
$comisionX     = ($montoEnviado * $comisionTotal) / 100;
$comisionBob   = $comisionX * $cotOficial;
$ivaBob = round(($comisionX * $cotOficial * $ivaPct) / 100, 2);
$itBob  = round(($itPct * $comisionBob) / 100, 2);
$itfX   = round(($montoEnviado * $itfPct) / 100, 2);
$itfBob = round($itfX * $cotOficial, 2);            // aquí usa $cotOficial, no la de compra
$totalOpX   = round($montoEnviado + $comisionX + $itfX, 2);   // NO usa $itX ni $ivaX
$totalOpBob = round($montoEnviadoBob + $comisionBob + $itfBob, 2);
```
**El parámetro `incluirComision` no existe en la firma de `comisionPreferencial`** (línea 934) y `calcularComision` no lo propaga en esa rama (`606`). Consecuencia: **con comisión preferencial, "Incluir Comisión = Sí" no tiene ningún efecto** en el backend, pero el frontend ofrece el radio y etiqueta "(incluida)" en el diálogo (840) y en el resultado (966). `moneda_x.total_operacion` también difiere de `bob.total_operacion` por moneda (1005 vs 996) en lugar de ser conversión exacta.

### 2.6 Rama CLP muerta
Existe `comisionHabitualCLP` (762-851), pero el selector del frontend sólo ofrece `1` (USD) y `2` (BOB) (`remesa.component.ts:447-448`). **Esa rama es inalcanzable desde la UI**; sólo entraría si se pudiera enviar `id_moneda = 3`.

### 2.7 Cómo el frontend obtiene el "equivalente"

- **Modo directo**: `enviarCalculo(this.montoEnvio)` (1850-1852).
- **Modo `equiv_clp`** (USD): pide `cotizacionChile(destino)` y hace `montoReal = Math.round((monto / cot.valor) * 100) / 100` — **redondeo propio en el frontend** (1870-1872). El valor mostrado `Por Entregar [CLP]` viene del backend.
- **Modo `equiv_clp` / `equiv_usd` con moneda BOB**: `resolverEquivalenteBob()` (1882-1912) hace una **llamada sonda con montos ficticios** para derivar el T.C. BOB/USD:
```ts
// 1883-1905
this.remesaSvc.calcularComision({
  tipo_comision: +this.tipoComision, id_moneda: 2, id_operacion: 16,
  monto: 1000, id_destino: +this.selectedDestino, incluir_comision: this.incluirComision,
}).subscribe({ next: (probe) => {
  ...
  const tcBobUsd = 1000 / probe.equivalente_usd;
  let targetUsd = this.modoMonto === 'equiv_clp' ? this.montoEnvio / tcClpUsd : this.montoEnvio;
  const realBob  = Math.round(targetUsd * tcBobUsd * 100) / 100;
  this.enviarCalculo(realBob);
}});
```
Esto es correcto aritméticamente si `tcBobUsd` es el T.C. paralelo (`equivalente_usd = montoEnviado / tcParalelo`, línea 896), pero tiene dos fallos: (a) **la sonda pasa `tipo_comision` del usuario**, y la rama preferencial **no devuelve `equivalente_usd`** (998-1010), por lo que el flujo falla con el mensaje engañoso "Error obteniendo tipo de cambio" (1894); (b) **dispara un cálculo adicional** que el `debounceTime(600)` puede reenviar (ver §8.3).

### 2.8 Snapshot y cotizaciones
`generarCotizacion()` (1946-1966) guarda `snapshot: this.comisionResult` en `pos_cotizacion_remesa` junto a moneda, destino, monto, tipo e incluir comisión (backend 83-114). Al recuperarla, `buscarCotizacion()` **restaura el snapshot tal cual** en `comisionResult` (1980) y **no vuelve a llamar al backend ni revalida precios**; el backend sólo comprueba estado (`aplicada` / `expirada`) y `valido_hasta` (130-135), con validez por defecto 4 h (`39`) y `marcarCotizacionAplicada` al registrar (`CajeroRemesaController.php:240-242`). Como el registro persiste ciegamente el snapshot, **una cotización de 4 horas fija el precio de forma efectiva**.

---

## 3. Remitente, destinatario y destinatarios frecuentes

- **Remitente**: uno existente se relee de `usuario_financiero` (`buscar-cliente`) y sus datos se reenvían completos en `registrar-remesa` (`cliente: this.cliente`, 2008). El backend hace *upsert* por `(num_doc_identidad, id_tipo_doc_identidad, id_expedicion)` (`upsertUsuarioFinanciero`, 2696-2748).
- **Validación de documentos**: **no existe ninguna** en el frontend ni en el backend para remesa: no hay dígito verificador, longitud, formato de CI/NIT/pasaporte ni coherencia tipo-doc/expedición. El único chequeo de documento del backend es `empty($cliente['num_doc_identidad'])` (`CajeroRemesaController.php:200`) y `empty($destinatario['nombres'])` (209). El campo `destinatario.num_doc_identidad_d` **puede ir vacío** y el registro se acepta (queda `''`).
- **Destinatarios frecuentes**: `destinatariosPorRemitente` hace `INNER JOIN remesa` y agrupa por datos, devolviendo `MIN(id_destinatario)`, incluyendo `cuenta` e `id_banco_chile` (backend 190-213). En el POS se pintan como chips; al elegir uno (`seleccionarDestinatarioPrevio`, 1756-1783) se rellena el formulario y, **si el destinatario tiene `id_banco_chile`, se fuerza `formaPagoChile = 2` y se precarga la cuenta** (1775-1778).
- El backend persiste el destinatario con *upsert* por **documento únicamente** (2805-2815), **sin filtrar por remitente**: un mismo documento de destinatario compartido entre dos remitentes reutiliza la misma fila y la **actualiza con los datos del último** (2818-2838). La lista de frecuentes sí filtra por remitente a través de `remesa`, por lo que el par remitente↔destinatario puede quedar contaminado.
- `id_destinatario` se envía desde el frontend (1760) pero **el backend lo ignora**.
- `destinatario.email` se captura (360) y se usa para notificaciones (backend 1479) pero **no se guarda** en la tabla `destinatario` (2818-2856 no incluye `email`). El `cliente.email` tampoco se persiste (2713-2745), sólo se usa para notificar.
- `buscarDestinatario()` existe en el servicio (`remesa.service.ts:215-221`) y el endpoint existe (`api.php:46`), pero **el componente nunca lo llama** (código muerto).

---

## 4. Verificación UIF, PCC-08 y listas de control

### Cuándo se verifica
`checkUif(r.monto_enviado_x)` se llama **automáticamente tras cada cálculo de comisión** (`remesa.component.ts:1929`, definición 2066-2086) y envía `id_moneda`, `monto`, `documento`, `tipo_doc`, `expedicion`. **No envía el destino ni la moneda destino.**

### Umbrales (backend 18-35, 298-375)
Valores por defecto si la tabla `parametros` no los define:
- `monto_remesa_formulario_operacion_unica` = **500 USD**; `..._operaciones_multiples` = 1000; ventana 30 días; nº ops 3.
- `monto_remesa_pcc08_operacion_unica` = **1000 USD**; `..._operaciones_multiples` = 2000; ventana 30 días; nº ops 3.
- Días de control: `dias_control_multiples_remesa_formulario` (30) y `dias_control_multiples_remesa_pcc08` (30).

### Lógica de exigencia
```php
// 306-308  dolarización
$tcOficial = $this->getTipoCambioOficialRemesa($idMoneda);
$dolarizado = $tcOficial > 0 ? round($monto / $tcOficial, 2) : 0;

// 319-331  FORMULARIO COMPLEMENTO
$uf = $this->buscarUsuarioFinanciero($doc, $tipoDoc, $expedicion);
$nivelImpresion = $uf ? (int) ($uf['nivel_impresion_formulario'] ?? 1) : 0;
if (empty($uf) || $nivelImpresion < 2) {
    if ($dolarizado >= (float) $params['unica_formulario']) {
        $requiereFormulario = true;
    } elseif (($opsPrevFormulario['cantidad'] + 1) >= (int) $params['num_ops_formulario']
           && ($opsPrevFormulario['sumatoria'] + $dolarizado) >= (float) $params['multiples_formulario']) {
        $requiereFormulario = true;
    }
}

// 334-352  PCC-08
if ($dolarizado >= (float) $params['unica_pcc08']) {
    $requierePcc08 = true;
} else {
    $hayPcc08EnPeriodo = $this->hayPcc08EnPeriodo($doc, $tipoDoc, $expedicion, $params['dias_pcc08']);
    if (!$hayPcc08EnPeriodo) {
        if (($opsPrevPcc08['cantidad'] + 1) >= $params['num_ops_pcc08']
            && ($opsPrevPcc08['sumatoria'] + $dolarizado) >= $params['multiples_pcc08']) { $requierePcc08 = true; }
    } else {
        $ultimasOps = $this->sumaUltimasRemesas($doc, $tipoDoc, $expedicion, $params['dias_pcc08']);
        if (($ultimasOps + $dolarizado) >= $params['multiples_pcc08']) { $requierePcc08 = true; }
    }
}
```
- La dolarización usa el **T.C. oficial de compra** de `moneda` (`476-489`); para `id_moneda = 1` es `1.0`.
- Si el cliente ya tiene `usuario_financiero.nivel_impresion_formulario >= 2`, el **formulario complementario no se exige nunca más** (322).
- Si ya hubo un PCC-08 en la ventana, pasa a evaluar la sumatoria de las 3 últimas remesas (`555-584`, `LIMIT 3`) contra `multiples_pcc08`.
- Las operaciones previas sólo cuentan remesas con `estado IN (0,1)` (520, 544, 572).

### Cómo se muestran
- Banda de estado con 3 estilos: verde "Dentro de límites UIF", ámbar "Requiere Formulario Complemento", rojo "Requiere PCC-08" (`remesa.component.ts:536-561`), mostrando `dolarizado`, cantidad de ops previas, ventana de días, total dolarizado y el umbral PCC-08 único.
- Alertas de listas: `alerta-alto` / `alerta-critico` con badge del tipo (`570-590`, estilos 1289-1309).
- Las alertas se repiten en el diálogo de confirmación en rojo (863-870) y se indica "Se generará declaración de fondos" si aplica PCC-08 (857-862).

### Qué bloquea la operación
**El frontend bloquea únicamente por PCC-08 y sólo parcialmente**: `canRegister` (1620-1625) exige `comisionResult`, `origen.length >= 5`, `destinoPcc.length >= 5` y, si `requierePcc08 && dinero_si === 0`, nombres y documento del tercero.

Riesgos detectados:
1. **`requiere_formulario` NO se usa para nada**: se muestra, pero no condiciona el registro ni obliga a llenar el formulario complementario. El POS puede registrar una remesa que el backend marcó como "requiere formulario".
2. **Las alertas no bloquean**: incluso `nivel === 'critico'` (`LISTA_RIESGO`) es puramente informativo; el botón Confirmar sigue habilitado.
3. **Si `verificarUif` falla, el error se traga** (`error: () => { this.verificandoUif = false; }`, 2082-2084): `uifResult` queda `null` y `requierePcc08` conserva su valor previo (`false` si es el primer cálculo). `canRegister` no exige que exista `uifResult`, por lo que **se puede registrar sin verificación UIF efectiva**.
4. `checkUif` usa **`monto_enviado_x`**, que con `incluir_comision = 1` ya **incluye la comisión** (`718`), inflando el dolarizado y pudiendo cruzar un umbral de más.
5. El PCC-08 sólo captura `dinero_si`, `select_per`, `nombres_dd`, `doc_nit_dd`, `domicilio_dd`, `telefono_dd`, `zona_dd` (606-657). **No se envía `act_eco_dd` ni `id_departamento` ni `id_ciudad_pcc`**, aunque el backend los lee y los inserta como 0 (`CajeroRemesaService.php:1110-1113`, 1211-1214).

---

## 5. Formas de pago (Chile) y formas de cobro (Bolivia)

### Pago en Chile — `formasPagoChile` (`/cajero/formas-pago-chile`)
El contrato define `requiere_cuenta` (`remesa.service.ts:115-119`) y el backend lo expone (73-81), pero el frontend **ignora ese flag** y decide con `formaPagoChile === 2` hardcodeado (377, 2002, 2044). En el resultado se muestra la descripción que devuelve el backend (`forma_pago_chile`, 950) y el banco (`banco_chile`, 951-953). El banco y la cuenta viajan **dentro del destinatario**: `id_banco_chile` y `cuenta` (2002-2003), y el backend los lee según `id_forma_pago_chile` (`1062-1063`), guardándolos en `destinatario.id_banco_chile` / `destinatario.cuenta` (2823, 2834).

### Cobro en Bolivia — `formasPagoCobro` + cobro mixto
- Catálogo en `/cajero/formas-pago-cobro` (`remesa.service.ts:285-287`); el componente asume que **`id_forma_pago === 1` es efectivo** (línea 885: si es distinto de 1, exige cuenta bancaria y referencia).
- Cuentas bancarias: `CajeroService.cuentasBancariasCobro()` (`/cajero/cuentas-bancarias-cobro`), **filtradas en la plantilla a moneda `'BOB'`** (890). La clave de selección es `entidad|numCuenta` (`cuentaKey`, 2112-2114) y `onSelectCuenta` copia `num_cuenta_bancaria`, `id_entidad_financiera`, `id_agencia`, `id_moneda`, `id_tipo_cuenta_bancaria` (2116-2128).
- Botón "+ Agregar método (mixto)" (904) y quitar por índice (899). Indicador visual de cuadre:
```html
<!-- 906-909 -->
<span class="cobro-total"
      [class.cobro-ok]="totalMetodosPago === comisionResult!.bob.total_operacion"
      [class.cobro-err]="totalMetodosPago !== comisionResult!.bob.total_operacion">
  Total: {{ totalMetodosPago | number:'1.2-2' }} / {{ comisionResult!.bob.total_operacion | number:'1.2-2' }} BOB
</span>
```
  **Es sólo un color**: no hay validación que impida confirmar con un descuadre.
- Al abrir el diálogo, si hay **un solo** método, se autocompleta `metodosPago[0].monto = comisionResult.bob.total_operacion` (1940-1942). Con **varios** métodos, no se autocompleta ni se prorratea nada.
- El payload envía `metodos_pago[]` con `id_forma_pago`, `monto`, `referencia`, `observaciones: ''` y los cinco campos de cuenta (2033-2043).
- **Backend**: recorre los métodos, suma a efectivo (`fpId === 1`) o a banco (resto) e inserta en `pos_metodo_pago_operacion` (1237-1277); si efectivo y banco son ambos ≤ 0 usa `$totalBob` (1280-1282); calcula `$ratioCash = efectivo / (efectivo + banco)` (1284-1285) y **prorratea el ITF y la comisión en BOB que entran a caja sólo por la porción efectivo** (1307, 1330); la porción bancaria genera filas en `movimiento_cuenta_bancaria` con conciliación `QC` (QR, forma 3) o `TF` (resto) (1343-1380). **No valida que la suma de métodos cuadre con `total_bob`.**

---

## 6. Comprobante, factura y recibo compartido

- **PDF**: generados en el backend, 80 mm térmico para el comprobante (`CajeroRemesaService.php:1651-1653`) y factura en `facturaPdf` (1883). El POS los consume por `GET /cajero/comprobante-remesa-pdf/{id}` y `/factura-remesa-pdf/{id}` (`api.php:55-58`, `remesa.service.ts:317-323`), con `Content-Type: application/pdf` inline (`CajeroRemesaController.php:256-278`).
- **Visualización**: `abrirPdfPopup()` (2188-2212) descarga el Blob, crea object URL, sanea con `bypassSecurityTrustResourceUrl` y lo muestra en `<iframe class="pdf-iframe">` (1032-1034). `imprimirPdf()` hace `iframe.contentWindow.print()` (2214-2221). `cerrarPdfPopup()` revoca el object URL (2223-2230). El mismo bloque está **duplicado literalmente** en `historial-remesa.component.ts:689-730`.
- **Facturación electrónica (SIAT)**: `facturarRemesa` (2538-2573) construye una operación ficticia (`mon_rec = mon_ent = 2`, `tipo_cambio = 1`, recibido/entregado = `comision_bob`) y delega en `FacturacionService::facturarCambio(...)` con `CODIGO_SERVICIO_REMESA`; los errores se capturan y devuelven `['error' => ..., 'numero_factura' => 0]` (2570-2572), **sin abortar el registro**. El POS muestra `numero_factura`, `estado` y `cuf` (976-980).
- **Compra USD** (opcional, sólo con moneda USD): `registrarCompraUsd` (2575) usa `getCotizacionOficialVenta(1)` (2580-2581); el POS muestra monto USD, T.C. oficial venta, total BOB y nº de factura de compra (984-996).
- **Recibo compartido — `recibo-remesa.component.ts`: NO SE USA.** El grep sobre todo `src` sólo encuentra sus propias definiciones (líneas 5, 34, 37, 129, 130); ningún módulo lo importa ni usa el selector `app-recibo-remesa`. Es un componente huérfano con su propio SCSS (`recibo-remesa.component.scss`). Incluye QR (`angularx-qrcode`), logo, cabecera, remitente/destinatario/operación, firmas cajero/cliente, nota de términos y marca de `RE-IMPRESION` (123-125). En el POS real **no hay reimpresión ni marca de reimpresión**; el historial permite reabrir los PDF pero nada indica que sea copia.
- Imprimir el comprobante **nunca** dispara la impresión desde el paso Resultado: hay que abrir el modal y pulsar "Imprimir" (1019-1022).

---

## 7. Historial de remesas (`historial-remesa.component.ts`)

- **Dos pestañas**: `remesas` y `cotizaciones` (16-25); la segunda se carga de forma perezosa (`switchTab`, 658-663).
- **Filtros de remesas** (31-66): Desde, Hasta (**por defecto hoy/hoy**, `ngOnInit` 631-636), Estado, Código, y un buscador de texto libre en la tabla (80-81, `filteredRemesas` 619-629 sobre remitente, destinatario, destino, moneda y código).
- **Estados ofrecidos en el filtro** (44-51): `Todos`, `0 Pendiente`, `1 Pagado`, `2 Devuelto`, `5 Anulado`. Coincide con el mapa del backend `[0=>'PENDIENTE',1=>'PAGADO',2=>'DEVUELTO',5=>'ANULADO']` (1584).
- **Tabla** (83-133): Código, Fecha, Remitente, Destinatario, Destino, Moneda, Monto, Total [BOB], Estado (badge con color por estado) y acciones: ver comprobante (`verComprobante`, 682-687) y abrir PDF de comprobante / factura (122-127).
- **Modal de comprobante** (232-329): lee `comprobante-remesa/{id}` (`remesa.service.ts:309-311` → `obtenerComprobante`, backend 1526) y muestra código, `fec_crea_remesa`, agencia, destino, remitente, destinatario, monedas, monto, T.C. (5 decimales), total BOB y comisión.
- **Totales**: **no hay totales**. Existe un `count-badge` con el número de filas (62-64) pero ningún agregado de montos/comisiones. El modelo `HistorialTotals` de `models.ts:292-303` pertenece al historial de **cambio**, no a remesas.
- **Pestaña Cotizaciones** (146-229): filtro por estado (`vigente`/`aplicada`/`expirada`), tabla con código, moneda, destino, monto, estado, válida hasta, creada y link a la remesa aplicada. El backend limita a 200 filas (`1611-1631`) y **calcula la expiración al vuelo** sin persistirla (1636, 1643).
- Campos que el backend devuelve y la UI **no usa**: `subtipo` y `subtipoDesc` (`'R' → 'Remesa Familiar'`, si no `'Giro Internacional'`, backend 1604), `correlativo`, `tipoCambio`, `totalCambio`, `comisionBob`.

---

## 8. Problemas, riesgos y bugs

### 8.1 Divergencias y confianza en el cliente (criticidad alta)
1. **El backend no recalcula la comisión**: persiste `comisionData` del POS (`CajeroRemesaService.php:1065-1078`) y sólo valida `monto >= 1` y el tope máximo. Un POS con datos manipulados fija libremente comisión, IVA, IT, ITF y total.
2. **El backend no valida que `total_bob` cuadre con los métodos de cobro** (`1280-1285`): un descuadre se acepta y se prorratea silenciosamente sobre el ITF y la comisión de caja.
3. **`incluir_comision` es ignorado en la rama preferencial** (firma sin el parámetro, `934`; llamada en `606`), mientras la UI lo ofrece y rotula "(incluida)" (840, 966).
4. **`upsertUsuarioFinanciero` asigna `id_ciudad` desde `id_expedicion`**: `$idCiudad = (int) ($c['id_expedicion'] ?? 0);` (`2710`, usado en `2734`). El `cliente.id_ciudad` que envía el POS (2239, `models.ts:105`) **nunca se usa**; la ciudad del remitente queda con el valor de la expedición.
5. **Upsert de destinatario por documento, sin remitente** (`2805-2815`) y `UPDATE` que sobreescribe datos de otra relación remitente↔destinatario (2818-2838).
6. **Campos capturados y nunca enviados** en el registro: `act_eco_dd`, `id_departamento`, `id_ciudad_pcc`, `itf_bob` (el backend los lee en `1110-1113` y `1079` y los persiste 0/null), y `id_destinatario` (1760), ignorado.
7. **`email` no se persiste** en `usuario_financiero` ni en `destinatario` (2713-2745, 2818-2856), aunque se captura y se usa para notificar (1479).

### 8.2 Cálculo
8. **División por cero latente**: `$gambarteBob = round(($comisionBob / $comisionTotal) * $gambartePorc, 2);` (`642`, y `645`, `871-872`). Si `total_comision` es 0 (tramo sin comisión configurada), PHP 8 lanza `DivisionByZeroError`.
9. **`comisionHabitualBOB`**: el IT se suma a la "comisión total" y luego **se recalcula** sobre el reparto (868, 880), por lo que `total ≠ monto + reparto + IVA + IT`; el IVA incluye el IT en su base (869).
10. **Redondeos asimétricos**: `bob.total_operacion` a 1 decimal frente a 2 en `moneda_x` (706 vs 715; 996 vs 1005); el IVA/IT en USD se derivan de bases ya redondeadas en BOB (633-637).
11. **Reparto inconsistente en USD**: la rama `$idMoneda === 1` (`676-688`) anula `gambarteBob/X/Porc` en el `else` pero no en el `if`; la línea 681 pone `$corresponsalPorc = 0` después de haberlo incrementado (680).
12. **ITF en preferencial** usa `$cotOficial` (960) mientras en habitual USD usa el T.C. de **compra** (640): dos bases distintas para el mismo impuesto.

### 8.3 Flujos del componente
13. **Rama CLP muerta**: `comisionHabitualCLP` (762-851) es inalcanzable porque el selector sólo ofrece USD/BOB (447-448), pese a que `monedaAbr` contempla `'3' → 'CLP'` (1585).
14. **Modo equivalente roto con comisión preferencial**: la sonda de `resolverEquivalenteBob` (1883-1896) recibe una respuesta sin `equivalente_usd` (rama preferencial, 998-1010) y muestra "Error obteniendo tipo de cambio".
15. **Cálculo duplicado por carrera**: en `calcularComision()` la rama no-directa retorna tras el `subscribe` (1874) y, aparte, `onMontoChange()`/`onDestinoChange()` ya han disparado `calcTrigger$` con 600 ms de debounce (1821-1834, 1666-1668) ⇒ **dos llamadas a `calcular-comision`** y posible parpadeo del desglose.
16. **`forkJoin` en `ngOnInit`**: si falla cualquiera de las 7 cargas, la pantalla queda inservible (1660-1663).
17. **`nuevaRemesa()` no resetea `tcOficialVenta`** (2146-2186): el badge de T.C. oficial de la operación anterior persiste (507-509).

### 8.4 UIF
18. **`requiere_formulario` no bloquea ni obliga a nada** (sólo texto, 552-553).
19. **Las alertas no bloquean**: `LISTA_RIESGO` crítico es informativo (570-590).
20. **Fallo de `verificarUif` silenciado** (2082-2084) + `canRegister` no exige `uifResult` (1620-1625) ⇒ registro sin control UIF.
21. **`checkUif` recibe `monto_enviado_x`**, que puede incluir la comisión (1929 con `718`).
22. **`operaciones_previas` mostradas corresponden a la ventana de PCC-08**, no a la del formulario (backend 362-366 usa `$opsPrevPcc08`), por lo que el "N ops en N días" puede no explicar la exigencia de formulario.
23. **`track alerta.tipo`** (571, 864) supone tipos únicos; si el backend devolviera dos alertas del mismo tipo, `@for` lanzaría error de claves duplicadas.

### 8.5 Duplicación, código muerto y validaciones faltantes
24. **`abrirPdfPopup` / `imprimirPdf` / `cerrarPdfPopup`** están duplicados entre `remesa.component.ts:2188-2230` y `historial-remesa.component.ts:689-730` (≈45 líneas).
25. **Código muerto**: `recibo-remesa.component.ts` completo (ningún consumidor), `RemesaService.buscarDestinatario` (215-221), `RemesaService.comprobantePdfUrl` (313-315, nunca llamado; se usa `descargarComprobantePdf`).
26. **Símbolos importados y no usados** en `remesa.component.ts:7`: `CompraUsdResult` y `MetodoPago` (el array `metodosPago` usa un tipo estructural anónimo, 1536-1543).
27. **Sin TODO/FIXME**: el grep de `TODO|FIXME|HACK|XXX|console.log` sobre `src` no devuelve ningún resultado real. **No hay `console.log` en producción.**
28. **Validaciones ausentes**: no hay validación de formato de documento, de email (el `input type="email"` no se valida porque no hay `form`/`ngModel` con validador), de NIT, ni de celular. `canRegister` no exige **comisión calculada coherente con lo mostrado**, ni que el monto no haya cambiado después de calcular.
29. **Recalcular no invalida la cotización aplicada**: si el usuario aplica una cotización, `calcularComision()` sigue habilitado (el botón depende sólo de `canCalculate`, 525) y puede reemplazar el `comisionResult` del snapshot. El backend registraría el nuevo snapshot con el `id_cotizacion` de la cotización vieja (`2047`) — inconsistencia entre `pos_cotizacion_remesa.snapshot_comision` y lo persistido.
30. **Marca de reimpresión ausente**: el backend tiene el concepto (`recibo-remesa.component.ts:123-125`) pero el POS no distingue primera impresión de reimpresión (§6).
31. **Colores inconsistentes** entre módulos: naranja `#F5820A` en remesa (`1053`) y `#F58634` en historial-remesa (`448`), con consecuencias visuales menores.

---

## 9. Manejo de estado

- **No hay signals**: el grep de `signal(|computed(|effect(|WritableSignal` sobre todo `src` **no devuelve resultados**. El componente usa propiedades mutables de clase + *change detection* por defecto, y `@if` / `@for` del nuevo control flow de Angular 17+ (standalone components, sin `NgModule`).
- **Wizard**: unión de literales `type Step` (línea 15) sobre una única propiedad `step` (1475). No hay enum real ni máquina de estados; la navegación es asignación directa (`step = 'destinatario'` en 1742, `step = 'operacion'` en 1792, `step = 'resultado'` en 2054) y los botones "Volver" también asignan directo (303, 398, 416, 754). `stepIndex` (1580-1582) se deriva con un `indexOf` sobre un array — el indicador de pasos depende de mantener ese array sincronizado con el tipo.
- **Reactividad**: un `Subject<void>` privado `calcTrigger$` + `debounceTime(600)` (1470-1471, 1666-1668) para el autocálculo, con limpieza en `ngOnDestroy` (1671-1673). El resto es `[(ngModel)]` con `FormsModule`.
- **Estado por paso** (1456-1561): `clienteDoc`, `cliente`, `clienteEncontrado: boolean | null` (tri-estado: `null` = sin buscar, `false` = nuevo, `true` = encontrado), `showClienteForm`, `destinatario`, `destinatariosPrevios`, `destinatarioSeleccionado`, `destinatarioNuevo`, `formaPagoChile`/`bancoChile`/`cuentaDestinoChile`, `monedaEnvio`/`selectedDestino`/`montoEnvio`/`tipoComision` (todos como **string** para los `<select>`, convertidos con `+` al enviar), `comisionResult`, `uifResult`/`requierePcc08`/`pcc08`, `metodosPago`, `cotizacionAplicada`, `resultado`, estado del modal PDF.
- **Estado duplicado / derivable manualmente**: `requierePcc08` replica `uifResult.requiere_pcc08` (2080) y debe recordarse resetearlo en **cinco** sitios (`onMonedaChange` 1801, `onModoMontoChange` 1817, `nuevaRemesa` 2170, y `checkUif`). `monedaAbr` (1584-1587) y `montoLabel`/`montoPlaceholder` (1604-1614) son getters derivados, pero los guards `clienteReady`, `destinatarioReady`, `canCalculate`, `canRegister` (1589-1625) contienen toda la validación de negocio del cliente, dispersa en cuatro getters sin pruebas.
- **Sin estado persistido**: recargar la página en medio del wizard pierde todo; sólo la cotización (código de 8 caracteres) permite retomar, y sólo si no expiró (4 h).

---

## Anexo — Contrato efectivo POS ⇄ API (remesa)

| Acción POS | Método/Endpoint | Backend |
|---|---|---|
| Cargar destinos | `GET /cajero/destinos` | `CajeroRemesaController.php:24` |
| Buscar remitente | `GET /cajero/buscar-cliente` | `CajeroCambioController::buscarCliente` |
| Destinatarios frecuentes | `GET /cajero/destinatarios-remitente` | `:48` |
| Cotización Chile (CLP/USD) | `GET /cajero/cotizacion-chile?id_destino` | `:129` |
| Calcular comisión | `POST /cajero/calcular-comision` | `:99` |
| Dolarización / umbrales UIF | `POST /cajero/verificar-uif-remesa` | `:62` |
| T.C. oficial venta | `GET /cajero/tc-oficial-venta` | `:79` |
| Bancos / formas de pago Chile | `GET /cajero/bancos-chile`, `/formas-pago-chile` | `:84`, `:89` |
| Formas de cobro BOB | `GET /cajero/formas-pago-cobro` | `:94` |
| Crear cotización | `POST /cajero/cotizacion-remesa` | `:141` |
| Recuperar cotización | `GET /cajero/buscar-cotizacion-remesa?codigo` | `:167` |
| **Registrar remesa** | `POST /cajero/registrar-remesa` | `:182` |
| Comprobante (datos / PDF) | `GET /cajero/comprobante-remesa/{id}`, `...-pdf/{id}` | `:247`, `:256` |
| Factura PDF | `GET /cajero/factura-remesa-pdf/{id}` | `:268` |
| Historial / cotizaciones | `GET /cajero/historial-remesa`, `/cotizaciones-remesa` | `:280`, `:297` |

Todas bajo `middleware(['jwt','role:1,2,4,6'])` (`api.php:9`). El POS **no aplica ninguna verificación de rol en el cliente** (`app.routes.ts:24,32`; `shell.component.ts:48,58`): un rol no autorizado ve los menús y sólo falla al llamar la API.

**Payload de `registrar-remesa`** (`remesa.component.ts:2006-2049`) frente a lo que el backend lee (`CajeroRemesaService.php:1046-1118`):

| Enviado por el POS | Leído por el backend | Observación |
|---|---|---|
| `id_operacion: 16` | `[4,16]` permitidos | OK (`CajeroRemesaController.php:197`) |
| `uuid` (crypto.randomUUID) | regex UUID | OK (`:219`) |
| `operacion.monto`, `mon_rec`, `mon_ent`, `tipo_cambio: 1`, `total_cambio` | sí | múltiplo de `montoEnviado` |
| `operacion.tipo_cambio_clp`, `total_clp` | sí | el backend recalcula `total_clp` si viene 0 (`1147`) |
| `operacion.origen`, `destino_pcc` | `origen`, `destino` | `destino_pcc` se guarda como **`concepto`** en la respuesta (`1519`) |
| `operacion.dinero_si`, `select_per`, `nombres_dd`, `doc_nit_dd`, `domicilio_dd`, `telefono_dd`, `zona_dd`, `impresion_pcc08` | sí | OK |
| `operacion.pago_comision` | `pago_comision` | OK |
| `operacion.monto_bob` | **ignorado** | el backend usa `comisionData.monto_enviado_bob` |
| `operacion.compra_usd`, `nit_compra` | sí | dispara `registrarCompraUsd` (`1453`) |
| `operacion.metodos_pago[]` | sí | sin validación de cuadre (`1280-1285`) |
| `operacion.id_forma_pago_chile` | sí | decide banco/cuenta (`1062-1063`) |
| `destinatario.id_banco_chile`, `cuenta` | sí | OK |
| `destinatario.id_destinatario` | **ignorado** | re-upsert por documento |
| `destinatario.email`, `cliente.email` | sólo para notificar | no se persisten |
| `comision` (snapshot completo) | persistido y usado como verdad | **clave del riesgo §2.1** |
| `id_cotizacion` | marca la cotización aplicada | `CajeroRemesaController.php:240-242` |
| — (no enviado) | `act_eco_dd`, `id_departamento`, `id_ciudad_pcc`, `itf_bob` | quedan en 0/null |

---

## Conclusión priorizada

1. **Alto** — Comisión, impuestos y total se persisten tal como los manda el POS, sin recálculo ni cotejo previo al `INSERT` (`CajeroRemesaService.php:1065-1078`). Es el riesgo de integridad financiera más importante.
2. **Alto** — `incluir_comision` no se aplica con comisión preferencial (`934`, `606`), pero la UI lo presenta como si aplicara (840, 966).
3. **Alto** — Sin verificación UIF efectiva si `verificar-uif-remesa` falla (2082-2084, 1620-1625); `requiere_formulario` y las alertas de listas no bloquean.
4. **Alto** — Sin validación de cuadre de los métodos de cobro, ni en el POS (sólo color, 906-909) ni en el backend (1280-1285).
5. **Medio** — `id_ciudad` del remitente se llena con `id_expedicion` en el backend (2710); upsert de destinatario sin remitente (2805-2815).
6. **Medio** — División por cero latente con `total_comision = 0` (642, 645, 871-872) y redondeos/identidades inconsistentes en BOB y USD (868-880, 706 vs 715).
7. **Medio** — Modo equivalente roto con comisión preferencial (1883-1896) y doble cálculo por debounce (1870-1874, 1666-1668).
8. **Bajo** — Código muerto (`recibo-remesa` completo, `buscarDestinatario`, `comprobantePdfUrl`, rama CLP), duplicación del bloque PDF (2188-2230 ≈ 689-730), imports sin usar (línea 7) y campos del historial sin renderizar (`subtipo`, `totalCambio`, `comisionBob`).

*Sin TODO/FIXME ni `console.log` en el código de producción analizado. Cuando un dato no está claro en el frontend (por ejemplo si `forma_pago_chile = 2` es el único valor que requiere cuenta, o si existe una restricción por destino en los umbrales UIF) se ha indicado explícitamente en el texto en lugar de asumirlo.*
