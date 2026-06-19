# Shortcodes — StudiaHub LMS Connector

El plugin expone **dos** shortcodes. Cada uno renderiza la **landing completa** de un curso (hero, descripción, temario, instructores, precios, FAQ, etc.) trayendo todo el contenido **en vivo desde el LMS** (el `landing-payload` del tenant).

> **No usan ACF.** El contenido se administra en el LMS, no en WordPress. (El modelo viejo basado en ACFs `sh_course_*` y shortcodes por sección quedó obsoleto.)

| Shortcode | Estilo |
|-----------|--------|
| `[studiahub_course_page]`  | Landing "página de curso" (refinada). |
| `[studiahub_course_pitch]` | Landing estilo DTC / pitch: hero grande con foto + cajitas, countdown de oferta, social proof, combos, garantía. |

## Atributo

| Atributo | Default | Descripción |
|----------|---------|-------------|
| `id` | (vacío) | ID del producto de WooCommerce. Si se omite, usa el producto de la **página actual**. |

```
[studiahub_course_pitch]
[studiahub_course_page id="1373"]
```

## Cómo se usa

- **Lo más común:** en la plantilla/contenido de la **página de producto** de WooCommerce, sin `id` (toma el producto actual).
- **En Elementor:** insertarlo con el widget **Shortcode** (no "Editor de texto") dentro de la plantilla de producto del Theme Builder. Renderiza del lado del servidor, así que Google y los visitantes lo reciben en el HTML (bien para SEO).
- **En una página suelta** (fuera del producto): pasar el `id` explícito.

> ⚠️ Si la tienda de WooCommerce está en modo "Próximamente"/construcción, los productos no se renderizan para visitantes anónimos (solo los ves logueado). Para que la landing sea pública, la tienda tiene que estar visible.

## De dónde sale el contenido

Cada shortcode hace `GET /api/wc/courses/:id/landing-payload` al LMS y renderiza ese payload (cacheado en un transient: ~15 min fresh + stale-while-revalidate de 7 días). Detalle:

- **Branding del tenant** (colores de marca y de texto, tipografía) → se inyecta como CSS vars en el wrapper de la landing.
- **Imagen del hero / portada** → `thumbnailUrl` del payload (se muestra a su proporción natural).
- **Imagen de "Por qué tomar este curso"** → `landingImageUrl` del payload (si no hay, no se muestra media en ese slot).
- **FAQ** → `faq[]` del payload; la respuesta (`faq[].a`) admite **HTML** (listas, negritas, enlaces).
- Precios, instructores, temario, social proof, etc. → del mismo payload.

Contrato completo del payload: [repo del LMS](https://github.com/studiahub/studiahub-lms).
