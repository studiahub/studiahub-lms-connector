<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/studiahub/v1/health — devuelve versiones de WP, WC, ACF y plugin.
 * Requiere bearer token válido (ver Auth::verify_request).
 */
final class REST_Health {
    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(
            'studiahub/v1',
            '/health',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [Auth::class, 'verify_request'],
            ]
        );
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        global $wp_version;

        return new \WP_REST_Response([
            'status'         => 'ok',
            'wp_version'     => $wp_version,
            'wc_version'     => defined('WC_VERSION') ? WC_VERSION : null,
            'acf_version'    => function_exists('acf_get_setting') ? acf_get_setting('version') : null,
            'plugin_version' => SLC_VERSION,
        ], 200);
    }
}
