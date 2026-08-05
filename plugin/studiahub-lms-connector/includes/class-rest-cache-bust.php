<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint POST /wp-json/studiahub/v1/cache-bust?course_id=X
 *
 * Llamado por el LMS cuando un curso cambia, para invalidar el transient
 * de la landing. Auth: misma Bearer key que los demás endpoints (Auth::verify_request).
 */
final class REST_Cache_Bust {
    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_route']);
    }

    public static function register_route(): void {
        register_rest_route('studiahub/v1', '/cache-bust', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [Auth::class, 'verify_request'],
            'args'                => [
                'course_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        $course_id = (string) $request->get_param('course_id');
        if ($course_id === '') {
            return new \WP_REST_Response(['error' => 'missing course_id'], 400);
        }
        // Invalida las copias con TTL, no la última copia buena: si el LMS se
        // cae justo después del bust, la landing tiene que seguir mostrando el
        // contenido anterior en vez de quedar vacía. Ver Landing_Fetch.
        Landing_Fetch::bust($course_id);
        return new \WP_REST_Response(['ok' => true, 'busted' => $course_id], 200);
    }
}
