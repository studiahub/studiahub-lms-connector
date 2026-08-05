<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rearma el payload de nuestros webhooks de pedido cuando WooCommerce lo entrega
 * roto. Sin esto, hay ventas que se cobran y nunca llegan al LMS.
 *
 * PROBLEMA
 * --------
 * El plugin fuerza la entrega SÍNCRONA de los webhooks (ver Plugin::register_hooks),
 * así que el body se arma en el `shutdown` del MISMO request que completó el pago.
 *
 * `WC_Webhook::build_payload()` no serializa el pedido a mano: hace un
 * `rest_do_request('/wc/v3/orders/N')` interno y manda esa respuesta. Pero desde
 * WC 9.2 los controllers de la REST API se cargan de forma perezosa: en
 * `rest_api_init`, `wc_rest_should_load_namespace()` registra un namespace conocido
 * (`wc/v1`, `wc/v2`, `wc/v3`, `wc/store`, `wc-analytics`, …) solo si el request
 * corriente pertenece a ESE namespace. Si el request es de `wc/store`, los
 * controllers de `wc/v3` nunca se registran — y `rest_api_init` ya pasó cuando el
 * webhook arma su payload, así que ese `rest_do_request` interno cae en 404.
 *
 * Resultado: WooCommerce firma y entrega esto en vez del pedido:
 *
 *     {"code":"rest_no_route","message":"No route was found…","data":{"status":404}}
 *
 * El LMS no puede hacer nada con ese body: responde 200 (a propósito, para no
 * gastarle el contador de fallas a WC y que termine pausando el webhook) y
 * descarta el evento. WooCommerce lo cuenta como entrega exitosa. La venta se
 * cobra y no queda rastro en ninguna capa.
 *
 * A QUIÉN LE PEGA
 * ---------------
 * Al checkout de bloques (el default de WC desde 8.3) cuando el gateway confirma
 * el pago INLINE, dentro del `POST /wp-json/wc/store/v1/checkout`: Stripe,
 * WooPayments y cualquier gateway sin redirect. Con producto Virtual +
 * Descargable — que es justo lo que pide el onboarding de StudiaHub — el pedido
 * salta directo a `completed` ahí adentro y dispara los dos topics con el payload
 * roto. Los gateways con redirect (MercadoPago, PayPal, transferencia manual)
 * zafan de casualidad: el pedido se completa en un request normal, donde `wc/v3`
 * sí se carga. Por eso nunca se vio en producción.
 *
 * El mismo agujero aplica a cualquier sistema externo que cree o actualice
 * pedidos por `wc/v1` o `wc/v2` (un ERP, un POS): el request vive en otro
 * namespace conocido y `wc/v3` tampoco se carga.
 *
 * SOLUCIÓN
 * --------
 * Interceptamos `woocommerce_webhook_payload` y, si el payload de un pedido no
 * tiene ni `id`, registramos el controller de pedidos que falta y repetimos el
 * pedido REST interno tal como lo hace el core. El body que sale es el payload
 * auténtico de la REST API de WC, no una reconstrucción nuestra.
 *
 * Por qué acá y no filtrando `wc_rest_should_load_namespace` para que `wc/v3` se
 * cargue durante los requests de `wc/store`:
 *
 *   - Costo cero en el camino sano. Ese filtro se evalúa en `rest_api_init`, así
 *     que habría que registrar los ~40 controllers de `wc/v3` en CADA request de
 *     la Store API (carrito, mini-carrito, listados de productos por bloques),
 *     no solo en el checkout. Acá no corre nada hasta que el payload ya vino roto.
 *   - Cubre todos los disparadores, no uno. Filtrar por `wc/store` dejaría
 *     afuera el caso del ERP que escribe por `wc/v2`. Reparar el payload no
 *     depende de qué namespace originó el request.
 */
final class Webhook_Payload {

    /**
     * Controller de pedidos de WC por versión de la REST API del webhook.
     * `WC_Webhook::get_api_version()` devuelve 'wp_api_v3' y derivados; los
     * webhooks legacy ('legacy_v3') no entran acá porque no arman el payload
     * con un pedido REST interno.
     */
    private const ORDERS_CONTROLLERS = [
        'v1' => 'WC_REST_Orders_V1_Controller',
        'v2' => 'WC_REST_Orders_V2_Controller',
        'v3' => 'WC_REST_Orders_Controller',
    ];

    /** Versiones cuyo controller ya registramos en este request. */
    private static array $registered = [];

    public static function register_hooks(): void {
        add_filter('woocommerce_webhook_payload', [self::class, 'repair'], 10, 4);
    }

    /**
     * @param mixed $payload     Payload que armó WC_Webhook::build_payload().
     * @param string $resource   'order', 'product', 'customer', 'coupon'…
     * @param mixed $resource_id Primer argumento del hook (el ID del pedido).
     * @param int   $webhook_id  Webhook que se está por entregar.
     * @return mixed
     */
    public static function repair($payload, $resource, $resource_id, $webhook_id) {
        if ($resource !== 'order') {
            return $payload;
        }
        // Los webhooks de otros plugins del cliente no son asunto nuestro, igual
        // que en WebhookBootstrap::filter_http_args().
        if (!WebhookBootstrap::is_lms_webhook((int) $webhook_id)) {
            return $payload;
        }

        // Payload sano: no hay nada que reconstruir, pero sí puede faltarle el
        // meta del combo. Un pedido cargado a mano en wp-admin llega hasta acá
        // perfecto salvo por eso — y sin eso el LMS no inscribe a nadie.
        if (self::looks_like_order($payload)) {
            return Order_Combo_Meta::fill_missing_combo_meta($payload);
        }

        $order_id = absint($resource_id);
        if ($order_id <= 0) {
            return $payload;
        }

        // La rama reconstruida sale de serialize_order(), que ya completa el
        // combo.
        $rebuilt = self::rebuild($order_id, (int) $webhook_id);
        return $rebuilt ?? $payload;
    }

    /**
     * Un payload de pedido sano siempre trae un `id` numérico — es lo mismo que
     * mira el LMS antes de descartar el evento. Los errores REST
     * (`rest_no_route`, `rest_forbidden`, …) no lo tienen. Las bajas
     * (`event === 'deleted'`) traen `['id' => N]` y quedan afuera: el pedido ya
     * no existe, no hay nada que reconstruir.
     *
     * @param mixed $payload
     */
    private static function looks_like_order($payload): bool {
        return is_array($payload) && isset($payload['id']) && absint($payload['id']) > 0;
    }

    /**
     * Registra el controller de pedidos que la carga perezosa se salteó y repite
     * el pedido REST interno. Devuelve null si no se pudo.
     */
    private static function rebuild(int $order_id, int $webhook_id): ?array {
        if (!class_exists('WC_Webhook')) {
            return null;
        }
        $webhook = new \WC_Webhook($webhook_id);
        $version = str_replace('wp_api_', '', (string) $webhook->get_api_version());

        return self::as_webhook_user($webhook, static function () use ($version, $order_id, $webhook_id) {
            return self::serialize_order($order_id, $version, "webhook {$webhook_id}");
        });
    }

    /**
     * Serializa un pedido con EXACTAMENTE el mismo camino que arma el body de un
     * webhook sano: el pedido REST interno `/wc/{version}/orders/{id}` del core.
     *
     * La reusa `REST_Orders_Recent` (el pull activo del cron de reconciliación
     * del LMS) para que un pedido rescatado a mano llegue al LMS con el mismo
     * shape que uno entregado por WooCommerce — mismo `billing`, mismos
     * `line_items[].meta_data` (de ahí salen los `_lms_course_ids` de los
     * combos). Cualquier reconstrucción propia se desviaría con el tiempo.
     *
     * Registrar el controller no es opcional en ninguno de los dos casos: desde
     * WC 9.2 los namespaces de la REST API se cargan solo si el request
     * corriente les pertenece, y ni el `shutdown` de un checkout de bloques ni
     * un request a `studiahub/v1` pertenecen a `wc/v3`.
     *
     * OJO: no eleva permisos. `/wc/v3/orders/{id}` exige poder leer el pedido
     * (`wc_rest_check_post_permissions`), así que el caller tiene que correrla
     * dentro de un contexto que los tenga — `as_webhook_user()` acá,
     * `with_order_read_access()` en el endpoint REST.
     *
     * @param string $version 'v1' | 'v2' | 'v3' (versión de la REST API de WC).
     * @param string $context Quién pidió el payload, solo para el error_log.
     */
    public static function serialize_order(int $order_id, string $version = 'v3', string $context = ''): ?array {
        $controller = self::ORDERS_CONTROLLERS[$version] ?? null;
        if ($order_id <= 0 || $controller === null || !class_exists($controller)) {
            return null;
        }

        if (!isset(self::$registered[$version])) {
            // Registramos SOLO el controller de pedidos, no el namespace entero:
            // es lo único que necesita el payload. `register_rest_route()` no
            // exige estar dentro de `rest_api_init`, escribe directo en el
            // servidor REST ya instanciado, y `WP_REST_Server::get_routes()` no
            // cachea — así que la ruta queda disponible para el dispatch de acá
            // abajo. El flag evita registrarla de nuevo en el mismo request
            // (dos topics del mismo pedido, o los N pedidos de un pull).
            (new $controller())->register_routes();
            self::$registered[$version] = true;
        }

        $payload = self::get_endpoint_data("/wc/{$version}/orders/{$order_id}");
        if (self::looks_like_order($payload)) {
            // Un pedido rescatado tiene que dar EXACTAMENTE las mismas
            // inscripciones que uno entregado en vivo, así que el combo se
            // completa acá también (ver Order_Combo_Meta, capa 2).
            return Order_Combo_Meta::fill_missing_combo_meta($payload);
        }
        error_log(sprintf(
            '[slc webhook-payload] no pude serializar el pedido %d%s: %s',
            $order_id,
            $context !== '' ? " ({$context})" : '',
            is_array($payload) ? (string) ($payload['code'] ?? 'sin code') : gettype($payload)
        ));
        return null;
    }

    /**
     * Corre $fn con el usuario del webhook, igual que WC_Webhook::build_payload().
     *
     * No es un detalle: `build_payload()` eleva al usuario dueño del webhook,
     * arma el payload y RESTAURA el usuario original ANTES de aplicar el filtro
     * `woocommerce_webhook_payload`. O sea que nosotros corremos de vuelta como
     * el usuario del request — en un checkout de bloques, un invitado — y
     * `/wc/v3/orders/N` nos contesta `woocommerce_rest_cannot_view`.
     *
     * El `finally` no es decorativo: si el pedido REST tirara una excepción, sin
     * él quedaría un request de invitado corriendo como administrador hasta el
     * final del shutdown.
     *
     * @return mixed
     */
    private static function as_webhook_user(\WC_Webhook $webhook, callable $fn) {
        $previous_user = get_current_user_id();
        wp_set_current_user($webhook->get_user_id());
        try {
            return $fn();
        } finally {
            wp_set_current_user($previous_user);
        }
    }

    /**
     * Mismo pedido interno que hace WC_Webhook::get_wp_api_payload(), para que el
     * body salga idéntico al del camino sano (misma normalización JSON incluida).
     *
     * @return mixed
     */
    private static function get_endpoint_data(string $endpoint) {
        $util = \Automattic\WooCommerce\Utilities\RestApiUtil::class;
        if (!function_exists('wc_get_container') || !class_exists($util)) {
            return null;
        }
        return wc_get_container()->get($util)->get_endpoint_data($endpoint);
    }
}
