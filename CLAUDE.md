# CLAUDE.md — StudiaHub LMS Connector

Contexto para cualquier agente que abra este repo. Leé también el [README](README.md) (arquitectura, release), [docs/INSTALL.md](docs/INSTALL.md) (setup Docker) y **[docs/LANDING-PAYLOAD.md](docs/LANDING-PAYLOAD.md) — el diccionario de TODAS las variables dinámicas que la landing recibe del LMS** (nombres exactos, tipos, qué renderiza cada una). Leelo antes de tocar cualquier cosa de la landing.

Para dar de alta un tenant nuevo o debuggear precios/landing vacía, mirá **[docs/multimoneda-y-troubleshooting.md](docs/multimoneda-y-troubleshooting.md)** (cadena de la landing, regla de oro de multimoneda `woocommerce_currency` = principal del LMS, WOOCS vs Booster, qué postmeta esperar).

🔴 **Si la landing muestra precios, fechas o textos que ya no son los del curso, leé [docs/landing-desactualizada.md](docs/landing-desactualizada.md) ANTES de tocar nada.** Ese caso ya nos costó horas de diagnóstico por perseguir hipótesis equivocadas, y la guía arranca con la heurística que lo resuelve en minutos. Lo más importante de todo: `Landing_Fetch::get_payload()` **muta estado** — cada vez que lo llamás para medir, arregla el síntoma sin querer y te borra la evidencia. Para mirar sin tocar está `GET /wp-json/studiahub/v1/landing-status`.

## Qué es
Plugin de WordPress que conecta WooCommerce con el StudiaHub LMS. Renderiza la **landing del curso en vivo** desde el LMS con dos shortcodes:
- `[studiahub_course_page]` — variante "refinada".
- `[studiahub_course_pitch]` — variante DTC / conversión (hero grande, countdown, CTA).

🟢 **La landing oficial en producción es `[studiahub_course_pitch]`** (la variante DTC/pitch con countdown y cajitas flotantes). Es la que corren TODOS los tenants. Todo cambio de landing va sobre el **pitch** ([class-shortcode-coursepitch.php](plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php)) salvo que se pida lo contrario. `[studiahub_course_page]` es una variante secundaria que hoy **no está en uso** — no la toques a menos que te lo indiquen. Detalle en [docs/shortcodes.md](docs/shortcodes.md).

El plugin se **auto-actualiza** en cada WP vía GitHub Releases. **Un release impacta a TODOS los clientes a la vez** — cuidado con lo que se mergea a `main`.

## Cómo llega un cambio a los WordPress
Pushear a `main` **NO** actualiza nada. Solo lo hace publicar una **GitHub Release**:
1. Bump de versión en 3 lugares: header `Version:` **y** `SLC_VERSION` en `plugin/studiahub-lms-connector/studiahub-lms-connector.php`, y `Stable tag:` + entrada de changelog en `plugin/studiahub-lms-connector/readme.txt` (los 3 números iguales).
2. Commit + push a `main`.
3. `bin/release.sh` (necesita `gh` autenticado y working tree limpio).

> ⚠️ Si `bin/release.sh` falla con `"workflow" scope may be required`, **el scope casi
> nunca es el problema**: es que la cuenta **activa** de `gh` no es la dueña del repo
> (hay 3 logueadas). Verificá con `gh auth status` cuál tiene `Active account: true` y
> cambiá con `gh auth switch --hostname github.com --user studiahub`. Dejá la cuenta
> activa como estaba cuando termines.

## El mock de la landing (dev local) — IMPORTANTE
La landing se dibuja desde un payload del LMS. Para trabajar el **diseño sin el LMS corriendo**, hay un mock:

- **Archivo:** [.docker/dev-mock/payload.json.disabled](.docker/dev-mock/payload.json.disabled)
- **Activar / desactivar:** `make mock-on` / `make mock-off` (renombra a `payload.json`). Con `make mock-status` ves el estado.
- **Cómo funciona:** el mu-plugin [.docker/mu-plugins/zz-dev-mock-payload.php](.docker/mu-plugins/zz-dev-mock-payload.php) intercepta el fetch al LMS (filter `slc_landing_payload_override`) y devuelve este JSON. Se relee en cada request: editás el archivo y refrescás, sin reiniciar.
- **Previsualizar:** `http://localhost:8080/?slc_test_render=1&variant=pitch&id=<ID>` (o `variant=page`). El `<ID>` tiene que ser un producto con `_lms_course_id` cargado.
- El mock contiene **todos los campos** que los shortcodes saben renderizar (es un superset). Los bloques `_comment` y `_editing` dentro del JSON explican los toggles (fechas futuras, `salesClosed`, curso sin reseñas, sin trailer, etc.).

> ⚠️ El mock es la fuente de verdad SOLO en dev. Producción usa el payload real del LMS. Si en local ves un campo que en un tenant real no aparece, puede ser que ese tenant no lo tenga cargado — no que el plugin esté roto.

### Regla para el agente (leer antes de tocar la landing)
Cuando el usuario quiera **cambiar algo de la landing, previsualizar otra info, mover una fecha, probar un curso "en vivo", ver el estado sin reseñas / inscripciones cerradas**, etc.:
1. **Avisale explícitamente** que esa data sale de este mock (`.docker/dev-mock/payload.json.disabled`), no del LMS.
2. **Ofrecele editarlo vos** por él (cambiar la fecha, vaciar reseñas, togglear `salesClosed`, etc.) para que vea la variante que necesita — o explicale qué campo tocar si prefiere hacerlo él.
3. Si las fechas del mock quedaron en el pasado (mirá `courseStartAt` / `offerDeadlineAt`), corrélas al futuro para que el countdown y el timer de oferta se vean activos.

## Cómo se mantiene la landing al día

La landing no se lee del LMS en cada visita: se cachea en tres capas (transient 15 min → transient 7 días → **option sin vencimiento**). Esa tercera capa existe para que una caída del LMS no deje la página vacía, pero tiene un costo: **una copia mala se puede servir para siempre**, porque solo la reemplaza un fetch exitoso.

Lo que mantiene la cadena viva:
- El LMS avisa de cada cambio (`POST /cache-bust`), y el plugin **invalida y trae el contenido nuevo en el momento** — no espera a que entre un visitante.
- Un cron horario del plugin refresca lo que haya vencido, empezando por los más atrasados, y avisa en el panel si algo quedó viejo.
- Un canario horario del lado del LMS compara lo publicado contra lo real y alerta en Sentry si no se corrige solo.

Detalle completo en **[docs/observabilidad-landing.md](docs/observabilidad-landing.md)**. Si tocás algo de esta cadena, tené presente que `Purchase_Gate` corre en **cada visita, antes del contenido** — ahí nació el peor bug que tuvo este plugin.

## Estructura
- `plugin/` — el plugin (lo único que se empaqueta y llega a los WP).
- `.docker/` — entorno de dev (WP + MariaDB), mu-plugins de dev y el mock. **No** se distribuye.
- `bin/` — `package.sh` (zip) y `release.sh` (tag + GitHub Release).

## Estilo
Comentarios en español; código y nombres en inglés. No over-engineer. Antes de un release, confirmar con el usuario (impacta a todos los clientes).
