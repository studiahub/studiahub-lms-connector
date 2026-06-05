<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Copia los course IDs del combo (postmeta del producto `_lms_course_ids`) al
 * meta del order item cuando se crea cada line_item de la orden.
 *
 * Por qué hace falta: WooCommerce NO copia el postmeta de un producto al order
 * item automáticamente. El array `line_items[].meta_data` del webhook
 * (order.updated, REST v3) contiene el meta del ORDER ITEM, no el del producto.
 * Para que el LMS lea los N cursos del combo, tenemos que escribirlos en el
 * order item acá, en el checkout.
 *
 * Hook: `woocommerce_checkout_create_order_line_item($item, $cart_item_key,
 * $values, $order)`. Se dispara al crear cada line_item en el checkout, con el
 * item ya poblado (product_id, qty, precio). Escribimos el meta con
 * `$item->add_meta_data()`.
 *
 * Confirmado que el meta llega al webhook aunque la key empiece con `_`: el REST
 * controller arma `line_items[].meta_data` desde `WC_Order_Item::get_data()`
 * (que usa get_meta_data() crudo, sin filtrar el prefijo `_`). El handler del
 * LMS lo lee con `item.meta_data.find(m => m.key === '_lms_course_ids')`.
 *
 * Formato del valor: el mismo JSON array string que persistimos en el postmeta
 * (ej `["uuid1","uuid2"]`). El LMS hace JSON.parse.
 */
final class Order_Combo_Meta {
    /** Key del meta que escribimos en el order item del combo. */
    public const ORDER_ITEM_META = '_lms_course_ids';

    public static function register_hooks(): void {
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'add_combo_meta'], 10, 4);
    }

    /**
     * @param \WC_Order_Item_Product $item
     * @param string                 $cart_item_key
     * @param array                  $values
     * @param \WC_Order              $order
     */
    public static function add_combo_meta($item, $cart_item_key, $values, $order): void {
        if (!$item instanceof \WC_Order_Item_Product) {
            return;
        }

        $product_id = (int) $item->get_product_id();
        if ($product_id <= 0) {
            return;
        }

        if (get_post_meta($product_id, Product_Metabox::META_IS_COMBO, true) !== 'yes') {
            return; // No es combo.
        }

        $course_ids = Product_Metabox::get_selected_course_ids($product_id);
        if (empty($course_ids)) {
            return; // Combo sin cursos: nada que mandar.
        }

        // Valor escalar (JSON array string) — viaja limpio en el webhook.
        $item->add_meta_data(self::ORDER_ITEM_META, wp_json_encode(array_values($course_ids)), true);
    }
}
