<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proxy anónimo de la lista de espera hacia el LMS.
 *
 * El visitante sólo elige el producto de WooCommerce. El course ID y el
 * destino se resuelven del lado servidor para que no pueda redirigir el proxy.
 */
final class REST_Waitlist {
    private const MAX_BODY_BYTES = 4096;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_EMAIL_LENGTH = 254;
    private const MAX_CONSENT_VERSION_LENGTH = 64;
    private const MAX_TURNSTILE_TOKEN_LENGTH = 2048;
    private const RATE_LIMIT = 3;
    private const RATE_WINDOW_SECONDS = 600;
    private const RATE_LOCK_TTL_SECONDS = 5;
    private const RATE_LOCK_ATTEMPTS = 4;
    private const RATE_LOCK_WAIT_MICROSECONDS = 50000;
    private const TIMEOUT_SECONDS = 10;

    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('studiahub/v1', '/course-waitlist/(?P<product_id>\d+)', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'product_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        if (self::body_too_large($request)) {
            return self::error_response(413, __('La solicitud es demasiado grande.', 'studiahub-lms-connector'));
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return self::error_response(400, __('La solicitud no es válida.', 'studiahub-lms-connector'));
        }

        foreach (['fullName', 'email', 'consentVersion', 'website'] as $field) {
            if (array_key_exists($field, $params) && !is_scalar($params[$field])) {
                return self::error_response(400, __('La solicitud no es válida.', 'studiahub-lms-connector'));
            }
        }
        if (array_key_exists('turnstileToken', $params) && !is_string($params['turnstileToken'])) {
            return self::error_response(400, __('La solicitud no es válida.', 'studiahub-lms-connector'));
        }

        // Campo trampa: no se completa ni con teclado ni con autocompletado.
        if (trim((string) ($params['website'] ?? '')) !== '') {
            return self::error_response(400, __('La solicitud no es válida.', 'studiahub-lms-connector'));
        }

        $input = self::validate_input($params);
        if (is_wp_error($input)) {
            return self::error_response(400, $input->get_error_message());
        }

        $product_id = absint($request->get_param('product_id'));
        $context = self::resolve_course_context($product_id, $input['consentVersion']);
        if (is_wp_error($context)) {
            $error_data = $context->get_error_data();
            $status = is_array($error_data) ? (int) ($error_data['status'] ?? 409) : 409;
            return self::error_response($status, $context->get_error_message());
        }

        if (!self::consume_rate_limit($context['courseId'], $input['email'])) {
            $response = self::error_response(429, __('Hiciste varios intentos. Esperá unos minutos y volvé a probar.', 'studiahub-lms-connector'));
            $response->header('Retry-After', (string) self::RATE_WINDOW_SECONDS);
            return $response;
        }

        return self::proxy_to_lms($context['courseId'], $input);
    }

    private static function body_too_large(\WP_REST_Request $request): bool {
        $content_length = trim((string) $request->get_header('content-length'));
        if ($content_length !== '' && ctype_digit($content_length) && (int) $content_length > self::MAX_BODY_BYTES) {
            return true;
        }
        return strlen((string) $request->get_body()) > self::MAX_BODY_BYTES;
    }

    /** @return array{fullName:string,email:string,consentVersion:string,turnstileToken?:string}|\WP_Error */
    private static function validate_input(array $params) {
        if (($params['consent'] ?? null) !== true) {
            return new \WP_Error('slc_waitlist_consent', __('Tenés que aceptar el consentimiento para continuar.', 'studiahub-lms-connector'));
        }

        $full_name = sanitize_text_field((string) ($params['fullName'] ?? ''));
        $raw_email = strtolower(trim((string) ($params['email'] ?? '')));
        $email = sanitize_email($raw_email);
        $consent_version = sanitize_text_field((string) ($params['consentVersion'] ?? ''));
        $turnstile_token = trim((string) ($params['turnstileToken'] ?? ''));

        if ($full_name === '' || self::text_length($full_name) > self::MAX_NAME_LENGTH) {
            return new \WP_Error('slc_waitlist_name', __('Ingresá un nombre válido.', 'studiahub-lms-connector'));
        }
        if ($email === '' || $email !== $raw_email || strlen($email) > self::MAX_EMAIL_LENGTH || !is_email($email)) {
            return new \WP_Error('slc_waitlist_email', __('Ingresá un email válido.', 'studiahub-lms-connector'));
        }
        if ($consent_version === '' || self::text_length($consent_version) > self::MAX_CONSENT_VERSION_LENGTH) {
            return new \WP_Error('slc_waitlist_consent_version', __('Actualizá la página y volvé a intentar.', 'studiahub-lms-connector'));
        }
        if (strlen($turnstile_token) > self::MAX_TURNSTILE_TOKEN_LENGTH) {
            return new \WP_Error('slc_waitlist_turnstile', __('No pudimos validar la solicitud. Probá de nuevo.', 'studiahub-lms-connector'));
        }

        $input = [
            'fullName'       => $full_name,
            'email'          => $email,
            'consentVersion' => $consent_version,
        ];
        if ($turnstile_token !== '') {
            $input['turnstileToken'] = $turnstile_token;
        }
        return $input;
    }

    private static function text_length(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @return array{courseId:string}|\WP_Error */
    private static function resolve_course_context(int $product_id, string $consent_version) {
        if ($product_id <= 0 || get_post_type($product_id) !== 'product' || get_post_status($product_id) !== 'publish') {
            return new \WP_Error('slc_waitlist_product', __('El curso no está disponible.', 'studiahub-lms-connector'), ['status' => 404]);
        }

        $course_id = trim((string) get_post_meta($product_id, '_lms_course_id', true));
        if ($course_id === '') {
            return new \WP_Error('slc_waitlist_course', __('El curso no está disponible.', 'studiahub-lms-connector'), ['status' => 404]);
        }

        $payload = Landing_Fetch::get_payload($course_id);
        if (!is_array($payload)) {
            return new \WP_Error('slc_waitlist_payload', __('No pudimos consultar el curso. Probá de nuevo en unos minutos.', 'studiahub-lms-connector'), ['status' => 503]);
        }
        if (isset($payload['lmsId']) && (string) $payload['lmsId'] !== $course_id) {
            return new \WP_Error('slc_waitlist_mismatch', __('El curso no está disponible.', 'studiahub-lms-connector'), ['status' => 404]);
        }

        $waitlist = is_array($payload['waitlist'] ?? null) ? $payload['waitlist'] : [];
        $current_version = is_string($waitlist['consentVersion'] ?? null)
            ? trim($waitlist['consentVersion'])
            : '';
        $consent_text = is_string($waitlist['consentText'] ?? null)
            ? trim($waitlist['consentText'])
            : '';
        if (($waitlist['enabled'] ?? false) !== true
            || $current_version === ''
            || $consent_text === '') {
            return new \WP_Error('slc_waitlist_unavailable', __('La lista de espera ya no está disponible.', 'studiahub-lms-connector'), ['status' => 409]);
        }
        if (!hash_equals($current_version, $consent_version)) {
            return new \WP_Error('slc_waitlist_stale', __('La configuración cambió. Actualizá la página y volvé a intentar.', 'studiahub-lms-connector'), ['status' => 409]);
        }

        return ['courseId' => $course_id];
    }

    private static function consume_rate_limit(string $course_id, string $email): bool {
        $normalized_email = strtolower(trim($email));
        $digest = hash_hmac('sha256', $course_id . '|' . $normalized_email, wp_salt('nonce'));
        $key = 'slc_waitlist_rate_' . $digest;
        $lock_key = 'slc_waitlist_lock_' . $digest;
        $lock_value = self::acquire_rate_lock($lock_key);
        if ($lock_value === null) {
            return false;
        }

        try {
            $now = time();
            $attempts = get_transient($key);
            $attempts = is_array($attempts) ? array_values(array_filter(
                $attempts,
                static fn($timestamp) => is_int($timestamp) && $timestamp > $now - self::RATE_WINDOW_SECONDS
            )) : [];

            if (count($attempts) >= self::RATE_LIMIT) {
                return false;
            }

            $attempts[] = $now;
            return set_transient($key, $attempts, self::RATE_WINDOW_SECONDS);
        } finally {
            self::delete_rate_lock($lock_key, $lock_value);
        }
    }

    private static function acquire_rate_lock(string $key): ?string {
        $value = (string) (time() + self::RATE_LOCK_TTL_SECONDS) . '|' . wp_generate_uuid4();

        for ($attempt = 0; $attempt < self::RATE_LOCK_ATTEMPTS; $attempt++) {
            if (add_option($key, $value, '', 'no')) {
                return $value;
            }

            $current = get_option($key, '');
            $parts = is_string($current) ? explode('|', $current, 2) : [];
            $expires_at = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : 0;
            if ($expires_at > 0 && $expires_at <= time()) {
                self::delete_rate_lock($key, $current);
                continue;
            }

            if ($attempt + 1 < self::RATE_LOCK_ATTEMPTS) {
                usleep(self::RATE_LOCK_WAIT_MICROSECONDS);
            }
        }

        return null;
    }

    private static function delete_rate_lock(string $key, string $value): void {
        global $wpdb;

        $wpdb->delete(
            $wpdb->options,
            ['option_name' => $key, 'option_value' => $value],
            ['%s', '%s']
        );
        wp_cache_delete($key, 'options');
    }

    /** @param array{fullName:string,email:string,consentVersion:string,turnstileToken?:string} $input */
    private static function proxy_to_lms(string $course_id, array $input): \WP_REST_Response {
        $lms_url = trim((string) get_option(Settings::OPT_LMS_URL, ''));
        $secret = (string) get_option(Settings::OPT_WEBHOOK_SECRET, '');
        if ($lms_url === '' || $secret === '') {
            return self::error_response(503, __('La lista de espera no está disponible en este momento.', 'studiahub-lms-connector'));
        }

        $url = rtrim($lms_url, '/') . '/api/wc/courses/' . rawurlencode($course_id) . '/waitlist';
        $body = [
            'fullName'       => $input['fullName'],
            'email'          => $input['email'],
            'consent'        => true,
            'consentVersion' => $input['consentVersion'],
        ];
        if (isset($input['turnstileToken'])) {
            $body['turnstileToken'] = $input['turnstileToken'];
        }

        $response = wp_remote_post($url, [
            'timeout'            => self::TIMEOUT_SECONDS,
            'redirection'        => 0,
            'reject_unsafe_urls' => true,
            'headers'            => [
                'Authorization' => 'Bearer ' . $secret,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'body'               => wp_json_encode($body),
            'data_format'        => 'body',
        ]);

        if (is_wp_error($response)) {
            return self::error_response(502, __('No pudimos registrar tu interés. Probá de nuevo en unos minutos.', 'studiahub-lms-connector'));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status === 200 && is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            return new \WP_REST_Response([
                'ok'      => true,
                'message' => __('Tu interés quedó registrado en la lista de espera.', 'studiahub-lms-connector'),
            ], 200);
        }

        $messages = [
            400 => __('Revisá los datos ingresados.', 'studiahub-lms-connector'),
            401 => __('No pudimos validar la solicitud. Probá de nuevo más tarde.', 'studiahub-lms-connector'),
            409 => __('La configuración cambió. Actualizá la página y volvé a intentar.', 'studiahub-lms-connector'),
            413 => __('La solicitud es demasiado grande.', 'studiahub-lms-connector'),
            429 => __('Hiciste varios intentos. Esperá unos minutos y volvé a probar.', 'studiahub-lms-connector'),
        ];
        $public_status = isset($messages[$status]) ? $status : 502;
        $message = $messages[$status] ?? __('No pudimos registrar tu interés. Probá de nuevo en unos minutos.', 'studiahub-lms-connector');
        $result = self::error_response($public_status, $message);

        if ($status === 429) {
            $retry_after = trim((string) wp_remote_retrieve_header($response, 'retry-after'));
            if (ctype_digit($retry_after)) {
                $result->header('Retry-After', (string) min(3600, max(1, (int) $retry_after)));
            }
        }
        return $result;
    }

    private static function error_response(int $status, string $message): \WP_REST_Response {
        return new \WP_REST_Response([
            'ok'      => false,
            'message' => $message,
        ], $status);
    }
}
