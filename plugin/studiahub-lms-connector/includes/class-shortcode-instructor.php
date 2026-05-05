<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_instructor] — renderiza una card con la foto,
 * el nombre, el cargo y la bio del instructor del curso.
 *
 * Lee los ACFs sh_course_instructor / instructor_title / instructor_bio /
 * instructor_photo_url. Si no hay nombre, no renderiza nada.
 */
final class Shortcode_Instructor {
    public const SHORTCODE_TAG = 'studiahub_course_instructor';
    private static bool $assets_printed = false;

    public static function register_hooks(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render']);
    }

    public static function render($atts): string {
        $atts = shortcode_atts([
            'id'       => '',
            'title'    => '',
            'layout'   => 'horizontal',  // horizontal | vertical
        ], $atts, self::SHORTCODE_TAG);

        $product_id = self::resolve_product_id($atts['id']);
        if (!$product_id || !function_exists('get_field')) {
            return '';
        }

        $name      = trim((string) get_field('sh_course_instructor', $product_id));
        $job_title = trim((string) get_field('sh_course_instructor_title', $product_id));
        $bio       = trim((string) get_field('sh_course_instructor_bio', $product_id));
        $photo_url = trim((string) get_field('sh_course_instructor_photo_url', $product_id));

        if ($name === '') {
            return '';
        }

        $section_title = trim((string) $atts['title']);
        $vertical = $atts['layout'] === 'vertical';
        $initial = mb_substr($name, 0, 1);

        ob_start();
        self::print_styles();
        ?>
        <div class="slc-instructor <?php echo $vertical ? 'slc-instructor--vertical' : ''; ?>">
            <?php if ($section_title !== ''): ?>
                <h3 class="slc-instructor__section-title"><?php echo esc_html($section_title); ?></h3>
            <?php endif; ?>
            <div class="slc-instructor__card">
                <div class="slc-instructor__photo">
                    <?php if ($photo_url !== ''): ?>
                        <img src="<?php echo esc_url($photo_url); ?>" alt="<?php echo esc_attr($name); ?>" loading="lazy">
                    <?php else: ?>
                        <span class="slc-instructor__initial"><?php echo esc_html($initial); ?></span>
                    <?php endif; ?>
                </div>
                <div class="slc-instructor__body">
                    <div class="slc-instructor__name"><?php echo esc_html($name); ?></div>
                    <?php if ($job_title !== ''): ?>
                        <div class="slc-instructor__role"><?php echo esc_html($job_title); ?></div>
                    <?php endif; ?>
                    <?php if ($bio !== ''): ?>
                        <div class="slc-instructor__bio"><?php echo nl2br(esc_html($bio)); ?></div>
                    <?php endif; ?>
                </div>
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

    private static function print_styles(): void {
        if (self::$assets_printed) {
            return;
        }
        self::$assets_printed = true;
        ?>
        <style>
            .slc-instructor { font-family: inherit; color: #1A1B1E; }
            .slc-instructor *, .slc-instructor *::before, .slc-instructor *::after { box-sizing: border-box; }
            .slc-instructor__section-title { font-size: 20px; font-weight: 700; margin: 0 0 16px; color: #1A1B1E; }
            .slc-instructor__card { display: flex; gap: 20px; align-items: flex-start; background: #fff; border: 1px solid #E9ECEF; border-radius: 16px; padding: 20px; }
            .slc-instructor--vertical .slc-instructor__card { flex-direction: column; align-items: center; text-align: center; gap: 16px; }
            .slc-instructor__photo { flex-shrink: 0; width: 96px; height: 96px; border-radius: 50%; overflow: hidden; background: linear-gradient(135deg, #E7F5FF, #D0EBFF); display: flex; align-items: center; justify-content: center; }
            .slc-instructor__photo img { width: 100%; height: 100%; object-fit: cover; }
            .slc-instructor__initial { font-size: 36px; font-weight: 700; color: #1971C2; }
            .slc-instructor__body { flex: 1; min-width: 0; }
            .slc-instructor__name { font-size: 18px; font-weight: 700; color: #1A1B1E; margin-bottom: 2px; }
            .slc-instructor__role { font-size: 14px; color: #1971C2; margin-bottom: 10px; font-weight: 500; }
            .slc-instructor__bio { font-size: 14px; color: #495057; line-height: 1.6; }
            @media (max-width: 600px) {
                .slc-instructor__card { flex-direction: column; align-items: center; text-align: center; gap: 14px; padding: 18px; }
                .slc-instructor__photo { width: 80px; height: 80px; }
                .slc-instructor__initial { font-size: 30px; }
            }
        </style>
        <?php
    }
}
