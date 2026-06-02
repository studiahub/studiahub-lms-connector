# StudiaHub Landing — Design System

Fuente de verdad visual para las landings de curso del plugin (shortcodes
`[studiahub_course_page]` V1 y `[studiahub_course_pitch]` V2).
**Toda nueva regla CSS o ajuste visual debe seguir esta guía.** Si un
cambio se desvía, justificarlo o no hacerlo.

> Si vas a tocar el CSS o el HTML de un shortcode, leé este doc primero.
> Después de cada cambio sustancial, invocá al `design-guardian` para
> que valide (ver `.claude/agents/design-guardian.md`).

---

## 🎨 Branding dinámico (CRÍTICO — leer antes de escribir 1 línea de CSS)

La landing es **multi-tenant**. Cada cliente del LMS tiene su propio
branding (color primario, secundario, font) que se inyecta dinámicamente
en el HTML del shortcode como CSS variables. **El CSS del plugin nunca
hardcodea colores o fonts del cliente** — siempre los lee via `var(...)`.

### Cómo llega el branding al CSS

El payload que el plugin fetcha al LMS (`/api/wc/courses/:id/landing-payload`)
trae un objeto `branding`:

```json
{
  "branding": {
    "primaryColor": "#7950F2",
    "secondaryColor": "#FA5252",
    "fontFamily": "Inter"
  }
}
```

El shortcode (`class-shortcode-coursepitch.php` / `class-shortcode-coursepage.php`)
inyecta esos valores como **inline style en el wrapper**:

```html
<div class="slc-coursepitch" style="
  --shub-accent: #7950F2;
  --shub-accent-rgb: 121, 80, 242;
  --shub-secondary: #FA5252;
  --shub-secondary-rgb: 250, 82, 82;
  --shub-cta-grad: linear-gradient(135deg, #7950F2 0%, #5F3DC4 100%);
  --shub-hero-grad: linear-gradient(135deg, rgba(121,80,242,0.06) 0%, rgba(132,94,247,0.10) 60%, rgba(121,80,242,0.04) 100%);
  --shub-font: 'Inter', sans-serif;
">...</div>
```

Si el cliente cambia `primaryColor` a `#0EA5E9` (cyan), la landing
automáticamente toma cyan **sin tocar el plugin**.

### Variables disponibles (las que vienen del tenant)

| Var | Default fallback | Origen |
|---|---|---|
| `--shub-accent` | `#7950F2` (purple) | `branding.primaryColor` |
| `--shub-accent-dark` | `#5F3DC4` | derivado del primary (oscurecido) |
| `--shub-accent-soft` | `rgba(121,80,242,0.10)` | derivado del primary |
| `--shub-accent-rgb` | `121, 80, 242` | RGB del primary (para `rgba()`) |
| `--shub-secondary` | `#845EF7` | `branding.secondaryColor` |
| `--shub-secondary-rgb` | `132, 94, 247` | RGB del secondary |
| `--shub-cta-grad` | `linear-gradient(135deg, #7950F2 0%, #5F3DC4 100%)` | derivado del primary |
| `--shub-hero-grad` | gradiente soft | derivado del primary |
| `--shub-avatar-grad` | `linear-gradient(135deg, #845EF7, #5C7CFA)` | derivado del primary |
| `--shub-font` | `inherit` (theme del cliente) | `branding.fontFamily` |

### Reglas no negociables

1. **NUNCA hardcodees un hex de color en una regla CSS nueva**. Si
   necesitás el acento, usá `var(--shub-accent)`. Si necesitás transparencia
   sobre el acento, usá `rgba(var(--shub-accent-rgb), 0.12)`.
2. **NUNCA hardcodees una font-family**. Usá `var(--shub-font, inherit)`
   o heredala desde el wrapper.
3. **Los defaults del CSS** (`:root` del wrapper) están como red de
   seguridad por si el wrapper no recibe el style inline. **Existen pero
   no son la fuente de verdad**.
4. **Para probar con OTRO color** sin tocar el código: editá
   `.docker/dev-mock/payload.json` (campo `branding.primaryColor`),
   corré `make refresh`, recargá con `Cmd+Shift+R`. El cambio queda en
   tu Mac, no afecta clientes reales.
5. Los **neutrales** (#0F172A títulos, #475569 body, #64748B muted,
   #E2E8F0 borders, etc) **sí pueden ser hex literales** — esos no
   dependen del tenant. Pero igual están tokenizados como
   `--shub-text-title`, `--shub-text-body`, etc. — usá los tokens.

### ¿Qué pasa si el tenant cambia la font?

El LMS expone en el payload `branding.fontFamily` (ej: `"Poppins"`,
`"Roboto"`, `"DM Sans"`). El plugin auto-carga esa font desde Google
Fonts vía `<link>` inyectado en el `<head>` (lo maneja
`class-shortcode-coursepitch.php`). En el CSS, `var(--shub-font)` la
aplica automáticamente al wrapper. Si el cliente no setea nada, hereda
la font del theme de WordPress.

---

## 📐 Anchos, breakpoints y spacing tokens de landing

### Anchos canónicos (wrappers)

```css
--shub-wrap:        1200px;  /* Wrapper principal. Hero, secciones grid */
--shub-wrap-narrow: 920px;   /* Prose densa (FAQ texto, "para quién es") */
```

| Contexto | Wrapper | Por qué |
|---|---|---|
| Hero, grids de cards, sticky CTA, módulos | `--shub-wrap` (1200px) | Sensación generosa, premium |
| FAQ desplegado, texto largo, "para quién" | `--shub-wrap-narrow` (920px) | Mejor readability en prose densa |

**No inventes anchos intermedios** (1080, 1100) sin razón. 1200 o 920.

### Padding horizontal del wrap

```css
.slc-cp__wrap, .slc-cpitch__wrap {
  padding: 0 32px;  /* desktop */
}
/* mobile: hereda los 32px o se reduce a 20px según contexto */
```

### Section spacing vertical

```css
--shub-section-y: clamp(64px, 8vw, 96px);   /* V1 (coursepage) */
--shub-section-y: clamp(72px, 9vw, 112px);  /* V2 (coursepitch) — más aire */
```

Aplicado como `padding: var(--shub-section-y) 0;` en `.slc-cp__section` /
`.slc-cpitch__section`.

### Title gap (aire entre H2 y su contenido)

```css
--shub-title-gap: 40px;   /* V1 */
--shub-title-gap: 44px;   /* V2 */
```

**Regla**: al menos 24-32px entre un H2 y el primer elemento que le
sigue. Si pegás contenido al título, se ve aplastado.

### Breakpoints

```css
/* Mobile primero */
/* Default styles = mobile */

@media (min-width: 640px)  { /* Tablet small */ }
@media (min-width: 768px)  { /* Tablet */ }
@media (min-width: 1024px) { /* Desktop */ }
@media (min-width: 1280px) { /* Desktop XL */ }
```

Mobile-first: escribís el CSS para 375px-ancho primero, después
escalás. NO escribas para desktop y "ajustes mobile" al final.

---

## 1. Principios

1. **Clean, premium, calmo.** Mucho espacio en blanco, sin ruido.
2. **Jerarquía por peso y color, no por bordes ni sombras pesadas.**
3. **Acentos con gradientes sutiles** (`var(--shub-cta-grad)` por default). Nunca colores planos saturados en superficies grandes.
4. **Icon boxes con bg tinted** (`rgba(var(--shub-accent-rgb), 0.08-0.15)`) para iconografía contextual.
5. **Hover translada, no levanta.** `translateY(-2px)` o `translateX(2px)` máximo. Evitar `box-shadow` agresivos.
6. **CSS scoped al shortcode.** Todas las clases prefijadas (`.slc-cp__*` para V1, `.slc-cpitch__*` para V2). No conflictos con theme/Elementor.

---

## 2. Paleta

### Acento (viene del tenant — NO hardcodear)

| Var | Default | Uso |
|---|---|---|
| `--shub-accent` | `#7950F2` | Botones primary, eyebrows, checks, links destacados, números |
| `--shub-accent-dark` | `#5F3DC4` | Hover de CTAs, segundo stop del gradient |
| `--shub-accent-soft` | `rgba(121,80,242,0.10)` | Bg de pills/badges/icon boxes |
| `--shub-secondary` | `#845EF7` | Acentos secundarios, gradient avatars |

### Superficies (neutrales — tokenizadas)

| Var | Hex | Uso |
|---|---|---|
| `--shub-bg-app` | `#FFFFFF` | Fondo de sección default |
| `--shub-bg-soft` | `#F8FAFC` | Sección "soft", cards de bonos, hover origin |
| `--shub-border` | `#E2E8F0` | Borde estándar de cards |
| `--shub-border-subtle` | `#EDF2F7` | Separadores muy sutiles |

### Texto (neutrales — tokenizados)

| Var | Hex | Uso |
|---|---|---|
| `--shub-text-title` | `#0F172A` (V1) / `#0B1226` (V2) | Títulos H1-H3, números |
| `--shub-text-body` | `#475569` | Texto cuerpo principal |
| `--shub-text-muted` | `#64748B` | Labels, descripciones secundarias |

### Gradientes

- **Primary CTA**: `var(--shub-cta-grad)` → default `linear-gradient(135deg, #7950F2 0%, #5F3DC4 100%)` + `box-shadow: 0 6px 20px -6px rgba(var(--shub-accent-rgb), 0.5)`
- **Hero bg soft**: `var(--shub-hero-grad)` → default `linear-gradient(135deg, rgba(121,80,242,0.06) 0%, rgba(132,94,247,0.10) 60%, rgba(121,80,242,0.04) 100%)`
- **Avatar default**: `var(--shub-avatar-grad)` → `linear-gradient(135deg, #845EF7, #5C7CFA)`

---

## 3. Tipografía

Family: `var(--shub-font, inherit)` — viene del tenant. Default hereda
del theme de WordPress.

### Escala de tamaños (NO inventar fuera de esta tabla)

| Uso | V1 (coursepage) | V2 (coursepitch) | Weight | Line-height |
|---|---|---|---|---|
| Hero title (H1) | `clamp(32px, 5.2vw, 54px)` | `clamp(36px, 6.4vw, 64px)` | 800-900 | 1.05-1.15 |
| Section title (H2) | `clamp(26px, 3.2vw, 36px)` | `clamp(28px, 3.6vw, 42px)` | 800 | 1.15 |
| Card title (H3) | 20-22px | 22-24px | 700 | 1.25 |
| Subtitle / lead | 17px | 18-22px | 400 | 1.5 |
| Body | 16px | 16px | 400 | 1.65 |
| Eyebrow / pill | 12-13px | 12px | 600-700 (uppercase) | 1 |
| Helper / meta | 13-14px | 13-14px | 400-500 | 1.4 |

### Reglas

- **Line-height explícito en headings**: `1.15` en H1/H2/H3. Sin esto
  heredan `1.65` del body y los títulos multilínea quedan aireados mal.
- **Letter-spacing en headings grandes**: `-0.02em` a `-0.03em` (más
  apretado, más premium).
- **Eyebrows uppercase**: `text-transform: uppercase; letter-spacing: 1.6px;`
- **Reset agresivo con `:where()`**: specificity 0 para que las clases
  propias siempre ganen la cascade (ver `coursepitch.css` líneas 51-77).

---

## 4. Spacing y radius

### Radius (border-radius)

| Token | Valor | Uso |
|---|---|---|
| `--shub-radius-pill` | `999px` | Pills, eyebrows, status badges, avatares |
| (raw) | `10px` | Icon boxes chicos (28-32px) |
| `--shub-radius` | `14px` (V1) / `16px` (V2) | Cards estándar, botones, inputs |
| `--shub-radius-lg` | `18px` (V1) / `22px` (V2) | Hero containers, sticky CTA, cards destacadas |

**NO mezcles radius**. Si una card vecina usa 14, la tuya también.
Si dudás, mirá un vecino y copiá.

### Padding estándar

| Contexto | Padding |
|---|---|
| Section (vertical) | `var(--shub-section-y)` |
| Wrap (horizontal) | `0 32px` (desktop), `0 20px` (mobile) |
| Card estándar | `24-28px` |
| Mini card (instructor, módulo) | `16-20px` |
| Botón large (CTA) | `16-18px 28-36px` |
| Pill / eyebrow | `6-8px 14-16px` |

### Gaps en grids/flex

Escala: `8, 12, 16, 20, 24, 32, 40, 48`. No inventes `13`, `27`, etc.

---

## 5. Componentes patrón

### 5.1 Hero (top of page)

```css
.slc-cpitch__hero {
  background: var(--shub-hero-grad);
  padding: clamp(56px, 8vw, 104px) 0;
  position: relative;
  overflow: hidden;
}
/* Spotlight sutil */
.slc-cpitch__hero::before {
  background: radial-gradient(ellipse at center, rgba(var(--shub-accent-rgb), 0.08), transparent 70%);
}
```

Estructura: eyebrow → H1 → subtitle → meta chips → CTA primary + CTA secondary.
**Hero suele ir centrado**. El resto de las secciones default left-aligned.

### 5.2 Botón Primary (CTA)

```css
.slc-cpitch__cta-primary {
  background: var(--shub-cta-grad);
  color: #fff;
  font-weight: 700;
  font-size: 16-18px;
  padding: 16px 32px;
  border-radius: var(--shub-radius);
  box-shadow: 0 6px 20px -6px rgba(var(--shub-accent-rgb), 0.5);
  transition: all 200ms ease;
}
.slc-cpitch__cta-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 28px -6px rgba(var(--shub-accent-rgb), 0.6);
}
```

### 5.3 Botón Secondary

```css
.slc-cpitch__cta-secondary {
  background: #fff;
  border: 1px solid var(--shub-border);
  color: var(--shub-text-title);
  /* Mismo sizing que primary */
}
```

### 5.4 Eyebrow (pill arriba de H2)

```css
.slc-cpitch__eyebrow {
  display: inline-block;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.6px;
  color: var(--shub-accent);
  background: rgba(var(--shub-accent-rgb), 0.10);
  padding: 7px 14px;
  border-radius: var(--shub-radius-pill);
}
```

### 5.5 Check item (lista "qué vas a aprender")

```css
.slc-cpitch__check {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}
.slc-cpitch__check::before {
  content: '';
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: rgba(var(--shub-accent-rgb), 0.12);
  /* Icono SVG check del color del acento */
}
```

### 5.6 Mini card (instructor, bono, módulo)

```css
.slc-cp__minicard {
  background: #fff;
  border: 1px solid var(--shub-border);
  border-radius: var(--shub-radius);
  padding: 20-24px;
  transition: all 200ms ease;
}
.slc-cp__minicard:hover {
  border-color: rgba(var(--shub-accent-rgb), 0.3);
  transform: translateY(-2px);
}
```

### 5.7 Sticky CTA bar (V2 — bottom or side)

Patrón propio del V2: una card sticky con precio + botón compra que
acompaña el scroll. Usa `--shub-radius-lg` y un `box-shadow`
más marcado pero todavía sutil:
`box-shadow: 0 12px 40px -12px rgba(15, 23, 42, 0.15);`

### 5.8 Status pill / badge

```css
/* Bg tinted del color + dot 7×7 + text 12px weight 600 */
background: rgba(var(--shub-accent-rgb), 0.12);
padding: 4px 10px 4px 8px;
border-radius: var(--shub-radius-pill);
```

---

## 6. Layout y alineación

### Regla de oro

**Header y contenido del bloque tienen la MISMA alineación.**

- Si centrás el eyebrow + H2 → centrás también el contenido (CTAs, cards).
- Si los alineás a la izquierda → idem.
- ❌ NUNCA "header centrado + content izquierda" o viceversa.

### Cuándo centrar vs izquierda

| Contexto | Default |
|---|---|
| Hero | Centrado |
| CTA final (banner sobre fondo oscuro) | Centrado |
| Stats band ("+5000 alumnos") | Centrado |
| Resto (qué vas a aprender, módulos, instructores, FAQ, bonos, requisitos) | **Izquierda** |

Centrar todo se ve "muerto". Centrá solo lo que naturalmente vive en
el medio (hero, banners). El resto a la izquierda.

---

## 7. Transiciones

- Standard hover: `transition: all 200ms ease`
- Layout (sidebar collapse, accordion expand): `300ms ease-in-out`
- **Nunca**: animaciones >400ms, bouncing, easings exóticos
  (`cubic-bezier(0.68, -0.55, ...)` etc).

---

## 8. Mobile

- Mobile-first siempre. Empezá con 375px ancho, escalá.
- Hero: H1 reducido a `clamp(32px, 7vw, 40px)` en mobile.
- Botones: full-width o casi (`max-width: 320px; margin: 0 auto`).
- Cards: stack vertical (`grid-template-columns: 1fr`).
- Padding wrap: reducir a `0 20px` si el contenido lo justifica.
- Touch targets: mínimo 44×44px (CTAs, links).

---

## 9. Defensive layer contra Elementor / themes

Las landings se embeben adentro de templates de Elementor del cliente.
Elementor inyecta CSS con specificity alta. Para ganar la cascade sin
ensuciar todo con `!important`:

### Estrategia

1. **Reset con `:where()`** (specificity 0) en headings/p/ul/ol/li del
   wrapper. Ver `coursepitch.css` líneas 51-77.
2. **Reglas críticas duplicadas** con prefijo `.elementor-widget .slc-coursepitch X`
   para subir specificity de forma quirúrgica. Solo donde se rompe.
3. **`!important` solo en casos extremos comentados** (ej: override
   de `text-align` que Elementor fuerza). Cada `!important` lleva un
   comentario `/* override Elementor */`.

### Anti-patrones

- ❌ `* { all: revert; }` o similar nuclear option — rompe más de lo que arregla.
- ❌ `body .slc-coursepitch X` — no resuelve si Elementor usa selectors más específicos.
- ❌ `!important` por defecto sin entender la cascade.

---

## 10. NO hacer (resumen)

- ❌ Hardcodear colores hex o nombres de fonts en CSS nuevo. Siempre `var()`.
- ❌ Colores planos saturados como bg de áreas grandes (#7950F2 fondo entero).
- ❌ Bordes >1px o muy contrastados (#000, #333).
- ❌ Sombras pesadas (`0 4px 20px rgba(0,0,0,0.2)`).
- ❌ Hover con `scale()` grandes o `translateY` >4px.
- ❌ Border radius fuera de la escala (10/12/14/16/18/22/999).
- ❌ Tamaños de tipografía fuera de la escala del §3.
- ❌ Mezclar wrappers (1200 y 1080) sin razón documentada.
- ❌ Centrar todo el contenido. Solo hero, CTA banner, stats.
- ❌ Header centrado con contenido izquierda (o viceversa) dentro del mismo bloque.
- ❌ Renderizar el logo del tenant en la landing (ya está en el header/footer del theme).
- ❌ Usar la palabra "tenant" en cualquier texto visible. Es jerga interna.
- ❌ Compartir CSS entre V1 y V2. Cada shortcode tiene su archivo.

---

## 💎 Referencias visuales

Inspiraciones que tomamos para construir el look & feel de las landings.
**No copiamos pixel a pixel**, tomamos principios de cada una:

- **Circle** (circle.so) — Premium calm. Mucho aire, gradients soft, pills
  tinted. De ahí viene la sensación general del portal alumno y secciones
  "community-style" de la landing.
- **MasteryHaus** (masteryhaus.com) — Layout del hero con sidebar de
  precio sticky. Cards de bonos. Trust elements. Es el norte del V2 pitch.
- **Hotmart** (landings de top producers) — Estructura de pitch agresiva:
  promesa fuerte → bonos con valor visible → garantía → urgencia → CTA
  repetido. Tomamos la **arquitectura** pero con un visual más sobrio
  (sin emojis cargados ni explosión de colores).
- **ThriveCart / Click7** — Sticky CTA bar, comparación de precio tachado,
  countdown de bonus. Patrones de conversion bien resueltos visualmente.

**Lo que NO copiamos**:
- Backgrounds animados de partículas / gradientes que se mueven.
- Emojis cada 3 palabras en headings.
- Colores neón / saturados.
- "Antes era $997, AHORA solo $97!!!" con tachado rojo y blink.

Vamos a un "Hotmart con estética Circle". Conversion-driven pero premium.

---

## ℹ️ Apéndice — Referencia del Admin LMS (NO replicar en landing)

> Esta sección documenta el design system del **panel admin del LMS**
> (`/admin`, `/dashboard`, etc — el lado Next.js). **No aplica directamente
> a la landing del plugin**. Está acá solo como referencia por si necesitás
> entender de dónde vienen ciertos patrones que sí adaptamos.

El admin del LMS usa:

- **Sidebar fijo 240px** (collapsable a 68px) con nav items + icon boxes.
- **Topbar sticky 60px** con breadcrumbs y avatar.
- **Mantine v7** como sistema de UI.
- **Hero blocks** con el mismo gradient soft que adoptamos para la landing
  (`linear-gradient(135deg, #EEF0FF 0%, #F4EEFF 50%, #FFF1F8 100%)`).
- **Stat cards** con número 30px weight 700 + icon box tinted.
- **Status pills** (idéntico al §5.8 nuestro).

**Qué NO replicar en landing**:
- Sidebar nav (la landing no es una app, no necesita nav lateral).
- Topbar de 60px con breadcrumbs (la landing tiene header del theme).
- Modales y drawers de Mantine (la landing es estática server-side rendered).
- Componentes Mantine en general (la landing es PHP + CSS plano).

**Qué SÍ tomamos del admin**:
- Paleta de acentos (purple #7950F2 como default).
- Gradientes (CTA, hero soft).
- Patrón icon box (38×38 radius 10 bg tinted).
- Status pill (bg tinted + dot 7×7).
- Tipografía: pesos y tamaños base.
- Transiciones suaves (150-200ms ease).

Fuente original del DS admin: `~/Dev/studiahub/studiahub-lms/docs/design-system.md`
en el repo del LMS.
