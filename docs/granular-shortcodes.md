# Shortcodes granulares — StudiaHub LMS Connector

> Audiencia: **equipo de diseño de StudiaHub** que arma landings a medida en Elementor.

Estos shortcodes exponen los **campos sueltos** de un curso del LMS para que los uses como piezas dentro de una landing propia. A diferencia de `[studiahub_course_page]` / `[studiahub_course_pitch]` — que rinden la landing **completa** con su propio diseño — los granulares exponen **DATA, no diseño**: vos ponés el markup, ellos ponen el contenido en vivo del curso.

Toda la data sale del mismo `landing-payload` del LMS (transient ~15 min + stale-while-revalidate) que usan los shortcodes monolíticos. El producto de WooCommerce solo aporta el postmeta `_lms_course_id` para mapear.

## Atributo `id`

Todos aceptan el atributo `id` (ID del producto de WooCommerce). Si se omite, usan el producto de la **página actual** (igual que los monolíticos).

```
[studiahub_course_field field="title"]           ← producto de la página actual
[studiahub_course_field field="title" id="1373"] ← fuerza el producto 1373
```

En Elementor: insertar con el widget **Shortcode** (no "Editor de texto"). Renderiza server-side (bien para SEO).

---

## 1. Campos escalares — `[studiahub_course_field]`

Un único shortcode genérico. Devuelve el valor **pelado** del campo, escapado según contexto. Campo inexistente o vacío → **string vacío** (sin warnings).

```
[studiahub_course_field field="title"]
[studiahub_course_field field="durationHours"]
[studiahub_course_field field="priceDisplay"]
```

| `field` | Qué es |
|---|---|
| `title` | Título del curso |
| `subtitle` | Subtítulo |
| `shortDescription` | Descripción corta (texto plano) |
| `longDescription` | Descripción larga — **HTML-rich** (se devuelve con `wp_kses_post`) |
| `thumbnailUrl` | URL de la portada del curso |
| `trailerUrl` | URL del trailer (YouTube/Vimeo/etc, cruda) |
| `landingImageUrl` | Imagen del hero cuando no hay trailer |
| `priceDisplay` | Precio principal ya formateado (ej `"USD 100 / ARS 100.000"`) |
| `compareAtPrice` | Precio tachado — solo viaja si hay oferta vigente |
| `installmentsLabel` | Texto de cuotas (ej `"3 cuotas sin interés"`) |
| `offerDeadlineAt` | ISO date del fin de oferta (solo si hay oferta vigente) |
| `ctaLabel` | Texto del botón de compra |
| `highlightBadge` | Badge destacado (ej `"-30%"`, `"Más vendido"`) |
| `category` | Categoría del curso |
| `courseType` | Modalidad (`on_demand` / `live` / `in_person` / `hybrid`) |
| `courseStartAt` | ISO date de inicio (solo modalidades con fecha) |
| `liveSessionsCount` | Cantidad de encuentros en vivo |
| `level` | Nivel (ej `"Principiante / Intermedio"`) |
| `language` | Idioma |
| `durationHours` | Horas de contenido |
| `hasCertificate` | Booleano → se rinde como `Sí` / `No` |
| `salesClosed` | Booleano → `Sí` / `No` (inscripciones cerradas) |
| `modulesCount` | Cantidad de módulos |
| `lessonsCount` | Cantidad de lecciones |
| `totalDurationMin` | Duración total en minutos |
| `tenantName` | Nombre de la academia |
| `reviewsAverage` | Promedio de reseñas (ej `4.83`). **Vacío si el curso no tiene reseñas.** |
| `reviewsCount` | Cantidad de reseñas aprobadas. **Vacío si es 0.** |

> `reviewsAverage` y `reviewsCount` son campos **virtuales** derivados del resumen de reseñas — sirven para el bloque "⭐ 4.8 · 120 reseñas" arriba del listado. Las reseñas **individuales** salen con `[studiahub_course_reviews]` (loop, más abajo).

> Los campos que son **arrays u objetos** (bonos, faq, temario, etc.) **no** se sacan con `field` — tienen su propio shortcode de loop más abajo.

---

## 2. Loops (arrays) — template interno con tokens

Cada array/objeto tiene su propio shortcode. El **contenido interno** del shortcode es el **template por-item**: se repite una vez por elemento, reemplazando los tokens `{{campo}}` por el valor de cada item.

```
[studiahub_course_bonuses]
  <div class="mi-bono">
    <img src="{{imageUrl}}">
    <h4>{{title}}</h4>
    <p>{{desc}}</p>
  </div>
[/studiahub_course_bonuses]
```

**Escaping automático y seguro** (no tenés que escapar vos):
- Tokens de URL (`imageUrl`, `avatarUrl`, `photoUrl`) → `esc_url` (una `javascript:...` se descarta).
- Tokens HTML-rich (`a` de FAQ, `bio`, `comment`) → `wp_kses_post` (deja negritas/links, mata `<script>`).
- Todo el resto → `esc_html`.
- Un token que no exista en el item → se reemplaza por vacío.

**Fallback sin template.** Si usás el shortcode **self-closing** (sin contenido interno), rinde un markup mínimo semántico con clases `slc-` y **cero estilos** — funciona out of the box, pero sin imponer diseño:

```
[studiahub_course_bonuses]
```
→
```html
<ul class="slc-bonuses">
  <li class="slc-bonus">
    <span class="slc-bonus__title">…</span>
    <span class="slc-bonus__desc">…</span>
    <span class="slc-bonus__value">…</span>
  </li>
  …
</ul>
```

Si el array está vacío (el curso no tiene ese dato), el shortcode devuelve vacío y **no** rinde el `<ul>`.

### Tokens por shortcode

#### `[studiahub_course_bonuses]` — bonos exclusivos
| Token | Valor |
|---|---|
| `{{title}}` | Título del bono |
| `{{desc}}` | Descripción |
| `{{value}}` | Valor percibido (ej `"$50 USD"`) |
| `{{imageUrl}}` | Imagen del bono (URL) |

Fallback: `.slc-bonuses > .slc-bonus > .slc-bonus__title / __desc / __value`

#### `[studiahub_course_faq]` — preguntas frecuentes
| Token | Valor |
|---|---|
| `{{q}}` | Pregunta |
| `{{a}}` | Respuesta — **HTML-rich** |

Fallback: `.slc-faq > .slc-faq__item > .slc-faq__q / .slc-faq__a`

#### `[studiahub_course_reviews]` — reseñas de alumnos
| Token | Valor |
|---|---|
| `{{author}}` | Nombre + inicial (ej `"Juan P."`) |
| `{{rating}}` | Puntaje 1–5 (número) |
| `{{comment}}` | Comentario — **HTML-rich** |
| `{{avatarUrl}}` | Avatar del alumno (URL) |
| `{{createdAt}}` | ISO date de la reseña |
| `{{stars}}` | 5 estrellas SVG ya renderizadas (llenas hasta `rating`) |

Fallback: `.slc-reviews > .slc-review > .slc-review__stars / .slc-review__comment / .slc-review__author`

#### `[studiahub_course_instructors]` — docentes
| Token | Valor |
|---|---|
| `{{name}}` | Nombre completo |
| `{{title}}` | Cargo / rol |
| `{{bio}}` | Bio — **HTML-rich** |
| `{{photoUrl}}` | Foto (URL) |
| `{{initial}}` | Primera letra del nombre (para avatar de fallback) |

Fallback: `.slc-instructors > .slc-instructor > .slc-instructor__name / __title / __bio`

#### `[studiahub_course_stats]` — stats de social proof
| Token | Valor |
|---|---|
| `{{num}}` | Número / valor (ej `"+2.400"`) |
| `{{label}}` | Etiqueta (ej `"Alumnos"`) |

Fallback: `.slc-stats > .slc-stat > .slc-stat__num / .slc-stat__label`

#### Arrays de texto — un token `{{text}}` + `{{index}}`
Cuatro shortcodes con la misma forma (lista de strings):

| Shortcode | Qué lista |
|---|---|
| `[studiahub_course_outcomes]` | Qué vas a aprender (`learningOutcomes`) |
| `[studiahub_course_audience]` | Para quién es (`targetAudience`) |
| `[studiahub_course_materials]` | Qué incluye (`includedMaterials`) |
| `[studiahub_course_requirements]` | Requisitos (`requirements`) |

| Token | Valor |
|---|---|
| `{{text}}` | El ítem |
| `{{index}}` | Posición 1-based |

```
[studiahub_course_outcomes]
  <li class="feature">✔ {{text}}</li>
[/studiahub_course_outcomes]
```

Fallbacks: `.slc-outcomes > .slc-outcome`, `.slc-audience > .slc-audience__item`, `.slc-materials > .slc-material`, `.slc-requirements > .slc-requirement`.

---

## 3. Temario anidado — `[studiahub_course_outline]` + `[studiahub_course_lessons]`

El temario tiene dos niveles: **módulos**, y dentro de cada uno, **lecciones**. Se resuelve con dos shortcodes: el loop de módulos, y — anidado dentro de su template — el loop de lecciones del módulo actual.

### `[studiahub_course_outline]` — loop de módulos
| Token | Valor |
|---|---|
| `{{title}}` | Título del módulo |
| `{{index}}` | Posición 1-based |
| `{{lessonsCount}}` | Cantidad de lecciones del módulo |
| `{{durationMin}}` | Duración del módulo en minutos (número) |
| `{{duration}}` | Duración formateada (ej `"1 h 30 min"`) |
| `{{isLive}}` | `Sí` / `No` (si la mayoría de sus lecciones son en vivo) |

### `[studiahub_course_lessons]` — loop de lecciones (solo dentro del outline)
Colocalo **dentro** del template de `[studiahub_course_outline]`. Itera las lecciones del módulo que se está renderizando.

| Token | Valor |
|---|---|
| `{{title}}` | Título de la lección |
| `{{type}}` | Tipo (`VIDEO` / `TEXT` / `PDF`) |
| `{{durationMin}}` | Duración en minutos (número) |
| `{{duration}}` | Duración formateada |
| `{{free}}` | `Sí` / `No` (lección de muestra gratis) |
| `{{index}}` | Posición 1-based dentro del módulo |

```
[studiahub_course_outline]
  <section class="modulo">
    <h3>{{index}}. {{title}} — {{lessonsCount}} lecciones · {{duration}}</h3>
    <ul>
      [studiahub_course_lessons]
        <li>{{title}} <small>{{duration}}</small></li>
      [/studiahub_course_lessons]
    </ul>
  </section>
[/studiahub_course_outline]
```

> `[studiahub_course_lessons]` **fuera** de un `[studiahub_course_outline]` devuelve vacío (no tiene módulo de contexto).

**Fallback** (outline self-closing): rinde `.slc-outline > .slc-outline__module` con su título y, anidado, `.slc-lessons > .slc-lesson > .slc-lesson__title / .slc-lesson__duration`.

---

## 4. Objeto único — `[studiahub_course_guarantee]`

La garantía es un objeto (no un array). Tokens dentro del template; sin template, un bloque mínimo. Si el tenant deshabilitó la garantía → **vacío**.

| Token | Valor |
|---|---|
| `{{title}}` | Título de la garantía (ej `"30 días de garantía"`) |
| `{{text}}` | Texto de la garantía |

```
[studiahub_course_guarantee]
  <div class="garantia"><strong>{{title}}</strong><p>{{text}}</p></div>
[/studiahub_course_guarantee]
```

Fallback: `.slc-guarantee > .slc-guarantee__title / .slc-guarantee__text`

---

## Cómo darles estilo con CSS

Tenés dos caminos:

1. **Diseño propio (recomendado):** usá el **template interno** con **tus propias clases**. El shortcode solo mete el texto; el markup y las clases las ponés vos, así estilás con total libertad sin pelear con nada.

   ```
   [studiahub_course_bonuses]
     <article class="card card--bonus">
       <img class="card__img" src="{{imageUrl}}" alt="">
       <h4 class="card__title">{{title}}</h4>
       <p class="card__body">{{desc}}</p>
     </article>
   [/studiahub_course_bonuses]
   ```

2. **Fallback + CSS a las clases `slc-`:** si usás el shortcode self-closing, estilá las clases namespaceadas que rinde:

   ```css
   .slc-bonuses { list-style: none; display: grid; gap: 1rem; }
   .slc-bonus { padding: 1rem; border-radius: 14px; }
   .slc-bonus__title { font-weight: 700; }
   ```

Los shortcodes **no cargan ningún CSS propio** — la parte visual es 100% tuya.

---

## Ejemplo end-to-end: landing mínima

```
<header class="hero">
  <span class="badge">[studiahub_course_field field="highlightBadge"]</span>
  <h1>[studiahub_course_field field="title"]</h1>
  <p class="sub">[studiahub_course_field field="subtitle"]</p>
  <div class="price">[studiahub_course_field field="priceDisplay"]</div>
</header>

<section class="outcomes">
  <h2>Qué vas a aprender</h2>
  <ul>
    [studiahub_course_outcomes]<li>✔ {{text}}</li>[/studiahub_course_outcomes]
  </ul>
</section>

<section class="curriculum">
  <h2>Contenido</h2>
  [studiahub_course_outline]
    <details>
      <summary>{{index}}. {{title}} — {{lessonsCount}} lecciones</summary>
      <ul>
        [studiahub_course_lessons]<li>{{title}} <span>{{duration}}</span></li>[/studiahub_course_lessons]
      </ul>
    </details>
  [/studiahub_course_outline]
</section>

<section class="team">
  <h2>Tus docentes</h2>
  [studiahub_course_instructors]
    <figure class="teacher">
      <img src="{{photoUrl}}" alt="{{name}}">
      <figcaption><strong>{{name}}</strong><br>{{title}}</figcaption>
    </figure>
  [/studiahub_course_instructors]
</section>

<section class="reviews">
  <h2>Lo que dicen los alumnos</h2>
  [studiahub_course_reviews]
    <blockquote>{{stars}}<p>{{comment}}</p><cite>{{author}}</cite></blockquote>
  [/studiahub_course_reviews]
</section>

<section class="faq">
  <h2>Preguntas frecuentes</h2>
  [studiahub_course_faq]
    <details><summary>{{q}}</summary><div>{{a}}</div></details>
  [/studiahub_course_faq]
</section>

[studiahub_course_guarantee]
  <aside class="guarantee"><strong>{{title}}</strong> {{text}}</aside>
[/studiahub_course_guarantee]

<a class="cta" href="?add-to-cart=...">[studiahub_course_field field="ctaLabel"]</a>
```

> Cada bloque se auto-oculta si el curso no tiene ese dato (array vacío / campo vacío) — no vas a ver placeholders vacíos en la landing.
