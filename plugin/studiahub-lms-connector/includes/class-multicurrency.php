<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bridge multimoneda.
 *
 * El LMS empuja los precios fijos por moneda (course.pricesByCurrency). Este
 * módulo los escribe en los postmetas que lee el plugin de moneda del sitio
 * (WOOCS / Booster), para que el checkout cobre el precio fijo de cada moneda en
 * lugar de convertir por tasa. Se llama desde REST_Course_Sync en cada sync.
 *
 * La moneda BASE del store (la del checkout / pasarela, típicamente USD) NO se
 * toca: la cobra el `_regular_price` nativo de WooCommerce. Solo escribimos las
 * monedas extra.
 *
 * WOOCS (formato confirmado en runtime):
 *   _woocs_regular_price_{CUR}  → precio fijo, o -1 = "convertir por tasa"
 *   _woocs_sale_price_{CUR}     → oferta fija, o -1 = "sin oferta"
 * Requiere que el cliente tenga activo el modo de precios fijos por producto
 * (woocs_is_multiple_allowed + woocs_is_fixed_enabled).
 *
 * MVP: escribimos el precio NORMAL (regular) por moneda; la oferta (sale) se deja
 * en -1 — la oferta multimoneda con vencimiento se afina en un paso posterior
 * (la vigencia vive en el LMS y requiere re-sync al vencer).
 */
final class Multicurrency {

    /** Valor que le dice al switcher "no hay precio fijo, convertí por tasa". */
    private const NO_FIXED = -1;

    /**
     * Escribe los precios por moneda del curso en los postmetas del switcher.
     *
     * @param int   $product_id
     * @param mixed $raw_prices  course.pricesByCurrency: [{code, regular, sale}]
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
    }

    private static function is_booster(): bool {
        return function_exists('wcj_get_woocommerce_currency') || class_exists('WCJ');
    }

    /**
     * Normaliza el payload del LMS a [CUR => ['regular' => string, 'sale' => string|null]].
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

    /**
     * WOOCS. Itera sobre las monedas configuradas en el switcher: a las que el LMS
     * trae precio les pone el fijo; a las demás -1 (resetea a conversión por tasa,
     * por si antes tenían un fijo obsoleto). La base se saltea (precio nativo).
     */
    private static function push_woocs(int $product_id, array $prices, string $base): void {
        foreach (self::woocs_currencies() as $cur) {
            if ($cur === '' || $cur === $base) {
                continue;
            }
            $regular = isset($prices[$cur]) ? $prices[$cur]['regular'] : self::NO_FIXED;
            update_post_meta($product_id, '_woocs_regular_price_' . $cur, $regular);
            // Oferta multimoneda = paso posterior; por ahora siempre sin oferta fija.
            update_post_meta($product_id, '_woocs_sale_price_' . $cur, self::NO_FIXED);
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

    /**
     * Booster (módulo Multicurrency per-product). Meta keys según el source del
     * plugin; se valida cuando probemos Booster en dev.
     */
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
}
