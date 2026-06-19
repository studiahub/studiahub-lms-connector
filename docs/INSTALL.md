# Instalación — StudiaHub LMS Connector

Tres formas de instalar/usar este plugin según el contexto.

---

## A. Instalación en WordPress real (cliente productivo)

Para cualquier WordPress en producción (hosting compartido, VPS, etc.).

### Requisitos previos

- WordPress ≥ 6.8
- PHP ≥ 8.1
- WooCommerce ≥ 8.0 instalado y activo (única dependencia de plugin del connector)
- Permalinks configurados en `/%postname%/` (Settings → Permalinks → Post name)

### Pasos

1. Descargar el `.zip` del release más reciente desde [Releases](https://github.com/studiahub/studiahub-lms-connector/releases).
2. WP Admin → Plugins → Añadir nuevo → Subir plugin → seleccionar el `.zip` → Instalar ahora → Activar plugin.
3. Si falta WooCommerce, el plugin no se va a activar y va a mostrar un error pidiéndolo. Instalarlo/activarlo y reintentar.
4. WP Admin → Settings → Permalinks → dejarlo en **Post name** (`/%postname%/`) y guardar. Necesario para que respondan las rutas REST del connector.
5. **Conectar al LMS (automático, OAuth-style).** No hace falta generar API keys ni configurar webhooks a mano — lo resuelve el flujo de conexión:
   - En el admin del **LMS**, ir a **WooCommerce** (`/admin/woocommerce`), pegar la URL pública de este WordPress (ej: `https://tienda.cliente.com`) y clickear **Conectar WordPress**.
   - El LMS te redirige a una pantalla de autorización dentro del WP ("Conectar al LMS"). Necesitás estar logueado como admin del WP. Verificá que el LMS y la URL sean los correctos y clickeá **✓ Sí, conectar**.
   - El WP genera las credenciales, se las pasa al LMS por un canal seguro (back-channel POST — nunca viajan por la URL del navegador) y **registra automáticamente el webhook de WooCommerce** (topics `order.created` y `order.updated`) apuntando a `<URL del LMS>/api/webhooks/woocommerce`.
   - Volvés al LMS ya conectado. En **WP Admin → Settings → StudiaHub LMS** vas a ver "● Conectado" y el estado del webhook.

Para **desconectar**: hacelo desde el admin del LMS (le avisa al WP y borra el webhook) o desde **WP Admin → Settings → StudiaHub LMS → Desconectar** (limpia el lado WP y desactiva el webhook). Para **reconectar**, repetí el paso 5.

> El webhook se mantiene solo: si lo borrás desde WC, se vuelve a crear al recargar el admin del WP mientras la conexión siga activa.

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

El script `wp-init.sh` instala WordPress, configura permalinks, instala y activa WooCommerce, y activa el plugin. Es **idempotente**: correrlo 2 veces no rompe nada.

> Nota: el script todavía instala ACF por compatibilidad histórica, pero el plugin **ya no lo usa** (la landing se renderiza en vivo desde el LMS). Es seguro ignorarlo.

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

El WP container llega al LMS corriendo en `localhost:3000` del host usando `http://host.docker.internal:3000` (configurado via `extra_hosts` en `docker-compose.yml`).

Para parear en dev, iniciá la conexión **desde el LMS local** apuntando al WP del Docker (`http://localhost:8080`). El flujo OAuth registra el webhook automáticamente; el delivery URL se computa desde la URL del LMS, así que el LMS tiene que identificarse como `http://host.docker.internal:3000` para que el webhook salga del container y le pegue. La pantalla de autorización permite el par `localhost` ↔ `host.docker.internal` justamente para este caso de dev.

> Para trabajar el diseño de la landing sin LMS corriendo, el mu-plugin de dev en `.docker/mu-plugins` puede inyectar un payload mockeado (`.docker/dev-mock`) via el filter `slc_landing_payload_override`.

---

## C. Troubleshooting

### "El plugin no se activa, falta dependencia"

Mensaje típico: `StudiaHub LMS Connector requiere los siguientes plugins activos: WooCommerce`.

Solución: instalar y activar WooCommerce (es la única dependencia), después activar este plugin.

### "404 al llamar /wp-json/studiahub/v1/health"

Causas comunes:
1. Permalinks no están en pretty (Settings → Permalinks → Post name → Save).
2. El plugin no está activo (Plugins → verificar).
3. El servidor web no permite los rewrite rules (Apache: chequear `.htaccess`; Nginx: chequear `try_files`).

### "401 Unauthorized desde el LMS"

El bearer token (`Authorization: Bearer ...`) no coincide con el secret guardado en WP — pasa si la conexión se rompió o se regeneraron credenciales de un solo lado. Solución: **reconectar** desde el admin del LMS (vuelve a correr el pairing y regenera el secret en ambos lados). No hay regeneración manual de API key.

### "El webhook de WC no llega al LMS local (Docker)"

Si el LMS está en `localhost:3000` y el WP en Docker, el WP no puede llegar a `localhost` (es loopback del container). El delivery URL del webhook se computa desde la URL del LMS guardada en el pairing, así que el LMS tiene que identificarse como `http://host.docker.internal:3000` para que el webhook resuelva a `http://host.docker.internal:3000/api/webhooks/woocommerce`. Si quedó mal, reconectá con la URL correcta.

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
