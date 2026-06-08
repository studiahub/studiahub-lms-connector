<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/studiahub/v1/lms-courses
 *
 * Proxy server-side que devuelve la lista de cursos del LMS (id + título) para
 * poblar el multiselect del combo en el editor de producto WC.
 *
 * A diferencia del resto de rutas studiahub/v1 (que las llama el LMS con su
 * Bearer), esta la llama el JS del admin del browser. Por eso:
 *   - permission_callback = capability del usuario logueado (manage_woocommerce)
 *     + nonce wp_rest (lo agrega wp_localize_script via wpApiSettings).
 *   - El secret del LMS (OPT_WEBHOOK_SECRET) nunca toca el browser: se usa solo
 *     en el fetch server-side hacia el LMS (igual que Landing_Fetch).
 *
 * Fuentes de cursos, en orden:
 *   1. Endpoint del LMS `GET {lms_url}/api/wc/courses` (id + title de los cursos
 *      publicados del tenant). PENDIENTE de implementar en el LMS — ver reporte.
 *   2. Fallback: derivar de los productos WC ya sincronizados (los que tienen
 *      `_lms_course_id`). Funciona hoy sin tocar el LMS; cubre los cursos que ya
 *      tienen un producto simple creado por el course-sync.
 *
 * El response marca `source` para que el front sepa de dónde salió la lista
 * (útil para el copy "sincronizá el curso primero" cuando solo hay fallback).
 */
final class REST_LMS_Courses {
    private const TIMEOUT_S      = 5;
    private const CACHE_KEY      = 'slc_lms_courses_list';
    private const CACHE_TTL      = 300; // 5 min — la lista cambia poco.
    private const LMS_PATH       = '/api/wc/courses';

    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(
            'studiahub/v1',
            '/lms-courses',
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [self::class, 'check_permission'],
            ]
        );
    }

    /**
     * El caller es el admin logueado (no el LMS): exigimos capability de WC.
     * El nonce wp_rest lo valida WP automáticamente al venir por la cookie auth
     * de la REST API (X-WP-Nonce), no hace falta chequearlo a mano.
     */
    public static function check_permission(): bool {
        return current_user_can('manage_woocommerce');
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        $force = $request->get_param('refresh') === '1';
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return new \WP_REST_Response($cached, 200);
            }
        }

        $from_lms = self::fetch_from_lms();
        if (is_array($from_lms)) {
            $payload = ['courses' => $from_lms, 'source' => 'lms'];
            set_transient(self::CACHE_KEY, $payload, self::CACHE_TTL);
            return new \WP_REST_Response($payload, 200);
        }

        // Fallback: cursos derivados de productos WC sincronizados.
        $payload = ['courses' => self::courses_from_synced_products(), 'source' => 'synced_products'];
        // Cache más corto para el fallback: queremos reintentar el LMS pronto.
        set_transient(self::CACHE_KEY, $payload, 60);
        return new \WP_REST_Response($payload, 200);
    }

    /**
     * Pega al endpoint del LMS que lista cursos publicados del tenant.
     * Devuelve `array<int, array{id:string, title:string}>` o null si el LMS no
     * está configurado / responde error / aún no implementa el endpoint (404).
     */
    private static function fetch_from_lms(): ?array {
        $lms_url = (string) get_option(Settings::OPT_LMS_URL, '');
        $api_key = (string) get_option(Settings::OPT_WEBHOOK_SECRET, '');
        if ($lms_url === '' || $api_key === '') {
            return null;
        }

        $url      = rtrim($lms_url, '/') . self::LMS_PATH;
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT_S,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('[slc lms-courses] WP error: ' . $response->get_error_message());
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            // 404 = el LMS todavía no implementó el endpoint → fallback silencioso.
            if ($code !== 404) {
                error_log('[slc lms-courses] HTTP ' . $code . ' for ' . $url);
            }
            return null;
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return null;
        }
        // Aceptamos tanto { courses: [...] } como [...] directo, por robustez.
        $raw = isset($decoded['courses']) && is_array($decoded['courses'])
            ? $decoded['courses']
            : $decoded;

        return self::normalize_courses($raw);
    }

    /**
     * Normaliza la lista a `{ id, title }`, descartando items sin id/title.
     */
    private static function normalize_courses(array $raw): array {
        $out = [];
        foreach ($raw as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id    = isset($c['id']) ? (string) $c['id'] : '';
            $title = '';
            if (isset($c['title']) && is_string($c['title'])) {
                $title = $c['title'];
            } elseif (isset($c['name']) && is_string($c['name'])) {
                $title = $c['name'];
            }
            if ($id === '' || $title === '') {
                continue;
            }
            $out[] = ['id' => $id, 'title' => $title];
        }
        return $out;
    }

    /**
     * Fallback: lista los cursos a partir de los productos WC que ya fueron
     * sincronizados (tienen `_lms_course_id`). Usa el título del producto como
     * label. Dedup por course id (puede haber varios productos del mismo curso).
     */
    private static function courses_from_synced_products(): array {
        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft', 'private', 'pending'],
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_lms_course_id',
                    'compare' => 'EXISTS',
                ],
            ],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $seen = [];
        $out  = [];
        foreach ($product_ids as $pid) {
            $course_id = (string) get_post_meta((int) $pid, '_lms_course_id', true);
            if ($course_id === '' || isset($seen[$course_id])) {
                continue;
            }
            // Saltear combos: un combo también podría tener _lms_course_id vacío,
            // pero por las dudas excluimos productos marcados como combo.
            if (get_post_meta((int) $pid, Product_Metabox::META_IS_COMBO, true) === 'yes') {
                continue;
            }
            $seen[$course_id] = true;
            $out[] = [
                'id'    => $course_id,
                'title' => get_the_title((int) $pid),
            ];
        }
        return $out;
    }
}
