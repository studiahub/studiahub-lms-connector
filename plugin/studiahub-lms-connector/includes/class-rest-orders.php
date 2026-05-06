<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/studiahub/v1/orders?email=foo@bar.com
 * Devuelve las órdenes de WC del email indicado que contienen al menos un
 * line_item cuyo producto tiene el meta `_lms_course_id`. El LMS usa este
 * endpoint para mostrarle al alumno su historial de compras desde /profile.
 *
 * Filtra por meta `_billing_email` (no por customer_id) porque las compras
 * de WC pueden venir de guests sin cuenta en WP.
 */
final class REST_Orders {
    private const MAX_LIMIT = 50;

    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(
            'studiahub/v1',
            '/orders',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [Auth::class, 'verify_request'],
                'args'                => [
                    'email' => [
                        'required'          => true,
                        'validate_callback' => static fn($v) => is_string($v) && is_email($v),
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'limit' => [
                        'required'          => false,
                        'validate_callback' => static fn($v) => is_numeric($v) && (int) $v > 0 && (int) $v <= self::MAX_LIMIT,
                        'default'           => self::MAX_LIMIT,
                    ],
                ],
            ]
        );
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        if (!function_exists('wc_get_orders')) {
            return new \WP_REST_Response(['orders' => []], 200);
        }

        $email = $request->get_param('email');
        $limit = (int) $request->get_param('limit');

        $orders = wc_get_orders([
            'billing_email' => $email,
            'limit'         => $limit,
            'orderby'       => 'date',
            'order'         => 'DESC',
            // No filtramos por status — el alumno tiene que ver completed,
            // refunded, cancelled, on-hold, etc. para entender el historial.
        ]);

        $result = [];
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $items = self::map_lms_items($order);
            // Si la orden no toca ningún producto LMS, no la mostramos.
            if (empty($items)) {
                continue;
            }

            $created = $order->get_date_created();

            $result[] = [
                'id'            => $order->get_id(),
                'number'        => $order->get_order_number(),
                'status'        => $order->get_status(),
                'total'         => (float) $order->get_total(),
                'currency'      => $order->get_currency(),
                'paymentMethod' => $order->get_payment_method_title() ?: $order->get_payment_method(),
                'createdAt'     => $created ? $created->date(\DateTimeInterface::ATOM) : null,
                'viewUrl'       => $order->get_view_order_url() ?: null,
                'items'         => $items,
            ];
        }

        return new \WP_REST_Response(['orders' => $result], 200);
    }

    /**
     * Devuelve solo los items del order cuyo producto tenga `_lms_course_id`.
     * Cada item incluye el lmsCourseId para que el LMS lo matchee con su Course.
     *
     * @return array<int, array{productId:int, name:string, quantity:int, total:float, lmsCourseId:string|null}>
     */
    private static function map_lms_items(\WC_Order $order): array {
        $mapped = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product_id = $item->get_product_id();
            if (!$product_id) {
                continue;
            }

            $lms_course_id = get_post_meta($product_id, '_lms_course_id', true);
            if (!$lms_course_id) {
                continue;
            }

            $mapped[] = [
                'productId'   => (int) $product_id,
                'name'        => $item->get_name(),
                'quantity'    => (int) $item->get_quantity(),
                'total'       => (float) $item->get_total(),
                'lmsCourseId' => (string) $lms_course_id,
            ];
        }

        return $mapped;
    }
}
