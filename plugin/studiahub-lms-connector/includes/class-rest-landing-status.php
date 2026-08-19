<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/studiahub/v1/landing-status[?course_id=X]
 *
 * Radiografía de la caché de la landing: de qué capa se está sirviendo cada
 * curso, cuándo fue el último fetch exitoso al LMS y si hay backoff activo.
 *
 * Para qué existe: la landing puede quedar sirviendo contenido viejo sin que
 * nada falle — sin error, sin excepción y con HTTP 200 en todos lados. Pasó en
 * producción: dos semanas mostrando fechas y precios viejos porque cada pieza
 * hacía exactamente lo que decía su código. No se puede alertar sobre errores
 * que no ocurren, así que hay que poder preguntar por el RESULTADO. Esto es lo
 * que el LMS consulta desde su cron para comparar lo que servimos contra lo que
 * él tiene, y avisar antes de que se entere el cliente.
 *
 * Sin `course_id` devuelve todos los cursos sincronizados, para que el LMS
 * resuelva un tenant entero en un request en vez de uno por curso.
 *
 * Es de solo lectura: no fetchea, no escribe y no altera lo que ve el visitante.
 * Auth: misma Bearer key que el resto (Auth::verify_request).
 */
final class REST_Landing_Status {
    /** Techo defensivo: un tenant con más cursos que esto tiene otros problemas. */
    private const MAX_COURSES = 200;

    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_route']);
    }

    public static function register_route(): void {
        register_rest_route('studiahub/v1', '/landing-status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [Auth::class, 'verify_request'],
            'args'                => [
                'course_id' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        $course_id = (string) $request->get_param('course_id');

        $course_ids = $course_id !== ''
            ? [$course_id]
            : self::synced_course_ids();

        $courses = [];
        foreach ($course_ids as $cid) {
            $courses[] = ['courseId' => $cid] + Landing_Fetch::status($cid);
        }

        return new \WP_REST_Response([
            'ok'          => true,
            'now'         => time(),
            'ttlFresh'    => Landing_Fetch::TTL_FRESH,
            'courses'     => $courses,
            'pluginVersion' => defined('SLC_VERSION') ? SLC_VERSION : null,
        ], 200);
    }

    /**
     * Los course_id del LMS que este WordPress tiene sincronizados, deduplicados.
     *
     * @return string[]
     */
    private static function synced_course_ids(): array {
        $product_ids = get_posts([
            'post_type'      => 'product',
            // Solo los publicados: un producto en borrador no tiene landing que
            // se pueda estar mostrando vieja, así que no es algo que alertar.
            'post_status'    => 'publish',
            'posts_per_page' => self::MAX_COURSES,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_lms_course_id',
                    'compare' => 'EXISTS',
                ],
            ],
            'no_found_rows'  => true,
        ]);

        // `fields => ids` no carga la meta cache, así que sin esto cada
        // get_post_meta de abajo sería una query propia.
        if (!empty($product_ids)) {
            update_meta_cache('post', $product_ids);
        }

        $ids = [];
        foreach ($product_ids as $pid) {
            $cid = (string) get_post_meta((int) $pid, '_lms_course_id', true);
            if ($cid !== '') {
                $ids[$cid] = true;
            }
        }

        return array_keys($ids);
    }
}
