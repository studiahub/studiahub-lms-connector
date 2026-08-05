<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hace que el cierre de inscripciones sea REAL y no un cartel.
 *
 * EL AGUJERO
 * ----------
 * `comingSoon` y `salesClosed` (el vencimiento de `salesEndAt`) llegan en el
 * payload de la landing y hasta acá solo cambiaban el TEXTO del botón: se
 * pintaba "Inscripciones cerradas" / "Próximamente" deshabilitado y listo. El
 * producto de WooCommerce seguía intacto, así que cualquiera que llegara por
 * fuera del botón compraba igual:
 *
 *   - `?add-to-cart=<id>` (el patrón nativo de WC) sobre cualquier URL;
 *   - un link al checkout guardado en marcadores, indexado por Google o
 *     compartido por WhatsApp cuando la venta estaba abierta;
 *   - un carrito abandonado de antes del cierre, que sigue vivo en la sesión;
 *   - el listado de la tienda y cualquier widget de producto del tema.
 *
 * Un curso con cupo cerrado o con la cohorte ya empezada se seguía cobrando, y
 * el LMS —que solo ve un pedido pagado— inscribía al alumno sin chistar.
 *
 * CÓMO SE CIERRA
 * --------------
 * `woocommerce_is_purchasable` es la llave maestra: la miran el carrito clásico
 * (`WC_Cart::add_to_cart()` tira "no se puede comprar"), la Store API del
 * checkout de bloques (`CartController::validate_cart_item()`), el template del
 * producto y `Enroll::handle()`. Con eso alcanza para que no entre al carrito;
 * los otros dos hooks son para que el visitante ENTIENDA por qué (un aviso en
 * vez de un error genérico de WooCommerce) y para frenar lo que ya estaba en el
 * carrito antes del cierre.
 *
 * POR QUÉ TAMBIÉN `comingSoon`
 * ----------------------------
 * Podría leerse como "preventa", que en otros negocios significa aceptar
 * reservas. Acá no: el toggle del LMS se llama "Próximamente (preventa)" y su
 * campo de texto se describe como "lo que muestra el botón deshabilitado". O
 * sea, el producto ya prometía landing visible + checkout cerrado; lo único que
 * faltaba era cumplirlo. Si algún día se quieren reservas de verdad, eso es un
 * producto aparte (una seña, una lista de espera), no este flag.
 *
 * DE DÓNDE SALE EL ESTADO
 * -----------------------
 * Del MISMO payload que renderiza la landing, o sea la fuente que decide qué
 * dice el botón. Esa es la propiedad que hace al gate seguro: el botón y el
 * carrito no pueden discrepar. Si el LMS está caído y la landing muestra el
 * contenido viejo, el gate aplica ese mismo contenido viejo — nunca bloquea una
 * venta que la página está ofreciendo, ni deja pasar una que la página dice
 * cerrada.
 *
 * Lo que cambia según el hook es si se permite salir a la red:
 *
 *   - `is_purchasable` NO fetchea nunca (solo caché). Se evalúa por producto en
 *     cada listado de la tienda: un LMS lento le sumaría segundos a cada página.
 *   - `add_to_cart_validation` y `check_cart_items` SÍ pueden fetchear. Corren
 *     una vez por acción del usuario (agregar al carrito, ver el carrito,
 *     pagar), y son los dos puntos donde la respuesta tiene que ser correcta
 *     aunque este WordPress nunca haya renderizado la landing — el caso de una
 *     tienda que vende por links directos. El backoff de `Landing_Fetch` evita
 *     que un LMS caído cuelgue el checkout.
 *
 * Como no se puede comprar sin pasar por agregar al carrito y por el checkout,
 * el camino cache-only nunca es el último control.
 *
 * LÍMITES CONOCIDOS
 * -----------------
 * - Combos (`_lms_is_combo` + `_lms_course_ids`): no tienen landing propia ni
 *   payload, así que el gate no los toca. La landing del combo es Elementor a
 *   mano y su cierre se maneja despublicando el producto.
 * - Producto sin `_lms_course_id`: no es un curso, no nos metemos.
 * - LMS caído Y sin ninguna copia cacheada del curso: no bloquea. Es la opción
 *   correcta — ante la duda, no romper la venta.
 */
final class Purchase_Gate {

    /** Memo por request: is_purchasable() se llama muchas veces por página. */
    private static array $memo = [];

    public static function register_hooks(): void {
        add_filter('woocommerce_is_purchasable', [self::class, 'filter_is_purchasable'], 10, 2);
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_add_to_cart'], 10, 2);
        add_filter('woocommerce_cart_product_cannot_be_purchased_message', [self::class, 'filter_cannot_purchase_message'], 10, 2);
        add_action('woocommerce_check_cart_items', [self::class, 'check_cart_items']);
    }

    /**
     * @param bool $purchasable
     * @param mixed $product
     */
    public static function filter_is_purchasable($purchasable, $product): bool {
        if (!$purchasable || !is_a($product, 'WC_Product')) {
            return (bool) $purchasable;
        }
        return self::closed_state((int) $product->get_id()) === null;
    }

    /**
     * Corta el agregado al carrito antes de que WooCommerce mire nada más.
     *
     * No es redundante con `is_purchasable`: este hook es el ÚNICO de los dos
     * que puede consultarle al LMS. Lo disparan el handler de `?add-to-cart=`,
     * la Store API y la restauración del carrito desde la sesión — o sea los
     * tres caminos por los que llega un visitante real — así que acá el cierre
     * se aplica incluso si este WordPress nunca cacheó el payload del curso,
     * que es donde `is_purchasable` (cache-only) se queda corto.
     *
     * @param bool $passed
     * @param int  $product_id
     */
    public static function validate_add_to_cart($passed, $product_id): bool {
        if (!$passed) {
            return false;
        }
        $closed = self::closed_state((int) $product_id, true);
        if ($closed === null) {
            return true;
        }
        if (function_exists('wc_add_notice')) {
            wc_add_notice(self::notice_for((int) $product_id, $closed), 'error');
        }
        return false;
    }

    /**
     * Reemplaza el "Sorry, this product cannot be purchased." de WooCommerce.
     *
     * Es el mensaje que ve el visitante en la práctica: desde WC 10,
     * `WC_Cart::add_to_cart()` evalúa `is_purchasable()` ANTES de
     * `woocommerce_add_to_cart_validation`, así que tira su excepción genérica
     * (y en inglés, en una landing en español) antes de que corra nuestro aviso.
     *
     * @param string $message
     * @param mixed  $product
     */
    public static function filter_cannot_purchase_message($message, $product): string {
        if (!is_a($product, 'WC_Product')) {
            return (string) $message;
        }
        $closed = self::closed_state((int) $product->get_id(), true);
        return $closed === null
            ? (string) $message
            : self::notice_for((int) $product->get_id(), $closed);
    }

    /**
     * Frena el checkout de lo que YA estaba en el carrito.
     *
     * El caso real: el visitante agregó el curso mientras la venta estaba
     * abierta, dejó la pestaña abierta (o el carrito quedó en su sesión) y paga
     * después del cierre. `is_purchasable` no se evalúa sobre un carrito ya
     * armado en el checkout clásico, así que sin esto la compra pasaba igual.
     * WooCommerce dispara este hook desde la página del carrito y desde
     * `WC_Checkout::process_checkout()`: un aviso de error acá aborta el pago.
     */
    public static function check_cart_items(): void {
        if (!function_exists('WC') || WC()->cart === null || !function_exists('wc_add_notice')) {
            return;
        }
        foreach (WC()->cart->get_cart() as $item) {
            $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            if ($product_id <= 0) {
                continue;
            }
            $closed = self::closed_state($product_id, true);
            if ($closed !== null) {
                wc_add_notice(self::notice_for($product_id, $closed), 'error');
            }
        }
    }

    /**
     * Estado de cierre de un producto, o null si se puede comprar.
     *
     * @param bool $allow_fetch Permitir ir al LMS si no hay nada cacheado. Solo
     *                          para los hooks que corren una vez por acción del
     *                          usuario, nunca para `is_purchasable`.
     * @return array{reason:string, label:string}|null
     */
    public static function closed_state(int $product_id, bool $allow_fetch = false): ?array {
        if ($product_id <= 0) {
            return null;
        }
        if (array_key_exists($product_id, self::$memo)) {
            return self::$memo[$product_id];
        }

        $course_id = (string) get_post_meta($product_id, '_lms_course_id', true);
        if ($course_id === '') {
            return self::$memo[$product_id] = null;
        }

        $payload = $allow_fetch
            ? Landing_Fetch::get_payload($course_id)
            : Landing_Fetch::get_cached_payload($course_id);

        if (!is_array($payload)) {
            // Sin payload no bloqueamos. Y no memoizamos el "no sé" que salió de
            // mirar solo la caché: si más adelante en el mismo request pasa por
            // el carrito —que sí puede preguntarle al LMS— tiene que poder
            // resolverlo bien en vez de heredar esta respuesta a medias.
            if ($allow_fetch) {
                self::$memo[$product_id] = null;
            }
            return null;
        }

        return self::$memo[$product_id] = self::closed_state_from_payload($payload);
    }

    /**
     * Única definición de "este curso no se puede comprar", para que el botón de
     * la landing y el carrito lean exactamente lo mismo. La usan los dos
     * shortcodes además del gate.
     *
     * @return array{reason:string, label:string}|null
     */
    public static function closed_state_from_payload(array $payload): ?array {
        // comingSoon tiene precedencia: es un override manual del admin, no una
        // fecha vencida. Un curso en preventa con salesEndAt viejo muestra
        // "Próximamente", no "cerradas".
        if (!empty($payload['comingSoon'])) {
            $label = trim((string) ($payload['comingSoonLabel'] ?? ''));
            return [
                'reason' => 'coming_soon',
                'label'  => $label !== '' ? $label : __('Próximamente', 'studiahub-lms-connector'),
            ];
        }
        if (!empty($payload['salesClosed'])) {
            return [
                'reason' => 'sales_closed',
                'label'  => __('Inscripciones cerradas', 'studiahub-lms-connector'),
            ];
        }
        return null;
    }

    /** @param array{reason:string, label:string} $closed */
    private static function notice_for(int $product_id, array $closed): string {
        $name = get_the_title($product_id);
        if ($closed['reason'] === 'coming_soon') {
            return sprintf(
                /* translators: 1: nombre del curso, 2: etiqueta de preventa */
                __('«%1$s» todavía no está a la venta (%2$s).', 'studiahub-lms-connector'),
                $name,
                $closed['label']
            );
        }
        return sprintf(
            /* translators: %s: nombre del curso */
            __('Las inscripciones a «%s» están cerradas.', 'studiahub-lms-connector'),
            $name
        );
    }
}
