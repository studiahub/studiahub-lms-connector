<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/studiahub/v1/orders/recent?after=<ISO>&before=<ISO>&limit=100
 *
 * Le devuelve al LMS los pedidos `completed` de una ventana de tiempo, con el
 * MISMO payload que manda el webhook. Es la mitad WordPress del "pull activo"
 * del cron `/api/cron/reconcile-orders` del LMS (ver
 * `docs/woocommerce-revenue.md` §7 en el repo del LMS).
 *
 * POR QUÉ EXISTE
 * --------------
 * La reconciliación del LMS tenía una sola pasada: releer los `WebhookLog` que
 * habían fallado. Pero cuando el webhook NUNCA llega —LMS caído hasta que
 * WooCommerce agota los reintentos, entrega perdida, body corrupto (ver
 * `Webhook_Payload`), webhook pausado por WC tras 5 fallas— no queda ningún log
 * que reprocesar: el cron reporta `checked: 0` con las ventas perdidas de fondo.
 * Acá el LMS pregunta al revés: "¿qué cobraste en las últimas 48hs?".
 *
 * POR QUÉ NO ALCANZA EL `GET /orders` QUE YA EXISTE
 * -------------------------------------------------
 * Ese endpoint es el historial de compras del alumno y no sirve para esto:
 *   - exige `email`, y de una venta perdida justamente no sabemos el email;
 *   - devuelve un resumen propio, sin `billing` ni `line_items[].meta_data`;
 *   - descarta los productos sin `_lms_course_id`, o sea que saltea los combos
 *     (que se marcan con `_lms_course_ids`) — el caso que más falla.
 *
 * FIDELIDAD DEL PAYLOAD
 * ---------------------
 * Serializamos con `Webhook_Payload::serialize_order()`, que hace el mismo
 * pedido REST interno que `WC_Webhook::build_payload()`. El LMS procesa lo que
 * sale de acá con `processCompletedOrder()`, exactamente la misma función que
 * corre para un webhook: si el shape se desviara, un pedido rescatado se
 * comportaría distinto a uno entregado, que es justo lo que no queremos.
 *
 * IDEMPOTENCIA
 * ------------
 * Este endpoint es de solo lectura. Duplicar inscripciones lo evita el LMS:
 * saltea los pedidos que ya dejaron algún `WebhookLog` y `processCompletedOrder`
 * es idempotente por los unique constraints de `Enrollment`/`User`.
 */
final class REST_Orders_Recent {
    /** Techo duro de pedidos por corrida. El LMS pide 100. */
    private const MAX_LIMIT     = 200;
    private const DEFAULT_LIMIT = 100;

    /** Ventana por defecto si el LMS no manda `after` (48hs, igual que el cron). */
    private const DEFAULT_WINDOW = 48 * HOUR_IN_SECONDS;

    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(
            'studiahub/v1',
            '/orders/recent',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [Auth::class, 'verify_request'],
                'args'                => [
                    'after' => [
                        'required'          => false,
                        'validate_callback' => [self::class, 'validate_datetime'],
                    ],
                    'before' => [
                        'required'          => false,
                        'validate_callback' => [self::class, 'validate_datetime'],
                    ],
                    'limit' => [
                        'required'          => false,
                        'validate_callback' => static fn($v) => is_numeric($v) && (int) $v > 0 && (int) $v <= self::MAX_LIMIT,
                        'default'           => self::DEFAULT_LIMIT,
                    ],
                ],
            ]
        );
    }

    /** Acepta cualquier fecha que entienda strtotime (el LMS manda ISO 8601). */
    public static function validate_datetime($value): bool {
        return is_string($value) && $value !== '' && strtotime($value) !== false;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        if (!function_exists('wc_get_orders')) {
            return new \WP_REST_Response(['orders' => []], 200);
        }

        $now       = time();
        $before_ts = self::param_to_timestamp($request->get_param('before'), $now);
        $after_ts  = self::param_to_timestamp($request->get_param('after'), $before_ts - self::DEFAULT_WINDOW);
        $limit     = (int) $request->get_param('limit');

        if ($after_ts >= $before_ts) {
            return new \WP_REST_Response(['orders' => []], 200);
        }

        // `date_modified` y no `date_created`: lo que nos interesa es cuándo el
        // pedido pasó a `completed`, no cuándo se abrió. Un pedido por
        // transferencia bancaria se crea el lunes y el admin lo marca pagado el
        // jueves — filtrando por creación, esa venta (la más común en Argentina)
        // queda fuera de la ventana y no se rescata nunca. Completar un pedido
        // siempre toca `date_modified`, así que este filtro es un superconjunto
        // seguro: puede traer algún pedido viejo reeditado, y eso no molesta
        // (el LMS saltea los que ya tienen log y el alta es idempotente).
        //
        // Se pasan timestamps UTC en vez de strings de fecha justamente para no
        // depender de la zona horaria configurada en el WordPress del cliente.
        $order_ids = wc_get_orders([
            'status'        => ['completed'],
            'date_modified' => $after_ts . '...' . $before_ts,
            'limit'         => $limit,
            'orderby'       => 'date',
            'order'         => 'DESC',
            'return'        => 'ids',
        ]);

        if (!is_array($order_ids) || empty($order_ids)) {
            return new \WP_REST_Response(['orders' => []], 200);
        }

        // `serialize_order()` se encarga del permiso para leer el pedido: este
        // request se autentica con nuestro Bearer y no tiene usuario de
        // WordPress, así que sin eso el dispatch interno contestaría
        // `woocommerce_rest_cannot_view` y el pull devolvería una lista vacía —
        // silenciosamente, que es el peor final posible para una red de
        // seguridad.
        $orders = [];
        foreach ($order_ids as $order_id) {
            $payload = Webhook_Payload::serialize_order((int) $order_id, 'v3', 'orders/recent');
            if ($payload !== null) {
                $orders[] = $payload;
            }
        }

        return new \WP_REST_Response(['orders' => $orders], 200);
    }

    private static function param_to_timestamp($value, int $fallback): int {
        if (!is_string($value) || $value === '') {
            return $fallback;
        }
        $ts = strtotime($value);
        return $ts === false ? $fallback : $ts;
    }

}
