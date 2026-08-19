<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoint POST /wp-json/studiahub/v1/cache-bust?course_id=X
 *
 * Llamado por el LMS cuando un curso cambia: invalida las copias con TTL y trae
 * el contenido nuevo en el momento, para que la landing quede publicada al día
 * sin esperar a que entre un visitante.
 *
 * Auth: misma Bearer key que los demás endpoints (Auth::verify_request).
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

        // Y traemos el contenido nuevo YA, sin esperar a que entre un visitante.
        //
        // Invalidar solo borraba: hasta que alguien abriera la landing, la
        // página seguía dibujándose con la última copia buena, o sea con el
        // contenido viejo. En un sitio con poco tráfico eso podía durar horas —
        // el LMS daba el cambio por publicado y no lo estaba. Además dejaba sin
        // sentido cualquier verificación posterior: preguntar justo después del
        // bust devolvía la copia vieja aunque todo estuviera funcionando bien.
        //
        // Quien llama es el LMS, que por definición está vivo en este momento;
        // es el mejor instante posible para ir a buscar. Si igual falla, no se
        // reporta error: el bust se hizo, y el próximo render reintenta.
        $payload = Landing_Fetch::get_payload($course_id);

        return new \WP_REST_Response([
            'ok'     => true,
            'busted' => $course_id,
            'warmed' => is_array($payload),
        ], 200);
    }
}
