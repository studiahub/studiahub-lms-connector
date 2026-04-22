<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/studiahub/v1/products/{id}
 * Permite al LMS verificar al abrir un curso si el producto sincronizado
 * todavía existe en WC. Retorna 404 si fue borrado.
 */
final class REST_Products {
    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(
            'studiahub/v1',
            '/products/(?P<id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [Auth::class, 'verify_request'],
                'args'                => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => static fn($v) => is_numeric($v) && (int) $v > 0,
                    ],
                ],
            ]
        );
    }

    public static function handle(\WP_REST_Request $request) {
        $id   = (int) $request['id'];
        $post = get_post($id);

        if (!$post || $post->post_type !== 'product' || $post->post_status === 'trash') {
            return new \WP_Error('slc_product_not_found', 'Producto inexistente o en papelera.', ['status' => 404]);
        }

        $permalink = get_permalink($post);

        return new \WP_REST_Response([
            'wcProductId' => $id,
            'status'      => $post->post_status,
            'title'       => $post->post_title,
            'permalink'   => $permalink ?: null,
        ], 200);
    }
}
