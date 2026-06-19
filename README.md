# StudiaHub LMS Connector

Plugin de WordPress que conecta WooCommerce con [StudiaHub LMS](https://github.com/studiahub/studiahub-lms) para sincronización unidireccional de cursos como productos WC y procesamiento de webhooks de compra.

> **Versión actual:** 0.12.0.

---

## Qué hace

- Renderiza la landing del curso **en vivo desde el LMS** con los shortcodes `[studiahub_course_page]` (refinado) y `[studiahub_course_pitch]` (estilo DTC) — **sin ACFs**. La data se fetchea de `GET /api/wc/courses/:id/landing-payload` del LMS y se cachea en un transient (15 min fresh + stale-while-revalidate de 7 días). El branding del tenant se inyecta dinámicamente via CSS vars.
- Sincroniza cursos del LMS como productos WC: endpoint REST `POST /wp-json/studiahub/v1/course-sync` que recibe el push del LMS y crea/actualiza productos atómicamente (incluye pricing multi-moneda).
- Conexión automática (OAuth-style) con el LMS: el pairing genera las credenciales y **registra el webhook de compras solo**, sin configuración manual.
- Procesa compras via webhook de WooCommerce (topics `order.created` + `order.updated`, entrega síncrona) que pega al LMS para crear el enrollment.
- Expone `GET /wp-json/studiahub/v1/health` para test de conexión desde el LMS.

## Arquitectura

LMS = single source of truth. La landing se renderiza en vivo con los datos que el LMS expone en su `landing-payload` (cacheados en WP con un transient corto); WC guarda los cursos como productos solo para el pricing y el checkout. El admin del cliente trabaja casi siempre en el LMS.

Detalle completo del contrato y plan en el [repo del LMS](https://github.com/studiahub/studiahub-lms) → `docs/integration-wc-plan.md`.

---

## Instalación

Ver [docs/INSTALL.md](docs/INSTALL.md) para:
- A) Instalar en un WP real (zip + activar + conectar desde el LMS)
- B) Levantar entorno de desarrollo con Docker
- C) Troubleshooting

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
