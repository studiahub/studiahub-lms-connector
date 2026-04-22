<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/studiahub/v1/categories
 * Devuelve las categorías de productos WC (taxonomía product_cat).
 * Lo usa el LMS para el autocomplete del form de curso.
 */
final class REST_Categories {
    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(
            'studiahub/v1',
            '/categories',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [Auth::class, 'verify_request'],
            ]
        );
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return new \WP_REST_Response(['categories' => []], 200);
        }

        $categories = [];
        foreach ($terms as $term) {
            // Saltear la categoría default "uncategorized" de WC
            if ($term->slug === 'uncategorized') {
                continue;
            }
            $categories[] = [
                'id'    => (int) $term->term_id,
                'name'  => $term->name,
                'slug'  => $term->slug,
                'count' => (int) $term->count,
            ];
        }

        return new \WP_REST_Response(['categories' => $categories], 200);
    }
}
