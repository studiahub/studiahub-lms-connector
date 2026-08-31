=== StudiaHub LMS Connector ===
Contributors: studiahub
Tags: lms, woocommerce, e-learning, courses
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.19.0
License: MIT

Vendé tus cursos de StudiaHub LMS desde WooCommerce, con alta automática de alumnos.

== Description ==

Plugin que extiende WooCommerce con la integración a StudiaHub LMS:

* Renderiza la landing del curso en vivo desde el LMS con los shortcodes `[studiahub_course_page]` y `[studiahub_course_pitch]` (estilo DTC), sin ACFs. El branding del tenant se inyecta dinámicamente.
* Sincroniza cursos del LMS como productos WC via `POST /wp-json/studiahub/v1/course-sync` (incluye pricing multi-moneda).
* Conexión automática (OAuth-style) con el LMS: registra el webhook de compras (`order.created` + `order.updated`) sin configuración manual.
* Expone `GET /wp-json/studiahub/v1/health` para test de conexión (con `?check_lms=1` también verifica la vuelta: que este WP alcance al LMS).
* Expone `GET /wp-json/studiahub/v1/landing-status` con la radiografía de la caché de la landing (qué copia se sirve y hace cuánto), para que el LMS detecte contenido desactualizado sin depender de que alguien mire la página.
* Expone `GET /wp-json/studiahub/v1/orders/recent` para que el LMS reconcilie las compras cuyo webhook nunca llegó.
* Cierra la venta de verdad: un curso en preventa o con las inscripciones cerradas no se puede agregar al carrito ni pagar.
* Auto-actualización: el plugin chequea las releases de GitHub y se actualiza solo, igual que un plugin del repo oficial. Sin tocar nada en cada sitio.

== Installation ==

1. Subir el .zip desde Plugins → Añadir nuevo → Subir plugin.
2. Activar (requiere WooCommerce activo — es la única dependencia).
3. Settings → Permalinks → Post name, y guardar.
4. Conectar desde el admin del LMS (WooCommerce → Conectar WordPress): el flujo OAuth autoriza en el WP y registra el webhook automáticamente. No hay que generar API keys ni webhooks a mano.

Ver docs/INSTALL.md para el detalle del flujo de conexión.

== Changelog ==

= 0.19.0 =
* Al dejar de publicar un curso, su página de venta ahora se baja sola. Antes apagarlo en la plataforma no tocaba la tienda: la página seguía online, visible en Google y compartible por link, y había que entrar a WordPress a pasarla a borrador a mano. Al que se olvidaba le quedaba a la venta un curso que ya no vendía. Ahora pasa a borrador en el momento. Volver a publicarla sigue siendo decisión tuya: la plataforma puede bajar una página, nunca subirla.
* Los combos quedan protegidos. Si un producto está marcado como combo de cursos, vende varios a la vez: dejar de publicar uno solo de ellos no lo baja, porque eso dejaría sin venta a todos los demás. En ese caso la plataforma te avisa cuál es el producto y qué revisar, en vez de bajarlo sin decir nada.

= 0.18.0 =
* Si la página de venta deja de actualizarse, ahora te enterás. Antes podía quedar mostrando precios y fechas viejos durante semanas sin que nada fallara ni avisara: cada parte funcionaba, la página cargaba bien, y el problema solo se descubría si alguien la miraba de casualidad. Ahora el plugin controla cada hora hace cuánto que no trae contenido de la plataforma y, si quedó atrasado o no logra conectarse, lo avisa en el panel de WordPress.
* La página se refresca sola aunque nadie la visite. El contenido se renovaba únicamente cuando alguien abría la página, así que en un sitio con poco tráfico el primer visitante del día se encontraba con información vieja. Ahora se renueva por reloj, empezando siempre por los cursos más atrasados.
* Los cambios se publican al instante. Cuando la plataforma avisaba que un curso cambió, el plugin solo descartaba lo viejo: el contenido nuevo recién se buscaba cuando alguien abría la página, así que en un sitio con poco tráfico la novedad podía tardar horas en verse. Ahora lo trae en el mismo momento del aviso.
* La plataforma puede auditar por su cuenta qué está publicado. El plugin expone en qué estado está el contenido de cada curso, para que la plataforma detecte diferencias y las corrija sin depender de que alguien mire la página.
* "Probar conexión" ahora prueba las dos direcciones. Verificaba solo que la plataforma llegara a tu sitio, y por eso podía dar verde mientras el sitio no lograba traer el contenido del curso — que es la parte de la que depende la página de venta. Ahora comprueba también el camino de vuelta y avisa si está cortado.

= 0.17.2 =
* La página de venta del curso se quedaba congelada con información vieja. Cambiabas fechas, precios o textos en la plataforma, el curso se guardaba bien, pero la página seguía mostrando la versión anterior por tiempo indefinido — semanas enteras, sin ningún aviso. Ocurría porque el control que decide si un curso se puede comprar consultaba la copia de respaldo guardada, y eso hacía que la página se conformara con esa copia en vez de pedirle la información actualizada a la plataforma. Nunca volvía a preguntar, y como la copia de respaldo solo se renueva cuando se pregunta, quedaba dando vueltas sobre sí misma. Ahora cada uno usa su propia memoria y la página vuelve a actualizarse sola.

= 0.17.1 =
* Compras que se perdían según a nombre de quién hubiera quedado el aviso automático. Tu tienda arma el aviso con los permisos del usuario que lo creó; si ese usuario era un cliente (algo que podía pasar sin que nadie lo notara, incluso durante una compra), la tienda le mandaba a la academia un error de permisos en vez del pedido y nadie quedaba inscripto. Ahora el aviso se crea siempre a nombre de alguien que pueda ver los pedidos, y además el pedido se lee correctamente sin importar de quién sea el aviso. Las tiendas que ya hubieran quedado en ese estado se reparan solas.

= 0.17.0 =
* Compras que se cobraban sin inscribir a nadie, sin dejar ningún registro. Si tu tienda usa el checkout en bloques y una pasarela que aprueba el pago al instante (Stripe, WooPayments), WooCommerce le mandaba a la academia un error interno en lugar del pedido. La academia contestaba "recibido", así que el aviso figuraba entregado, el registro quedaba vacío y la venta se perdía en silencio. Las pasarelas que redirigen a otra página para pagar (MercadoPago, PayPal, transferencia) no estaban afectadas.
* Los avisos de compra pausados vuelven a andar solos. WooCommerce los pausa cuando fallan varias veces seguidas (por ejemplo si la academia estuvo unos minutos fuera de servicio) y, desde ahí, todas las compras se cobran y ninguna inscribe. Antes había que entrar al panel de WordPress para que se reactivaran; ahora se recuperan solos.
* Las ventas que igual no lleguen ahora se pueden rescatar. La academia puede preguntarle a la tienda por los pedidos completados de los últimos días y dar de alta los que le falten, sin depender de que el aviso haya llegado.
* Los combos cargados a mano ahora inscriben. Un pack armado desde el panel de WooCommerce (el camino habitual cuando te pagan por transferencia) se cobraba y no le daba acceso a ningún curso. Los pedidos de ese tipo que ya existan necesitan que uses "Entregar de nuevo" una vez.
* "Inscripciones cerradas" y "Próximamente" ahora cierran de verdad. Antes solo cambiaban el texto del botón: quien tuviera el link directo del producto (guardado, compartido por WhatsApp o indexado por Google) podía comprar igual.
* Los combos ya no se cobran convertidos por cotización. Si vendés en más de una moneda y el pack no tiene precio cargado en la moneda que eligió el comprador, la compra se frena con un aviso en vez de cobrar una conversión automática. Al marcar un producto como combo, la pantalla te avisa en amarillo qué monedas te faltan completar.
* El video de presentación solo acepta direcciones web comunes. Una dirección con formato raro podía ejecutar código en la página de venta cuando un visitante tocaba play.
* La página de venta ya no se vacía si la academia no responde. Antes perdía el temario y el contenido del curso y quedaba como un producto pelado, sin avisar nada. Ahora conserva la última copia buena. Si el curso se da de baja, sí se limpia: una landing dada de baja no debe seguir vendiendo.

= 0.16.6 =
* El aviso de compra hacia el LMS ahora espera 10 segundos como máximo en vez de 60. Con el LMS lento (no caído), cada compra dejaba un proceso del sitio ocupado hasta un minuto por cada uno de los dos avisos; con varias compras a la vez eso podía dejar la web entera sin responder, incluidas las páginas de venta. Los avisos de otros plugins no se tocan.
* Nuevo botón "Recrear webhook" en Ajustes → StudiaHub LMS. WooCommerce pausa el aviso automático cuando falla varias veces seguidas (por ejemplo si el LMS estuvo unos minutos fuera de servicio) y, a partir de ahí, las compras se cobran pero nadie queda inscripto y no queda ningún registro. Antes la única salida era desconectar y volver a conectar el sitio; ahora se recupera con un clic, sin perder la conexión. Además el plugin lo reactiva solo cuando detecta que fue WooCommerce quien lo pausó, y la pantalla de ajustes explica el estado en vez de mostrarlo en blanco.

= 0.16.5 =
* Los productos de curso quedan siempre marcados como Virtual y Descargable, tanto al crearse como en cada sincronización. WooCommerce solo cierra un pedido automáticamente al cobrar si todos sus productos tienen esas dos casillas; si faltaba alguna, el pedido se quedaba en "Procesando" para siempre y el alumno pagaba sin quedar inscripto, sin ningún cartel de error. Antes había que tildarlas a mano en cada producto. Los productos que ya estaban mal se reparan solos en la próxima sincronización del curso.

= 0.16.4 =
* Encuentros en vivo: las fechas se muestran en la zona horaria de la academia. Antes se imprimía la hora UTC y se le pegaba la etiqueta "(ARG)" fija, así que un encuentro cargado a las 10:00 de Argentina se publicaba como "13hs (ARG)". El LMS ahora manda la zona horaria en el payload y la landing convierte. Si la zona no se puede resolver, se usa Argentina y se omite la etiqueta en vez de rotular con un huso que no corresponde.
* Fecha de inicio del curso: también se muestra en la zona horaria de la academia. Usaba `date()`, que en WordPress corre en UTC y no en la zona del sitio, así que un curso que arrancaba 21hs de Argentina se anunciaba con la fecha del día siguiente.
* Ofertas: corregido el cálculo del tiempo restante, que se corría por el huso configurado en WordPress. Además de sobrestimar el "Termina en X", la oferta seguía figurando vigente y con countdown durante unas horas DESPUÉS de haber vencido (3 horas en un sitio argentino).

= 0.16.3 =
* Multimoneda: la moneda principal del LMS ahora sincroniza también la OFERTA al precio nativo del producto (`_sale_price`). Antes solo se escribía el precio regular, así que una promo cargada en la moneda principal se mostraba en la landing pero el checkout cobraba el precio lleno (y al borrarla, no se limpiaba). Si el LMS deja de mandar precio para la moneda principal, la oferta se limpia: solo la que puso el connector (queda marcada en `_studiahub_native_sale`), nunca una promo cargada a mano en WooCommerce.
* Multimoneda: en cada sync se borran los precios por moneda del switcher (WOOCS / Booster) que ya no corresponden: los de la moneda base y los de monedas que el LMS dejó de mandar. Antes, cambiar la moneda base de WooCommerce dejaba el precio fijo viejo de esa moneda pisando el precio nativo para siempre.

= 0.16.2 =
* Landing (pitch): nuevo estado "Próximamente" (preventa). Cuando el curso está marcado como preventa en el LMS, la landing se muestra pero el botón de compra queda deshabilitado con un texto configurable (default "Próximamente"), en vez del link al checkout. Tiene precedencia sobre "Inscripciones cerradas".

= 0.16.1 =
* Landing (pitch): las fechas ahora incluyen el año para que quede claro a qué año corresponden. La fecha de inicio pasa a "Inicia: 1 de marzo de 2027" (hero, plan de estudio y pricing) y las fechas de las sesiones en vivo del temario a "12 mar 2027 · 18hs (ARG)". El año sale del dato real del curso.

= 0.16.0 =
* Checkout (WOOCS): el botón de inscripción de la landing ahora fuerza el checkout en ARS (agrega `currency=ARS` a la URL y lo propaga al checkout limpio), para no depender de la config "moneda inicial" de WOOCS. Guardrail: solo se aplica si WOOCS está activo y ARS es una moneda del switcher; en Booster o tenants sin multimoneda el botón no cambia.

= 0.15.9 =
* Landing: el precio multimoneda ahora muestra siempre ARS primero (ej "ARS 699.000 / USD 699"). Cambio solo cosmético en el orden de visualización; no afecta precios, checkout ni el push de multimoneda (WOOCS/Booster). Aplica a las variantes pitch y page.

= 0.15.8 =
* Multimoneda (Booster): se corrige la detección del plugin Booster. Las versiones 4.x+ (Booster Plus 8.x) usan la clase `WC_Jetpack` y el helper `wcj_get_option`, que la detección anterior no contemplaba, por lo que los precios por moneda del LMS no se empujaban a los campos del switcher (quedaban vacíos). No afecta a los tenants con WOOCS.

= 0.15.7 =
* Landing (pitch): en mobile/tablet (hero apilado) la imagen del curso ahora va arriba del título (se invierte el orden de las columnas del hero). En desktop no cambia.

= 0.15.6 =
* Landing (pitch): en mobile/tablet (cuando el hero se apila, <=960px) se ocultan las cajitas flotantes del hero y queda solo la imagen del curso, para evitar la superposición con la foto. En desktop siguen apareciendo.

= 0.15.5 =
* Landing (pitch): ajustes de responsive en mobile — las tarjetas de "Al terminar este curso vas a poder…" pasan a 1 por fila (antes 2), y las cajitas flotantes del hero quedan fijas (sin parallax) en pantallas donde el hero se apila (<=960px), porque el movimiento las desprendía y superponía con el contenido.

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
