=== StudiaHub LMS Connector ===
Contributors: studiahub
Tags: lms, woocommerce, e-learning, courses
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.15.4
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

= 0.15.4 =
* Landings (`[studiahub_course_pitch]` y `[studiahub_course_page]`): **fix de raíz** del conflicto con `wpautop`. En vez de sanear el output para sobrevivir a `wpautop` (que rompía el HTML de mil formas: `<p>` en el grid, `<br>` en los botones, `</p><p>` en el `<script>`, `<p>` alrededor de la flecha del acordeón), ahora el shortcode NO pasa por `wpautop` en absoluto. Devuelve un placeholder de texto plano y el HTML real se inyecta en un output-buffer de la página, después de que `wpautop` ya corrió — así nunca puede tocarlo. Elimina toda esa clase de bugs de una, sin importar el contexto (página, template de producto de un block theme, bloque Shortcode del Site Editor). Verificado E2E en una página de producto WooCommerce bajo Twenty Twenty-Five. En Elementor sigue igual. Reemplaza los parches de 0.15.1–0.15.3.

= 0.15.3 =
* Landings (`[studiahub_course_pitch]` y `[studiahub_course_page]`): completa el fix de wpautop de la 0.15.2. Además de los `<p>` vacíos del grid, `wpautop` metía `<br>` dentro de los botones (`<a>`) — que quedaban de 2-3 líneas de alto, con aspecto de "mucho padding" — y `</p><p>` dentro del `<script>` inline, que rompía el JS (countdown/parallax) con `Uncaught SyntaxError: Unexpected token '<'`. Ahora el saneo del output convierte los saltos de línea en espacios fuera de los `<script>` (elimina los `<br>`/`<p>`) y protege el JS colapsando solo sus líneas en blanco. Botones a su altura correcta y JS funcionando. En Elementor sin cambios (nunca corría `wpautop`).

= 0.15.2 =
* Landings (`[studiahub_course_pitch]` y `[studiahub_course_page]`): fix del hero/layout descuadrado cuando el shortcode va en una página o en el template de producto de un block theme (fuera de Elementor). WordPress corría `wpautop` sobre el output y convertía los comentarios HTML y los saltos de línea del template en `<p>` vacíos, que se colaban como items del grid y empujaban el contenido (el título quedaba a la derecha con la izquierda vacía). Ahora el output se sanea antes de devolverse (se quitan los comentarios y se colapsa el whitespace entre tags), así `wpautop` no tiene nada que envolver. En Elementor no había cambios porque nunca corría `wpautop`.

= 0.15.1 =
* Landings (`[studiahub_course_pitch]` y `[studiahub_course_page]`): fix para que se vean bien sobre cualquier theme, no solo Hello Elementor. Un block theme opinado (Twenty Twenty-*) metía el landing bajo `.is-layout-constrained` y le clavaba el content-size del theme (~645px), aplastándolo a una columna angosta y descuadrando el hero. Ahora el landing rompe ese content-size en su contenedor raíz y renderiza a su ancho completo. No-op sobre Hello Elementor (sin cambios para los sitios existentes).

= 0.15.0 =
* Shortcodes granulares: nuevos shortcodes para exponer los campos sueltos de un curso del LMS y componer landings a medida. `[studiahub_course_field field="..."]` para campos escalares (título, precio, duración, `reviewsAverage`/`reviewsCount`, etc.) y shortcodes de loop con template interno de tokens `{{campo}}` + fallback semántico para arrays (`[studiahub_course_bonuses]`, `[studiahub_course_faq]`, `[studiahub_course_reviews]`, `[studiahub_course_instructors]`, `[studiahub_course_stats]`, `[studiahub_course_outcomes]`, `[studiahub_course_audience]`, `[studiahub_course_materials]`, `[studiahub_course_requirements]`, `[studiahub_course_outline]` + `[studiahub_course_lessons]`, `[studiahub_course_guarantee]`). Reusan el mismo payload cacheado que las landings completas. Ver docs/granular-shortcodes.md.

= 0.14.8 =
* Landing (pitch): la barra de countdown ahora usa el color de los botones del cliente (degradé de marca) en vez del fondo oscuro, con el texto en el color de texto de botón del tenant. "Inscripción abierta" en blanco y el botón "Reservar mi lugar" con esquinas de 14px (como el resto de los botones).
* Landing (pitch): responsive de la barra en mobile — el botón se muestra, todo queda centrado y se oculta el badge "Inscripción abierta" para que no quede tan alta.

= 0.14.7 =
* Landing (pitch): fix del chip de fecha de inicio en la pricing card, que en producción (Elementor) quedaba alineado a la izquierda en vez de centrado.
* Landing (pitch): los eyebrows de las secciones pierden el fondo (píldora) y pasan a ser solo texto en mayúsculas, para no competir con los chips de fecha.
* Landing (pitch): un poco más de espacio entre el título del temario y la fila de chips.

= 0.14.6 =
* Landing (pitch): el chip de fecha de inicio se unificó en el hero, el plan de estudio y la pricing card — mismo estilo (pill con el tono clarito del color del cliente) y misma etiqueta "Inicia: <fecha>". En la pricing card, la fecha va arriba del nombre del curso.
* Landing (pitch): ícono de check unificado en el checklist de la pricing card, en las cajitas flotantes del hero y en la sección "¿A quién está dirigido?".

= 0.14.5 =
* Landing (pitch): en la card de precio, el título del curso, la fecha de inicio y los items del checklist ahora quedan centrados (antes alineados a la izquierda).

= 0.14.4 =
* Landing (pitch): la fecha de inicio del curso gana visibilidad — se muestra en un chip destacado (fondo blanco) al lado del rating, primero en la fila del plan de estudio, y también en la card de precio debajo del nombre del curso.
* Landing (pitch): la barra superior con countdown ahora queda fija abajo en vez de arriba, para no quedar tapada por el menú sticky del sitio del cliente.
* Landing (pitch): las tarjetas de "Al terminar este curso vas a poder…" usan un ícono de check uniforme sobre el fondo de acento del tenant.

= 0.14.3 =
* Landing (pitch): la fecha de inicio ("Inicia 12 de agosto") ahora aparece arriba del título aunque el curso no tenga reseñas ni alumnos. Antes vivía dentro del bloque de social proof y se salteaba en cursos nuevos, aunque el countdown de la barra superior sí funcionara.

= 0.14.2 =
* Descripción larga: los bullets y la numeración del editor rico ahora se ven en la landing. El reset global de listas apagaba los marcadores dentro de la descripción del curso (`[studiahub_course_pitch]` y `[studiahub_course_page]`); se restauran solo dentro de la prosa.

= 0.14.1 =
* Checkout: el botón de inscripción ahora agrega el curso vía un endpoint propio y redirige al checkout limpio, en vez de usar `?add-to-cart=` en la URL. Soluciona el error "no se puede agregar otro producto al carrito" al recargar el checkout o al cambiar de moneda en el switcher. Permite acumular varios cursos en el carrito sin duplicar.

= 0.14.0 =
* Tipografía: la landing hereda la tipografía global de **Elementor** (Site Settings → Typography). El cuerpo usa la **Text** font y los títulos (h1–h6) la **Accent** font (familia **y** peso), así cada sitio toma su propia tipografía automáticamente. Los títulos dejan de usar un peso bold hardcodeado. Si el sitio no usa Elementor, cae a la font del branding del LMS (o la del tema). Aplica a ambos shortcodes.

= 0.13.7 =
* Hero (`[studiahub_course_pitch]`): la foto del hero ya no se fuerza a `aspect-ratio` 4/3. Forzar ese ratio escalaba/recortaba la imagen del tenant (que puede tener otra proporción — la del genograma es ~3/2) y se veía pixelada en monitores no-Retina. Ahora la imagen se muestra a su tamaño natural (nítida) y el contenedor coincide con ella, así las cajitas flotantes quedan ancladas en su lugar. Reemplaza el enfoque de 0.13.4–0.13.5. Verificado en vivo en un monitor de 27".

= 0.13.6 =
* FAQ: las respuestas con HTML del LMS (`faq[].a`) ahora muestran su formato — listas con viñetas/numeración, párrafos y enlaces. El reset global de listas del plugin (`:where(...) ul,ol { list-style:none }`) las dejaba sin viñetas; se restaura el estilo dentro de `.faq-a` en ambos shortcodes.

= 0.13.5 =
* Hero (`[studiahub_course_pitch]`): se quita el `overflow:hidden` del contenedor de la foto, que recortaba las cajitas flotantes (Videos/Pdf's/Certificado). El recorte y redondeo de la foto los hace ahora la propia `<img>` (corrige un efecto secundario de 0.13.4).
* Sección "Por qué tomar este curso" (`[studiahub_course_pitch]`): el slot de imagen usa `landingImageUrl` del payload cuando no hay trailer; si tampoco hay `landingImageUrl`, no se muestra media (ya no repite la portada). El slot solo existe en este shortcode.
* FAQ: la respuesta se renderiza con `wp_kses_post` para soportar HTML del LMS (`faq[].a`). Las FAQ de texto plano siguen funcionando. Aplica a ambos shortcodes.

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
