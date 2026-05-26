# 🗺️ Guía visual de secciones — Landing V2 (`[studiahub_course_pitch]`)

Mapa de cada bloque de la landing, con su clase CSS principal y qué datos del LMS muestra. Pensado para que sepas **dónde editar qué** cuando le pidas cambios a Claude Code.

> Convención: 🎨 = diseño (podés tocar), 📥 = contenido (viene del LMS, no se edita acá).

---

## 📐 Anatomía general

```
.slc-coursepitch               ← wrapper raíz, acá viven los CSS variables del branding
└── .slc-cpitch__hero              ← Hero asymmetric (texto izq + card der)
└── .slc-cpitch__hero--big         ← Hero gigante original (preservado, debajo del asymmetric)
└── .slc-cpitch__section --soft    ← Outcomes ("Lo que te llevás")
└── .slc-cpitch__band              ← Banda oscura con stats (social proof bar)
└── .slc-cpitch__section           ← Descripción larga
└── .slc-cpitch__section --alt     ← Audience ("¿Es para vos?")
└── .slc-cpitch__section           ← Outline (módulos del temario)
└── .slc-cpitch__section --alt     ← Bonos incluidos
└── .slc-cpitch__section           ← Instructores
└── .slc-cpitch__section --alt     ← Reseñas de alumnos
└── .slc-cpitch__section           ← Garantía (sello CSS)
└── .slc-cpitch__section --alt     ← FAQ
└── .slc-cpitch__section           ← Requisitos
└── .slc-cpitch__cta-final         ← CTA final (dark gradient)
└── .slc-cpitch__sticky-cta        ← Sticky CTA (aparece al scrollear)
```

**Anchos consistentes:**
- Hero, banda stats, CTA final → `1200px` max (`.slc-cpitch__wrap`)
- Todas las secciones de contenido → `920px` max (`.slc-cpitch__wrap--narrow`)

---

## 1. Hero asymmetric — `.slc-cpitch__hero` (no `--big`)

**Layout:** Grid 2 columnas (texto izquierda + card derecha con trailer/precio/CTA).

📥 **Data del LMS:**
- Título, subtítulo, descripción corta
- Badge destacado (ej. "Actualizado 2026")
- Categoría
- Trailer URL (video) o thumbnail fallback
- Precio + precio tachado + label de cuotas + deadline
- Rating + label de alumnos
- Chips de meta (módulos, lecciones, horas, certificado, idioma)

🎨 **Clases CSS principales:**
- `.slc-cpitch__hero-grid` — define las 2 columnas
- `.slc-cpitch__hero-main` — columna izquierda con texto
- `.slc-cpitch__hero-card` — card derecha (radius, sombra, padding)
- `.slc-cpitch__hero-media` — wrapper del trailer/thumb
- `.slc-cpitch__hero-cardbody` — body con precio + CTA + garantía
- `.slc-cpitch__hero-title`, `.slc-cpitch__hero-sub`, `.slc-cpitch__hero-meta`
- `.slc-cpitch__hero-guarantee` — banda inferior de la card con icon shield

---

## 2. Hero gigante (preservado) — `.slc-cpitch__hero--big`

**Layout:** Stack centrado full-width. Trailer dominante a `max-width: 880px` centrado.

📥 Misma data del hero asymmetric (es la misma data renderizada en otro layout).

🎨 **Clases CSS principales** (todas con prefijo `herobig` para no chocar con el asymmetric):
- `.slc-cpitch__herobig-inner` — wrapper centrado
- `.slc-cpitch__herobig-title` — H1 gigante
- `.slc-cpitch__herobig-sub` — subtítulo
- `.slc-cpitch__herobig-proof` — pill de rating
- `.slc-cpitch__herobig-trailer` — caja del video
- `.slc-cpitch__herobig-cta` — bloque precio + CTA + deadline
- `.slc-cpitch__herobig-meta` — chips de meta

Este hero se preservó porque a Gon le gusta cuando hay video. Diseño puede iterar libre — el CSS está aislado al final de `coursepitch.css`.

---

## 3. Outcomes ("Lo que te llevás") — `.slc-cpitch__section --soft`

📥 **Data:** Lista `learningOutcomes` (5 ítems typical).

🎨 **Clases:**
- `.slc-cpitch__section-head` — eyebrow + H2 (left-aligned)
- `.slc-cpitch__eyebrow` — pill morada arriba del título
- `.slc-cpitch__h2`
- `.slc-cpitch__checks` — grid 2 columnas en desktop
- `.slc-cpitch__check` — cada item con ✓ verde
- `.slc-cpitch__check-icon`

---

## 4. Banda de stats — `.slc-cpitch__band`

**Layout:** Fondo oscuro (gradient con primary + secondary). Stats centradas.

📥 **Data:**
- Alumnos count (real o override)
- Rating promedio
- Stats custom (configurables por el cliente en el admin del LMS)

🎨 **Clases:**
- `.slc-cpitch__band` — section dark
- `.slc-cpitch__band-grid` — flex centrado
- `.slc-cpitch__band-item` — cada stat
- `.slc-cpitch__band-num` — número grande
- `.slc-cpitch__band-label` — texto pequeño debajo

---

## 5. Descripción larga — `.slc-cpitch__section`

📥 **Data:** `longDescription` (HTML rich text del LMS, puede tener `<p>`, `<strong>`, listas).

🎨 **Clases:**
- `.slc-cpitch__prose` — wrapper de texto con tipografía optimizada
- Estilos de párrafos, negritas, listas dentro de prose

---

## 6. Audience ("¿Es para vos?") — `.slc-cpitch__section --alt`

📥 **Data:** Lista `targetAudience`.

🎨 **Clases:**
- `.slc-cpitch__persona-list` — stack vertical de items
- `.slc-cpitch__persona` — cada row con flecha al final
- `.slc-cpitch__persona-arrow` — la flecha → animada en hover

---

## 7. Outline (Temario) — `.slc-cpitch__section`

📥 **Data:** Array `outline` con módulos. Cada módulo tiene array de lecciones.

🎨 **Clases:**
- `.slc-cpitch__outline` — stack de módulos
- `.slc-cpitch__module` — cada módulo (badge numerado 01/02 + título)
- `.slc-cpitch__module-num` — número grande con primary color
- `.slc-cpitch__module-meta` — "3 lecciones · 30 min"
- `.slc-cpitch__lesson` — cada lección dentro del módulo (collapsible)
- `.slc-cpitch__lesson-icon` — icon de tipo (video/text/pdf)
- `.slc-cpitch__lesson-free` — badge "Gratis" para lecciones free

---

## 8. Bonos — `.slc-cpitch__section --alt`

📥 **Data:** Array `bonuses` con `{ title, desc, value }`.

🎨 **Clases:**
- `.slc-cpitch__bonus-grid` — grid de cards
- `.slc-cpitch__bonus` — cada card de bono
- `.slc-cpitch__bonus-value` — tag verde "VALOR: USD X" arriba derecha
- `.slc-cpitch__bonus-title`, `.slc-cpitch__bonus-desc`

---

## 9. Instructores — `.slc-cpitch__section`

📥 **Data:** Array `instructors` con `{ name, title, bio, photoUrl }`.

🎨 **Clases:**
- `.slc-cpitch__instructors` — wrapper
- `.slc-cpitch__instructor` — grid asymmetric foto + body
- `.slc-cpitch__instructor-photo`
- `.slc-cpitch__instructor-body` — nombre + cargo + bio

---

## 10. Reseñas — `.slc-cpitch__section --alt`

📥 **Data:** Array `reviews` (REALES de alumnos del LMS) + `reviewStats` (count + promedio).

🎨 **Clases:**
- `.slc-cpitch__reviews-hero` — card grande con rating promedio
- `.slc-cpitch__reviews-grid` — grid 3 cols de reviews
- `.slc-cpitch__review` — card de review
- `.slc-cpitch__review-stars`
- `.slc-cpitch__review-quote` — el texto del review con comilla decorativa
- `.slc-cpitch__review-author`

---

## 11. Garantía — `.slc-cpitch__section`

📥 **Data:** `guarantee.title` + `guarantee.text`. Si el cliente la deshabilitó en su admin del LMS, esta sección no aparece.

🎨 **Clases:**
- `.slc-cpitch__guarantee` — layout asymmetric (sello izq + texto der)
- `.slc-cpitch__guarantee-seal` — sello circular CSS-only con border dasheado animado
- `.slc-cpitch__guarantee-body` — title + text

El sello está hecho 100% en CSS — es una buena target para iterar.

---

## 12. FAQ — `.slc-cpitch__section --alt`

📥 **Data:** Array `faq` con `{ q, a }`. Ya viene mergeado del LMS (defaults del tenant + específicos del curso).

🎨 **Clases:**
- `.slc-cpitch__faq` — stack de items
- `.slc-cpitch__faq-item` — cada Q+A (collapsible con `<details>` nativo)
- `.slc-cpitch__faq-q` — pregunta clickeable
- `.slc-cpitch__faq-a` — respuesta

---

## 13. Requisitos — `.slc-cpitch__section`

📥 **Data:** Lista `requirements`.

🎨 **Clases:**
- `.slc-cpitch__requirements` — lista con bullets custom

---

## 14. CTA final — `.slc-cpitch__cta-final`

**Layout:** Fondo oscuro con radial gradients de accent + secondary. Centro: precio + CTA grande + garantía + trust bar.

📥 **Data:**
- Precio + precio tachado + label cuotas + deadline
- "Curso oficial de [tenantName]" como trust bar

🎨 **Clases:**
- `.slc-cpitch__cta-final` — section dark
- `.slc-cpitch__cta-final-inner` — content centrado
- `.slc-cpitch__cta-final-trust` — "Curso oficial de X"

---

## 15. Sticky CTA — `.slc-cpitch__sticky-cta`

Aparece después de ~600px de scroll y se oculta cerca del CTA final.

📥 **Data:** Precio + label CTA del LMS.

🎨 **Clases:**
- `.slc-cpitch__sticky-cta` — fixed bottom
- `.slc-cpitch__sticky-text` — precio
- `.slc-cpitch__sticky-btn` — botón

Tiene una pequeña pieza de JS al final del shortcode para mostrar/ocultar según scroll.

---

## 🎨 Variables CSS del branding (las globales)

Definidas en el `<div>` raíz inline por el PHP, leyendo el `branding` del tenant:

```css
--shub-accent          /* color primary del tenant (ej #7950F2) */
--shub-accent-rgb      /* mismo color en formato R,G,B para rgba() */
--shub-accent-dark     /* primary 12% más oscuro */
--shub-secondary       /* color secondary del tenant */
--shub-font            /* fontFamily del tenant (ej "Inter") */
--shub-cta-grad        /* gradient para el CTA principal */
--shub-radius-pill, --shub-radius-lg, --shub-radius
--shub-border, --shub-text-title, --shub-text-body, --shub-text-muted
--shub-title-gap       /* gap entre title y contenido — 40px desktop, 28 mobile */
```

**Importante:** todo lo que vos diseñes tiene que **usar estas variables** en lugar de hardcodear colores. Así cuando un cliente cambia su `primaryColor` en el admin del LMS, su landing toma el color nuevo automáticamente.

Ejemplo:
```css
/* ❌ Mal — hardcodea el color */
background: #7950F2;

/* ✅ Bien — usa la variable del branding */
background: var(--shub-accent);
```

---

---

## 🏷️ Sobre los títulos de cada sección ("Todo lo que vas a aprender", "Tus instructores", etc.)

Los títulos de cada bloque (eyebrow + H2) **NO vienen del LMS**. Son strings hardcoded en el PHP del plugin. Ejemplos:

| Sección | Eyebrow | Título (H2) |
|---|---|---|
| Outcomes | "Lo que te llevás" | "Al terminar este curso vas a poder…" |
| Descripción larga | "La propuesta" | "Por qué tomar este curso" |
| Audience | "Para vos" | "¿Es para vos?" |
| Outline | "Plan de estudio" | "Todo lo que vas a aprender" |
| Bonos | "Bonos exclusivos" | "Si te inscribís hoy, también te llevás:" |
| Instructores | "Quién enseña" | "Tus instructores" |
| Reseñas | "Lo que dicen" | "Alumnos reales, resultados reales" |
| Garantía | (sello) | "X días de garantía total" (este SÍ viene del LMS) |
| FAQ | "Dudas" | "Preguntas frecuentes" |
| Requisitos | "Lo que necesitás" | "Antes de empezar" |

### ⚠️ Si los modificás, afectás a TODOS los clientes

Estos labels viven en el archivo PHP del plugin (`includes/class-shortcode-coursepitch.php`). Cambiarlos altera el template para todos los cursos de todos los clientes que usen este plugin. **No es algo malo** — es parte de tu rol como diseño definir el copy del template — pero tenelo presente.

### Si querés cambiar uno

Pedile a Claude Code:

> "En la sección de outline del shortcode pitch, cambiá el título 'Todo lo que vas a aprender' por 'Programa completo del curso'."

Claude Code va a editar el PHP y el cambio aparece al recargar.

### Lo que sí viene del LMS y NO se toca acá

- El **título del curso** (h1 del hero) → viene de `payload.title`.
- El **subtítulo** del curso → `payload.subtitle`.
- La **descripción larga** del curso → `payload.longDescription`.
- El **título de la garantía** (ej. "7 días de garantía") → `payload.guarantee.title`.
- Los **textos de cada FAQ** (pregunta + respuesta) → `payload.faq`.
- Los **nombres de instructores, sus bios, sus cargos** → `payload.instructors`.
- Etc.

Todo eso lo configura cada cliente desde su admin del LMS. Nadi no los toca.

---

## 🔍 Cómo identificar qué clase tocar

1. Abrí la landing en el browser.
2. Click derecho sobre el elemento que querés cambiar → **Inspeccionar**.
3. En el panel de la derecha vas a ver las clases CSS aplicadas.
4. Buscá la que tenga prefijo `slc-cpitch__` — esa es la del plugin.
5. Pedile a Claude Code:
   ```
   Cambiame el padding de .slc-cpitch__bonus a 32px.
   ```

Si la clase tiene prefijo `elementor-` o algo distinto, es del theme/builder y no la toques desde acá.
