<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_cta] — CTA autónomo para landings a medida.
 *
 * Consume el mismo landing-payload y la misma definición de cierre que la
 * landing oficial. Así una página armada por piezas no tiene que reimplementar
 * en el theme cuándo vender, cuándo mostrar la lista de espera ni qué estados
 * bloquean el checkout.
 *
 * Usage:
 *   [studiahub_course_cta]
 *   [studiahub_course_cta id="42"]
 */
final class Shortcode_CourseCTA {
    public const SHORTCODE_TAG = 'studiahub_course_cta';

    private const DEFAULT_WAITLIST_INTRO = 'Dejanos tus datos y te avisamos cuando haya novedades sobre este curso.';

    private static int $instance = 0;

    public static function register_hooks(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render']);
    }

    public static function render($atts): string {
        $atts = shortcode_atts(['id' => ''], $atts, self::SHORTCODE_TAG);

        $product_id = self::resolve_product_id($atts['id']);
        if ($product_id <= 0) {
            return '';
        }

        $course_id = trim((string) get_post_meta($product_id, '_lms_course_id', true));
        if ($course_id === '') {
            return '<!-- studiahub_course_cta: producto sin _lms_course_id -->';
        }

        $payload = Landing_Fetch::get_payload($course_id);
        if (!is_array($payload)) {
            return '<!-- studiahub_course_cta: LMS no respondió y no hay cache -->';
        }

        $closed = Purchase_Gate::closed_state_from_payload($payload);
        $waitlist = self::waitlist_config($payload);
        $cta_label = trim((string) ($payload['ctaLabel'] ?? ''));
        if ($cta_label === '') {
            $cta_label = __('Quiero inscribirme', 'studiahub-lms-connector');
        }

        $branding = is_array($payload['branding'] ?? null) ? $payload['branding'] : [];
        $brand_style = Shortcode_CoursePage::build_brand_style($branding);
        Shortcode_CoursePage::maybe_enqueue_google_font_public((string) ($branding['fontFamily'] ?? 'default'));
        wp_enqueue_style(Shortcode_CoursePitch::STYLE_HANDLE);

        if ($waitlist['enabled']) {
            if ($waitlist['turnstileSiteKey'] !== '') {
                wp_enqueue_script(Shortcode_CoursePitch::TURNSTILE_SCRIPT_HANDLE);
            }
            wp_enqueue_script(Shortcode_CoursePitch::WAITLIST_SCRIPT_HANDLE);
        }

        $instance_id = $product_id . '-' . ++self::$instance;

        ob_start();
        ?>
        <div class="slc-coursecta slc-coursepitch"<?php if ($brand_style !== '') echo ' style="' . esc_attr($brand_style) . '"'; ?>>
            <?php if ($waitlist['enabled']): ?>
            <button type="button" class="slc-cpitch__btn slc-cpitch__btn--block slc-cpitch__waitlist-open" data-slc-waitlist-open>
                <?php esc_html_e('Anotarme a la lista de espera', 'studiahub-lms-connector'); ?> →
            </button>
            <?php elseif ($closed !== null): ?>
            <span class="slc-cpitch__btn slc-cpitch__btn--block slc-cpitch__pricing-cta--closed" aria-disabled="true">
                <?php echo esc_html($closed['label']); ?>
            </span>
            <?php else: ?>
            <a class="slc-cpitch__btn slc-cpitch__btn--block" href="<?php echo esc_url(Enroll::url($product_id)); ?>">
                <?php echo esc_html($cta_label); ?> →
            </a>
            <?php endif; ?>

            <?php if ($waitlist['enabled']): ?>
            <div class="slc-cpitch__waitlist-modal"
                 data-slc-waitlist-modal
                 data-endpoint="<?php echo esc_url(rest_url('studiahub/v1/course-waitlist/' . $product_id)); ?>"
                 data-consent-version="<?php echo esc_attr($waitlist['consentVersion']); ?>"
                 <?php if ($waitlist['turnstileSiteKey'] !== ''): ?>data-turnstile-site-key="<?php echo esc_attr($waitlist['turnstileSiteKey']); ?>"<?php endif; ?>
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="slc-coursecta-waitlist-title-<?php echo esc_attr($instance_id); ?>"
                 hidden>
                <div class="slc-cpitch__waitlist-panel" data-slc-waitlist-panel>
                    <button type="button" class="slc-cpitch__waitlist-close" data-slc-waitlist-close aria-label="<?php esc_attr_e('Cerrar formulario', 'studiahub-lms-connector'); ?>">&times;</button>
                    <h2 id="slc-coursecta-waitlist-title-<?php echo esc_attr($instance_id); ?>" class="slc-cpitch__waitlist-title"><?php esc_html_e('Lista de espera', 'studiahub-lms-connector'); ?></h2>
                    <p class="slc-cpitch__waitlist-intro"><?php echo esc_html($waitlist['formIntroText']); ?></p>
                    <form class="slc-cpitch__waitlist-form" data-slc-waitlist-form novalidate>
                        <div class="slc-cpitch__waitlist-fields" data-slc-waitlist-fields>
                            <label for="slc-coursecta-waitlist-name-<?php echo esc_attr($instance_id); ?>"><?php esc_html_e('Nombre completo', 'studiahub-lms-connector'); ?></label>
                            <input id="slc-coursecta-waitlist-name-<?php echo esc_attr($instance_id); ?>" name="fullName" type="text" autocomplete="name" maxlength="120" required>

                            <label for="slc-coursecta-waitlist-email-<?php echo esc_attr($instance_id); ?>"><?php esc_html_e('Email', 'studiahub-lms-connector'); ?></label>
                            <input id="slc-coursecta-waitlist-email-<?php echo esc_attr($instance_id); ?>" name="email" type="email" autocomplete="email" maxlength="254" required>

                            <div class="slc-cpitch__waitlist-honeypot" aria-hidden="true">
                                <label for="slc-coursecta-waitlist-website-<?php echo esc_attr($instance_id); ?>">Website</label>
                                <input id="slc-coursecta-waitlist-website-<?php echo esc_attr($instance_id); ?>" name="website" type="text" autocomplete="off" tabindex="-1">
                            </div>

                            <label class="slc-cpitch__waitlist-consent">
                                <input name="consent" type="checkbox" required>
                                <span><?php echo esc_html($waitlist['consentText']); ?></span>
                            </label>

                            <?php if ($waitlist['turnstileSiteKey'] !== ''): ?>
                            <div class="slc-cpitch__waitlist-turnstile" data-slc-waitlist-turnstile></div>
                            <?php endif; ?>

                            <button type="submit" class="slc-cpitch__btn slc-cpitch__waitlist-submit" data-slc-waitlist-submit>
                                <?php esc_html_e('Anotarme', 'studiahub-lms-connector'); ?>
                            </button>
                        </div>
                        <p class="slc-cpitch__waitlist-status" data-slc-waitlist-status role="status" aria-live="polite" tabindex="-1"></p>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return Render_Guard::protect((string) ob_get_clean());
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
     * @return array{enabled:bool,formIntroText:string,consentText:string,consentVersion:string,turnstileSiteKey:string}
     */
    private static function waitlist_config(array $payload): array {
        $waitlist = is_array($payload['waitlist'] ?? null) ? $payload['waitlist'] : [];
        $intro = is_string($waitlist['formIntroText'] ?? null)
            ? trim($waitlist['formIntroText'])
            : '';
        $consent = is_string($waitlist['consentText'] ?? null)
            ? trim($waitlist['consentText'])
            : '';
        $version = is_string($waitlist['consentVersion'] ?? null)
            ? trim($waitlist['consentVersion'])
            : '';
        $turnstile = is_string($waitlist['turnstileSiteKey'] ?? null)
            ? trim($waitlist['turnstileSiteKey'])
            : '';

        return [
            'enabled' => ($waitlist['enabled'] ?? false) === true && $consent !== '' && $version !== '',
            'formIntroText' => $intro !== '' ? $intro : self::DEFAULT_WAITLIST_INTRO,
            'consentText' => $consent,
            'consentVersion' => $version,
            'turnstileSiteKey' => $turnstile,
        ];
    }
}
