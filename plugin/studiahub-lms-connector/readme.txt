=== StudiaHub LMS Connector ===
Contributors: studiahub
Tags: lms, woocommerce, e-learning, courses
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.13.4
License: MIT

Vendé tus cursos de StudiaHub LMS desde WooCommerce, con alta automática de alumnos.

== Description ==

Plugin que extiende WooCommerce con la integración a StudiaHub LMS:

* Renderiza la landing del curso en vivo desde el LMS con los shortcodes `[studiahub_course_page]` y `[studiahub_course_pitch]` (estilo DTC), sin ACFs. El branding del tenant se inyecta dinámicamente.
* Sincroniza cursos del LMS como productos WC via `POST /wp-json/studiahub/v1/course-sync` (incluye pricing multi-moneda).
* Conexión automática (OAuth-style) con el LMS: registra el webhook de compras (`order.created` + `order.updated`) sin configuración manual.
* Expone `GET /wp-json/studiahub/v1/health` para test de conexión.
* Auto-actualización: el plugin chequea las releases de GitHub y se actualiza solo, igual que un plugin del repo oficial. Sin tocar nada en cada sitio.

== Installation ==

1. Subir el .zip desde Plugins → Añadir nuevo → Subir plugin.
2. Activar (requiere WooCommerce activo — es la única dependencia).
3. Settings → Permalinks → Post name, y guardar.
4. Conectar desde el admin del LMS (WooCommerce → Conectar WordPress): el flujo OAuth autoriza en el WP y registra el webhook automáticamente. No hay que generar API keys ni webhooks a mano.

Ver docs/INSTALL.md para el detalle del flujo de conexión.

== Changelog ==

= 0.13.4 =
* Hero (`[studiahub_course_pitch]`): el `aspect-ratio` de la foto del hero se mueve al contenedor `<div>` (en vez de la `<img>`), para que sea inmune al reset de `aspect-ratio` que aplican algunos temas (Hello Elementor) a las imágenes. El intento de 0.13.3 (blindar la `<img>` con `!important`) no alcanzaba cuando el tema gana por specificity. Verificado contra un reset agresivo del tema.
* FOUC: el CSS se encola en el `<head>` también cuando la página es un producto de WooCommerce (`is_product()`), no solo cuando el shortcode está en el contenido del post. Necesario porque con Elementor Theme Builder el shortcode vive en una plantilla (post aparte) que no es detectable desde el producto.

= 0.13.3 =
* FOUC: el CSS de las landings se encola en el `<head>` (antes se cargaba tarde, desde el render del shortcode, y producía un flash de contenido sin estilo al cargar la página). Detecta el shortcode tanto en contenido clásico/Gutenberg como en Elementor (`_elementor_data`).
* Hero (`[studiahub_course_pitch]`): se blinda el `aspect-ratio` (4/3) de la foto del hero con `!important`. Algunos temas (Hello Elementor, entre otros) resetean el `aspect-ratio` de las `<img>` a nivel global, lo que apaisaba la foto y descolocaba las cajitas flotantes en pantallas anchas.
* Card de precio (`[studiahub_course_pitch]`): la imagen pasa de `aspect-ratio` 16/7 a 16/10, para que se vea más completa (consistente con la imagen de la descripción).

= 0.13.2 =
* Fix: los botones de compra e inscripción ya no toman los colores por defecto de Elementor. Se refuerza el color y el fondo de todos los CTA (en `[studiahub_course_page]` y `[studiahub_course_pitch]`) con una capa defensiva scopeada bajo el wrapper del plugin, para que el branding del tenant gane sobre el kit global de Elementor. Cada variante (degradé, invertida, outline y cerrada) conserva su color.

= 0.13.1 =
* Descripción del plugin más clara y orientada al beneficio en el listado de plugins. Sin cambios funcionales.

= 0.13.0 =
* Auto-actualización desde GitHub Releases (Plugin Update Checker). El plugin avisa de versiones nuevas en el admin y se auto-instala via el cron de cada WP, sin intervención manual. Se puede desactivar por sitio con `define('SLC_AUTO_UPDATE', false)` en wp-config.php. Nota: esta versión hay que instalarla a mano una última vez; a partir de acá las actualizaciones son automáticas.

= 0.12.0 =
* Branding: colores de texto de la landing configurables desde el LMS (títulos, cuerpo y botones). El payload del tenant ahora puede traer `titleColor` (títulos, precios y nombres), `bodyColor` (cuerpo de texto y párrafos) y `buttonTextColor` (label de los CTA de compra), aplicables en ambos shortcodes (`[studiahub_course_page]` y `[studiahub_course_pitch]`). Con los defaults (títulos `#0F172A` / cuerpo `#475569` / botón `#FFFFFF`) el render no cambia.

= 0.11.0 =
* Webhook: se registra `order.created` además de `order.updated`, para cubrir gateways que crean la orden ya completada. Entrega síncrona al LMS.
* Conexión OAuth-style automática: pairing desde el LMS (pantalla de autorización + back-channel `/exchange`), generación de credenciales y registro del webhook sin pasos manuales. Endpoint `/disconnect` para cerrar la conexión.
* La landing se renderiza en vivo desde el LMS (`landing-payload` con transient de 15 min + stale-while-revalidate). Se elimina la dependencia de ACF: la única dependencia de plugin es WooCommerce.
* Shortcode `[studiahub_course_pitch]` estilo DTC (countdown de oferta, combos, social proof) y refinamientos de `[studiahub_course_page]`.
* Multi-moneda: oferta y precios por moneda sincronizados desde el LMS.

= 0.6.0 =
* Shortcode `[studiahub_course_page]` ahora lee TODA la data de marketing del payload del LMS (pricing de oferta, bonuses, garantía, FAQ, social proof real). Se eliminan los hardcodes — cada sección oculta si no hay data. Reseñas: se quita el fallback fake; si el curso no tiene reseñas aprobadas, la sección no se renderiza. Agrega `aggregateRating` al JSON-LD cuando hay reviews reales.

= 0.1.0 =
* Versión inicial. Bootstrap del plugin con verificación de dependencias.
