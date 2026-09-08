<?php
/** Prueba aislada del renderer real; ejecutar: php tests/course-pitch-access-duration.php */
namespace SLC {
    final class Landing_Fetch {
        public static array $payload = [];
        public static function get_payload($id): array { return self::$payload; }
    }
    final class Enroll {
        public static function url($id): string { return 'https://example.test/checkout/' . $id; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    function shortcode_atts($defaults, $atts, $tag) { return array_merge($defaults, $atts); }
    function get_post_meta($id, $key, $single) { return 'test-course'; }
    function get_the_title($id) { return 'Fallback title'; }
    function wp_enqueue_style(...$args) {}
    function __($text, $domain = '') { return $GLOBALS['translations'][$text] ?? $text; }
    function _n($single, $plural, $number, $domain = '') { return __($number === 1 ? $single : $plural, $domain); }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function esc_attr($text) { return esc_html($text); }
    function esc_url($text) { return esc_html($text); }
    function esc_html__($text, $domain = '') { return esc_html(__($text, $domain)); }
    function esc_html_e($text, $domain = '') { echo esc_html__($text, $domain); }
    function number_format_i18n($number, $decimals = 0) { return number_format($number, $decimals); }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-render-guard.php';
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-shortcode-coursepage.php';
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-purchase-gate.php';
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-shortcode-coursepitch.php';

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
    function render_pitch(array $extra): string {
        \SLC\Landing_Fetch::$payload = array_merge([
            'title' => 'Legacy <course>', 'priceDisplay' => 'USD 100',
            'courseType' => 'on_demand', 'level' => 'Intermedio', 'language' => 'Español',
            'hasCertificate' => true, 'modulesCount' => 2, 'lessonsCount' => 4,
            'durationHours' => 19, 'totalDurationMin' => 1140,
            'includedMaterials' => [['text' => 'Legacy <material>']],
        ], $extra);
        return \SLC\Render_Guard::restore(\SLC\Shortcode_CoursePitch::render(['id' => '42']));
    }
    function regions(string $html): array {
        preg_match('~<ul class="slc-cpitch__hero-meta">(.*?)</ul>~s', $html, $hero);
        preg_match('~<div class="slc-cpitch__pricing-card">(.*?)<div class="slc-cpitch__pricing-price">~s', $html, $pricing);
        check(isset($hero[1], $pricing[1]), 'Header/pricing regions exist');
        return [$hero[1] ?? '', $pricing[1] ?? ''];
    }
    foreach ([30 => 'Acceso por 30 días', 1 => 'Acceso por 1 día', 0 => 'Acceso de por vida'] as $days => $label) {
        foreach ([[], ['includedMaterials' => []]] as $variant) {
            $html = render_pitch(array_merge($variant, ['accessDays' => $days]));
            foreach (regions($html) as $i => $region) {
                check(substr_count($region, $label) === 1, "accessDays=$days region=$i displays exactly one label");
            }
            check(substr_count($html, $label) === 2, "accessDays=$days only header and pricing");
        }
    }
    $legacy = render_pitch([]);
    foreach ([null, -1, 1.5, true, false, '0', '30', '<script>alert(1)</script>', [], new \stdClass(), 0.0] as $invalid) {
        $html = render_pitch(['accessDays' => $invalid]);
        check($html === $legacy, 'Invalid accessDays leaves legacy output byte-for-byte unchanged: ' . json_encode($invalid));
    }
    foreach (regions($legacy) as $region) check(strpos($region, 'Acceso ') === false, 'Missing accessDays never promises access');
    foreach (['On demand', 'Intermedio', 'Español', 'Certificado', '2 módulos', '4 lecciones'] as $label) {
        check(strpos(regions($legacy)[0], $label) !== false, "Legacy chip remains: $label");
    }
    check(strpos($legacy, 'Legacy &lt;material&gt;') !== false, 'Legacy materials remain escaped');
    check(strpos($legacy, 'https://example.test/checkout/42') !== false, 'Checkout URL remains');
    check(strpos(regions($legacy)[0], '19') === false, 'No extra content-duration chip');
    foreach ([30 => 'Acceso por %d días', 1 => 'Acceso por %d día', 0 => 'Acceso de por vida'] as $days => $source) {
        $GLOBALS['translations'] = [$source => '<b>' . $source . '</b>'];
        $html = render_pitch(['accessDays' => $days]);
        foreach (regions($html) as $region) {
            check(strpos($region, '&lt;b&gt;Acceso ') !== false && strpos($region, '<b>Acceso ') === false, 'Access label translation is escaped');
        }
    }
    unset($GLOBALS['translations']);
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n");
    echo ($checks - count($failures)) . "/$checks assertions passed\n";
    exit($failures ? 1 : 0);
}
