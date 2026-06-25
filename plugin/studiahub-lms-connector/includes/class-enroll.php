<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint de inscripción.
 *
 * Reemplaza el patrón nativo de WooCommerce `?add-to-cart=ID` sobre la URL del
 * checkout, que tiene dos defectos en este flujo:
 *
 *   1) Recargar el checkout re-procesa el query arg e intenta agregar otra vez
 *      el curso. Como ya está en el carrito, el re-agregado falla, WooCommerce
 *      no dispara el redirect que limpia la URL y el `?add-to-cart=` queda
 *      pegado, mostrando el aviso "no se puede agregar otro producto".
 *   2) Cambiar la moneda en el switcher recarga esa misma URL con el query arg
 *      pegado, re-disparando el problema.
 *
 * En su lugar interceptamos un parámetro propio (`slc_enroll`), agregamos el
 * curso al carrito server-side (sin duplicar si ya estaba) y redirigimos al
 * checkout LIMPIO. Así la URL final nunca lleva query args de carrito: recargar
 * o cambiar de moneda ya no vuelve a tocar el carrito.
 *
 * Sin nonce a propósito: igual que el `add-to-cart` nativo de WC, para no
 * romper cuando la landing está cacheada. El peor caso de un link compartido es
 * "se agrega un curso al carrito de un visitante", sin impacto (no cobra nada).
 */
final class Enroll {

    /** Query arg que dispara la inscripción. */
    private const PARAM = 'slc_enroll';

    public static function register_hooks(): void {
        // Prioridad 20: después de que WC carga el carrito desde la sesión
        // (wp_loaded/10), mismo timing que usa el form handler nativo de WC.
        add_action('wp_loaded', [self::class, 'handle'], 20);
    }

    /** URL del botón "inscribirme" para un curso. */
    public static function url(int $product_id): string {
        if (!function_exists('wc_get_checkout_url')) {
            return '#';
        }
        // Base = checkout para degradar bien si el handler no llegara a correr;
        // en el flujo normal redirige antes de renderizar nada.
        return add_query_arg(self::PARAM, $product_id, wc_get_checkout_url());
    }

    /** Intercepta el endpoint, agrega el curso y redirige al checkout limpio. */
    public static function handle(): void {
        if (empty($_GET[self::PARAM])) {
            return;
        }
        $product_id = absint(wp_unslash($_GET[self::PARAM]));
        if ($product_id <= 0 || !function_exists('WC') || is_null(WC()->cart)) {
            return;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product || !$product->is_purchasable()) {
            // Producto inexistente o no comprable: al carrito, donde WC muestra
            // el aviso correspondiente en vez de un checkout vacío.
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        // Acumular sin duplicar: si el curso ya está en el carrito no lo
        // re-agregamos (un curso no se compra dos veces).
        $cart_id = WC()->cart->generate_cart_id($product_id);
        if (!WC()->cart->find_product_in_cart($cart_id)) {
            WC()->cart->add_to_cart($product_id);
        }

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}
