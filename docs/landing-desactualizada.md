# La landing no refleja los cambios del LMS

Guía para diagnosticar el problema más caro de este plugin: la página de venta muestra precios, fechas o textos que ya no son los del curso.

Escrito después de un caso real (AMERITAELENCUENTRO, agosto 2026) que estuvo **catorce días roto sin que nada avisara**, y que costó varias horas de diagnóstico por perseguir hipótesis equivocadas. Lo que sigue está ordenado para que no vuelva a pasar.

---

## Antes que nada: la heurística que resuelve el caso

**Pedí la landing varias veces seguidas y después fijate si el plugin dejó rastro.**

```bash
wp eval '$h = md5("<COURSE_ID>");
echo "fresh: "   . var_export(get_transient("slc_landing_$h") !== false, true) . "\n";
echo "backoff: " . var_export(get_transient("slc_landing_fail_$h") !== false, true) . "\n";'
```

`Landing_Fetch::get_payload()` tiene exactamente dos desenlaces y **los dos dejan huella**: si el fetch sale bien escribe el transient `fresh`; si falla escribe el `backoff`.

- **Quedó `fresh`** → el fetch anduvo. El problema está después, entre el payload y el HTML.
- **Quedó `backoff`** → el fetch falló. El error está en `debug.log` (`[slc landing-fetch]`).
- **No quedó ninguno** → **el código no se ejecutó**, o algo lo cortocircuitó antes. No pierdas tiempo con la red ni con las credenciales: buscá qué está respondiendo en su lugar.

Ese tercer caso es el que costó horas. La causa fue que `Purchase_Gate` (que corre en cada visita, antes del contenido) dejaba la copia vieja en el memo compartido, y el shortcode se la encontraba ya resuelta.

> ⚠️ **`get_payload()` muta.** Cada vez que lo llamás para "medir", reescribe las tres capas y **arregla el síntoma sin querer**. Leé el estado ANTES de dispararlo o destruís la evidencia. En el caso real, cada medición hacía revivir la landing por un rato y mandaba el diagnóstico para cualquier lado.

Desde **v0.18.0** hay una forma de mirar sin tocar nada:

```bash
curl -sS -H "Authorization: Bearer $(wp option get slc_webhook_secret)" \
  "https://<sitio>/wp-json/studiahub/v1/landing-status"
```

Devuelve, por curso: de qué capa se sirve (`fresh` / `stale` / `lkg` / `none`), hace cuánto fue el último contenido traído, si hay backoff activo y la huella del contenido. **No fetchea ni escribe.**

---

## Las cuatro trampas

### 1. El "● Conectado" verde del admin miente

```php
$is_connected = $has_api_key && $lms_url !== '';   // class-settings.php
```

Solo verifica que existan dos options locales. **No hace ni un request al LMS.** Podés tener la conexión rota hace semanas con el punto en verde.

Desde v0.18.0, el "Probar conexión" del LMS sí verifica las dos direcciones (usa `?check_lms=1`).

### 2. "El producto se actualizó" no prueba nada

Al producto de WooCommerce solo viajan **título, descripción corta, precio, thumbnail y meta**. Las fechas, los textos largos, los bonuses y las FAQ de la landing **no pasan por ahí**: van en el payload, que es otro canal.

Son dos caminos que se rompen por separado. Que el título del producto se haya actualizado no dice nada sobre la landing.

### 3. Un curl OK desde tu máquina no prueba que el servidor pueda

El fetch sale de PHP-FPM, con su propia red, su DNS y su firewall. Para probar esa punta:

```bash
wp eval '$r = wp_remote_get("<LMS_URL>/api/wc/courses/<ID>/landing-payload",
  ["timeout" => 10, "headers" => ["Authorization" => "Bearer " . get_option("slc_webhook_secret")]]);
echo is_wp_error($r) ? "WP_ERROR: " . $r->get_error_message() : "HTTP " . wp_remote_retrieve_response_code($r);'
```

### 4. El `<title>` y el cuerpo salen de fuentes distintas

El `<title>` lo genera WordPress desde su propia base. El cuerpo de la landing lo renderiza el shortcode desde el payload.

**Si en la misma respuesta el título está fresco y el cuerpo viejo, eso descarta un caché de página** —que congelaría todo por igual— y prueba que PHP se está ejecutando. Fue la pista que destrabó el caso real.

---

## Falso positivo clásico: el 401

Probando el Bearer a mano es muy fácil que dé 401 por **el secret copiado truncado** desde el cliente de base de datos, y no por una desincronización real.

Verificá siempre el largo (**64 caracteres**, `wp_generate_password(64)`) y leé el cuerpo del error: el LMS distingue `Token inválido.` (menos de 32 chars) de `Token desconocido.` (hash sin match, o tenant inactivo).

---

## Dónde vive el shortcode

No des por hecho que está en el producto. Con Elementor Theme Builder vive en un template aparte, y el producto puede tener el `post_content` completamente vacío.

```sql
SELECT ID, post_type, post_title FROM wp_posts WHERE post_content LIKE '%studiahub_course%';
SELECT post_id FROM wp_postmeta WHERE meta_value LIKE '%studiahub_course%';
```

---

## Las capas de caché

| Key | Tipo | Vence |
|---|---|---|
| `slc_landing_<md5>` | transient | 15 min |
| `slc_landing_<md5>_stale` | transient | 7 días |
| `slc_landing_lkg_<md5>` | **option** | **nunca** |
| `slc_landing_fail_<md5>` | transient | 60 s (backoff) |
| `slc_landing_at_<md5>` | option | — (marca del último contenido traído) |

La tercera no vence nunca a propósito: si el LMS se cae, la landing sigue mostrando el contenido anterior en vez de quedar vacía. El precio de eso es que **una copia mala se puede servir para siempre** si nada la pisa — sólo la reemplaza un fetch exitoso.

```sql
SELECT option_name, LENGTH(option_value)
FROM wp_options
WHERE option_name LIKE '%<md5(course_id)>%';
```

> Con object cache persistente (Redis) los transients **no** están en `wp_options`. Verificalo con `wp eval 'var_dump(wp_using_ext_object_cache());'`.

---

## Qué vigila esto hoy

Desde v0.18.0 el problema no debería volver a pasar en silencio. Ver [observabilidad-landing.md](observabilidad-landing.md).

Si igual llegaste acá, seguí el orden de arriba: primero la heurística del rastro, después las trampas. En ese orden el caso real se habría resuelto en minutos.
