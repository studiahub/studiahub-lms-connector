<?php
/** Pruebas aisladas del renderer y proxy; ejecutar: php tests/course-pitch-waitlist.php */
namespace SLC {
    function usleep(int $microseconds): void { $GLOBALS['lock_waits'][] = $microseconds; }

    final class Settings {
        public const OPT_LMS_URL = 'slc_lms_url';
        public const OPT_WEBHOOK_SECRET = 'slc_webhook_secret';
    }
    final class Landing_Fetch {
        public static array $payload = [];
        public static function get_payload($id): array { return self::$payload; }
        public static function get_cached_payload($id): array { return self::$payload; }
    }
    final class Enroll {
        public static function url($id): string { return 'https://example.test/checkout/' . $id; }
    }
    final class Shortcode_CoursePage {
        public static function data_social_proof_public($payload): array { return ['rating' => null, 'students_label' => '', 'stats' => []]; }
        public static function data_offer_pricing_public($payload, $price): array { return ['original' => '', 'current' => $price, 'installments' => '']; }
        public static function data_bonuses_public($payload): array { return []; }
        public static function data_guarantee_public($payload) { return null; }
        public static function data_faq_public($payload): array { return []; }
        public static function parse_trailer_public($url) { return null; }
        public static function build_brand_style($branding): string { return ''; }
        public static function maybe_enqueue_google_font_public($font): void {}
        public static function stars_public($rating): string { return ''; }
        public static function lesson_icon_public($type): string { return '';}
    }
}
namespace {
    define('ABSPATH', __DIR__);
    define('SLC_VERSION', 'test');
    define('SLC_PLUGIN_URL', 'https://shop.test/wp-content/plugins/studiahub/');

    class WP_REST_Server { public const CREATABLE = 'POST'; }
    class WC_Product {
        private int $id;
        public function __construct(int $id) { $this->id = $id; }
        public function get_id(): int { return $this->id; }
    }
    class WP_REST_Response {
        private $data;
        private int $status;
        private array $headers = [];
        public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
        public function header($name, $value): void { $this->headers[strtolower($name)] = $value; }
        public function get_headers(): array { return $this->headers; }
    }
    class WP_Error {
        private string $code;
        private string $message;
        private $data;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
    class WP_REST_Request {
        public array $params;
        public $json;
        public string $body;
        public array $headers;
        public function __construct(array $params, $json, ?string $body = null, array $headers = []) {
            $this->params = $params; $this->json = $json;
            $this->body = $body ?? json_encode($json); $this->headers = array_change_key_case($headers);
        }
        public function get_param($key) { return $this->params[$key] ?? null; }
        public function get_json_params() { return $this->json; }
        public function get_body(): string { return $this->body; }
        public function get_header($key): string { return (string) ($this->headers[strtolower($key)] ?? ''); }
    }
    class WPDB_Test_Double {
        public string $options = 'wp_options';
        public array $deleted_values = [];
        public function delete($table, array $where, array $formats) {
            $key = $where['option_name'];
            $value = $where['option_value'];
            if (($GLOBALS['options'][$key] ?? null) !== $value) return 0;
            unset($GLOBALS['options'][$key]);
            $this->deleted_values[] = $value;
            return 1;
        }
    }

    $GLOBALS['transients'] = [];
    $GLOBALS['options'] = [];
    $GLOBALS['lock_snapshots'] = [];
    $GLOBALS['lock_waits'] = [];
    $GLOBALS['before_get_transient'] = null;
    $GLOBALS['remote_calls'] = [];
    $GLOBALS['remote_response'] = null;
    $GLOBALS['enqueued_scripts'] = [];
    $GLOBALS['registered_scripts'] = [];
    $GLOBALS['registered_routes'] = [];
    $GLOBALS['wc_notices'] = [];
    $GLOBALS['wpdb'] = new WPDB_Test_Double();

    function add_action(...$args) {}
    function add_shortcode(...$args) {}
    function register_rest_route($namespace, $route, $args) { $GLOBALS['registered_routes'][] = compact('namespace', 'route', 'args'); }
    function shortcode_atts($defaults, $atts, $tag) { return array_merge($defaults, $atts); }
    function is_singular() { return false; }
    function get_post_meta($id, $key, $single) { return $key === '_lms_course_id' ? 'test-course' : ''; }
    function get_post_type($id) { return $id === 42 ? 'product' : null; }
    function get_post_status($id) { return $id === 42 ? 'publish' : false; }
    function get_the_title($id) { return 'Curso de prueba'; }
    function get_option($key, $default = '') {
        if ($key === \SLC\Settings::OPT_LMS_URL) return 'https://lms.test';
        if ($key === \SLC\Settings::OPT_WEBHOOK_SECRET) return 'server-only-secret';
        return $GLOBALS['options'][$key] ?? $default;
    }
    function add_option($key, $value, $deprecated = '', $autoload = 'yes') {
        if (array_key_exists($key, $GLOBALS['options'])) return false;
        $GLOBALS['options'][$key] = $value;
        $GLOBALS['lock_snapshots'][] = [$key, $value, $autoload];
        return true;
    }
    function get_transient($key) {
        if (is_callable($GLOBALS['before_get_transient'])) {
            $callback = $GLOBALS['before_get_transient'];
            $GLOBALS['before_get_transient'] = null;
            $callback($key);
        }
        return $GLOBALS['transients'][$key] ?? false;
    }
    function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
    function wp_generate_uuid4() { static $id = 0; return 'test-lock-' . ++$id; }
    function wp_cache_delete($key, $group = '') { return true; }
    function wp_salt($scheme = '') { return 'test-salt'; }
    function absint($value) { return abs((int) $value); }
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
    function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
    function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wp_remote_post($url, $args) {
        $GLOBALS['remote_calls'][] = compact('url', 'args');
        return $GLOBALS['remote_response'];
    }
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
    function wp_remote_retrieve_header($response, $name) { return (string) ($response['headers'][strtolower($name)] ?? ''); }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
    function rest_url($path) { return 'https://shop.test/wp-json/' . ltrim($path, '/'); }
    function wp_register_style(...$args) {}
    function wp_register_script($handle, $src, $deps = [], $version = false, $in_footer = false) {
        $GLOBALS['registered_scripts'][$handle] = compact('src', 'deps', 'version', 'in_footer');
    }
    function wp_enqueue_style(...$args) {}
    function wp_enqueue_script($handle) { $GLOBALS['enqueued_scripts'][] = $handle; }
    function __($text, $domain = '') { return $text; }
    function _n($single, $plural, $number, $domain = '') { return $number === 1 ? $single : $plural; }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function esc_attr($text) { return esc_html($text); }
    function esc_url($text) { return esc_html($text); }
    function esc_html__($text, $domain = '') { return esc_html(__($text, $domain)); }
    function esc_html_e($text, $domain = '') { echo esc_html__($text, $domain); }
    function esc_attr_e($text, $domain = '') { echo esc_attr(__($text, $domain)); }
    function wp_kses_post($html) { return $html; }
    function wpautop($html) { return $html; }
    function wp_strip_all_tags($html) { return strip_tags($html); }
    function number_format_i18n($number, $decimals = 0) { return number_format($number, $decimals); }
    function wc_add_notice($message, $type = 'success') { $GLOBALS['wc_notices'][] = compact('message', 'type'); }

    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-render-guard.php';
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-purchase-gate.php';
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php';
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-shortcode-coursecta.php';
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-rest-waitlist.php';

    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $failures = [];
    $checks = 0;
    function check($condition, $message) {
        global $checks, $failures;
        $checks++;
        if (!$condition) $failures[] = $message;
    }
    function base_payload(array $extra = []): array {
        return array_merge([
            'lmsId' => 'test-course', 'title' => 'Curso de prueba', 'priceDisplay' => 'ARS 10.000',
            'salesClosed' => true, 'includedMaterials' => [],
            'waitlist' => [
                'enabled' => true,
                'formIntroText' => 'Sumate para enterarte <antes>.',
                'consentText' => 'Acepto novedades <del curso>.',
                'consentVersion' => 'v1',
            ],
        ], $extra);
    }
    function render_pitch(array $payload): string {
        $GLOBALS['enqueued_scripts'] = [];
        \SLC\Landing_Fetch::$payload = $payload;
        return \SLC\Render_Guard::restore(\SLC\Shortcode_CoursePitch::render(['id' => '42']));
    }
    function render_cta(array $payload): string {
        $GLOBALS['enqueued_scripts'] = [];
        \SLC\Landing_Fetch::$payload = $payload;
        return \SLC\Render_Guard::restore(\SLC\Shortcode_CourseCTA::render(['id' => '42']));
    }
    function remote_response(int $status, array $body = [], array $headers = []): array {
        return ['response' => ['code' => $status], 'body' => json_encode($body), 'headers' => array_change_key_case($headers)];
    }
    function request(array $changes = [], ?string $body = null, int $product_id = 42): WP_REST_Request {
        $json = array_merge([
            'fullName' => 'Ada Lovelace', 'email' => 'ADA@example.com ', 'consent' => true,
            'consentVersion' => 'v1', 'website' => '',
        ], $changes);
        return new WP_REST_Request(['product_id' => $product_id], $json, $body);
    }

    // Renderer: compatibilidad hacia atrás y gate único con Purchase_Gate.
    \SLC\Shortcode_CoursePitch::register_styles();
    check(
        ($GLOBALS['registered_scripts'][\SLC\Shortcode_CoursePitch::TURNSTILE_SCRIPT_HANDLE]['src'] ?? '')
            === 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
        'Official Turnstile API is registered for explicit rendering'
    );
    $html = render_pitch(base_payload());
    check(str_contains($html, 'data-slc-waitlist-open'), 'Closed enabled waitlist renders CTA');
    check(str_contains($html, 'data-slc-waitlist-modal'), 'Closed enabled waitlist renders modal');
    check(str_contains($html, 'Sumate para enterarte &lt;antes&gt;.'), 'Custom intro text is rendered and escaped');
    check(str_contains($html, 'Acepto novedades &lt;del curso&gt;.'), 'Consent text is escaped');
    check(str_contains($html, '/wp-json/studiahub/v1/course-waitlist/42'), 'Browser only receives product-scoped proxy URL');
    check(!str_contains($html, 'server-only-secret') && !str_contains($html, 'Bearer'), 'HTML never exposes Bearer secret');
    check(in_array(\SLC\Shortcode_CoursePitch::WAITLIST_SCRIPT_HANDLE, $GLOBALS['enqueued_scripts'], true), 'Waitlist JS is enqueued only for visible CTA');
    check(!in_array(\SLC\Shortcode_CoursePitch::TURNSTILE_SCRIPT_HANDLE, $GLOBALS['enqueued_scripts'], true), 'Turnstile asset is not enqueued without a site key');

    $turnstile_html = render_pitch(base_payload([
        'waitlist' => [
            'enabled' => true,
            'formIntroText' => 'Sumate.',
            'consentText' => 'Acepto.',
            'consentVersion' => 'v1',
            'turnstileSiteKey' => 'site-key-<public>',
        ],
    ]));
    check(str_contains($turnstile_html, 'data-slc-waitlist-turnstile'), 'Turnstile-enabled waitlist renders an explicit widget container');
    check(str_contains($turnstile_html, 'data-turnstile-site-key="site-key-&lt;public&gt;"'), 'Public Turnstile site key is escaped in the modal');
    check($GLOBALS['enqueued_scripts'] === [
        \SLC\Shortcode_CoursePitch::TURNSTILE_SCRIPT_HANDLE,
        \SLC\Shortcode_CoursePitch::WAITLIST_SCRIPT_HANDLE,
    ], 'Turnstile asset loads before waitlist controller only when configured');

    $legacy = render_pitch(base_payload(['waitlist' => null]));
    check(!str_contains($legacy, 'data-slc-waitlist-modal'), 'Old payload without waitlist keeps legacy UI');
    check(str_contains($legacy, 'Inscripciones cerradas'), 'Old closed payload keeps disabled CTA');
    $missing_waitlist = base_payload();
    unset($missing_waitlist['waitlist']);
    check($legacy === render_pitch($missing_waitlist), 'Missing waitlist is byte-compatible with null waitlist');
    $legacy_intro = base_payload();
    unset($legacy_intro['waitlist']['formIntroText']);
    check(
        str_contains(render_pitch($legacy_intro), 'Dejanos tus datos y te avisamos cuando haya novedades sobre este curso.'),
        'Payload without editable intro uses the legacy default text'
    );
    $open_waitlist = render_pitch(base_payload(['salesClosed' => false, 'comingSoon' => false]));
    check(str_contains($open_waitlist, 'data-slc-waitlist-open'), 'Open sale with enabled waitlist renders CTA');
    check(str_contains($open_waitlist, 'data-slc-waitlist-modal'), 'Open sale with enabled waitlist renders modal');
    check(!str_contains($open_waitlist, 'https://example.test/checkout/42'), 'Open sale with enabled waitlist hides checkout CTA');

    $open_sale = render_pitch(base_payload([
        'salesClosed' => false,
        'comingSoon' => false,
        'waitlist' => ['enabled' => false],
    ]));
    check(!str_contains($open_sale, 'data-slc-waitlist-modal'), 'Open sale with disabled waitlist hides modal');
    check(str_contains($open_sale, 'https://example.test/checkout/42'), 'Open sale with disabled waitlist restores checkout CTA');

    $waitlist_state = \SLC\Purchase_Gate::closed_state_from_payload(base_payload([
        'salesClosed' => true,
        'comingSoon' => true,
    ]));
    check(($waitlist_state['reason'] ?? '') === 'waitlist', 'Waitlist takes precedence over coming soon and sales closed');
    $incomplete = render_pitch(base_payload([
        'salesClosed' => false,
        'waitlist' => ['enabled' => true],
    ]));
    check(!str_contains($incomplete, 'data-slc-waitlist-modal'), 'Incomplete enabled waitlist does not render an unusable modal');
    check(!str_contains($incomplete, 'https://example.test/checkout/42'), 'Incomplete enabled waitlist fails safe by keeping checkout blocked');
    $invalid_config = render_pitch(base_payload([
        'salesClosed' => false,
        'waitlist' => ['enabled' => true, 'consentText' => ['invalid'], 'consentVersion' => 'v1'],
    ]));
    check(!str_contains($invalid_config, 'data-slc-waitlist-modal'), 'Non-string waitlist config fails safe without PHP coercion');
    check(!str_contains($invalid_config, 'https://example.test/checkout/42'), 'Non-string waitlist config keeps checkout blocked');

    // CTA granular: misma fuente de verdad que la landing completa.
    $cta_open = render_cta(base_payload([
        'salesClosed' => false,
        'comingSoon' => false,
        'ctaLabel' => 'Quiero empezar <hoy>',
        'waitlist' => ['enabled' => false],
    ]));
    check(str_contains($cta_open, 'https://example.test/checkout/42'), 'Granular CTA links to the clean enrollment endpoint when sale is open');
    check(str_contains($cta_open, 'Quiero empezar &lt;hoy&gt;'), 'Granular CTA renders and escapes the LMS label');
    check(!str_contains($cta_open, 'data-slc-waitlist-modal'), 'Open granular CTA does not render a waitlist modal');

    $cta_waitlist = render_cta(base_payload([
        'salesClosed' => false,
        'comingSoon' => false,
        'waitlist' => [
            'enabled' => true,
            'formIntroText' => 'Avisame <pronto>.',
            'consentText' => 'Acepto <novedades>.',
            'consentVersion' => 'v2',
            'turnstileSiteKey' => 'public-<site-key>',
        ],
    ]));
    check(str_contains($cta_waitlist, 'data-slc-waitlist-open'), 'Granular CTA becomes a waitlist opener');
    check(str_contains($cta_waitlist, 'data-slc-waitlist-modal'), 'Granular CTA includes its waitlist modal');
    check(str_contains($cta_waitlist, 'Avisame &lt;pronto&gt;.'), 'Granular CTA renders and escapes the waitlist intro');
    check(str_contains($cta_waitlist, 'Acepto &lt;novedades&gt;.'), 'Granular CTA renders and escapes consent');
    check(str_contains($cta_waitlist, 'data-consent-version="v2"'), 'Granular CTA sends the current consent version');
    check(str_contains($cta_waitlist, 'data-turnstile-site-key="public-&lt;site-key&gt;"'), 'Granular CTA includes the public Turnstile site key');
    check(!str_contains($cta_waitlist, 'https://example.test/checkout/42'), 'Waitlist granular CTA hides checkout');
    check(!str_contains($cta_waitlist, 'server-only-secret') && !str_contains($cta_waitlist, 'Bearer'), 'Granular CTA never exposes the connector secret');
    check($GLOBALS['enqueued_scripts'] === [
        \SLC\Shortcode_CoursePitch::TURNSTILE_SCRIPT_HANDLE,
        \SLC\Shortcode_CoursePitch::WAITLIST_SCRIPT_HANDLE,
    ], 'Granular CTA loads Turnstile before the waitlist controller');

    $cta_waitlist_again = render_cta(base_payload());
    preg_match('/slc-coursecta-waitlist-title-([^" ]+)/', $cta_waitlist, $first_cta_id);
    preg_match('/slc-coursecta-waitlist-title-([^" ]+)/', $cta_waitlist_again, $second_cta_id);
    check(($first_cta_id[1] ?? '') !== ($second_cta_id[1] ?? ''), 'Multiple granular CTAs use unique form IDs');

    $cta_coming_soon = render_cta(base_payload([
        'salesClosed' => false,
        'comingSoon' => true,
        'comingSoonLabel' => 'Muy pronto',
        'waitlist' => ['enabled' => false],
    ]));
    check(str_contains($cta_coming_soon, 'Muy pronto'), 'Granular CTA renders the coming-soon state');
    check(!str_contains($cta_coming_soon, '<a '), 'Coming-soon granular CTA cannot navigate to checkout');

    $cta_incomplete = render_cta(base_payload([
        'salesClosed' => false,
        'comingSoon' => false,
        'waitlist' => ['enabled' => true],
    ]));
    check(str_contains($cta_incomplete, 'Lista de espera'), 'Incomplete waitlist keeps the granular CTA closed');
    check(!str_contains($cta_incomplete, 'data-slc-waitlist-modal'), 'Incomplete waitlist does not render an unusable granular modal');
    check(!str_contains($cta_incomplete, 'https://example.test/checkout/42'), 'Incomplete waitlist granular CTA fails closed');

    // Purchase_Gate también cierra los caminos directos de WooCommerce.
    \SLC\Landing_Fetch::$payload = base_payload(['salesClosed' => false, 'comingSoon' => false]);
    check(\SLC\Purchase_Gate::filter_is_purchasable(true, new WC_Product(43)) === false, 'Enabled waitlist makes an open product non-purchasable');
    check(\SLC\Purchase_Gate::validate_add_to_cart(true, 44) === false, 'Enabled waitlist blocks direct add-to-cart');
    check(str_contains($GLOBALS['wc_notices'][0]['message'] ?? '', 'lista de espera'), 'Blocked add-to-cart explains the waitlist state');
    \SLC\Landing_Fetch::$payload = base_payload([
        'salesClosed' => false,
        'comingSoon' => false,
        'waitlist' => ['enabled' => false],
    ]);
    check(\SLC\Purchase_Gate::filter_is_purchasable(true, new WC_Product(45)) === true, 'Disabling waitlist restores purchasability for an open sale');
    check(\SLC\Purchase_Gate::validate_add_to_cart(true, 46) === true, 'Disabling waitlist restores direct add-to-cart for an open sale');

    \SLC\REST_Waitlist::register_routes();
    $route = $GLOBALS['registered_routes'][0] ?? null;
    check(($route['args']['methods'] ?? null) === 'POST', 'Anonymous proxy only registers POST');
    check(($route['args']['permission_callback'] ?? null) === '__return_true', 'Anonymous cached landing does not require nonce');

    // Transporte server-side y respuesta pública controlada.
    $GLOBALS['transients'] = [];
    $GLOBALS['remote_calls'] = [];
    \SLC\Landing_Fetch::$payload = base_payload();
    $GLOBALS['remote_response'] = remote_response(200, ['ok' => true, 'message' => 'raw upstream']);
    $response = \SLC\REST_Waitlist::handle(request());
    check($response->get_status() === 200 && $response->get_data()['ok'] === true, 'Valid LMS success maps to public success');
    $call = $GLOBALS['remote_calls'][0] ?? [];
    check(($call['url'] ?? '') === 'https://lms.test/api/wc/courses/test-course/waitlist', 'Course destination comes from product metadata');
    check(($call['args']['headers']['Authorization'] ?? '') === 'Bearer server-only-secret', 'Bearer is only added server-side');
    check(($call['args']['redirection'] ?? null) === 0 && ($call['args']['reject_unsafe_urls'] ?? null) === true, 'Proxy does not forward secret through redirects or unsafe URLs');
    $sent = json_decode($call['args']['body'] ?? '', true);
    check(($sent['email'] ?? '') === 'ada@example.com', 'Email is normalized before proxy');
    check(!array_key_exists('product_id', $sent), 'Visitor cannot choose an LMS destination');
    check(!array_key_exists('turnstileToken', $sent), 'Legacy request without Turnstile stays compatible');

    $GLOBALS['remote_response'] = remote_response(200, ['ok' => true]);
    $turnstile_response = \SLC\REST_Waitlist::handle(request([
        'email' => 'turnstile@example.com',
        'turnstileToken' => ' turnstile-token ',
    ]));
    $turnstile_call = $GLOBALS['remote_calls'][1] ?? [];
    $turnstile_sent = json_decode($turnstile_call['args']['body'] ?? '', true);
    check($turnstile_response->get_status() === 200, 'Turnstile request is accepted by the WordPress proxy');
    check(($turnstile_sent['turnstileToken'] ?? '') === 'turnstile-token', 'Turnstile token is normalized and passed only to LMS');

    \SLC\Landing_Fetch::$payload = base_payload(['salesClosed' => false, 'comingSoon' => false]);
    $GLOBALS['remote_response'] = remote_response(200, ['ok' => true]);
    $open_capture = \SLC\REST_Waitlist::handle(request(['email' => 'open@example.com']));
    check($open_capture->get_status() === 200, 'Open sale with enabled waitlist accepts capture');

    foreach ([400, 401, 409, 413, 429] as $status) {
        $GLOBALS['remote_response'] = remote_response($status, ['message' => 'sensitive upstream body'], ['retry-after' => '25']);
        $mapped = \SLC\REST_Waitlist::handle(request(['email' => "status{$status}@example.com"]));
        check($mapped->get_status() === $status && $mapped->get_data()['ok'] === false, "Upstream $status remains $status");
        check(!str_contains($mapped->get_data()['message'], 'sensitive'), "Upstream $status body is not exposed");
    }
    $GLOBALS['remote_response'] = new WP_Error('timeout', 'server-only-secret timed out');
    $transport = \SLC\REST_Waitlist::handle(request(['email' => 'transport@example.com']));
    check($transport->get_status() === 502 && $transport->get_data()['ok'] === false, 'Transport error never becomes success');
    check(!str_contains(json_encode($transport->get_data()), 'server-only-secret'), 'Transport error does not leak secret');
    $GLOBALS['remote_response'] = ['response' => ['code' => 200], 'body' => '<html>proxy error</html>', 'headers' => []];
    check(\SLC\REST_Waitlist::handle(request(['email' => 'bad-json@example.com']))->get_status() === 502, 'Malformed 200 is not treated as success');

    // Validación, honeypot, tamaño y límite sin confiar en IP compartida.
    $before = count($GLOBALS['remote_calls']);
    check(\SLC\REST_Waitlist::handle(request(['email' => 'not-an-email']))->get_status() === 400, 'Invalid email is rejected');
    check(\SLC\REST_Waitlist::handle(request(['consent' => false]))->get_status() === 400, 'Missing consent is rejected');
    check(\SLC\REST_Waitlist::handle(request(['website' => 'https://spam.test']))->get_status() === 400, 'Honeypot is rejected');
    foreach ([123, true, ['nested']] as $invalid_token) {
        check(\SLC\REST_Waitlist::handle(request(['turnstileToken' => $invalid_token]))->get_status() === 400, 'Turnstile token rejects non-string JSON');
    }
    check(\SLC\REST_Waitlist::handle(request(['turnstileToken' => str_repeat('x', 2049)]))->get_status() === 400, 'Turnstile token enforces its 2048-byte limit');
    foreach (['fullName', 'email', 'consentVersion', 'website'] as $field) {
        foreach ([['nested'], (object) ['nested' => 'value']] as $non_scalar) {
            $invalid = \SLC\REST_Waitlist::handle(request([$field => $non_scalar]));
            check($invalid->get_status() === 400, "$field rejects non-scalar JSON without coercion");
        }
    }
    check(\SLC\REST_Waitlist::handle(request([], str_repeat('x', 4097)))->get_status() === 413, 'Oversized body is rejected');
    check(\SLC\REST_Waitlist::handle(request([], null, 99))->get_status() === 404, 'Unknown product cannot select a course');
    \SLC\Landing_Fetch::$payload = base_payload([
        'salesClosed' => false,
        'waitlist' => ['enabled' => true, 'consentText' => ['invalid'], 'consentVersion' => 'v1'],
    ]);
    check(\SLC\REST_Waitlist::handle(request(['email' => 'incomplete@example.com']))->get_status() === 409, 'Incomplete enabled waitlist rejects direct capture');
    \SLC\Landing_Fetch::$payload = base_payload(['waitlist' => ['enabled' => false]]);
    check(\SLC\REST_Waitlist::handle(request(['email' => 'disabled@example.com']))->get_status() === 409, 'Disabled waitlist rejects direct POST');
    \SLC\Landing_Fetch::$payload = base_payload();
    check(\SLC\REST_Waitlist::handle(request(['email' => 'stale@example.com', 'consentVersion' => 'old']))->get_status() === 409, 'Stale consent version is rejected');
    check(count($GLOBALS['remote_calls']) === $before, 'Invalid requests never reach LMS');

    $GLOBALS['transients'] = [];
    $GLOBALS['remote_response'] = remote_response(200, ['ok' => true]);
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    for ($i = 0; $i < 3; $i++) {
        check(\SLC\REST_Waitlist::handle(request(['email' => 'repeat@example.com']))->get_status() === 200, 'First three same-course/email attempts are allowed');
    }
    check(\SLC\REST_Waitlist::handle(request(['email' => 'repeat@example.com']))->get_status() === 429, 'Fourth same-course/email attempt is limited');
    check(\SLC\REST_Waitlist::handle(request(['email' => 'other@example.com']))->get_status() === 200, 'Different email behind same proxy IP remains allowed');
    check(!str_contains(implode('|', array_keys($GLOBALS['transients'])), 'repeat@example.com'), 'Rate-limit keys never store raw email');
    check(!str_contains(json_encode($GLOBALS['lock_snapshots']), 'repeat@example.com'), 'Database lock never stores raw email');
    check(array_reduce(
        $GLOBALS['lock_snapshots'],
        static fn($valid, $snapshot) => $valid && $snapshot[2] === 'no',
        true
    ), 'Database locks disable autoload');

    // Una segunda request que entra en la sección crítica no puede saltear el tercer intento.
    $concurrent_email = 'concurrent@example.com';
    $concurrent_digest = hash_hmac('sha256', 'test-course|' . $concurrent_email, wp_salt('nonce'));
    $concurrent_rate_key = 'slc_waitlist_rate_' . $concurrent_digest;
    $GLOBALS['transients'][$concurrent_rate_key] = [time(), time()];
    $GLOBALS['concurrent_response'] = null;
    $GLOBALS['before_get_transient'] = static function ($key) use ($concurrent_email, $concurrent_rate_key): void {
        if ($key === $concurrent_rate_key) {
            $GLOBALS['concurrent_response'] = \SLC\REST_Waitlist::handle(request(['email' => $concurrent_email]));
        }
    };
    $concurrent_calls_before = count($GLOBALS['remote_calls']);
    $third = \SLC\REST_Waitlist::handle(request(['email' => $concurrent_email]));
    check($third->get_status() === 200, 'Lock owner records the third attempt');
    check($GLOBALS['concurrent_response'] instanceof WP_REST_Response
        && $GLOBALS['concurrent_response']->get_status() === 429, 'Concurrent lock contender fails safe');
    check(count($GLOBALS['transients'][$concurrent_rate_key]) === 3, 'Concurrent contention cannot lose a rate-limit update');
    check(count($GLOBALS['remote_calls']) === $concurrent_calls_before + 1, 'Only the accepted concurrent request reaches LMS');
    check(\SLC\REST_Waitlist::handle(request(['email' => $concurrent_email]))->get_status() === 429, 'Limit remains effective after contention');
    check(count($GLOBALS['lock_waits']) > 0, 'Active lock retries are bounded before failing safe');

    // Un proceso caído no bloquea la clave para siempre: el valor vencido se reemplaza y libera.
    $stale_email = 'stale-lock@example.com';
    $stale_digest = hash_hmac('sha256', 'test-course|' . $stale_email, wp_salt('nonce'));
    $stale_lock_key = 'slc_waitlist_lock_' . $stale_digest;
    $stale_value = (time() - 1) . '|abandoned-lock';
    $GLOBALS['options'][$stale_lock_key] = $stale_value;
    $stale_response = \SLC\REST_Waitlist::handle(request(['email' => $stale_email]));
    check($stale_response->get_status() === 200, 'Expired database lock is recovered');
    check(!array_key_exists($stale_lock_key, $GLOBALS['options']), 'Recovered lock is released after use');
    check(in_array($stale_value, $GLOBALS['wpdb']->deleted_values, true), 'Stale lock removal is value-conditional');

    foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n");
    echo ($checks - count($failures)) . "/$checks assertions passed\n";
    exit($failures ? 1 : 0);
}
