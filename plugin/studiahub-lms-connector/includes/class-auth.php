<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permission callback para rutas REST del namespace studiahub/v1.
 * Extrae `Authorization: Bearer <token>` y lo compara con el hash guardado.
 */
final class Auth {
    public static function verify_request(\WP_REST_Request $request) {
        $header = $request->get_header('authorization');
        if (!$header) {
            $header = $request->get_header('Authorization');
        }
        if (!$header || stripos($header, 'Bearer ') !== 0) {
            return new \WP_Error(
                'slc_missing_bearer',
                'Falta header Authorization: Bearer <token>.',
                ['status' => 401]
            );
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return new \WP_Error('slc_empty_token', 'Token vacío.', ['status' => 401]);
        }

        $hash = get_option(Settings::OPT_API_KEY_HASH);
        if (!$hash) {
            return new \WP_Error(
                'slc_no_api_key',
                'API key no configurada en el plugin.',
                ['status' => 401]
            );
        }

        if (!wp_check_password($token, $hash)) {
            return new \WP_Error('slc_invalid_token', 'Token inválido.', ['status' => 401]);
        }

        return true;
    }
}
