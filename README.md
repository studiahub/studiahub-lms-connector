# StudiaHub LMS Connector

Plugin de WordPress que conecta WooCommerce con [StudiaHub LMS](https://github.com/studiahub/studiahub-lms) para sincronización unidireccional de cursos como productos WC y procesamiento de webhooks de compra.

> **Estado:** en desarrollo. Versión inicial 0.1.0.

---

## Qué hace

- Registra un set fijo de campos ACF en productos WC para los datos del curso (read-only en la UI de WP).
- Expone endpoint REST `POST /wp-json/studiahub/v1/course-sync` que recibe el push del LMS y crea/actualiza productos atómicamente.
- Expone endpoint REST `GET /wp-json/studiahub/v1/health` para test de conexión desde el LMS.
- Página de settings en WP admin para generar API key + ver URL del webhook a configurar en WC.

## Arquitectura

LMS = single source of truth. WP/WC consume los datos via ACFs poblados desde el LMS. El admin del cliente trabaja casi siempre en el LMS — WC queda solo para pricing.

Detalle completo del contrato y plan en el [repo del LMS](https://github.com/studiahub/studiahub-lms) → `docs/integration-wc-plan.md`.

---

## Instalación

Ver [docs/INSTALL.md](docs/INSTALL.md) para:
- A) Instalar en un WP real (zip + activar)
- B) Levantar entorno de desarrollo con Docker
- C) Troubleshooting

## Requisitos

- WordPress ≥ 6.8
- PHP ≥ 8.1
- WooCommerce ≥ 8.0
- Advanced Custom Fields ≥ 6.0 (free es suficiente)
- Permalinks en `/%postname%/`

## Estructura del repo

```
.docker/        # entorno de desarrollo (no se incluye en el zip distribuido)
plugin/         # el plugin en sí (lo que se zippea para distribuir)
bin/            # scripts de build/release
docs/           # documentación
dist/           # builds generados (gitignored)
```
