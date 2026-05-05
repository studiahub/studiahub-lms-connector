<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_list] — renderiza una lista de bullets
 * (learning outcomes / audience / materials / requirements) sincronizada
 * desde el LMS.
 *
 * Usage en Elementor o editor WP:
 *   [studiahub_course_list field="learning"]
 *     → muestra la lista "Lo que vas a aprender"
 *
 *   [studiahub_course_list field="audience" id="42"]
 *     → fuerza producto WC con ID 42
 *
 *   [studiahub_course_list field="materials" icon="dot" columns="2"]
 *     → ícono de bullet point en lugar de check, 2 columnas
 *
 * Atributos:
 *   field    learning | audience | materials | requirements (requerido)
 *   id       ID del producto WC (default: producto actual)
 *   icon     check (default) | dot | star | arrow
 *   columns  1 (default) | 2
 *   title    Título opcional sobre la lista
 */
final class Shortcode_List {
    public const SHORTCODE_TAG = 'studiahub_course_list';
    private static bool $assets_printed = false;

    private const FIELD_MAP = [
        'learning'     => 'sh_course_learning_outcomes',
        'audience'     => 'sh_course_target_audience',
        'materials'    => 'sh_course_included_materials',
        'requirements' => 'sh_course_requirements',
    ];

    public static function register_hooks(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render']);
    }

    public static function render($atts): string {
        $atts = shortcode_atts([
            'field'   => '',
            'id'      => '',
            'icon'    => 'check',
            'columns' => '1',
            'title'   => '',
        ], $atts, self::SHORTCODE_TAG);

        $field_key = strtolower(trim($atts['field']));
        if (!isset(self::FIELD_MAP[$field_key])) {
            return '';
        }
        $acf_field = self::FIELD_MAP[$field_key];

        $product_id = self::resolve_product_id($atts['id']);
        if (!$product_id) {
            return '';
        }

        $items = self::get_items($product_id, $acf_field);
        if (empty($items)) {
            return '';
        }

        $columns = $atts['columns'] === '2' ? 2 : 1;
        $icon    = self::lookup_icon($atts['icon']);
        $title   = trim((string) $atts['title']);

        ob_start();
        self::print_styles();
        ?>
        <div class="slc-list slc-list--cols-<?php echo (int) $columns; ?>">
            <?php if ($title !== ''): ?>
                <h3 class="slc-list__title"><?php echo esc_html($title); ?></h3>
            <?php endif; ?>
            <ul class="slc-list__items">
                <?php foreach ($items as $item): ?>
                    <li class="slc-list__item">
                        <span class="slc-list__icon" aria-hidden="true"><?php echo $icon; ?></span>
                        <span class="slc-list__text"><?php echo esc_html($item); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
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

    private static function get_items(int $product_id, string $acf_field): array {
        if (!function_exists('get_field')) {
            return [];
        }
        $raw = get_field($acf_field, $product_id);
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, static fn($s) => is_string($s) && trim($s) !== ''));
    }

    private static function lookup_icon(string $name): string {
        $icons = [
            'check' => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3,8.5 6.5,12 13,4.5"/></svg>',
            'dot'   => '<svg viewBox="0 0 16 16" width="10" height="10"><circle cx="8" cy="8" r="4" fill="currentColor"/></svg>',
            'star'  => '<svg viewBox="0 0 16 16" width="14" height="14" fill="currentColor"><polygon points="8,1 10.2,5.8 15.5,6.4 11.5,10 12.6,15.2 8,12.6 3.4,15.2 4.5,10 0.5,6.4 5.8,5.8"/></svg>',
            'arrow' => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="5,3 11,8 5,13"/></svg>',
        ];
        return $icons[strtolower($name)] ?? $icons['check'];
    }

    private static function print_styles(): void {
        if (self::$assets_printed) {
            return;
        }
        self::$assets_printed = true;
        ?>
        <style>
            .slc-list { font-family: inherit; color: #1A1B1E; }
            .slc-list *, .slc-list *::before, .slc-list *::after { box-sizing: border-box; }
            .slc-list__title { font-size: 20px; font-weight: 700; margin: 0 0 16px; color: #1A1B1E; }
            .slc-list__items { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
            .slc-list--cols-2 .slc-list__items { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 24px; }
            .slc-list__item { display: flex; gap: 12px; align-items: flex-start; line-height: 1.5; }
            .slc-list__icon { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background: #EBFBEE; color: #2F9E44; margin-top: 1px; }
            .slc-list__text { flex: 1; color: #495057; font-size: 15px; }
            @media (max-width: 600px) {
                .slc-list--cols-2 .slc-list__items { grid-template-columns: 1fr; }
            }
        </style>
        <?php
    }
}
