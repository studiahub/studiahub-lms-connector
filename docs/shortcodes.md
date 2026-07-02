# Shortcodes — StudiaHub LMS Connector

El plugin expone **dos** shortcodes. Cada uno renderiza la **landing completa** de un curso (hero, descripción, temario, instructores, precios, FAQ, etc.) trayendo todo el contenido **en vivo desde el LMS** (el `landing-payload` del tenant).

> 🟢 **La landing oficial en uso es `[studiahub_course_pitch]`.** Es la que corren los tenants en producción. Cualquier cambio de diseño/funcional de la landing va sobre el **pitch** salvo que se pida explícitamente lo contrario. `[studiahub_course_page]` es una variante **secundaria** que hoy NO está en uso — no la toques a menos que te lo indiquen puntualmente.

> **No usan ACF.** El contenido se administra en el LMS, no en WordPress. (El modelo viejo basado en ACFs `sh_course_*` y shortcodes por sección quedó obsoleto.)

| Shortcode | Estado | Estilo |
|-----------|--------|--------|
| `[studiahub_course_pitch]` | 🟢 **Oficial / en producción** | Landing estilo DTC / pitch: hero grande con foto + cajitas, countdown de inicio, social proof, combos, garantía. |
| `[studiahub_course_page]`  | ⚪ Secundaria (no en uso) | Landing "página de curso" (refinada). |

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

- **Branding del tenant** (colores de marca y de texto) → se inyecta como CSS vars en el wrapper de la landing.
- **Imagen del hero / portada** → `thumbnailUrl` del payload (se muestra a su proporción natural).
- **Imagen de "Por qué tomar este curso"** → `landingImageUrl` del payload (si no hay, no se muestra media en ese slot).
- **FAQ** → `faq[]` del payload; la respuesta (`faq[].a`) admite **HTML** (listas, negritas, enlaces).
- Precios, instructores, temario, social proof, etc. → del mismo payload.

## Tipografía

La landing **hereda la tipografía global de Elementor** (Site Settings → Typography), **no** del LMS:

- **Cuerpo** → la font **Text** de Elementor (`--e-global-typography-text-font-family`).
- **Títulos** (h1–h6) → la font **Accent** de Elementor (familia **y** peso — por eso no quedan con un bold fijo).

Así cada sitio toma su propia tipografía automáticamente. Si el sitio no usa Elementor, cae a la `fontFamily` del branding del LMS y, en último caso, a la del tema. **Configurá las fonts en Elementor, no en el LMS.**

Contrato completo del payload: [repo del LMS](https://github.com/studiahub/studiahub-lms).
