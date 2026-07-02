# Referencia del payload de la landing (variables que vienen del LMS)

Este documento lista **todos los campos dinámicos** que las landings reciben del LMS y cómo los usa el plugin. Es la referencia para no inventar campos ni trabajar sobre nombres que no existen.

## De dónde sale esta data
- La landing se renderiza en vivo desde el LMS: `GET {LMS}/api/wc/courses/:id/landing-payload` (ver [class-landing-fetch.php](../plugin/studiahub-lms-connector/includes/class-landing-fetch.php)). El plugin **no transforma** el JSON: lee las claves tal cual llegan.
- Los dos shortcodes consumen el mismo payload:
  - `[studiahub_course_page]` → [class-shortcode-coursepage.php](../plugin/studiahub-lms-connector/includes/class-shortcode-coursepage.php)
  - `[studiahub_course_pitch]` → [class-shortcode-coursepitch.php](../plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php)
- **Fuente de verdad de esta tabla = esos dos archivos** (lo que el plugin realmente lee y renderiza). Este repo no tiene el LMS; si dudás de si el LMS manda un campo, confirmalo en el repo del LMS.
- En dev, esta misma estructura se mockea en [.docker/dev-mock/payload.json.disabled](../.docker/dev-mock/payload.json.disabled) (`make mock-on`). Preview: `http://localhost:8080/?slc_test_render=1&variant=pitch&id=<ID_producto>`.

> **Regla de trabajo:** todos estos valores son dinámicos por curso/tenant. Nunca hardcodees un valor en el HTML/CSS del shortcode: si algo tiene que ser configurable, tiene que salir de uno de estos campos. Cualquier campo puede venir vacío/ausente → el template lo oculta. Diseñá para el caso "sin dato" también.

Convención de columnas: **Dónde** = en qué shortcode se usa (`ambos` / `pitch` / `page`). **Opcional** = qué pasa si no viene.

---

## Identidad y textos

| Campo | Tipo | Dónde | Qué hace / notas |
|---|---|---|---|
| `lmsId` | string | interno | ID del curso en el LMS. No se renderiza; en dev el mock lo sobreescribe con el `_lms_course_id` del producto. |
| `title` | string | ambos | Título del curso (H1). Fallback: título del producto WC. |
| `subtitle` | string | ambos | Subtítulo bajo el H1. |
| `shortDescription` | string | ambos | Descripción corta. En pitch se usa como subtítulo si falta `subtitle`. |
| `longDescription` | HTML | ambos | Prosa del bloque "Por qué tomar este curso". Se sanitiza con `wp_kses_post` + `wpautop` (permite `<p>`, `<ul>`, `<strong>`, etc.). |
| `tenantName` | string | ambos | Nombre de la academia/tenant. |
| `category` | string | ambos | Categoría del curso. |
| `highlightBadge` | string | ambos | Badge destacado (ej "Actualizado 2026", "-30%"). Texto libre. |
| `ctaLabel` | string | ambos | Texto del botón de compra. Fallback: "Quiero inscribirme". |

## Imágenes y media

| Campo | Tipo | Dónde | Qué hace / notas |
|---|---|---|---|
| `thumbnailUrl` | url | ambos | Imagen principal: hero (columna derecha, pitch) + pricing card. |
| `landingImageUrl` | url | pitch | Imagen del bloque "Por qué" **cuando no hay trailer**. Si hay `trailerUrl`, gana el trailer. |
| `trailerUrl` | url | ambos | YouTube/Vimeo. Se parsea a una fachada con play + embed diferido. |
| `requirementsImageUrl` | url | pitch | Imagen del bloque "Requisitos". Fallback: `thumbnailUrl`. |

## Precio y oferta

| Campo | Tipo | Dónde | Qué hace / notas |
|---|---|---|---|
| `priceDisplay` | string | ambos | Precio formateado, multimoneda (ej "USD 1.000 / ARS 1.400.000"). Se muestra tal cual. Fallback: precio del producto WC. |
| `price` | number | ambos | Precio numérico. Fallback interno para formatear si no hay `priceDisplay`. |
| `compareAtPrice` | string | ambos | Precio regular tachado (texto libre multimoneda). No calcula descuento. |
| `installmentsLabel` | string | ambos | Ej "o 12 cuotas sin interés". |
| `offerDeadlineAt` | ISO datetime \| null | ambos | Deadline de la oferta → timer "La oferta termina en X". El LMS manda `null` si ya venció. A < 48 hs pasa a countdown vivo (JS). |
| `salesClosed` | bool | pitch | `true` → botón "Inscripciones cerradas" deshabilitado (reemplaza el CTA). |
| `paymentMethods[]` | array | pitch | Logos de medios de pago en la pricing card. Cada item: `{ name, logoUrl }`. |

## Metadata del curso (chips)

| Campo | Tipo | Dónde | Qué hace / notas |
|---|---|---|---|
| `courseType` | enum | ambos | `on_demand` \| `live` \| `in_person` \| `hybrid` → chip "On demand / En vivo / Presencial / Híbrido". |
| `level` | string | ambos | Nivel (ej "Intermedio", "Intermedio / Avanzado"). |
| `language` | string | ambos | Idioma. |
| `durationHours` | int | ambos | Duración total en horas (chip). |
| `totalDurationMin` | int | ambos | Duración total en minutos. |
| `hasCertificate` | bool | ambos | `true` → chip "Certificado". |
| `modulesCount` | int | ambos | Cantidad de módulos. |
| `lessonsCount` | int | ambos | Cantidad de lecciones. |
| `liveSessionsCount` | int | pitch | Cantidad de encuentros en vivo (chip). |

## Fecha de inicio y countdown (pitch)

| Campo | Tipo | Dónde | Qué hace / notas |
|---|---|---|---|
| `courseStartAt` | ISO datetime | pitch | Fecha de inicio. Activa **dos cosas**: la barra superior con countdown, y la fecha "Inicia 12 de agosto" arriba del título (esta última se muestra aunque el curso no tenga reseñas). |
| `liveSessionAt` | ISO datetime | pitch | Fallback de `courseStartAt` si este no viene. |
| `courseStartLabel` | string | pitch | Texto del countdown. Default: "El curso comienza en". |

## Instructores

| Campo | Tipo | Notas |
|---|---|---|
| `instructors[]` | array | Cada item: `{ id, name, title, bio, photoUrl }`. `name` es obligatorio (sin él, el item se saltea). Sin `photoUrl` se muestra la inicial. |

## Bloques de contenido (arrays)

| Campo | Tipo | Dónde | Qué hace / notas |
|---|---|---|---|
| `learningOutcomes[]` | array | ambos | "Al terminar vas a poder…". Item: `{ title, desc, icon }`. `icon` = clase Flaticon (`fi-tr-*`). Soporta `{ text }` legacy. |
| `targetAudience[]` | array | ambos | "A quién está dirigido". Strings, o `{ text }`. |
| `includedMaterials[]` | array | ambos | Materiales incluidos. Item: `{ text, icon }`. En pitch, los primeros 3 salen como cajitas flotantes del hero; todos van en la checklist de la pricing card. |
| `requirements[]` | array | ambos | Requisitos previos. Strings, o `{ text }`. |
| `bonuses[]` | array | ambos | Stack de bonos. Item: `{ title, desc, value, imageUrl }`. `title` obligatorio. |
| `faq[]` | array | ambos | Preguntas frecuentes. Item: `{ q, a }`. El LMS ya mergea `Course.faq` → `Tenant.defaultFaq`. |

## Temario (`outline`)

| Campo | Tipo | Notas |
|---|---|---|
| `outline[]` | array | Módulos. Item: `{ title, durationMin, isLive, mode, lessons[] }`. |
| `outline[].isLive` | bool | `true` → badge "EN VIVO" en el módulo. Alternativa: `mode: "live"`. |
| `outline[].durationMin` | int | Duración del módulo. Si es 0, se suma de las lecciones. |
| `outline[].lessons[]` | array | Lecciones. Item: `{ title, type, durationMin, free, liveAt }`. |
| `lessons[].type` | enum | `VIDEO` \| `TEXT` \| `PDF` → ícono de la lección. |
| `lessons[].durationMin` | int | Duración de la lección. |
| `lessons[].liveAt` | ISO datetime | Si viene, la lección muestra ícono live + la fecha, en vez de la duración. |
| `lessons[].free` | bool | ⚠️ Viene en el payload pero **el plugin hoy NO lo renderiza**. (Candidato a "clase gratis / preview" si algún día se implementa.) |

## Reseñas y prueba social

| Campo | Tipo | Notas |
|---|---|---|
| `reviews[]` | array | Item: `{ author, avatarUrl, rating, comment, createdAt }`. `author` (string ya formateado) + `rating` ≥ 1 son obligatorios. **La sección se oculta si hay < 3 reseñas válidas.** Con 6+ pasa a marquee de 2 filas; con 3-5, grid estático. NO usar `{ user: { firstName } }` (ese es formato de DB, no de payload). |
| `reviewStats` | object | `{ count, average }`. Alimenta el rating de estrellas (soporta fracción, ej 4,7). |
| `socialProof` | object | `{ studentsCount, studentsLabel, stats[] }`. `studentsLabel` es un override manual del "+200 alumnos". `stats[]` = `{ num, label, icon }` (máx 3 custom en la barra). |

## Garantía y branding

| Campo | Tipo | Notas |
|---|---|---|
| `guarantee` | object \| null | `{ title, text }`. Si viene `null`, se ocultan la sección y el mini-badge. |
| `branding.primaryColor` | hex | CSS var color primario del tenant. |
| `branding.secondaryColor` | hex | CSS var de acento (pitch). |
| `branding.fontFamily` | string | Google Font; si no está disponible se ignora (en prod, la tipografía se hereda de Elementor). |
| `branding.logoUrl` | url \| null | Logo del tenant. Reservado — estos dos shortcodes hoy no lo renderizan. |

---

## Cómo previsualizar variantes
Editá [.docker/dev-mock/payload.json.disabled](../.docker/dev-mock/payload.json.disabled) (con el mock activo, `make mock-on`) y refrescá. Casos útiles:
- **Curso nuevo (sin reseñas):** `reviews: []` + `reviewStats: {count:0, average:0}`.
- **Countdown activo:** `courseStartAt` en el futuro.
- **Oferta con timer:** `offerDeadlineAt` en el futuro (a < 48 hs, countdown vivo).
- **Inscripciones cerradas:** `salesClosed: true`.
- **Sin trailer:** borrá `trailerUrl` (usa `landingImageUrl`).

Ver también los bloques `_comment` / `_editing` dentro del JSON.
