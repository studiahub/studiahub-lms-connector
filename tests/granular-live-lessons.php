<?php
/** Prueba aislada del temario granular; ejecutar: php tests/granular-live-lessons.php */
namespace SLC {
    final class Landing_Fetch {
        public static array $payload = [];
        public static function get_payload($id): array { return self::$payload; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    function get_post_meta($id, $key, $single) { return 'test-course'; }
    function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function esc_attr($value) { return esc_html($value); }
    function esc_html__($value, $domain = '') { return esc_html($value); }
    function do_shortcode($value) {
        return preg_replace_callback(
            '~\[studiahub_course_lessons\](.*?)\[/studiahub_course_lessons\]~s',
            static fn($match) => \SLC\Shortcode_Fields::render_lessons([], $match[1]),
            $value
        );
    }
    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-shortcode-fields.php';

    \SLC\Landing_Fetch::$payload = [
        'timezone' => 'America/Argentina/Buenos_Aires',
        'timezoneLabel' => 'Argentina',
        'outline' => [[
            'title' => 'Etapa 2',
            'lessons' => [
                ['title' => 'Clase en Vivo 01', 'type' => 'TEXT', 'liveAt' => '2026-09-25T03:00:00.000Z', 'durationMin' => 0, 'meetingUrl' => 'https://meet.example.test/private'],
                ['title' => 'Video grabado', 'type' => 'VIDEO', 'liveAt' => null, 'durationMin' => 15],
            ],
        ]],
    ];

    $html = \SLC\Shortcode_Fields::render_outline(['id' => '42']);
    $checks = [
        'markup de apertura compatible con el theme NUA' => substr_count($html, '<li class="slc-lesson">') === 2,
        'fecha convertida al huso de la academia' => str_contains($html, '<time class="slc-lesson__live-date" datetime="2026-09-25T03:00:00.000Z">25 sep 2026 · 0hs (Argentina)</time>'),
        'clase grabada conserva duración' => str_contains($html, '<span class="slc-lesson__duration">15 min</span>'),
        'clase grabada no recibe fecha live' => substr_count($html, 'slc-lesson__live-date') === 1,
        'link del encuentro no se publica' => !str_contains($html, 'meet.example.test'),
    ];
    preg_match_all('~<li class="slc-lesson">(.*?)</li>~s', $html, $old_theme_items);
    $checks['parser actual de NUA sigue viendo las dos clases'] = count($old_theme_items[1]) === 2;
    $nested = \SLC\Shortcode_Fields::render_outline(['id' => '42'], '[studiahub_course_lessons][/studiahub_course_lessons]');
    $checks['fallback de lecciones anidadas incluye la fecha'] = str_contains($nested, '<time class="slc-lesson__live-date" datetime="2026-09-25T03:00:00.000Z">');

    foreach ($checks as $name => $passed) {
        if (!$passed) fwrite(STDERR, "FAIL: $name\n");
    }
    echo array_sum($checks) . '/' . count($checks) . " assertions passed\n";
    exit(in_array(false, $checks, true) ? 1 : 0);
}
