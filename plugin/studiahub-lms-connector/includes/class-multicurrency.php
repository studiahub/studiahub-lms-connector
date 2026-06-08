<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bridge multimoneda + safeguard.
 *
 * 1) PUSH (desde el sync): escribe los precios por moneda del LMS en los postmetas
 *    que lee el switcher (WOOCS / Booster), para que el checkout cobre el precio
 *    fijo de cada moneda en lugar de convertir por tasa. La base del store (USD)
 *    queda en el _regular_price nativo. Guardamos además _studiahub_prices como
 *    registro propio (fuente de verdad del safeguard).
 *
 * 2) SAFEGUARD (front): si el visitante tiene una moneda != base y el LMS NO
 *    definió un precio fijo para esa moneda (el sync falló o no se cargó), el
 *    producto del LMS queda NO COMPRABLE en esa moneda. Así, ante cualquier falla,
 *    el cliente nunca paga la conversión por tasa (precio incorrecto): compra en la
 *    moneda base o se frena. El peor caso es recuperable, no una venta mal cobrada.
 *
 * WOOCS (formato confirmado en runtime):
 *   _woocs_regular_price_{CUR}  → precio fijo, o -1 = "convertir por tasa"
 *   _woocs_sale_price_{CUR}     → oferta fija, o -1 = "sin oferta"
 * WOOCS aplica el sale fijo siempre que el meta != -1 (no chequea fechas), así que
 * la VIGENCIA de la oferta la decide el LMS: manda el sale solo si está vigente.
 */
final class Multicurrency {

    /** Valor que le dice al switcher "no hay precio fijo, convertí por tasa". */
    private const NO_FIXED = -1;

    /** Cache por-request de _studiahub_prices normalizado, por product id. */
    private static array $cache = [];

    // ───────────────────────────────────────────────────────────── HOOKS (front)

    public static function register_hooks(): void {
        if (is_admin()) {
            return;
        }
        // Safeguard: si la moneda activa no tiene precio fijo del LMS, frenamos el
        // checkout con un aviso claro en vez de cobrar la conversión por tasa.
        // check_cart_items bloquea el botón al cargar el checkout; checkout_process
        // es la red final al hacer submit. (Solo afecta cuando la moneda activa NO
        // es la base, así que el carrito en la moneda base no se traba.)
        add_action('woocommerce_check_cart_items', [self::class, 'guard_checkout']);
        add_action('woocommerce_checkout_process', [self::class, 'guard_checkout']);
    }

    /**
     * Safeguard: si la moneda de pago elegida no es la base y algún curso del
     * carrito no tiene precio fijo del LMS para esa moneda, frena el pago con un
     * aviso. Garantiza que nunca se cobra la conversión por tasa.
     */
    public static function guard_checkout(): void {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        $current = self::active_currency();
        $base    = strtoupper((string) get_option('woocommerce_currency'));
        if ($current === '' || $current === $base) {
            return; // la base la cobra el _regular_price nativo
        }
        foreach (WC()->cart->get_cart() as $item) {
            $pid = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            if ($pid <= 0 || !get_post_meta($pid, '_lms_course_id', true)) {
                continue; // solo productos del LMS
            }
            $prices = self::stored_prices($pid);
            if (!isset($prices[$current])) {
                $avail = implode(' / ', array_keys($prices));
                wc_add_notice(
                    sprintf(
                        /* translators: 1: nombre del curso, 2: moneda actual, 3: monedas disponibles */
                        esc_html__('El curso "%1$s" no está disponible en %2$s. Cambiá la moneda de pago a: %3$s.', 'studiahub-lms-connector'),
                        get_the_title($pid),
                        $current,
                        $avail !== '' ? $avail : $base
                    ),
                    'error'
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────── PUSH (desde sync)

    /**
     * Escribe los precios por moneda del curso en los postmetas del switcher.
     *
     * @param int   $product_id
     * @param mixed $raw_prices  course.pricesByCurrency: [{code, regular, sale}]
     *                           (el LMS ya filtró el sale por vigencia de la oferta)
     */
    public static function push_prices(int $product_id, $raw_prices): void {
        $prices = self::normalize($raw_prices);
        $base   = strtoupper((string) get_option('woocommerce_currency'));

        if (class_exists('WOOCS')) {
            self::push_woocs($product_id, $prices, $base);
        }
        if (self::is_booster()) {
            self::push_booster($product_id, $prices, $base);
        }
        unset(self::$cache[$product_id]);
    }

    private static function is_booster(): bool {
        return function_exists('wcj_get_woocommerce_currency') || class_exists('WCJ');
    }

    /**
     * WOOCS. Itera sobre las monedas del switcher: a las que el LMS trae precio les
     * pone el fijo (regular + sale si hay oferta vigente); a las demás -1 (resetea a
     * conversión, por si tenían un fijo obsoleto). La base se saltea (precio nativo).
     */
    private static function push_woocs(int $product_id, array $prices, string $base): void {
        foreach (self::woocs_currencies() as $cur) {
            if ($cur === '' || $cur === $base) {
                continue;
            }
            if (isset($prices[$cur])) {
                update_post_meta($product_id, '_woocs_regular_price_' . $cur, $prices[$cur]['regular']);
                update_post_meta($product_id, '_woocs_sale_price_' . $cur, $prices[$cur]['sale'] ?? self::NO_FIXED);
            } else {
                update_post_meta($product_id, '_woocs_regular_price_' . $cur, self::NO_FIXED);
                update_post_meta($product_id, '_woocs_sale_price_' . $cur, self::NO_FIXED);
            }
        }
    }

    /** Códigos de moneda configurados en WOOCS (uppercase). */
    private static function woocs_currencies(): array {
        global $WOOCS;
        $out = [];
        if (is_object($WOOCS) && method_exists($WOOCS, 'get_currencies')) {
            foreach ((array) $WOOCS->get_currencies() as $code => $_data) {
                $code = strtoupper((string) $code);
                if ($code !== '') {
                    $out[] = $code;
                }
            }
        }
        return $out;
    }

    /** Booster (módulo Multicurrency per-product). A validar cuando probemos Booster. */
    private static function push_booster(int $product_id, array $prices, string $base): void {
        foreach ($prices as $cur => $p) {
            if ($cur === $base) {
                continue;
            }
            update_post_meta($product_id, '_wcj_multicurrency_per_product_regular_price_' . $cur, $p['regular']);
            if ($p['sale'] !== null) {
                update_post_meta($product_id, '_wcj_multicurrency_per_product_sale_price_' . $cur, $p['sale']);
            } else {
                delete_post_meta($product_id, '_wcj_multicurrency_per_product_sale_price_' . $cur);
            }
        }
    }

    // ───────────────────────────────────────────────────────────────────── HELPERS

    /** Moneda elegida por el visitante en el switcher (ISO uppercase). */
    private static function active_currency(): string {
        if (class_exists('WOOCS')) {
            global $WOOCS;
            if (is_object($WOOCS) && !empty($WOOCS->current_currency)) {
                return strtoupper((string) $WOOCS->current_currency);
            }
        }
        return strtoupper((string) get_woocommerce_currency());
    }

    /** Precios del LMS guardados en _studiahub_prices (fuente de verdad del safeguard). */
    private static function stored_prices(int $product_id): array {
        if (!array_key_exists($product_id, self::$cache)) {
            $raw = get_post_meta($product_id, '_studiahub_prices', true);
            $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
            self::$cache[$product_id] = self::normalize(is_array($decoded) ? $decoded : []);
        }
        return self::$cache[$product_id];
    }

    /**
     * Normaliza a [CUR => ['regular' => string, 'sale' => string|null]].
     * Descarta entradas sin code ISO o sin precio regular numérico.
     */
    private static function normalize($raw): array {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            if (!preg_match('/^[A-Z]{3}$/', $code)) {
                continue;
            }
            $regular = isset($row['regular']) ? trim((string) $row['regular']) : '';
            if ($regular === '' || !is_numeric($regular)) {
                continue;
            }
            $sale = (isset($row['sale']) && $row['sale'] !== null) ? trim((string) $row['sale']) : '';
            $out[$code] = [
                'regular' => $regular,
                'sale'    => ($sale !== '' && is_numeric($sale)) ? $sale : null,
            ];
        }
        return $out;
    }
}
