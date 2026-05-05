<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_badge] — renderiza el highlight badge del curso
 * (ej: "Bestseller", "Nuevo", "Actualizado 2026") como un pill llamativo.
 * Si el ACF sh_course_highlight_badge está vacío, no renderiza nada.
 *
 * Atributos:
 *   id       ID del producto WC (default: actual)
 *   color    blue (default) | green | orange | red | purple | dark
 *   text     Override del texto (default: ACF sh_course_highlight_badge)
 */
final class Shortcode_Badge {
    public const SHORTCODE_TAG = 'studiahub_course_badge';
    private static bool $assets_printed = false;

    private const COLORS = [
        'blue'   => ['bg' => '#E7F5FF', 'fg' => '#1971C2'],
        'green'  => ['bg' => '#EBFBEE', 'fg' => '#2F9E44'],
        'orange' => ['bg' => '#FFF4E6', 'fg' => '#E8590C'],
        'red'    => ['bg' => '#FFE3E3', 'fg' => '#C92A2A'],
        'purple' => ['bg' => '#F3E8FF', 'fg' => '#7048E8'],
        'dark'   => ['bg' => '#1A1B1E', 'fg' => '#FFFFFF'],
    ];

    public static function register_hooks(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render']);
    }

    public static function render($atts): string {
        $atts = shortcode_atts([
            'id'    => '',
            'color' => 'blue',
            'text'  => '',
        ], $atts, self::SHORTCODE_TAG);

        $product_id = self::resolve_product_id($atts['id']);
        if (!$product_id || !function_exists('get_field')) {
            return '';
        }

        $text = trim((string) $atts['text']);
        if ($text === '') {
            $text = trim((string) get_field('sh_course_highlight_badge', $product_id));
        }
        if ($text === '') {
            return '';
        }

        $color = isset(self::COLORS[$atts['color']]) ? $atts['color'] : 'blue';
        $palette = self::COLORS[$color];

        ob_start();
        self::print_styles();
        ?>
        <span class="slc-badge" style="background:<?php echo esc_attr($palette['bg']); ?>;color:<?php echo esc_attr($palette['fg']); ?>;">
            <?php echo esc_html($text); ?>
        </span>
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

    private static function print_styles(): void {
        if (self::$assets_printed) {
            return;
        }
        self::$assets_printed = true;
        ?>
        <style>
            .slc-badge { display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.4; }
        </style>
        <?php
    }
}
