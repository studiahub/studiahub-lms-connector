# Feedback histórico — Contexto de iteraciones previas

> **Importante**: esto NO son reglas eternas. Son aprendizajes de las
> iteraciones que hicimos antes de que Nadi (la diseñadora oficial) se
> sumara al proyecto. Sirven como CONTEXTO para entender por qué hoy
> tomamos ciertas decisiones — no como prohibiciones.
>
> **Nadi tiene autoridad sobre todas estas convenciones**. Si quiere
> cambiar algo (centrar más cosas, anchos más angostos, otras escalas
> tipográficas, etc), dale. Estas notas solo explican el "estado actual"
> y por qué quedó así.

Léelo antes de proponer cambios para tener contexto, pero no lo tomes
como "no se puede tocar".

## Layout y espaciado

- **NO centrar todo**: el centrado excesivo se ve "muerto". Centrá solo
  hero, CTAs principales, y bands con stats. El resto (descripciones,
  FAQ, listas, bonos, requisitos) va left-aligned.
- **Header y contenido del bloque deben tener la MISMA alineación**.
  Si centrás el eyebrow+título, también centrás el contenido. Si los
  alineás a la izquierda, idem. NUNCA "header centrado + content izq".
- **Anchos generosos**: el wrap principal de la landing va a 1200px
  (no 800-900 — eso se ve angosto y "blog-y"). Variante narrow va a
  920px para prose densa.
- **Aire entre títulos y contenido**: al menos 24-32px de margin-top
  en el contenido bajo un H2. Si pegamos el contenido al título, se
  lee aplastado.
- **Hero del V1 spacing**: badge 28px → H1 18px → subtitle 32px →
  meta chips 28px. Probado y aprobado.

## Tipografía

- **Heading line-height explícito**: usar `line-height: 1.15` en H1
  y H2. Sin esto heredan el 1.65 del body y se rompe (un H1 de 54px
  con line-height 89px empuja todo y se ve "aireado mal").
- **Reset con `:where()`** (specificity 0) para que cualquier clase
  propia gane la cascade sin pelear.

## Branding y tokens

- **Cero hardcodes de color o font**. Todo via CSS vars que vienen
  del tenant. Si necesitás un color, mirá si hay var en el DS antes
  de inventar.
- **Logo del tenant NO se renderiza en la landing**. La landing vive
  dentro del header/footer del cliente WP que ya muestra su logo.

## UI / textos

- **No usar la palabra "tenant"** en ningún texto visible. Es jerga
  interna. Usar "academia", "Configuración", "la plataforma".
- **Sin placeholder vacío**: si un bloque no tiene data, se oculta
  entero. NO mostrar "Sin información" en su lugar.

## Build / CSS específico

- **Defensive layer contra Elementor**: las reglas críticas duplicadas
  con prefijo `.elementor-widget .slc-coursepage X` para ganar la
  cascade cuando el shortcode se embebe en widget de Elementor. SIN
  `!important` salvo casos extremos comentados.
- **Single CSS por shortcode**: V1 carga solo `coursepage.css`. V2
  carga solo `coursepitch.css`. NO compartir CSS entre los dos.
- **Mobile-first**: media queries para escalar a tablet/desktop, no
  al revés.

## Workflow

- **Cmd+Shift+R para recargar sin cache** después de cambios CSS.
- **`make refresh` antes de probar** si el cambio fue al mock JSON.
- **Si el cambio impacta a TODOS los clientes** (un label hardcoded
  por ej), avisar explícitamente al usuario antes de commitear.
