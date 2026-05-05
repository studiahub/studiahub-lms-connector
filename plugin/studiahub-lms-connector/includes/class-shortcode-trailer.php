<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_trailer] — embebe el video promocional del
 * curso (YouTube / Vimeo / cualquier iframe URL). Lee el ACF
 * sh_course_trailer_url. Si no hay URL, no renderiza nada.
 *
 * Atributos:
 *   id              ID del producto WC (default: actual)
 *   url             Override de la URL del video (default: ACF)
 *   ratio           16:9 (default) | 4:3 | 1:1
 *   max_width       Ancho máximo en px (default: 100%)
 *   rounded         1 (default) | 0
 */
final class Shortcode_Trailer {
    public const SHORTCODE_TAG = 'studiahub_course_trailer';
    private static bool $assets_printed = false;

    public static function register_hooks(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render']);
    }

    public static function render($atts): string {
        $atts = shortcode_atts([
            'id'        => '',
            'url'       => '',
            'ratio'     => '16:9',
            'max_width' => '',
            'rounded'   => '1',
        ], $atts, self::SHORTCODE_TAG);

        $product_id = self::resolve_product_id($atts['id']);
        if (!$product_id) {
            return '';
        }

        $url = trim((string) $atts['url']);
        if ($url === '' && function_exists('get_field')) {
            $url = trim((string) get_field('sh_course_trailer_url', $product_id));
        }
        if ($url === '') {
            return '';
        }

        $embed = self::to_embed_url($url);
        if ($embed === null) {
            return '';
        }

        $padding = self::ratio_to_padding($atts['ratio']);
        $rounded = $atts['rounded'] === '1';
        $max_width = trim((string) $atts['max_width']);
        $wrapper_style = $max_width !== '' ? 'max-width:' . esc_attr($max_width) . ';' : '';

        ob_start();
        self::print_styles();
        ?>
        <div class="slc-trailer <?php echo $rounded ? 'slc-trailer--rounded' : ''; ?>" style="<?php echo $wrapper_style; ?>">
            <div class="slc-trailer__frame" style="padding-bottom: <?php echo esc_attr($padding); ?>;">
                <iframe
                    src="<?php echo esc_url($embed); ?>"
                    title="Trailer del curso"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                    allowfullscreen
                    loading="lazy"
                ></iframe>
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

    /**
     * Convierte una URL de YouTube/Vimeo en su URL de embed. Si ya es una URL
     * de embed la devuelve tal cual. Si no es reconocida, devuelve null.
     */
    private static function to_embed_url(string $url): ?string {
        $url = trim($url);

        // YouTube: https://youtu.be/{id} o https://youtube.com/watch?v={id} o https://youtube.com/shorts/{id}
        if (preg_match('~(?:youtube\.com/watch\?(?:.*&)?v=|youtu\.be/|youtube\.com/shorts/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1] . '?rel=0';
        }

        // Vimeo: https://vimeo.com/{id} o https://player.vimeo.com/video/{id}
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        // URL ya en formato embed o iframe URL custom (Bunny.net, Wistia, etc.)
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        return null;
    }

    private static function ratio_to_padding(string $ratio): string {
        $map = [
            '16:9' => '56.25%',
            '4:3'  => '75%',
            '1:1'  => '100%',
            '21:9' => '42.86%',
        ];
        return $map[$ratio] ?? '56.25%';
    }

    private static function print_styles(): void {
        if (self::$assets_printed) {
            return;
        }
        self::$assets_printed = true;
        ?>
        <style>
            .slc-trailer { width: 100%; }
            .slc-trailer__frame { position: relative; height: 0; overflow: hidden; background: #000; }
            .slc-trailer--rounded .slc-trailer__frame { border-radius: 12px; }
            .slc-trailer__frame iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
        </style>
        <?php
    }
}
