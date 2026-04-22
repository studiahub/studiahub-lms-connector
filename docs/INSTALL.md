# Instalación — StudiaHub LMS Connector

Tres formas de instalar/usar este plugin según el contexto.

---

## A. Instalación en WordPress real (cliente productivo)

Para cualquier WordPress en producción (hosting compartido, VPS, etc.).

### Requisitos previos

- WordPress ≥ 6.8
- PHP ≥ 8.1
- WooCommerce ≥ 8.0 instalado y activo
- Advanced Custom Fields ≥ 6.0 instalado y activo (free es suficiente)
- Permalinks configurados en `/%postname%/` (Settings → Permalinks → Post name)

### Pasos

1. Descargar el `.zip` del release más reciente desde [Releases](https://github.com/studiahub/studiahub-lms-connector/releases).
2. WP Admin → Plugins → Añadir nuevo → Subir plugin → seleccionar el `.zip` → Instalar ahora → Activar plugin.
3. Si falta WooCommerce o ACF, el plugin no se va a activar y va a mostrar un error con la lista de dependencias faltantes. Instalar las que falten y reintentar.
4. WP Admin → Settings → StudiaHub LMS:
   - Generar la **API key** (botón "Generar"). Va a aparecer una sola vez — copiala y guardala en lugar seguro.
   - Pegar la **URL del LMS** (ej: `https://academia.cliente.com`).
   - Anotar el **webhook secret** que se muestra (lo necesitás en el siguiente paso).
5. Configurar el webhook nativo de WooCommerce:
   - WP Admin → WooCommerce → Settings → Advanced → Webhooks → Add webhook
   - **Name:** StudiaHub LMS
   - **Status:** Active
   - **Topic:** Order created (crear uno por cada topic relevante: created, updated, refunded)
   - **Delivery URL:** `<URL del LMS>/api/webhooks/woocommerce`
   - **Secret:** el webhook secret del paso 4
   - **API Version:** WP REST API Integration v3
   - Save
6. En el panel admin del LMS (Settings del tenant), pegar la API key + URL de WC. Probar conexión → debería retornar OK.

---

## B. Entorno de desarrollo con Docker

Para desarrollo local del plugin o pruebas de integración con el LMS local.

### Requisitos previos

- Docker Desktop o Docker Engine ≥ 24
- Docker Compose v2 (incluido en Docker Desktop)
- Puerto `8080` libre

### Pasos

```bash
# Desde el root del repo
cd .docker

# Levantar WP + MariaDB (en background)
docker compose up -d

# Esperar ~10 segundos a que arranquen, después correr el init
docker compose --profile init run --rm wpcli
```

El script `wp-init.sh` instala WordPress, configura permalinks, instala WooCommerce + ACF + activa el plugin. Es **idempotente**: corre 2 veces no rompe nada.

Al terminar vas a ver:

```
═══════════════════════════════════════════════════════
  ✅ Setup completo
═══════════════════════════════════════════════════════
  URL:   http://localhost:8080
  Admin: http://localhost:8080/wp-admin
  User:  admin
  Pass:  admin
═══════════════════════════════════════════════════════
```

### Operaciones comunes

```bash
# Ver logs en vivo
docker compose logs -f wp

# Frenar todo (preserva data)
docker compose down

# Reset completo (borra DB y wp-content/uploads — el plugin sigue intacto porque vive en el filesystem del host)
docker compose down -v

# Re-correr init después de un reset
docker compose up -d && docker compose --profile init run --rm wpcli

# Entrar al container del WP por shell
docker compose exec wp bash

# Correr WP-CLI ad-hoc
docker compose --profile init run --rm wpcli wp plugin list
```

### Hot-reload del plugin

El folder `plugin/studiahub-lms-connector/` está montado como volumen al WP container. Cualquier cambio en el código PHP se ve reflejado inmediatamente sin reiniciar nada.

### Cómo se conecta al LMS local

El WP container puede llegar al LMS corriendo en `localhost:3000` del host usando `http://host.docker.internal:3000`. Esto está configurado via `extra_hosts` en `docker-compose.yml`. Cuando configures el webhook de WC, usar esa URL para que llegue al LMS local.

---

## C. Troubleshooting

### "El plugin no se activa, falta dependencia"

Mensaje típico: `StudiaHub LMS Connector requiere los siguientes plugins activos: WooCommerce`.

Solución: instalar y activar primero WooCommerce y/o ACF, después activar este plugin.

### "404 al llamar /wp-json/studiahub/v1/health"

Causas comunes:
1. Permalinks no están en pretty (Settings → Permalinks → Post name → Save).
2. El plugin no está activo (Plugins → verificar).
3. El servidor web no permite los rewrite rules (Apache: chequear `.htaccess`; Nginx: chequear `try_files`).

### "401 Unauthorized desde el LMS"

La API key del header `Authorization: Bearer ...` no coincide con la guardada en WP. Regenerar desde Settings → StudiaHub LMS y volver a pegar en el LMS.

### "El webhook de WC no llega al LMS local (Docker)"

Si el LMS está en `localhost:3000` y el WP en Docker, el WP no puede llegar a `localhost` (es loopback del container). Usar `http://host.docker.internal:3000/api/webhooks/woocommerce` como Delivery URL del webhook.

### "Cambios en el plugin no se ven reflejados (Docker)"

El plugin se monta como volumen, los cambios deberían aparecer al instante. Si no:
1. Verificar que el path del volumen en `docker-compose.yml` apunta al folder correcto.
2. Hard refresh del navegador (Cmd+Shift+R en Mac).
3. WP cachea hooks: probar `wp cache flush` desde el wpcli.

### "MySQL connection error en el primer arranque"

La DB tarda unos segundos en estar lista. El healthcheck del compose ya espera, pero si igual falla:

```bash
docker compose down
docker compose up -d
# esperar 15 segundos
docker compose --profile init run --rm wpcli
```
