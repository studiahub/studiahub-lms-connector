#!/usr/bin/env bash
# Idempotent WP setup script.
# Corre dentro del container `wpcli` (imagen wordpress:cli con bash + wp-cli).
# Uso: docker compose --profile init run --rm wpcli

set -euo pipefail

echo "→ Esperando que wp-config.php esté listo..."
until [ -f /var/www/html/wp-config.php ]; do
  sleep 2
done
echo "✓ wp-config.php presente"

echo "→ Verificando conexión a DB..."
ATTEMPTS=0
until wp db query "SELECT 1" >/dev/null 2>&1; do
  ATTEMPTS=$((ATTEMPTS+1))
  if [ $ATTEMPTS -gt 30 ]; then
    echo "✗ DB no respondió después de 60s. Mostrando error real:"
    wp db query "SELECT 1" || true
    exit 1
  fi
  echo "  DB todavía no responde (intento $ATTEMPTS/30), reintentando..."
  sleep 2
done
echo "✓ DB OK"

if wp core is-installed 2>/dev/null; then
  echo "✓ WordPress ya instalado, skip core install"
else
  echo "→ Instalando WordPress..."
  wp core install \
    --url=http://localhost:8080 \
    --title="StudiaHub LMS Dev" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@test.local \
    --skip-email
  echo "✓ WordPress instalado"
fi

echo "→ Configurando permalinks pretty..."
wp option update permalink_structure '/%postname%/' >/dev/null
wp rewrite flush >/dev/null
echo "✓ Permalinks: /%postname%/"

echo "→ Instalando WooCommerce..."
if wp plugin is-installed woocommerce 2>/dev/null; then
  wp plugin activate woocommerce >/dev/null || true
else
  wp plugin install woocommerce --activate
fi
echo "✓ WooCommerce activo"

echo "→ Instalando Advanced Custom Fields..."
if wp plugin is-installed advanced-custom-fields 2>/dev/null; then
  wp plugin activate advanced-custom-fields >/dev/null || true
else
  wp plugin install advanced-custom-fields --activate
fi
echo "✓ ACF activo"

echo "→ Activando studiahub-lms-connector..."
if wp plugin is-installed studiahub-lms-connector 2>/dev/null; then
  wp plugin activate studiahub-lms-connector >/dev/null
  echo "✓ Plugin activo"
else
  echo "⚠ Plugin todavía no presente en plugins/ — se va a activar al estar el código"
fi

echo ""
echo "═══════════════════════════════════════════════════════"
echo "  ✅ Setup completo"
echo "═══════════════════════════════════════════════════════"
echo "  URL:   http://localhost:8080"
echo "  Admin: http://localhost:8080/wp-admin"
echo "  User:  admin"
echo "  Pass:  admin"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "  Próximo paso: ir a Settings → StudiaHub LMS"
echo "  y generar la API key para conectar con el LMS."
echo ""
