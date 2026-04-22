<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_outline] — renderiza el temario del curso
 * (módulos + lecciones) como accordion HTML.
 *
 * Usage en Elementor o editor WP:
 *   [studiahub_course_outline]
 *     → usa el producto actual (contexto product de WC)
 *
 *   [studiahub_course_outline id="42"]
 *     → fuerza el producto con ID 42
 *
 *   [studiahub_course_outline default_open="0"]
 *     → todos los módulos cerrados por defecto (default: primero abierto)
 */
final class Shortcode_Outline {
    public const SHORTCODE_TAG = 'studiahub_course_outline';
    private static bool $assets_printed = false;

    public static function register_hooks(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render']);
    }

    public static function render($atts): string {
        $atts = shortcode_atts([
            'id'           => '',
            'default_open' => '1',
            'show_count'   => '1',
        ], $atts, self::SHORTCODE_TAG);

        $product_id = self::resolve_product_id($atts['id']);
        if (!$product_id) {
            return '';
        }

        $outline = self::get_outline($product_id);
        if (empty($outline)) {
            return '';
        }

        $default_open = $atts['default_open'] === '1';
        $show_count   = $atts['show_count'] === '1';
        $total_lessons = array_sum(array_map(static fn($m) => count($m['lessons'] ?? []), $outline));

        ob_start();
        self::print_styles();
        ?>
        <div class="slc-outline" data-slc-outline>
            <div class="slc-outline__header">
                <h3 class="slc-outline__title"><?php esc_html_e('Contenido del curso', 'studiahub-lms-connector'); ?></h3>
                <?php if ($show_count): ?>
                    <div class="slc-outline__meta">
                        <span><?php echo esc_html(sprintf('%d módulos', count($outline))); ?></span>
                        <span class="slc-outline__meta-sep">·</span>
                        <span><?php echo esc_html(sprintf('%d lecciones', $total_lessons)); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="slc-outline__modules">
                <?php foreach ($outline as $index => $module): ?>
                    <?php
                    $is_open = $default_open && $index === 0;
                    $lesson_count = isset($module['lessons']) && is_array($module['lessons']) ? count($module['lessons']) : 0;
                    ?>
                    <details class="slc-outline__module"<?php if ($is_open) echo ' open'; ?>>
                        <summary class="slc-outline__summary">
                            <span class="slc-outline__chevron" aria-hidden="true"></span>
                            <span class="slc-outline__module-title"><?php echo esc_html($module['title'] ?? ''); ?></span>
                            <span class="slc-outline__badge"><?php echo esc_html($lesson_count . ' ' . ($lesson_count === 1 ? 'lección' : 'lecciones')); ?></span>
                        </summary>
                        <?php if ($lesson_count > 0): ?>
                            <ul class="slc-outline__lessons">
                                <?php foreach ($module['lessons'] as $lesson): ?>
                                    <li class="slc-outline__lesson">
                                        <span class="slc-outline__lesson-icon" aria-hidden="true"><?php echo self::lesson_icon($lesson['type'] ?? null); ?></span>
                                        <span class="slc-outline__lesson-title"><?php echo esc_html($lesson['title'] ?? ''); ?></span>
                                        <?php if (!empty($lesson['free'])): ?>
                                            <span class="slc-outline__pill slc-outline__pill--free">Preview</span>
                                        <?php endif; ?>
                                        <?php if (!empty($lesson['durationMin'])): ?>
                                            <span class="slc-outline__duration"><?php echo esc_html(self::format_duration((int) $lesson['durationMin'])); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
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

    private static function get_outline(int $product_id): array {
        if (!function_exists('get_field')) {
            return [];
        }
        $raw = get_field('sh_course_outline', $product_id);
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function lesson_icon(?string $type): string {
        // SVG inline livianos — sin dependencias externas.
        $icons = [
            'VIDEO' => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="6,4 12,8 6,12" fill="currentColor"/></svg>',
            'TEXT'  => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="3" y1="5" x2="13" y2="5"/><line x1="3" y1="8" x2="13" y2="8"/><line x1="3" y1="11" x2="10" y2="11"/></svg>',
            'PDF'   => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 2h6l3 3v9H4z"/><path d="M10 2v3h3"/></svg>',
        ];
        return $icons[$type ?? ''] ?? $icons['VIDEO'];
    }

    private static function format_duration(int $minutes): string {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m === 0 ? $h . ' h' : $h . ' h ' . $m . ' min';
    }

    private static function print_styles(): void {
        if (self::$assets_printed) {
            return;
        }
        self::$assets_printed = true;
        ?>
        <style>
            .slc-outline { font-family: inherit; color: #1A1B1E; max-width: 100%; }
            .slc-outline *, .slc-outline *::before, .slc-outline *::after { box-sizing: border-box; }
            .slc-outline__header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
            .slc-outline__title { font-size: 20px; font-weight: 700; margin: 0; color: #1A1B1E; }
            .slc-outline__meta { display: flex; align-items: center; gap: 6px; color: #6C757D; font-size: 13px; }
            .slc-outline__meta-sep { opacity: 0.6; }
            .slc-outline__modules { display: flex; flex-direction: column; gap: 8px; }
            .slc-outline__module { background: #fff; border: 1px solid #E9ECEF; border-radius: 12px; overflow: hidden; transition: border-color 0.15s; }
            .slc-outline__module[open] { border-color: #CED4DA; }
            .slc-outline__summary { list-style: none; display: flex; align-items: center; gap: 12px; padding: 14px 18px; cursor: pointer; user-select: none; font-weight: 500; }
            .slc-outline__summary::-webkit-details-marker { display: none; }
            .slc-outline__chevron { width: 10px; height: 10px; border-right: 2px solid #868E96; border-bottom: 2px solid #868E96; transform: rotate(-45deg); transition: transform 0.2s; flex-shrink: 0; margin-top: -2px; }
            .slc-outline__module[open] .slc-outline__chevron { transform: rotate(45deg); margin-top: 0; margin-bottom: -2px; }
            .slc-outline__module-title { flex: 1; font-size: 15px; color: #1A1B1E; }
            .slc-outline__badge { background: #F1F3F5; color: #495057; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 10px; }
            .slc-outline__lessons { list-style: none; margin: 0; padding: 0 0 8px; border-top: 1px solid #F1F3F5; }
            .slc-outline__lesson { display: flex; align-items: center; gap: 10px; padding: 10px 18px 10px 44px; font-size: 14px; color: #495057; }
            .slc-outline__lesson + .slc-outline__lesson { border-top: 1px solid #F8F9FA; }
            .slc-outline__lesson-icon { display: inline-flex; color: #868E96; flex-shrink: 0; }
            .slc-outline__lesson-title { flex: 1; }
            .slc-outline__pill { font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.3px; }
            .slc-outline__pill--free { background: #EBFBEE; color: #2F9E44; }
            .slc-outline__duration { font-size: 12px; color: #868E96; font-variant-numeric: tabular-nums; }
            @media (max-width: 520px) {
                .slc-outline__summary { padding: 12px 14px; gap: 10px; }
                .slc-outline__lesson { padding: 10px 14px 10px 34px; font-size: 13px; gap: 8px; }
                .slc-outline__module-title { font-size: 14px; }
            }
        </style>
        <?php
    }
}
