<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetch del landing payload del LMS, con WP transient + stale-while-revalidate.
 *
 * - TTL fresh: 15 min (slc_landing_<hash>)
 * - TTL stale: 7 días (slc_landing_<hash>_stale)
 *
 * Flujo:
 * 1. Si hay fresh → devolverlo.
 * 2. Si no, fetch al LMS.
 *    2a. Si responde OK → guardar fresh + stale, devolver.
 *    2b. Si falla y hay stale → devolver stale (sin actualizar TTL).
 *    2c. Si falla y NO hay stale → devolver null.
 * 3. Cache-bust borra ambos.
 *
 * Auth: usamos OPT_WEBHOOK_SECRET como Bearer. Es el "secret unificado"
 * generado en el pair: el LMS lo guarda como API key (cifrada at-rest),
 * el plugin lo guarda en plaintext (acá) + hash (para verificar requests
 * entrantes en Auth).
 */
final class Landing_Fetch {
    private const TTL_FRESH  = 900;     // 15 min
    private const TTL_STALE  = 604800;  // 7 días
    private const TIMEOUT_S  = 5;

    public static function get_payload(string $course_id): ?array {
        // 0. Filter de override — usado por el mu-plugin de dev para inyectar
        // un payload mockeado y permitir trabajar el diseño sin LMS corriendo.
        // Si el filter devuelve un array, se devuelve directamente saltando
        // cache + fetch. Cualquier otra cosa (null/false/etc) sigue el flow normal.
        $override = apply_filters('slc_landing_payload_override', null, $course_id);
        if (is_array($override)) {
            return $override;
        }

        $key       = 'slc_landing_' . md5($course_id);
        $key_stale = $key . '_stale';

        // 1. Fresh hit.
        $fresh = get_transient($key);
        if (is_array($fresh)) {
            return $fresh;
        }

        // 2. Fetch al LMS.
        $payload = self::fetch_from_lms($course_id);

        if (is_array($payload)) {
            // 2a. Éxito → cachear fresh + stale.
            set_transient($key, $payload, self::TTL_FRESH);
            set_transient($key_stale, $payload, self::TTL_STALE);
            return $payload;
        }

        // 2b. Fallo → fallback a stale.
        $stale = get_transient($key_stale);
        if (is_array($stale)) {
            return $stale;
        }

        // 2c. Nada que servir.
        return null;
    }

    private static function fetch_from_lms(string $course_id): ?array {
        $lms_url = (string) get_option(Settings::OPT_LMS_URL, '');
        $api_key = (string) get_option(Settings::OPT_WEBHOOK_SECRET, '');
        if ($lms_url === '' || $api_key === '') {
            return null;
        }

        $url = rtrim($lms_url, '/') . '/api/wc/courses/' . rawurlencode($course_id) . '/landing-payload';
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT_S,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('[slc landing-fetch] WP error: ' . $response->get_error_message());
            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('[slc landing-fetch] HTTP ' . $code . ' for ' . $url);
            return null;
        }

        $body    = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
