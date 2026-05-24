=== StudiaHub LMS Connector ===
Contributors: studiahub
Tags: lms, woocommerce, e-learning, acf
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.6.0
License: MIT

Conecta WooCommerce con StudiaHub LMS para sync unidireccional de cursos y procesamiento de webhooks de compra.

== Description ==

Plugin que extiende WooCommerce con la integración a StudiaHub LMS:

* Registra ACFs fijos en productos WC con los datos del curso (read-only en la UI).
* Expone endpoint REST `POST /wp-json/studiahub/v1/course-sync` para que el LMS pushee productos.
* Expone endpoint REST `GET /wp-json/studiahub/v1/health` para test de conexión.
* Página de settings para generar API key y ver URL del webhook a configurar en WC.

== Installation ==

1. Subir el .zip desde Plugins → Añadir nuevo → Subir plugin.
2. Activar.
3. Si falta WooCommerce o ACF, activar esos primero.
4. Settings → StudiaHub LMS → generar API key.
5. Pegar la API key en el panel admin del LMS (Settings del tenant).

== Changelog ==

= 0.6.0 =
* Shortcode `[studiahub_course_page]` ahora lee TODA la data de marketing del payload del LMS (pricing de oferta, bonuses, garantía, FAQ, social proof real). Se eliminan los hardcodes — cada sección oculta si no hay data. Reseñas: se quita el fallback fake; si el curso no tiene reseñas aprobadas, la sección no se renderiza. Agrega `aggregateRating` al JSON-LD cuando hay reviews reales.

= 0.1.0 =
* Versión inicial. Bootstrap del plugin con verificación de dependencias (WC + ACF).
