<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hace que un combo inscriba en sus N cursos SIN IMPORTAR cómo se creó el pedido.
 *
 * EL CONTRATO
 * -----------
 * El LMS resuelve un combo mirando UNA sola cosa: el meta `_lms_course_ids` en
 * `line_items[].meta_data` del payload (`parseComboCourseIds()` en
 * `src/lib/wc-orders.ts`). No mira el producto. Si ese meta no está, el item no
 * matchea ningún curso y el pedido termina en `no_match`: cero inscripciones.
 *
 * Ese meta no aparece solo. WooCommerce NO copia el postmeta de un producto al
 * order item, y `line_items[].meta_data` es el meta del ORDER ITEM, no el del
 * producto. Alguien lo tiene que escribir.
 *
 * EL AGUJERO QUE ESTO CIERRA
 * --------------------------
 * Hasta acá lo escribía un solo hook: `woocommerce_checkout_create_order_line_item`,
 * que corre ÚNICAMENTE en el checkout del front. Un pedido nacido en cualquier
 * otro lado no lo dispara nunca:
 *
 *   - alta manual desde wp-admin (Pedidos → Añadir pedido → Añadir artículos);
 *   - `POST /wc/v3/orders` desde un ERP, un POS o una automatización;
 *   - WP-CLI, o cualquier código que use `wc_create_order()`.
 *
 * En Argentina el primero no es un caso raro: es EL flujo de la transferencia
 * bancaria. El cliente transfiere, el admin carga el pedido a mano y lo marca
 * pagado. Se cobró la venta, el webhook salió, el LMS contestó 200 — y el alumno
 * nunca tuvo acceso a nada.
 *
 * LAS DOS CAPAS (y por qué hacen falta las dos)
 * ---------------------------------------------
 * 1. ESCRITURA (`backfill_order_item`, hook `woocommerce_new_order_item`).
 *    Ese hook lo dispara el data store al CREAR cualquier order item, así que
 *    cubre los cuatro caminos de arriba de una. Deja el meta guardado en el
 *    pedido: queda visible en wp-admin y es el registro de qué incluía el combo
 *    EL DÍA DE LA COMPRA. Si mañana el dueño le saca un curso al combo, el
 *    pedido viejo sigue diciendo la verdad.
 *
 * 2. RESCATE (`fill_missing_combo_meta`, en la serialización del payload).
 *    Si el meta igual no está, lo resolvemos leyendo el postmeta del producto en
 *    el momento de armar el body. Cubre lo que la capa 1 no puede:
 *      - los pedidos que YA existen sin el meta (acá: 83, 110, 111), que se
 *        recuperan con un "entregar de nuevo" o con el cron de reconciliación;
 *      - cualquier camino exótico que persista items sin pasar por el data store.
 *    Corre tanto en la entrega del webhook como en `GET /orders/recent` (el pull
 *    del cron), porque las dos pasan por `Webhook_Payload::serialize_order()`.
 *
 * El orden importa: la capa 2 SOLO actúa si no hay meta. O sea que la foto del
 * momento de la compra (capa 1) siempre le gana a la reconstrucción de hoy. La
 * capa 2 es una red, no la fuente de verdad — su límite conocido es que, para un
 * pedido viejo sin meta, refleja el combo TAL COMO ESTÁ HOY. Es la única opción
 * disponible cuando no hay foto, y es infinitamente mejor que cero inscripciones.
 *
 * El hook del checkout se mantiene aunque `woocommerce_new_order_item` ya lo
 * cubriría: escribe el meta ANTES del primer save (una sola escritura, sin
 * UPDATE extra) y es el camino validado E2E de la venta real. La capa 1 lo
 * detecta y no lo pisa.
 *
 * Confirmado que el meta llega al webhook aunque la key empiece con `_`: el REST
 * controller arma `line_items[].meta_data` desde `WC_Order_Item::get_data()`
 * (que usa get_meta_data() crudo, sin filtrar el prefijo `_`).
 *
 * Formato del valor: el mismo JSON array string que persistimos en el postmeta
 * (ej `["uuid1","uuid2"]`). El LMS hace JSON.parse.
 */
final class Order_Combo_Meta {
    /** Key del meta que escribimos en el order item del combo. */
    public const ORDER_ITEM_META = '_lms_course_ids';

    public static function register_hooks(): void {
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'add_combo_meta'], 10, 4);
        add_action('woocommerce_new_order_item', [self::class, 'backfill_order_item'], 10, 2);
    }

    // ────────────────────────────────────────────────── CAPA 1 — escritura

    /**
     * Checkout del front. El item todavía no se guardó, así que alcanza con
     * dejarle el meta puesto: lo persiste el save del pedido.
     *
     * @param \WC_Order_Item_Product $item
     * @param string                 $cart_item_key
     * @param array                  $values
     * @param \WC_Order              $order
     */
    public static function add_combo_meta($item, $cart_item_key, $values, $order): void {
        if (!$item instanceof \WC_Order_Item_Product) {
            return;
        }
        $course_ids = self::combo_course_ids((int) $item->get_product_id());
        if ($course_ids === []) {
            return;
        }
        $item->add_meta_data(self::ORDER_ITEM_META, wp_json_encode($course_ids), true);
    }

    /**
     * Cualquier otro origen: wp-admin, REST API, WP-CLI, `wc_create_order()`.
     *
     * `woocommerce_new_order_item` lo dispara
     * `Abstract_WC_Order_Item_Type_Data_Store::create()`, o sea que corre para
     * TODO item recién persistido, venga de donde venga. Por eso es el punto
     * correcto para esto y no una lista de hooks de wp-admin: no hay que
     * adivinar los orígenes: se cubren todos.
     *
     * Acá el item YA está en la base, así que hay que guardar el meta a mano con
     * `save_meta_data()` (`add_meta_data()` solo lo pone en memoria).
     *
     * Idempotente por doble motivo: si el item ya tiene el meta —el caso del
     * checkout, que lo escribió unos milisegundos antes— salimos sin tocar nada,
     * y `add_meta_data(..., true)` es unique, así que ni aunque el hook corriera
     * dos veces habría filas duplicadas.
     *
     * @param int   $item_id
     * @param mixed $item
     */
    public static function backfill_order_item($item_id, $item): void {
        if (!$item instanceof \WC_Order_Item_Product) {
            return; // envíos, cupones, tasas: no son productos
        }
        if (trim((string) $item->get_meta(self::ORDER_ITEM_META, true)) !== '') {
            return; // ya lo puso el checkout
        }
        $course_ids = self::combo_course_ids((int) $item->get_product_id());
        if ($course_ids === []) {
            return;
        }
        $item->add_meta_data(self::ORDER_ITEM_META, wp_json_encode($course_ids), true);
        $item->save_meta_data();
    }

    // ─────────────────────────────────────────────────── CAPA 2 — rescate

    /**
     * Completa los `_lms_course_ids` que falten en los line items de un payload
     * de pedido ya serializado, resolviéndolos desde el postmeta del producto.
     *
     * La llama `Webhook_Payload` en los dos únicos lugares por donde sale un
     * payload de pedido hacia el LMS: el filtro `woocommerce_webhook_payload`
     * (toda entrega de webhook, sana o reparada) y `serialize_order()` (que usa
     * además `GET /orders/recent`, el pull del cron de reconciliación). Con esos
     * dos puntos cubiertos, un combo inscribe igual por webhook en vivo, por
     * "entregar de nuevo" desde wp-admin o por reconciliación.
     *
     * No escribe en la base: healear un pedido es efecto de un GET del cron o de
     * un shutdown de checkout, y ninguno de los dos es contexto para mutar
     * pedidos. La escritura vive en la capa 1, que sí corre en contexto de
     * escritura.
     *
     * Idempotente: si el item ya trae el meta, no lo toca.
     */
    public static function fill_missing_combo_meta(array $payload): array {
        if (empty($payload['line_items']) || !is_array($payload['line_items'])) {
            return $payload;
        }

        foreach ($payload['line_items'] as $index => $item) {
            if (!is_array($item) || self::payload_item_has_meta($item)) {
                continue;
            }
            $course_ids = self::combo_course_ids((int) ($item['product_id'] ?? 0));
            if ($course_ids === []) {
                continue;
            }
            $encoded = wp_json_encode($course_ids);

            // Mismo shape que arma el REST controller de WooCommerce para un
            // meta real (id/key/value/display_key/display_value). `id => 0`
            // porque no existe una fila en la base detrás de este: es un meta
            // derivado. El LMS solo lee key/value, pero el payload también se
            // guarda entero en `WebhookLog.payload`, así que mantener el shape
            // evita que un pedido rescatado se lea distinto a uno normal.
            $payload['line_items'][$index]['meta_data'][] = [
                'id'            => 0,
                'key'           => self::ORDER_ITEM_META,
                'value'         => $encoded,
                'display_key'   => self::ORDER_ITEM_META,
                'display_value' => $encoded,
            ];
        }

        return $payload;
    }

    /** ¿El line item del payload ya trae `_lms_course_ids` con algo adentro? */
    private static function payload_item_has_meta(array $item): bool {
        if (empty($item['meta_data']) || !is_array($item['meta_data'])) {
            return false;
        }
        foreach ($item['meta_data'] as $meta) {
            $key = is_array($meta) ? ($meta['key'] ?? null) : ($meta->key ?? null);
            if ($key !== self::ORDER_ITEM_META) {
                continue;
            }
            $value = is_array($meta) ? ($meta['value'] ?? null) : ($meta->value ?? null);
            // Un meta presente pero vacío no sirve de nada y taparía el rescate.
            if (is_string($value) ? trim($value) !== '' : !empty($value)) {
                return true;
            }
        }
        return false;
    }

    // ────────────────────────────────────────────────────────────── HELPERS

    /**
     * Course IDs del combo, o `[]` si el producto no es un combo (o es un combo
     * sin cursos cargados, que es lo mismo que nada para el LMS).
     *
     * @return array<int, string>
     */
    private static function combo_course_ids(int $product_id): array {
        if ($product_id <= 0) {
            return [];
        }
        if (get_post_meta($product_id, Product_Metabox::META_IS_COMBO, true) !== 'yes') {
            return [];
        }
        return array_values(Product_Metabox::get_selected_course_ids($product_id));
    }
}
