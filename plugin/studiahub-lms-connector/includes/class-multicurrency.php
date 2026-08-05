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
 *    fijo de cada moneda en lugar de convertir por tasa. La moneda base del store
 *    NO va al switcher: la cobra el precio nativo del producto (regular + sale),
 *    que también escribimos desde el LMS. Guardamos además _studiahub_prices como
 *    registro propio (fuente de verdad del safeguard).
 *
 * 2) SAFEGUARD (front): si el visitante tiene una moneda != base y NO hay un precio
 *    fijo para esa moneda (el sync falló, no se cargó, o es un combo que nadie
 *    tarifó), el producto queda NO COMPRABLE en esa moneda. Así, ante cualquier
 *    falla, el cliente nunca paga la conversión por tasa (precio incorrecto):
 *    compra en la moneda base o se frena. El peor caso es recuperable, no una
 *    venta mal cobrada.
 *
 *    LOS COMBOS TAMBIÉN. Un combo es un producto de WooCommerce sin registro en el
 *    LMS, así que el validador del LMS que impide publicar un curso sin todas sus
 *    monedas cargadas (`syncCourseToWC`) no lo toca ni lo puede tocar. Sin este
 *    safeguard, un pack de USD 200 con la tasa de WOOCS en 1440,5 se cobraba
 *    288.100 ARS: un precio que no decidió nadie, que depende de una cotización
 *    que alguien tiene que mantener a mano en WordPress, y que se desactualiza
 *    sola. El precio fijo de un combo lo carga el dueño en los campos por moneda
 *    del switcher (en WOOCS: pestaña "General" del producto, con "Fixed prices"
 *    habilitado). Si no lo cargó, en esa moneda no se vende — a propósito.
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

    /** Qué vende un producto nuestro: un curso del LMS o un combo armado en WC. */
    private const KIND_COURSE = 'course';
    private const KIND_COMBO  = 'combo';

    /**
     * Marca de propiedad de la oferta nativa: guarda el sale que escribimos desde el
     * LMS. Si existe, la promo del producto es NUESTRA y la podemos limpiar; si no
     * existe, la cargó el admin a mano en WooCommerce y no la tocamos.
     */
    private const NATIVE_SALE = '_studiahub_native_sale';

    /** Prefijos de los postmeta por moneda de cada switcher. */
    private const WOOCS_REGULAR   = '_woocs_regular_price_';
    private const WOOCS_SALE      = '_woocs_sale_price_';
    private const BOOSTER_REGULAR = '_wcj_multicurrency_per_product_regular_price_';
    private const BOOSTER_SALE    = '_wcj_multicurrency_per_product_sale_price_';

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
     * Safeguard: si la moneda de pago elegida no es la base y algo del carrito no
     * tiene precio FIJO para esa moneda, frena el pago con un aviso. Garantiza
     * que nunca se cobra la conversión por tasa.
     *
     * Cubre las dos cosas que vendemos, con una fuente de verdad distinta cada
     * una (ver `has_fixed_price`): los cursos sincronizados desde el LMS y los
     * combos, que son productos de WooCommerce sin registro en el LMS.
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
            if ($pid <= 0) {
                continue;
            }
            $kind = self::product_kind($pid);
            if ($kind === null) {
                continue; // no es nuestro: un ebook, una consultoría, lo que sea
            }
            if (self::has_fixed_price($pid, $current, $kind)) {
                continue;
            }

            $avail = self::sellable_currencies($pid, $kind, $base);
            $avail = $avail !== [] ? implode(' / ', $avail) : $base;

            wc_add_notice(
                $kind === self::KIND_COMBO
                    ? sprintf(
                        /* translators: 1: nombre del combo, 2: moneda actual, 3: monedas disponibles */
                        esc_html__('El combo "%1$s" no está disponible en %2$s. Cambiá la moneda de pago a: %3$s.', 'studiahub-lms-connector'),
                        get_the_title($pid),
                        $current,
                        $avail
                    )
                    : sprintf(
                        /* translators: 1: nombre del curso, 2: moneda actual, 3: monedas disponibles */
                        esc_html__('El curso "%1$s" no está disponible en %2$s. Cambiá la moneda de pago a: %3$s.', 'studiahub-lms-connector'),
                        get_the_title($pid),
                        $current,
                        $avail
                    ),
                'error'
            );
        }
    }

    /**
     * Qué es este producto para nosotros, o null si no nos incumbe.
     *
     * El combo gana si por algún motivo tuviera las dos marcas: sus precios los
     * carga el dueño en WooCommerce, no el LMS.
     */
    private static function product_kind(int $product_id): ?string {
        if ((string) get_post_meta($product_id, Product_Metabox::META_IS_COMBO, true) === 'yes') {
            return self::KIND_COMBO;
        }
        if ((string) get_post_meta($product_id, '_lms_course_id', true) !== '') {
            return self::KIND_COURSE;
        }
        return null;
    }

    /**
     * ¿Hay un precio FIJO (no una conversión por tasa) para esta moneda?
     *
     * La fuente cambia según qué sea el producto, y no es un detalle de
     * implementación sino el contrato de cada uno:
     *
     *  - CURSO: `_studiahub_prices`, o sea lo que mandó el LMS. Deliberadamente
     *    NO alcanza con un fijo cargado a mano en el switcher: la landing del
     *    curso muestra el precio del LMS, así que aceptar otro precio en el
     *    checkout sería prometer una cosa y cobrar otra. El LMS manda.
     *
     *  - COMBO: los campos del switcher (WOOCS / Booster). Un combo no existe en
     *    el LMS —es un producto de WooCommerce a secas, y su landing es Elementor
     *    a mano— así que no hay ningún precio del LMS con el que contradecirse.
     *    Acá el dueño ES la fuente de verdad, y el único requisito es que el
     *    precio lo haya puesto él a propósito.
     */
    private static function has_fixed_price(int $product_id, string $currency, string $kind): bool {
        if ($kind === self::KIND_COMBO) {
            return self::switcher_fixed_price($product_id, $currency) !== null;
        }
        return isset(self::stored_prices($product_id)[$currency]);
    }

    /**
     * Precio fijo cargado en el switcher para esa moneda, o null si no hay.
     *
     * En WOOCS el `-1` NO es un precio: es literalmente "convertí por tasa", que
     * es justo lo que este safeguard existe para no dejar pasar. Booster no tiene
     * ese centinela: o está el meta con un número, o no está.
     */
    private static function switcher_fixed_price(int $product_id, string $currency): ?string {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }
        foreach ([self::WOOCS_REGULAR, self::BOOSTER_REGULAR] as $prefix) {
            $raw = get_post_meta($product_id, $prefix . $currency, true);
            if (!is_scalar($raw)) {
                continue;
            }
            $raw = trim((string) $raw);
            if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0) {
                continue;
            }
            return $raw;
        }
        return null;
    }

    /**
     * Monedas en las que este producto SÍ se puede comprar, para decírselo al
     * visitante en vez de dejarlo adivinando.
     *
     * @return array<int, string>
     */
    private static function sellable_currencies(int $product_id, string $kind, string $base): array {
        if ($kind !== self::KIND_COMBO) {
            return array_keys(self::stored_prices($product_id));
        }
        // La base siempre se puede: la cobra el precio nativo del producto.
        $out = $base !== '' ? [$base => true] : [];
        foreach (self::product_currency_metas($product_id) as $cur) {
            if (self::switcher_fixed_price($product_id, $cur) !== null) {
                $out[$cur] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Monedas que aparecen en los postmeta por moneda de ESTE producto.
     *
     * Se escanean las claves reales en vez de pedirle la lista al switcher
     * porque Booster no expone una: así el mismo código sirve para los dos.
     *
     * @return array<int, string>
     */
    private static function product_currency_metas(int $product_id): array {
        $out = [];
        foreach (array_keys((array) get_post_meta($product_id)) as $key) {
            foreach ([self::WOOCS_REGULAR, self::BOOSTER_REGULAR] as $prefix) {
                if (strpos((string) $key, $prefix) !== 0) {
                    continue;
                }
                $cur = strtoupper(substr((string) $key, strlen($prefix)));
                if (preg_match('/^[A-Z]{3}$/', $cur)) {
                    $out[$cur] = true;
                }
            }
        }
        return array_keys($out);
    }

    /**
     * Monedas del switcher en las que este combo NO se va a poder vender, para
     * avisarle al dueño en la pantalla del producto y no cuando un cliente se
     * come el bloqueo del checkout.
     *
     * Solo WOOCS: es el único de los dos switchers que expone su lista de
     * monedas. Con Booster devolvemos vacío (no hay aviso), pero el safeguard del
     * checkout protege igual — que es lo que no se negocia.
     *
     * @return array<int, string>
     */
    public static function missing_combo_currencies(int $product_id): array {
        if (!class_exists('WOOCS')) {
            return [];
        }
        $base    = strtoupper((string) get_option('woocommerce_currency'));
        $missing = [];
        foreach (self::woocs_currencies() as $cur) {
            if ($cur === '' || $cur === $base) {
                continue; // la base la cobra el precio nativo
            }
            if (self::switcher_fixed_price($product_id, $cur) === null) {
                $missing[] = $cur;
            }
        }
        return $missing;
    }

    // ─────────────────────────────────────────────────────────── PUSH (desde sync)

    /**
     * Aplica los precios por moneda del curso: la moneda base al precio nativo del
     * producto y el resto a los postmetas del switcher (WOOCS / Booster).
     *
     * @param int   $product_id
     * @param mixed $raw_prices  course.pricesByCurrency: [{code, regular, sale}]
     *                           (el LMS ya filtró el sale por vigencia de la oferta)
     */
    public static function push_prices(int $product_id, $raw_prices): void {
        $prices = self::normalize($raw_prices);
        $base   = strtoupper((string) get_option('woocommerce_currency'));

        self::push_native($product_id, $prices, $base);
        if (class_exists('WOOCS')) {
            self::push_woocs($product_id, $prices, $base);
        }
        if (self::is_booster()) {
            self::push_booster($product_id, $prices, $base);
        }
        unset(self::$cache[$product_id]);
    }

    /**
     * Moneda base → precio NATIVO del producto (regular + sale).
     *
     * La base nunca va a los postmeta del switcher, así que si no escribimos acá el
     * sale, una oferta cargada en la moneda principal del LMS no llega nunca al
     * checkout (la landing la muestra igual porque sale del payload del LMS → la
     * landing promete un precio que WooCommerce no cobra).
     *
     * Si el LMS deja de mandar precio para la base, la oferta que habíamos escrito
     * TIENE que limpiarse: el regular se sigue actualizando por su lado (el sync lo
     * escribe desde course.price) y una promo vieja quedaría vigente pisando el
     * precio nuevo. Acá no hay red: guard_checkout() no cubre la moneda base. Pero
     * solo limpiamos lo que pusimos nosotros (marca NATIVE_SALE): en un tenant sin
     * multimoneda el admin puede cargar una promo a mano y no es nuestra para tocar.
     *
     * El LMS ya filtró el sale por vigencia, así que limpiamos las fechas de oferta
     * nativas: la vigencia la decide el LMS, no un schedule viejo de WooCommerce.
     */
    private static function push_native(int $product_id, array $prices, string $base): void {
        if (!function_exists('wc_get_product')) {
            return;
        }
        $has_base = ($base !== '' && isset($prices[$base]));
        $ours     = (string) get_post_meta($product_id, self::NATIVE_SALE, true) !== '';
        if (!$has_base && !$ours) {
            return; // el LMS nunca puso oferta acá: no tocamos nada del producto
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Sin precio del LMS para la base solo sacamos la oferta: el regular ya lo
        // escribió el sync desde course.price y no es nuestro para pisar acá.
        $sale = $has_base ? $prices[$base]['sale'] : null;
        if ($has_base) {
            $product->set_regular_price($prices[$base]['regular']);
        }
        $product->set_sale_price($sale === null ? '' : $sale);
        $product->set_date_on_sale_from('');
        $product->set_date_on_sale_to('');
        // WC recalcula _price solo al guardar cuando cambian regular/sale.
        $product->save();

        if ($sale === null) {
            delete_post_meta($product_id, self::NATIVE_SALE);
        } else {
            update_post_meta($product_id, self::NATIVE_SALE, $sale);
        }
    }

    private static function is_booster(): bool {
        // Detección multi-señal del linaje Booster (WooCommerce Jetpack → Booster
        // for WooCommerce / Booster Plus / Elite). La versión vieja solo cubría el
        // Booster legacy (clase WCJ / wcj_get_woocommerce_currency), que las 4.x+
        // ya no exponen: Booster Plus 8.x usa la clase WC_Jetpack y el helper
        // wcj_get_option. Todas estas señales son EXCLUSIVAS de Booster, así que en
        // un tenant que corre WOOCS esto sigue dando false (no interfiere).
        return class_exists('WC_Jetpack')                      // Booster 4.x+ (Plus/free/Elite)
            || function_exists('wcj_get_option')               // helper del core Booster
            || function_exists('wcj_get_woocommerce_currency') // variantes legacy
            || class_exists('WCJ')                             // Booster legacy
            || defined('WCJ_VERSION');                         // constante legacy
    }

    /**
     * WOOCS. Itera sobre las monedas del switcher: a las que el LMS trae precio les
     * pone el fijo (regular + sale si hay oferta vigente); a las demás -1 (resetea a
     * conversión, por si tenían un fijo obsoleto). La base se saltea (precio nativo).
     */
    private static function push_woocs(int $product_id, array $prices, string $base): void {
        $currencies = self::woocs_currencies();
        if ($currencies === []) {
            // Sin config del switcher no tocamos nada: si leyéramos vacío por un
            // arranque a medias, la limpieza de abajo borraría TODOS los fijos.
            return;
        }
        $keep = [];
        foreach ($currencies as $cur) {
            if ($cur === '' || $cur === $base) {
                continue;
            }
            $keep[$cur] = true;
            if (isset($prices[$cur])) {
                update_post_meta($product_id, self::WOOCS_REGULAR . $cur, $prices[$cur]['regular']);
                update_post_meta($product_id, self::WOOCS_SALE . $cur, $prices[$cur]['sale'] ?? self::NO_FIXED);
            } else {
                update_post_meta($product_id, self::WOOCS_REGULAR . $cur, self::NO_FIXED);
                update_post_meta($product_id, self::WOOCS_SALE . $cur, self::NO_FIXED);
            }
        }
        // Borra lo que quedó fuera del switcher: la base (la cobra el precio nativo)
        // y monedas que se sacaron de WOOCS. Sin esto, cambiar la moneda base deja
        // el fijo viejo de la base pisando el precio nativo para siempre.
        self::delete_stale_metas($product_id, [self::WOOCS_REGULAR, self::WOOCS_SALE], $keep);
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

    /** Booster (módulo Multicurrency per-product). */
    private static function push_booster(int $product_id, array $prices, string $base): void {
        $keep = [];
        foreach ($prices as $cur => $p) {
            if ($cur === $base) {
                continue;
            }
            $keep[$cur] = true;
            update_post_meta($product_id, self::BOOSTER_REGULAR . $cur, $p['regular']);
            if ($p['sale'] !== null) {
                update_post_meta($product_id, self::BOOSTER_SALE . $cur, $p['sale']);
            } else {
                delete_post_meta($product_id, self::BOOSTER_SALE . $cur);
            }
        }
        // Booster no tiene el "-1" de WOOCS: un fijo viejo se queda pisando el precio
        // para siempre. Borramos los de la base (la cobra el precio nativo) y los de
        // monedas que el LMS ya no manda o que quedaron de una moneda base anterior.
        self::delete_stale_metas($product_id, [self::BOOSTER_REGULAR, self::BOOSTER_SALE], $keep);
    }

    /**
     * Borra los postmeta por moneda del switcher que ya no corresponden.
     *
     * Escanea las claves reales del producto (no las que "deberían" estar), así
     * limpia también lo que dejó una configuración anterior: monedas sacadas del
     * switcher o una moneda base distinta a la de hoy.
     *
     * @param string[]           $prefixes claves de meta por moneda a revisar
     * @param array<string,true> $keep     monedas (ISO uppercase) que SÍ se conservan
     */
    private static function delete_stale_metas(int $product_id, array $prefixes, array $keep): void {
        foreach (array_keys((array) get_post_meta($product_id)) as $key) {
            $key = (string) $key;
            foreach ($prefixes as $prefix) {
                if (strpos($key, $prefix) !== 0) {
                    continue;
                }
                $cur = strtoupper(substr($key, strlen($prefix)));
                if (preg_match('/^[A-Z]{3}$/', $cur) && !isset($keep[$cur])) {
                    delete_post_meta($product_id, $key);
                }
            }
        }
    }

    // ───────────────────────────────────────────────────────────────────── HELPERS

    /**
     * Moneda a forzar en el checkout desde el botón de la landing.
     *
     * Hoy: ARS. Solo se fuerza si el tenant corre WOOCS y ARS es una de las
     * monedas configuradas en el switcher (guardrail: si ARS no está activa,
     * devolvemos '' y el botón no toca nada → checkout normal). NO aplica a
     * Booster ni a tenants sin multimoneda: para ellos siempre devuelve ''.
     *
     * El objetivo es no depender de la config "moneda inicial" de WOOCS, que
     * en algunos tenants no se respeta en el checkout.
     */
    public static function forced_checkout_currency(): string {
        if (!class_exists('WOOCS')) {
            return '';
        }
        if (!in_array('ARS', self::woocs_currencies(), true)) {
            return '';
        }
        return 'ARS';
    }

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
