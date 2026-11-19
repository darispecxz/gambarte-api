# Integración de recargas Chile → POS Bolivia (fase piloto)

## Topología

- Angular solo consume `GET/POST /api/cajero/recargas/*` en Laravel (JWT + roles 1/2/4/6).
- Laravel firma solicitudes HMAC hacia `https://<host-chile>/api/integracion/recargas.php/...`.
- Chile conserva la verdad de las solicitudes (`recarga`, `detalle_recarga`) y **temporalmente** las líneas y saldos (`linea`, `saldo_linea`). No se migran saldos en esta fase.
- La API no ejecuta la recarga contra una operadora: el operador debe hacerla manualmente y luego confirmar. La confirmación descuenta el saldo en Chile.

## Configuración (sin secretos en Git)

En Laravel, definir `RECARGAS_CHILE_URL` (HTTPS, sin barra final), `RECARGAS_CHILE_CLIENT_ID`, `RECARGAS_CHILE_SECRET` (>=32 caracteres aleatorios). Limpiar caché de configuración.

En PHP Chile, definir `RCR_INTEGRATION_CLIENT_ID`, `RCR_INTEGRATION_SECRET` idénticos a los de Laravel, y ejecutar previamente `database/migrations/integracion_recargas.sql` en una ventana controlada y con backup. Ajustar Apache/reverse proxy para exponer PATH_INFO y comunicar HTTPS correctamente a PHP.

## Firma HMAC v1

`SHA256_HMAC(secret, METHOD + "\\n" + exact-path + "\\n" + SHA256(raw-body) + "\\n" + unix-timestamp + "\\n" + nonce)` en hexadecimal minúscula. Se firma la ruta, no el host. La API de este piloto no admite query parameters firmados. `X-Client-ID`, `X-Timestamp`, `X-Nonce`, `X-Signature` obligatorios. Ventana ±60 s; nonce hex de 16 bytes único y persistido por clave cliente/nonce. El mantenimiento debe borrar nonces >2 minutos.

## Estados y límites

1. `GET pendientes`: últimos 200 detalles con `estado=0` sin reservas vigentes.
2. `POST {id}/reservar` con `request_id` UUID: reserva exclusiva por 5 minutos. Repetir el mismo ID devuelve la misma reserva mientras esté vigente.
3. El operador realiza la recarga real fuera del sistema.
4. `POST {id}/confirmar` requiere `request_id`, `token`, `id_linea`; la API Chile bloquea las filas, valida proveedor y saldo y actualiza detalle y saldo en la misma transacción. Repetir confirmación devuelve el estado confirmado sin otro descuento.
5. `POST {id}/liberar` libera una reserva aún no confirmada.

## Requisitos antes del despliegue

- **No habilitar simultáneamente los endpoints legacy `paga_recarga.php` y la API nueva sin protección compartida**: el legacy no participa de la reserva y podría descontar dos veces. Deshabilitar el procesamiento legacy o adaptarlo a la misma ruta transaccional.
- Verificar en staging que `config.php` y las clases de conexión Chile carguen sin iniciar redirecciones de sesión. Ninguna credencial debe exponerse en la respuesta.
- Ensayar firma válida/inválida, timestamp fuera de ventana, nonce repetido, reserva competidora, saldo insuficiente, dos confirmaciones simultáneas y corte de conexión después del COMMIT.
- No automatizar reintentos de confirmación cuando el operador no tenga certeza del resultado; consultar detalle/bitácora en Chile antes de reenviar.
- Las firmas autentican servidores, no sustituyen la autorización JWT por operador ni la evidencia del procesamiento manual.
- No ejecutar las migraciones SQL en producción sin aprobación y backup comprobado.
