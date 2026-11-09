# Despliegue en Hostinger — CgrApi Laravel 8

## Requisitos
- PHP 8.3 (Hostinger lo tiene en `/opt/alt/php83/usr/bin/php`)
- Composer
- Extensiones: pdo_mysql, mbstring, openssl, tokenizer, json

## Pasos

### 1. Subir el proyecto
Subir el contenido del proyecto al directorio del dominio en Hostinger. Sustituye
`<usuario>`, `<dominio>` y `<puerto>` por los valores de tu cuenta.

### 2. Instalar dependencias
```bash
cd ~/domains/<dominio>/public_html
/opt/alt/php83/usr/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader
```

### 3. Configurar .env
```bash
cp .env.example .env
# Editar .env con las credenciales reales de DB
```

### 4. Generar APP_KEY
```bash
/opt/alt/php83/usr/bin/php artisan key:generate
```

### 5. Document root
En Hostinger, apuntar el document root a la carpeta `public/` del proyecto.

### 6. Permisos
```bash
chmod -R 775 storage bootstrap/cache
```

### 7. Cron (reconciliación)
Agregar en el cron de Hostinger:
```
0 */6 * * * /opt/alt/php83/usr/bin/php ~/domains/<dominio>/public_html/artisan reconciliation:run >> ~/domains/<dominio>/public_html/storage/logs/reconciliation.log 2>&1
```

O usar el scheduler de Laravel:
```
* * * * * /opt/alt/php83/usr/bin/php ~/domains/<dominio>/public_html/artisan schedule:run >> /dev/null 2>&1
```

### 8. Verificar
```bash
curl https://<dominio>/api/health
```

## Estructura de endpoints
Todos los endpoints mantienen el mismo formato: `/api/...`
La respuesta sigue el formato estándar:
```json
{"success": true, "data": ..., "message": null, "errors": []}
```
