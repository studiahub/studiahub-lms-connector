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
        $args = [self::PARAM => $product_id];
        // Forzar moneda en el checkout (hoy: ARS con WOOCS) si el tenant lo
        // permite. Si el guardrail devuelve '', el botón queda como siempre.
        // handle() se encarga de propagar este param al checkout limpio.
        $currency = Multicurrency::forced_checkout_currency();
        if ($currency !== '') {
            $args['currency'] = $currency;
        }
        return add_query_arg($args, wc_get_checkout_url());
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

        // Cierre de inscripciones / preventa. `is_purchasable()` de arriba ya lo
        // cubre en el caso normal, pero solo mira la caché: si este WordPress
        // todavía no tiene el payload del curso (nunca se renderizó la landing),
        // deja pasar. Acá sí podemos preguntarle al LMS — es una acción del
        // visitante, no un listado — y este endpoint es justamente el link que
        // viaja por WhatsApp y queda en los marcadores.
        //
        // Además `WC_Cart::add_to_cart()` NO dispara
        // `woocommerce_add_to_cart_validation`, así que sin este chequeo el
        // curso cerrado entraba igual al carrito y recién lo frenaba el checkout.
        $closed = Purchase_Gate::closed_state($product_id, true);
        if ($closed !== null) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice($closed['label'], 'notice');
            }
            wp_safe_redirect(get_permalink($product_id) ?: wc_get_cart_url());
            exit;
        }

        // Acumular sin duplicar: si el curso ya está en el carrito no lo
        // re-agregamos (un curso no se compra dos veces).
        $cart_id = WC()->cart->generate_cart_id($product_id);
        if (!WC()->cart->find_product_in_cart($cart_id)) {
            WC()->cart->add_to_cart($product_id);
        }

        // El checkout final va LIMPIO (sin slc_enroll, para no re-disparar el
        // carrito al recargar). Pero si el botón pidió forzar una moneda,
        // propagamos SOLO ese param: WOOCS lo lee y abre el checkout en esa
        // moneda. Es idempotente, recargar no rompe nada.
        $redirect = wc_get_checkout_url();
        if (!empty($_GET['currency'])) {
            $currency = strtoupper(sanitize_text_field(wp_unslash($_GET['currency'])));
            if (preg_match('/^[A-Z]{3}$/', $currency)) {
                $redirect = add_query_arg('currency', $currency, $redirect);
            }
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
