# StudiaHub LMS Connector

Plugin de WordPress que conecta WooCommerce con [StudiaHub LMS](https://github.com/studiahub/studiahub-lms) para sincronización unidireccional de cursos como productos WC y procesamiento de webhooks de compra.

> **Última versión:** ver [Releases](https://github.com/studiahub/studiahub-lms-connector/releases/latest). El plugin se auto-actualiza en cada WP.

---

## Qué hace

- Renderiza la landing del curso **en vivo desde el LMS** con los shortcodes `[studiahub_course_page]` (refinado) y `[studiahub_course_pitch]` (estilo DTC) — **sin ACFs**. La data se fetchea de `GET /api/wc/courses/:id/landing-payload` del LMS y se cachea en un transient (15 min fresh + stale-while-revalidate de 7 días). El branding del tenant se inyecta dinámicamente via CSS vars.
- Sincroniza cursos del LMS como productos WC: endpoint REST `POST /wp-json/studiahub/v1/course-sync` que recibe el push del LMS y crea/actualiza productos atómicamente (incluye pricing multi-moneda).
- Conexión automática (OAuth-style) con el LMS: el pairing genera las credenciales y **registra el webhook de compras solo**, sin configuración manual.
- Procesa compras via webhook de WooCommerce (topics `order.created` + `order.updated`, entrega síncrona) que pega al LMS para crear el enrollment.
- Expone `GET /wp-json/studiahub/v1/health` para test de conexión desde el LMS.
- **Se auto-actualiza** desde las [Releases](https://github.com/studiahub/studiahub-lms-connector/releases) de este repo (vía Plugin Update Checker): cada WP detecta versiones nuevas y las instala solo con el cron, igual que un plugin del repo oficial. Desactivable por sitio con `define('SLC_AUTO_UPDATE', false)` en `wp-config.php`.

## Arquitectura

LMS = single source of truth. La landing se renderiza en vivo con los datos que el LMS expone en su `landing-payload` (cacheados en WP con un transient corto); WC guarda los cursos como productos solo para el pricing y el checkout. El admin del cliente trabaja casi siempre en el LMS.

Detalle completo del contrato y plan en el [repo del LMS](https://github.com/studiahub/studiahub-lms) → `docs/integration-wc-plan.md`.

---

## Instalación

Ver [docs/INSTALL.md](docs/INSTALL.md) para:
- A) Instalar en un WP real (zip + activar + conectar desde el LMS)
- B) Levantar entorno de desarrollo con Docker
- C) Troubleshooting

## Publicar una actualización (release)

Cada WP con el plugin se actualiza solo cuando se publica una **GitHub Release** nueva. Para sacar una versión:

1. Bumpeá la versión en `plugin/studiahub-lms-connector/studiahub-lms-connector.php` (header `Version:` **y** la constante `SLC_VERSION`), el `Stable tag:` de `readme.txt`, y agregá la entrada al changelog.
2. Commiteá y pusheá a `main`.
3. Corré `bin/release.sh` — empaqueta el `.zip` y crea el tag + la Release adjuntando el `.zip` como **asset** (imprescindible: el plugin no vive en la raíz del repo, así que el zipball automático de GitHub instalaría la estructura mal).

> La primera versión con auto-update (0.13.0) hay que instalarla **a mano una última vez** en cada WP que tenga ≤ 0.12.0; las versiones viejas no traen el sistema de updates. De ahí en adelante, automático.

## Requisitos

- WordPress ≥ 6.8
- PHP ≥ 8.1
- WooCommerce ≥ 8.0 — **única dependencia de plugin** (el connector la verifica al activar)
- Permalinks en `/%postname%/`

## Estructura del repo

```
.docker/        # entorno de desarrollo (no se incluye en el zip distribuido)
plugin/         # el plugin en sí (lo que se zippea para distribuir)
bin/            # scripts de build/release
docs/           # documentación
dist/           # builds generados (gitignored)
```
