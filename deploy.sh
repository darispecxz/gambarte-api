#!/bin/bash
# ============================================================
# deploy.sh — Despliegue de CgrApi Laravel 8 en Hostinger
#
# Ejecutar DENTRO del servidor via SSH:
#   bash deploy.sh
#
# Prerequisitos: haber subido cgrapi-laravel.tar.gz al home (~/)
# ============================================================

set -e

DOMAIN_ROOT="${DEPLOY_DOMAIN_ROOT:?Define DEPLOY_DOMAIN_ROOT con la ruta del proyecto en el servidor}"
PUBLIC_HTML="$DOMAIN_ROOT/public_html"
BACKUP_DIR="$DOMAIN_ROOT/backup_$(date +%Y%m%d_%H%M%S)"
PHP="/opt/alt/php83/usr/bin/php"
TARBALL="$HOME/cgrapi-laravel.tar.gz"

echo "=== CgrApi Laravel 8 — Despliegue ==="
echo ""

# ── 1. Verificar PHP ────────────────────────────────────────
echo "[1/8] Verificando PHP..."
$PHP -v | head -1
echo ""

# ── 2. Verificar tarball ────────────────────────────────────
if [ ! -f "$TARBALL" ]; then
    echo "ERROR: No se encontró $TARBALL"
    echo "Sube el archivo primero con:"
    echo "  scp -P <puerto> cgrapi-laravel.tar.gz <usuario>@<servidor>:~/"
    exit 1
fi

# ── 3. Backup de archivos actuales ──────────────────────────
echo "[2/8] Respaldando archivos actuales en $BACKUP_DIR ..."
mkdir -p "$BACKUP_DIR"
if [ -d "$PUBLIC_HTML" ] && [ "$(ls -A $PUBLIC_HTML 2>/dev/null)" ]; then
    cp -a "$PUBLIC_HTML" "$BACKUP_DIR/public_html_old"
fi
# Respaldar archivos Laravel existentes en domain root (si los hay)
for item in app bootstrap config routes storage vendor artisan composer.json composer.lock .env; do
    if [ -e "$DOMAIN_ROOT/$item" ]; then
        cp -a "$DOMAIN_ROOT/$item" "$BACKUP_DIR/" 2>/dev/null || true
    fi
done
echo "   Backup creado en: $BACKUP_DIR"
echo ""

# ── 4. Extraer proyecto ────────────────────────────────────
echo "[3/8] Extrayendo proyecto..."
TEMP_DIR=$(mktemp -d)
tar -xzf "$TARBALL" -C "$TEMP_DIR"
echo "   Extraído en: $TEMP_DIR"
echo ""

# ── 5. Copiar archivos Laravel al domain root ──────────────
echo "[4/8] Instalando archivos Laravel..."

# Carpetas que van al domain root (fuera de public_html)
for dir in app bootstrap config routes storage cron database resources tests; do
    if [ -d "$TEMP_DIR/$dir" ]; then
        rm -rf "$DOMAIN_ROOT/$dir"
        cp -a "$TEMP_DIR/$dir" "$DOMAIN_ROOT/$dir"
    fi
done

# Archivos sueltos al domain root
for file in artisan composer.json server.php; do
    if [ -f "$TEMP_DIR/$file" ]; then
        cp -f "$TEMP_DIR/$file" "$DOMAIN_ROOT/$file"
    fi
done

# .env: solo copiar si no existe (no sobreescribir credenciales)
if [ ! -f "$DOMAIN_ROOT/.env" ]; then
    cp -f "$TEMP_DIR/.env" "$DOMAIN_ROOT/.env"
    echo "   .env copiado (EDITAR credenciales si es necesario)"
else
    echo "   .env existente preservado"
fi
cp -f "$TEMP_DIR/.env.example" "$DOMAIN_ROOT/.env.example"

echo ""

# ── 6. Actualizar public_html ──────────────────────────────
echo "[5/8] Actualizando public_html..."

# Limpiar archivos PHP antiguos de public_html (pero NO borrar la carpeta)
# Solo reemplazamos index.php, .htaccess y router.php
cp -f "$TEMP_DIR/public/index.php" "$PUBLIC_HTML/index.php"
cp -f "$TEMP_DIR/public/.htaccess" "$PUBLIC_HTML/.htaccess"

# Borrar archivos del framework antiguo que ya no se necesitan en public_html
# (el index.legacy.php es solo referencia, no se sube)
for old_file in router.php index.legacy.php; do
    rm -f "$PUBLIC_HTML/$old_file" 2>/dev/null || true
done

echo "   public_html actualizado"
echo ""

# ── 7. Permisos ────────────────────────────────────────────
echo "[6/8] Configurando permisos..."
chmod -R 775 "$DOMAIN_ROOT/storage" "$DOMAIN_ROOT/bootstrap/cache"
# Crear directorios de cache si no existen
mkdir -p "$DOMAIN_ROOT/storage/framework/cache/data"
mkdir -p "$DOMAIN_ROOT/storage/framework/sessions"
mkdir -p "$DOMAIN_ROOT/storage/framework/views"
mkdir -p "$DOMAIN_ROOT/storage/logs"
mkdir -p "$DOMAIN_ROOT/bootstrap/cache"
# Cache del dashboard de riesgos
mkdir -p "$DOMAIN_ROOT/app/Legacy/riesgo_cambiario/cache"
chmod 775 "$DOMAIN_ROOT/app/Legacy/riesgo_cambiario/cache"
echo "   Permisos configurados"
echo ""

# ── 8. Composer install ────────────────────────────────────
echo "[7/8] Instalando dependencias con Composer..."
cd "$DOMAIN_ROOT"

# Verificar si composer está disponible
if command -v composer &> /dev/null; then
    COMPOSER="composer"
elif [ -f "$HOME/composer.phar" ]; then
    COMPOSER="$PHP $HOME/composer.phar"
elif [ -f "/usr/local/bin/composer" ]; then
    COMPOSER="$PHP /usr/local/bin/composer"
else
    echo "   Composer no encontrado. Instalando composer.phar..."
    cd "$HOME"
    $PHP -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    $PHP composer-setup.php
    $PHP -r "unlink('composer-setup.php');"
    COMPOSER="$PHP $HOME/composer.phar"
    cd "$DOMAIN_ROOT"
fi

$COMPOSER install --no-dev --optimize-autoloader --no-interaction 2>&1
echo ""

# ── 9. APP_KEY ─────────────────────────────────────────────
echo "[8/8] Generando APP_KEY..."
$PHP artisan key:generate --force
echo ""

# ── Limpieza ───────────────────────────────────────────────
rm -rf "$TEMP_DIR"
echo "=== Despliegue completado ==="
echo ""
echo "Verificar con:"
echo "  curl https://<dominio>/api/health"
echo ""
echo "Backup disponible en: $BACKUP_DIR"
echo "Si algo falla, restaurar con:"
echo "  cp -a $BACKUP_DIR/public_html_old/* $PUBLIC_HTML/"
echo ""
