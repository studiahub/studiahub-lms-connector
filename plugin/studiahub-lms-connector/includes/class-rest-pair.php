<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoints REST del flow OAuth-style "Conectar al LMS".
 *
 *   POST /studiahub/v1/exchange
 *     Sin bearer. Recibe { code, state }. Si el code es válido y matchea el
 *     state (transient seteado por la pantalla authorize), genera un secret
 *     unificado, lo guarda local, recrea el WC webhook con ese secret y
 *     responde { secret, wcSiteUrl, wpVersion } al LMS.
 *
 *   POST /studiahub/v1/disconnect
 *     Bearer auth. Limpia api_key_hash, webhook_secret, lms_url y borra el
 *     WC webhook. Responde { ok: true }.
 */
final class REST_Pair {
    public const TRANSIENT_PREFIX = 'slc_pending_pair_';
    public const TRANSIENT_TTL    = 600; // 10 min

    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(
            'studiahub/v1',
            '/exchange',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handle_exchange'],
                'permission_callback' => '__return_true', // valida con code
            ]
        );
        register_rest_route(
            'studiahub/v1',
            '/disconnect',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handle_disconnect'],
                'permission_callback' => [Auth::class, 'verify_request'],
            ]
        );
    }

    /**
     * Canjea code one-time por secret unificado.
     */
    public static function handle_exchange(\WP_REST_Request $request): \WP_REST_Response {
        $body  = $request->get_json_params();
        $code  = isset($body['code'])  ? sanitize_text_field((string) $body['code'])  : '';
        $state = isset($body['state']) ? sanitize_text_field((string) $body['state']) : '';

        if ($code === '' || $state === '') {
            return new \WP_REST_Response(['error' => 'Faltan code o state.'], 400);
        }
        if (strlen($code) < 16 || strlen($state) < 16) {
            return new \WP_REST_Response(['error' => 'code o state inválidos.'], 400);
        }

        $transient_key = self::TRANSIENT_PREFIX . $state;
        $stored        = get_transient($transient_key);
        if (!$stored || !is_array($stored)) {
            return new \WP_REST_Response(['error' => 'No hay solicitud pendiente para ese state.'], 400);
        }
        if (!hash_equals((string) ($stored['code'] ?? ''), $code)) {
            return new \WP_REST_Response(['error' => 'code no coincide.'], 400);
        }

        // Consumir el transient (one-time).
        delete_transient($transient_key);

        // Generar secret unificado: REST bearer (hashed) + webhook HMAC (plaintext).
        $secret  = wp_generate_password(64, false, false);
        $lms_url = isset($stored['lms_url']) ? (string) $stored['lms_url'] : '';

        update_option(Settings::OPT_API_KEY_HASH, wp_hash_password($secret), false);
        update_option(Settings::OPT_API_KEY_GENERATED_AT, time(), false);
        update_option(Settings::OPT_WEBHOOK_SECRET, $secret, false);
        if ($lms_url !== '') {
            update_option(Settings::OPT_LMS_URL, $lms_url, false);
        }

        // Recrear webhook con el secret nuevo.
        if (class_exists('\\SLC\\WebhookBootstrap')) {
            WebhookBootstrap::force_recreate();
        }

        global $wp_version;
        return new \WP_REST_Response([
            'secret'    => $secret,
            'wcSiteUrl' => home_url(),
            'wpVersion' => $wp_version,
        ], 200);
    }

    /**
     * Cierra la conexión de este WP con el LMS — el LMS lo invoca cuando el
     * admin pidió desconectar.
     */
    public static function handle_disconnect(\WP_REST_Request $request): \WP_REST_Response {
        delete_option(Settings::OPT_API_KEY_HASH);
        delete_option(Settings::OPT_API_KEY_GENERATED_AT);
        delete_option(Settings::OPT_WEBHOOK_SECRET);
        delete_option(Settings::OPT_LMS_URL);

        // Borrar los WC webhooks del LMS (todos los topics).
        if (class_exists('\\SLC\\WebhookBootstrap')) {
            WebhookBootstrap::delete_all_for_lms();
        }

        return new \WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Helper estático para que la pantalla authorize cree el transient.
     */
    public static function store_pending(string $state, string $code, string $lms_url): void {
        set_transient(
            self::TRANSIENT_PREFIX . $state,
            [
                'code'       => $code,
                'lms_url'    => $lms_url,
                'created_at' => time(),
            ],
            self::TRANSIENT_TTL
        );
    }
}
