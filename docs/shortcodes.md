# Shortcodes — StudiaHub LMS Connector

Todos los shortcodes leen los ACFs `sh_course_*` del producto WC actual (o del que pases con `id`).
Si el producto no está conectado al LMS, no renderizan nada.

Pegalos en un widget **Shortcode** de Elementor (o en cualquier editor de WP).

---

## `[studiahub_course_outline]`

Renderiza el temario del curso como accordion (módulos + lecciones).
Primer módulo abierto por default. Cada lección muestra ícono según su tipo
(video / texto / PDF), badge "Preview" si es gratis, y duración.

### Uso básico

```
[studiahub_course_outline]
```

### Atributos opcionales

| Atributo       | Default              | Valores              | Descripción                                                |
|----------------|----------------------|----------------------|------------------------------------------------------------|
| `title`        | `Contenido del curso`| texto libre          | Header de la sección                                       |
| `default_open` | `1`                  | `1` / `0`            | `1` abre el primer módulo; `0` deja todos cerrados         |
| `show_count`   | `1`                  | `1` / `0`            | Muestra "X módulos · Y lecciones · Z h" debajo del título  |
| `id`           | producto actual      | ID numérico WC       | Forzar otro producto                                       |

### Ejemplos

```
[studiahub_course_outline title="Programa del curso"]

[studiahub_course_outline default_open="0" show_count="0"]

[studiahub_course_outline id="42"]
```

### Datos derivados que muestra

- Cantidad total de módulos y lecciones
- Duración total del curso (suma de las duraciones de las lecciones cargadas en el LMS)
- Duración por módulo (badge azul al lado del módulo)
- Lecciones marcadas como `free` muestran un pill "Preview"

---

## `[studiahub_course_list]`

Renderiza una de las 4 listas del curso (lo que vas a aprender, audiencia,
materiales, requisitos) como bullets con íconos.

### Uso básico

```
[studiahub_course_list field="learning"]
```

### `field` (requerido)

| Valor          | ACF que lee                       | Sección del curso          |
|----------------|-----------------------------------|----------------------------|
| `learning`     | `sh_course_learning_outcomes`     | Lo que vas a aprender      |
| `audience`     | `sh_course_target_audience`       | A quién está dirigido      |
| `materials`    | `sh_course_included_materials`    | Materiales incluidos       |
| `requirements` | `sh_course_requirements`          | Requisitos / Instrucciones |

### Atributos opcionales

| Atributo  | Default         | Valores                              | Descripción                            |
|-----------|-----------------|--------------------------------------|----------------------------------------|
| `title`   | (vacío)         | texto libre                          | Header opcional encima de la lista     |
| `icon`    | `check`         | `check` / `dot` / `star` / `arrow`   | Ícono al lado de cada ítem             |
| `columns` | `1`             | `1` / `2`                            | Layout en 1 o 2 columnas (responsive)  |
| `id`      | producto actual | ID numérico WC                       | Forzar otro producto                   |

### Ejemplos

```
[studiahub_course_list field="learning" title="Lo que vas a aprender"]

[studiahub_course_list field="audience" icon="star" columns="2"]

[studiahub_course_list field="materials" icon="dot"]

[studiahub_course_list field="requirements" icon="arrow" columns="1"]
```

---

## `[studiahub_course_instructor]`

Renderiza una card con la foto, nombre, cargo y bio del instructor.
Lee los ACFs `sh_course_instructor`, `sh_course_instructor_title`,
`sh_course_instructor_bio`, `sh_course_instructor_photo_url`.
Si no hay nombre, no renderiza nada.

### Uso básico

```
[studiahub_course_instructor]
```

### Atributos opcionales

| Atributo | Default      | Valores                | Descripción                                  |
|----------|--------------|------------------------|----------------------------------------------|
| `title`  | (vacío)      | texto libre            | Header opcional ("Sobre el instructor", etc) |
| `layout` | `horizontal` | `horizontal` / `vertical` | Layout de la card                          |
| `id`     | actual       | ID numérico WC         | Forzar otro producto                         |

### Ejemplos

```
[studiahub_course_instructor title="Tu instructor"]

[studiahub_course_instructor layout="vertical"]
```

Si no hay foto cargada, muestra un círculo con la inicial del nombre.

---

## `[studiahub_course_meta]`

Renderiza una fila de chips con los datos clave del curso: tipo, duración,
nivel, idioma, certificado, módulos, lecciones. Cada chip se muestra solo
si el dato está cargado.

### Uso básico

```
[studiahub_course_meta]
```

### Atributos opcionales

| Atributo | Default                                                  | Valores              | Descripción                              |
|----------|----------------------------------------------------------|----------------------|------------------------------------------|
| `show`   | `type,duration,level,language,certificate,modules,lessons` | CSV de chips         | Filtrar qué chips mostrar                |
| `layout` | `row`                                                    | `row` / `grid`       | Fila inline (chips) o grid de cards      |
| `id`     | actual                                                   | ID numérico WC       | Forzar otro producto                     |

### Ejemplos

```
[studiahub_course_meta show="duration,level,language"]

[studiahub_course_meta layout="grid"]
```

---

## `[studiahub_course_badge]`

Renderiza el highlight del curso (Bestseller, Nuevo, etc.) como un pill
llamativo. Si el ACF `sh_course_highlight_badge` está vacío, no renderiza nada.

### Uso básico

```
[studiahub_course_badge]
```

### Atributos opcionales

| Atributo | Default | Valores                                          | Descripción                       |
|----------|---------|--------------------------------------------------|-----------------------------------|
| `color`  | `blue`  | `blue` / `green` / `orange` / `red` / `purple` / `dark` | Paleta del badge          |
| `text`   | (ACF)   | texto libre                                      | Override del ACF                  |
| `id`     | actual  | ID numérico WC                                   | Forzar otro producto              |

### Ejemplos

```
[studiahub_course_badge color="orange"]

[studiahub_course_badge text="Más vendido" color="red"]
```

---

## `[studiahub_course_trailer]`

Embebe el video promocional del curso (YouTube, Vimeo, o cualquier URL de iframe).
Lee el ACF `sh_course_trailer_url`. Si no hay URL, no renderiza nada.

### Uso básico

```
[studiahub_course_trailer]
```

### Atributos opcionales

| Atributo    | Default | Valores                              | Descripción                    |
|-------------|---------|--------------------------------------|--------------------------------|
| `ratio`     | `16:9`  | `16:9` / `4:3` / `1:1` / `21:9`      | Aspect ratio del player        |
| `max_width` | `100%`  | ej: `720px`, `90%`                   | Ancho máximo del wrapper       |
| `rounded`   | `1`     | `1` / `0`                            | Border-radius del player       |
| `url`       | (ACF)   | URL libre                            | Override del ACF               |
| `id`        | actual  | ID numérico WC                       | Forzar otro producto           |

### URLs soportadas

- **YouTube**: `https://youtu.be/xxx`, `https://youtube.com/watch?v=xxx`, shorts, embed
- **Vimeo**: `https://vimeo.com/xxx`, `https://player.vimeo.com/video/xxx`
- **Cualquier URL** (Bunny.net, Wistia, custom): se usa tal cual como `src` del iframe

### Ejemplos

```
[studiahub_course_trailer]

[studiahub_course_trailer max_width="720px" ratio="16:9"]

[studiahub_course_trailer url="https://vimeo.com/123456789" rounded="0"]
```

---

## ACFs disponibles para Elementor (Dynamic Tags)

Los siguientes campos de texto los podés insertar directo con Dynamic Tag de
Elementor (widget de texto / heading / imagen):

| ACF                                  | Tipo     | Descripción                                  |
|--------------------------------------|----------|----------------------------------------------|
| `sh_course_subtitle`                 | texto    | Subtítulo / tagline                          |
| `sh_course_short_description`        | textarea | Descripción corta                            |
| `sh_course_long_description`         | wysiwyg  | Descripción larga (HTML)                     |
| `sh_course_course_type`              | select   | `on_demand` / `live` / `in_person` / `hybrid`|
| `sh_course_duration_hours`           | number   | Duración del curso (horas, manual)           |
| `sh_course_total_duration_min`       | number   | Duración total derivada (suma de lecciones)  |
| `sh_course_level`                    | select   | `Principiante` / `Intermedio` / `Avanzado`   |
| `sh_course_language`                 | texto    | Idioma del curso                             |
| `sh_course_has_certificate`          | bool     | Incluye certificado                          |
| `sh_course_highlight_badge`          | texto    | Badge ("Bestseller", "Nuevo", etc.)          |
| `sh_course_price_display`            | texto    | Precio multimoneda (texto libre)             |
| `sh_course_cta_label`                | texto    | Texto del botón de compra                    |
| `sh_course_trailer_url`              | URL      | Video promocional (YouTube/Vimeo)            |
| `sh_course_instructor`               | texto    | Nombre del instructor                        |
| `sh_course_instructor_title`         | texto    | Cargo / título profesional                   |
| `sh_course_instructor_bio`           | textarea | Bio del instructor                           |
| `sh_course_instructor_photo_url`     | URL      | URL de la foto del instructor                |
| `sh_course_modules_count`            | number   | Cantidad de módulos                          |
| `sh_course_lessons_count`            | number   | Cantidad de lecciones                        |
| `sh_course_access_days`              | number   | Días de acceso (0 = de por vida)             |

### ACFs que NO conviene usar como Dynamic Tag

Estos guardan JSON crudo. Usá los shortcodes de arriba para renderizarlos:

- `sh_course_outline` → `[studiahub_course_outline]`
- `sh_course_learning_outcomes` → `[studiahub_course_list field="learning"]`
- `sh_course_target_audience` → `[studiahub_course_list field="audience"]`
- `sh_course_included_materials` → `[studiahub_course_list field="materials"]`
- `sh_course_requirements` → `[studiahub_course_list field="requirements"]`

---

## Notas

- Todos los ACFs `sh_course_*` se sincronizan **desde** el LMS y son read-only en WP admin. No editar manualmente.
- Los estilos de los shortcodes son inline (vanilla CSS) y respetan el font-family heredado del theme.
- Si necesitás un layout muy custom por ítem de las listas, mirá la sección "ACF Repeater" en el plan de integración (requiere ACF Pro).
