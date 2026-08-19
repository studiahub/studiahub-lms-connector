<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /wp-json/studiahub/v1/health — devuelve versiones de WP, WC, ACF y plugin.
 * Requiere bearer token válido (ver Auth::verify_request).
 *
 * Con `?check_lms=1` agrega la VUELTA: este WordPress le pega al LMS y reporta
 * si pudo. Existe porque "el LMS alcanza al WordPress" y "el WordPress alcanza
 * al LMS" son dos cosas distintas que se rompen por separado, y probar solo la
 * primera daba una falsa sensación de seguridad: la landing se alimenta de la
 * segunda. Es opcional para no encarecer el health normal con un HTTP saliente.
 */
final class REST_Health {
    private const LMS_TIMEOUT_S = 5;

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
                'args'                => [
                    'check_lms' => [
                        'required' => false,
                    ],
                ],
            ]
        );
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        global $wp_version;

        $body = [
            'status'         => 'ok',
            'wp_version'     => $wp_version,
            'wc_version'     => defined('WC_VERSION') ? WC_VERSION : null,
            'acf_version'    => function_exists('acf_get_setting') ? acf_get_setting('version') : null,
            'plugin_version' => SLC_VERSION,
        ];

        if ($request->get_param('check_lms')) {
            $body['lms_reachable'] = self::check_lms();
        }

        return new \WP_REST_Response($body, 200);
    }

    /**
     * Prueba la dirección WordPress → LMS con las credenciales guardadas.
     *
     * @return array{ok:bool, status:?int, latency_ms:?int, error:?string}
     */
    private static function check_lms(): array {
        $lms_url = (string) get_option(Settings::OPT_LMS_URL, '');
        $api_key = (string) get_option(Settings::OPT_WEBHOOK_SECRET, '');

        if ($lms_url === '' || $api_key === '') {
            return [
                'ok'         => false,
                'status'     => null,
                'latency_ms' => null,
                'error'      => 'Este WordPress no tiene guardada la conexión al LMS.',
            ];
        }

        $started  = microtime(true);
        $response = wp_remote_get(rtrim($lms_url, '/') . '/api/wc/health', [
            'timeout' => self::LMS_TIMEOUT_S,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ],
        ]);
        $latency = (int) round((microtime(true) - $started) * 1000);

        if (is_wp_error($response)) {
            return [
                'ok'         => false,
                'status'     => null,
                'latency_ms' => $latency,
                'error'      => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return [
            'ok'         => $code === 200,
            'status'     => $code,
            'latency_ms' => $latency,
            'error'      => $code === 200
                ? null
                : ($code === 401
                    ? 'El LMS rechazó las credenciales de este WordPress.'
                    : 'El LMS respondió ' . $code . '.'),
        ];
    }
}
