<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_meta] — renderiza una fila de chips con los
 * datos clave del curso: tipo, duración, nivel, idioma, certificado, módulos
 * y lecciones. Lo que esté vacío no se muestra.
 *
 * Atributos:
 *   id                ID del producto WC (default: actual)
 *   show              CSV de qué chips mostrar — type,duration,level,language,
 *                     certificate,modules,lessons — default: todos
 *   layout            row (default) | grid
 */
final class Shortcode_Meta {
    public const SHORTCODE_TAG = 'studiahub_course_meta';
    private static bool $assets_printed = false;

    private const TYPE_LABELS = [
        'on_demand' => 'On demand',
        'live'      => 'En vivo',
        'in_person' => 'Presencial',
        'hybrid'    => 'Híbrido',
    ];

    public static function register_hooks(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render']);
    }

    public static function render($atts): string {
        $atts = shortcode_atts([
            'id'     => '',
            'show'   => 'type,duration,level,language,certificate,modules,lessons',
            'layout' => 'row',
        ], $atts, self::SHORTCODE_TAG);

        $product_id = self::resolve_product_id($atts['id']);
        if (!$product_id || !function_exists('get_field')) {
            return '';
        }

        $show = array_filter(array_map('trim', explode(',', strtolower($atts['show']))));
        $items = [];

        if (in_array('type', $show, true)) {
            $type_key = (string) get_field('sh_course_course_type', $product_id);
            if ($type_key !== '' && isset(self::TYPE_LABELS[$type_key])) {
                $items[] = ['icon' => self::icon('type'), 'label' => self::TYPE_LABELS[$type_key]];
            }
        }

        if (in_array('duration', $show, true)) {
            $hours = (int) get_field('sh_course_duration_hours', $product_id);
            if ($hours > 0) {
                $items[] = ['icon' => self::icon('clock'), 'label' => $hours . ' h de contenido'];
            } else {
                // Fallback: usar suma de duraciones derivadas
                $mins = (int) get_field('sh_course_total_duration_min', $product_id);
                if ($mins > 0) {
                    $items[] = ['icon' => self::icon('clock'), 'label' => self::format_duration($mins)];
                }
            }
        }

        if (in_array('level', $show, true)) {
            $level = (string) get_field('sh_course_level', $product_id);
            if ($level !== '') {
                $items[] = ['icon' => self::icon('level'), 'label' => $level];
            }
        }

        if (in_array('language', $show, true)) {
            $lang = (string) get_field('sh_course_language', $product_id);
            if ($lang !== '') {
                $items[] = ['icon' => self::icon('globe'), 'label' => $lang];
            }
        }

        if (in_array('certificate', $show, true)) {
            $has_cert = (bool) get_field('sh_course_has_certificate', $product_id);
            if ($has_cert) {
                $items[] = ['icon' => self::icon('certificate'), 'label' => 'Certificado'];
            }
        }

        if (in_array('modules', $show, true)) {
            $modules = (int) get_field('sh_course_modules_count', $product_id);
            if ($modules > 0) {
                $items[] = ['icon' => self::icon('stack'), 'label' => $modules . ' ' . ($modules === 1 ? 'módulo' : 'módulos')];
            }
        }

        if (in_array('lessons', $show, true)) {
            $lessons = (int) get_field('sh_course_lessons_count', $product_id);
            if ($lessons > 0) {
                $items[] = ['icon' => self::icon('play'), 'label' => $lessons . ' ' . ($lessons === 1 ? 'lección' : 'lecciones')];
            }
        }

        if (empty($items)) {
            return '';
        }

        $is_grid = $atts['layout'] === 'grid';

        ob_start();
        self::print_styles();
        ?>
        <div class="slc-meta <?php echo $is_grid ? 'slc-meta--grid' : 'slc-meta--row'; ?>">
            <?php foreach ($items as $item): ?>
                <div class="slc-meta__chip">
                    <span class="slc-meta__icon" aria-hidden="true"><?php echo $item['icon']; ?></span>
                    <span class="slc-meta__label"><?php echo esc_html($item['label']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function resolve_product_id($override): int {
        if ($override !== '' && is_numeric($override)) {
            return (int) $override;
        }
        global $product, $post;
        if ($product && is_a($product, 'WC_Product')) {
            return (int) $product->get_id();
        }
        if ($post && $post->post_type === 'product') {
            return (int) $post->ID;
        }
        return 0;
    }

    private static function format_duration(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m === 0 ? $h . ' h de contenido' : $h . ' h ' . $m . ' min';
    }

    private static function icon(string $name): string {
        $icons = [
            'type'        => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="12" height="9" rx="1"/><path d="M5 14h6"/></svg>',
            'clock'       => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><polyline points="8,4.5 8,8 10.5,9.5"/></svg>',
            'level'       => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="9" width="3" height="5"/><rect x="6.5" y="6" width="3" height="8"/><rect x="11" y="3" width="3" height="11"/></svg>',
            'globe'       => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c2 2 2 10 0 12M8 2c-2 2-2 10 0 12"/></svg>',
            'certificate' => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="6.5" r="3.5"/><polyline points="6,9 5.5,14 8,12.5 10.5,14 10,9"/></svg>',
            'stack'       => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polygon points="8,2 14,5 8,8 2,5"/><polyline points="2,8 8,11 14,8"/><polyline points="2,11 8,14 14,11"/></svg>',
            'play'        => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><polygon points="6.5,5 11,8 6.5,11" fill="currentColor" stroke="none"/></svg>',
        ];
        return $icons[$name] ?? '';
    }

    private static function print_styles(): void {
        if (self::$assets_printed) {
            return;
        }
        self::$assets_printed = true;
        ?>
        <style>
            .slc-meta { font-family: inherit; color: #1A1B1E; }
            .slc-meta *, .slc-meta *::before, .slc-meta *::after { box-sizing: border-box; }
            .slc-meta--row { display: flex; flex-wrap: wrap; gap: 8px; }
            .slc-meta--grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
            .slc-meta__chip { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px; background: #F1F3F5; color: #495057; font-size: 13px; font-weight: 500; line-height: 1; }
            .slc-meta--grid .slc-meta__chip { border-radius: 10px; padding: 10px 14px; background: #fff; border: 1px solid #E9ECEF; }
            .slc-meta__icon { display: inline-flex; align-items: center; justify-content: center; color: #1971C2; }
            .slc-meta__label { white-space: nowrap; }
        </style>
        <?php
    }
}
