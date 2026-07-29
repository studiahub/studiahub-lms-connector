# Multimoneda y Troubleshooting — StudiaHub LMS Connector

Guía práctica para dar de alta un tenant nuevo y resolver los dos problemas más
comunes: **"la landing no trae data"** y **"los precios por moneda no funcionan"**.
Escrita a partir del debug real del tenant NUA (jul 2026).

> Regla mental para todo este doc: los shortcodes y la landing **no fallan con
> error**. Cuando algo no está bien configurado, simplemente **devuelven vacío**.
> Por eso el debug es siempre "seguir la cadena", no "leer el stack trace".

---

## Parte 1 — Cómo llega la data a la landing (cadena de 3 eslabones)

Toda la data de la landing (el `[studiahub_course_pitch]` y **todos** los shortcodes
granulares) sale del mismo **landing-payload** del LMS. El producto de WooCommerce
solo aporta el mapeo. La cadena es:

```
Producto WC con postmeta `_lms_course_id`
        │  (class-shortcode-fields.php / class-shortcode-coursepitch.php)
        ▼
Plugin "paired": OPT_LMS_URL + OPT_WEBHOOK_SECRET cargados
        │  (class-landing-fetch.php → fetch_from_lms)
        ▼
Endpoint del LMS responde 200 con data:
   GET {LMS_URL}/api/wc/courses/{course_id}/landing-payload
        │  ← el curso tiene que estar PUBLICADO en el LMS
        ▼
Landing renderiza
```

**Si cualquiera de los 3 eslabones falla → shortcodes vacíos, sin warning.**

---

## Parte 2 — Troubleshooting: "la landing no trae data"

| Síntoma | Causa probable | Cómo verificar / resolver |
|---|---|---|
| Landing 100% vacía en un tenant que antes andaba | **Curso en BORRADOR** en el LMS | El endpoint no sirve el payload de un curso en draft. **Publicar** el curso en el LMS + hard refresh (Cmd+Shift+R). |
| Landing vacía en un producto puntual | Falta el postmeta `_lms_course_id` | Editar producto → panel lateral **"StudiaHub LMS"** → tiene que mostrar **"Curso vinculado"** con un UUID. Si no está, el sync no lo mapeó (re-sincronizar desde el LMS). |
| Landing vacía en TODO el tenant | Plugin no "paired" | Settings del plugin: `OPT_LMS_URL` + `OPT_WEBHOOK_SECRET` cargados. El health check tiene que dar verde. |

**Gotchas importantes:**

- **El health check verde NO garantiza** que un curso puntual traiga su payload.
  Solo confirma que el plugin habla con el LMS en general. Un curso en draft da
  health verde igual.
- **Un curso en draft NO envenena el cache.** `Landing_Fetch` solo cachea
  respuestas válidas (arrays), nunca los errores. Por eso publicar + refrescar
  trae la data al instante, sin esperar el transient de 15 min.
- La landing con diseño custom (shortcodes granulares) va en una **página** de WP
  o Elementor, **no** en la descripción del producto (ahí queda atrapada en el
  template single-product de WooCommerce). Fuera del producto hay que pasar
  `id="<product_id>"` a cada shortcode. Ver [granular-shortcodes.md](granular-shortcodes.md).

---

## Parte 3 — Setup de multimoneda en un tenant nuevo

El connector empuja los precios por moneda del LMS a los campos del **currency
switcher** (soporta **WOOCS** y **Booster**), para que el checkout cobre el precio
**fijo** de cada moneda en lugar de convertir por tasa.

### La regla de oro (leer esto ANTES de cargar precios)

> **La moneda base de WooCommerce (`Ajustes → General → Moneda`) TIENE que ser la
> misma que la moneda "principal" del curso en el LMS.**

El connector mete el precio de la **moneda principal** del LMS (`course.price`) en el
`_regular_price` **nativo** del producto, asumiendo que esa moneda = la base del
store. Si no coinciden, el número se interpreta en la moneda equivocada (ej: 720
dólares leídos como 720 pesos, o al revés).

### Las 3 piezas a alinear

1. **Moneda principal del curso en el LMS** (ej: USD).
2. **Moneda de WooCommerce** (`Ajustes → General → Moneda`) → **igual a la 1** (USD).
3. **Sin "Product Base Price per-product" del Booster pisando**: ese módulo permite
   fijar una moneda base distinta por producto y rompe el supuesto. **Dejarlo
   desactivado.** (Deja un residuo `_wcj_multicurrency_base_price_currency` en el
   postmeta que, si el módulo está activo, hace leer el precio nativo en la moneda
   equivocada.)

> Para un tenant que quiere ARS como canónica, se cambian **las 3** a ARS. Lo que
> **nunca** funciona es mezclar (LMS en una moneda, Woo en otra).

### Cómo carga los precios el LMS

En el curso del LMS se cargan las monedas: una **principal** + las secundarias, cada
una con precio regular y (opcional) oferta. El LMS manda:

- `course.price` → escalar, en la **moneda principal** → va al `_regular_price` nativo.
- `course.pricesByCurrency` → `[{code, regular, sale}]` → se guarda en
  `_studiahub_prices` y se reparte en dos:
  - la **moneda base** va al producto nativo (`_regular_price` **y `_sale_price`**).
    Si el LMS deja de mandar precio para la base, la oferta que había escrito el
    connector **se limpia** (si no, el regular se actualiza y la promo vieja queda
    vigente pisándolo). Solo se limpia la que puso el connector: la marca
    `_studiahub_native_sale` distingue esa de una promo cargada a mano por el admin,
    que no se toca;
  - las **secundarias** van a los postmeta del switcher.

  Además, en cada sync se **borran** los postmeta del switcher que ya no
  corresponden: los de la moneda base y los de monedas que el LMS dejó de mandar o
  que quedaron de una moneda base anterior. Sin esa limpieza, un fijo viejo se queda
  pisando el precio para siempre (`Multicurrency::delete_stale_metas`).

### Safeguard (por qué a veces se "traba" el checkout)

Si el visitante elige una moneda que **no es la base** y el LMS **no** definió un
precio fijo para esa moneda, el connector **frena el checkout** con un aviso, en vez
de cobrar la conversión por tasa (que sería un precio incorrecto). O sea: si una
moneda no tiene precio cargado en el LMS, en esa moneda **no se vende** — es a
propósito. (`Multicurrency::guard_checkout`.)

---

## Parte 4 — Troubleshooting: precios por moneda

Verificá los postmeta del producto con el plugin **Post Meta Inspector** (muestra los
meta protegidos que empiezan con `_`, que el panel nativo de WP oculta).

| Síntoma | Causa | Resolución |
|---|---|---|
| **Todos** los campos del switcher vacíos, pero `_studiahub_prices` trae las monedas | El connector no detecta el plugin de switcher | Con **Booster Plus 8.x** hace falta plugin **≥ 0.15.8** (antes `is_booster()` no detectaba `WC_Jetpack`). Actualizar el plugin + re-sync. |
| Una moneda se llena y la otra **no** (ej: USD sí, ARS no) | `woocommerce_currency` está en la moneda que **no** se llena | `push_booster`/`push_woocs` **saltean la moneda base** (esa la cubre el nativo). Si ARS queda vacío, es porque el store base **es** ARS. Alinear `Ajustes → General → Moneda` con la principal del LMS y re-sincronizar. |
| El precio base sale absurdo (ej: $0,00, o 720000 donde debería ir 720) | `woocommerce_currency` ≠ moneda principal del LMS, **o** el módulo Booster "Product Base Price per-product" está activo | Aplicar la regla de oro (Parte 3). Confirmar ese módulo desactivado. |
| Precio $0,00 en el front con el switcher en una moneda | Esa moneda no tiene precio fijo cargado (switcher convertiría por tasa, pero no hay fijo) | Cargar el precio de esa moneda en el LMS, re-sync. |
| El switcher muestra un precio **viejo** en la moneda base (ej: quedó 720/520 cuando el LMS dice 360) | Postmeta huérfanos: se escribieron cuando esa moneda **no** era la base y nunca se limpiaron | Corregido en **≥ 0.16.3**: el sync borra los metas del switcher de la moneda base. Actualizar el plugin + re-sync. |
| Se borra la oferta en el LMS y el checkout sigue cobrando el precio de promo (o al revés: hay promo y cobra el precio lleno) en la **moneda principal** | El connector nunca escribía el `_sale_price` nativo | Corregido en **≥ 0.16.3**. Actualizar el plugin + re-sync. |

### Qué postmeta esperar (producto bien configurado, Booster, base USD)

```
_regular_price                                       → 720        (moneda principal / base)
_sale_price                                          → 520        (oferta de la base; vacío si no hay)
_studiahub_native_sale                               → 520        (marca: esa oferta la puso el connector)
_studiahub_prices                                    → [{ARS: 720000/520000}, {USD: 720/520}]
_wcj_multicurrency_per_product_regular_price_ARS     → 720000     (secundaria, la escribe el connector)
_wcj_multicurrency_per_product_sale_price_ARS        → 520000
_wcj_multicurrency_per_product_regular_price_USD     → (NO existe: es la base, la cubre _regular_price)
```

> Con **WOOCS** las keys son `_woocs_regular_price_{CUR}` / `_woocs_sale_price_{CUR}`
> (valor `-1` = "convertir por tasa"). Las dos ramas (WOOCS / Booster) son
> independientes en el código y conviven sin pisarse: un tenant usa una u otra.

### Snippet de diagnóstico (detección del switcher)

Si sospechás que el connector no detecta el plugin de moneda, pegá esto en
**Code Snippets** (modo "Run everywhere"), entrá logueado como admin a
`https://<tenant>/?slc_booster_diag=1`, y borralo después:

```php
add_action('init', function () {
    if (empty($_GET['slc_booster_diag']) || !current_user_can('manage_options')) return;
    $active = (array) get_option('active_plugins', []);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SLC MONEDA DIAG\n\n";
    print_r([
        'woocommerce_currency'  => get_option('woocommerce_currency'),
        'WOOCS'                 => class_exists('WOOCS') ? 'SI' : 'NO',
        'WC_Jetpack (Booster)'  => class_exists('WC_Jetpack') ? 'SI' : 'NO',
        'wcj_get_option'        => function_exists('wcj_get_option') ? 'SI' : 'NO',
        'booster_path'          => implode(', ', array_filter($active, function ($p) {
            return stripos($p, 'booster') !== false || stripos($p, 'jetpack') !== false;
        })),
    ]);
    exit;
});
```

---

## Referencias de código

- `includes/class-landing-fetch.php` — fetch del payload (transient 15 min + stale 7 días).
- `includes/class-rest-course-sync.php` — crea/actualiza el producto; `_regular_price` nativo (líneas 127/175) y `push_prices` (217).
- `includes/class-multicurrency.php` — bridge de multimoneda: `push_prices`, `push_woocs`, `push_booster`, `is_booster` (110), safeguard `guard_checkout` (57).
- `includes/class-shortcode-fields.php` — shortcodes granulares (cadena de payload en `get_payload`, 127).

Ver también: [LANDING-PAYLOAD.md](LANDING-PAYLOAD.md) (todos los campos del payload),
[granular-shortcodes.md](granular-shortcodes.md) (shortcodes sueltos para landing custom).
