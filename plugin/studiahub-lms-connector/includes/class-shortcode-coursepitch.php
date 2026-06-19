<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_pitch] — variante "pitch" de la landing del
 * curso. Comparte la MISMA data que [studiahub_course_page] (mismo payload
 * del LMS, mismo branding dinámico), pero con un layout orientado a
 * conversion / DTC style: hero gigante, CTA sticky, secondary color como
 * acentos, bloques en orden de funnel (hook → outcome → social proof →
 * outline → bonos → garantía → FAQ → CTA final), garantía como sello.
 *
 * Usage:
 *   [studiahub_course_pitch]
 *   [studiahub_course_pitch id="42"]
 *
 * Reusa los helpers de branding / pricing / social proof de
 * Shortcode_CoursePage (build_brand_style, parse_trailer, etc) — no
 * duplicamos lógica, solo el HTML/CSS del renderer.
 */
final class Shortcode_CoursePitch {
    public const SHORTCODE_TAG = 'studiahub_course_pitch';
    public const STYLE_HANDLE  = 'slc-coursepitch';

    private const TYPE_LABELS = [
        'on_demand' => 'On demand',
        'live'      => 'En vivo',
        'in_person' => 'Presencial',
        'hybrid'    => 'Híbrido',
    ];

    public static function register_hooks(): void {
        add_shortcode(self::SHORTCODE_TAG, [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'register_styles']);
    }

    public static function register_styles(): void {
        if (!defined('SLC_VERSION')) return;
        wp_register_style(
            'flaticon-uicons-thin-rounded',
            'https://cdn-uicons.flaticon.com/2.6.0/uicons-thin-rounded/css/uicons-thin-rounded.css',
            [],
            '2.6.0'
        );
        wp_register_style(
            'slc-playfair-quote',
            'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&text=%E2%80%9C&display=swap',
            [],
            null
        );
        wp_register_style(
            self::STYLE_HANDLE,
            SLC_PLUGIN_URL . 'assets/css/coursepitch.css',
            ['flaticon-uicons-thin-rounded', 'slc-playfair-quote'],
            SLC_VERSION
        );

        // Anti-FOUC: encolamos en el <head> si la página ya trae el shortcode
        // (el enqueue de render() corre tarde y WP lo manda al footer → flash).
        // Arrastra las deps (iconos + font). render() queda como fallback.
        if (self::current_page_has_shortcode()) {
            wp_enqueue_style(self::STYLE_HANDLE);
        }
    }

    /**
     * ¿La página actual contiene el shortcode? Cubre contenido clásico/Gutenberg
     * y Elementor (postmeta `_elementor_data`).
     */
    private static function current_page_has_shortcode(): bool {
        if (!is_singular()) {
            return false;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return false;
        }
        if (has_shortcode((string) $post->post_content, self::SHORTCODE_TAG)) {
            return true;
        }
        $elementor = get_post_meta($post->ID, '_elementor_data', true);
        return is_string($elementor) && $elementor !== '' && strpos($elementor, self::SHORTCODE_TAG) !== false;
    }

    public static function render($atts): string {
        $atts = shortcode_atts(['id' => ''], $atts, self::SHORTCODE_TAG);

        $product_id = self::resolve_product_id($atts['id']);
        if (!$product_id) return '';

        $course_id = (string) get_post_meta($product_id, '_lms_course_id', true);
        if ($course_id === '') {
            return '<!-- studiahub_course_pitch: producto sin _lms_course_id -->';
        }

        $payload = Landing_Fetch::get_payload($course_id);
        if (!is_array($payload)) {
            return '<!-- studiahub_course_pitch: LMS no respondió y no hay cache -->';
        }

        // ── Data del curso ───────────────────────────────────────────────
        $title       = (string) ($payload['title'] ?? get_the_title($product_id));
        $subtitle    = trim((string) ($payload['subtitle'] ?? ''));
        $short_desc  = trim((string) ($payload['shortDescription'] ?? ''));
        $long_desc   = trim((string) ($payload['longDescription'] ?? ''));
        $type_key    = (string) ($payload['courseType'] ?? '');
        $hours       = (int) ($payload['durationHours'] ?? 0);
        $level       = trim((string) ($payload['level'] ?? ''));
        $language    = trim((string) ($payload['language'] ?? ''));
        $has_cert    = (bool) ($payload['hasCertificate'] ?? false);
        $badge       = trim((string) ($payload['highlightBadge'] ?? ''));
        $category    = trim((string) ($payload['category'] ?? ''));
        $price_disp  = trim((string) ($payload['priceDisplay'] ?? ''));
        $cta_label   = trim((string) ($payload['ctaLabel'] ?? ''));
        $trailer_url = trim((string) ($payload['trailerUrl'] ?? ''));
        $thumbnail_url = trim((string) ($payload['thumbnailUrl'] ?? ''));
        $tenant_name = trim((string) ($payload['tenantName'] ?? ''));
        $live_count   = (int) ($payload['liveSessionsCount'] ?? 0);
        // Barra superior: fecha de inicio del curso (countdown a la apertura).
        $start_at     = trim((string) ($payload['courseStartAt'] ?? $payload['liveSessionAt'] ?? ''));
        $start_label  = trim((string) ($payload['courseStartLabel'] ?? ''));
        if ($start_label === '') {
            $start_label = __('El curso comienza en', 'studiahub-lms-connector');
        }
        $start_ts = $start_at !== '' ? strtotime($start_at) : 0;
        $start_date_fmt = '';
        if ($start_ts) {
            $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                      'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            $start_date_fmt = (int) date('j', $start_ts) . ' de ' . $meses[(int) date('n', $start_ts)];
        }

        $instructors_raw = is_array($payload['instructors'] ?? null) ? $payload['instructors'] : [];
        $instructors = [];
        foreach ($instructors_raw as $i) {
            if (!is_array($i)) continue;
            $name = trim((string) ($i['name'] ?? ''));
            if ($name === '') continue;
            $instructors[] = [
                'name'  => $name,
                'title' => trim((string) ($i['title'] ?? '')),
                'bio'   => trim((string) ($i['bio'] ?? '')),
                'photo' => trim((string) ($i['photoUrl'] ?? '')),
            ];
        }

        $outcomes  = is_array($payload['learningOutcomes'] ?? null) ? $payload['learningOutcomes'] : [];
        $audience  = is_array($payload['targetAudience'] ?? null) ? $payload['targetAudience'] : [];
        $materials = is_array($payload['includedMaterials'] ?? null) ? $payload['includedMaterials'] : [];
        $reqs      = is_array($payload['requirements'] ?? null) ? $payload['requirements'] : [];
        $reqs_img  = trim((string) ($payload['requirementsImageUrl'] ?? $payload['thumbnailUrl'] ?? ''));

        $modules_count = (int) ($payload['modulesCount'] ?? 0);
        $lessons_count = (int) ($payload['lessonsCount'] ?? 0);
        $total_min     = (int) ($payload['totalDurationMin'] ?? 0);
        $outline       = is_array($payload['outline'] ?? null) ? $payload['outline'] : [];

        $reviews         = is_array($payload['reviews'] ?? null) ? $payload['reviews'] : [];
        $review_stats    = is_array($payload['reviewStats'] ?? null) ? $payload['reviewStats'] : [];
        $payment_methods = is_array($payload['paymentMethods'] ?? null) ? $payload['paymentMethods'] : [];

        if ($cta_label === '') {
            $cta_label = __('Quiero inscribirme', 'studiahub-lms-connector');
        }
        if ($price_disp === '') {
            $price_disp = self::wc_price_fallback($product_id);
        }

        $checkout_url = self::checkout_url($product_id);

        // Reusamos los helpers de la clase v1 para no duplicar lógica de
        // pricing / social proof / etc.
        $social    = Shortcode_CoursePage::data_social_proof_public($payload);
        $offer     = Shortcode_CoursePage::data_offer_pricing_public($payload, $price_disp);
        // Countdown de oferta (offerDeadlineAt solo viaja si la oferta está
        // vigente) + cierre de inscripciones (salesClosed → botón deshabilitado).
        $offer_deadline_iso   = trim((string) ($payload['offerDeadlineAt'] ?? ''));
        $offer_deadline_label = '';
        $offer_imminent       = false; // a < 48hs pasamos a countdown vivo (JS)
        if ($offer_deadline_iso !== '') {
            $odl_ts = strtotime($offer_deadline_iso);
            $now_ts = function_exists('current_time') ? (int) current_time('timestamp') : time();
            if ($odl_ts && $odl_ts > $now_ts) {
                $remaining = $odl_ts - $now_ts;
                $offer_imminent = $remaining < 48 * 3600;
                $offer_deadline_label = self::format_relative_es($remaining);
            }
        }
        $sales_closed = !empty($payload['salesClosed']);
        $bonuses   = Shortcode_CoursePage::data_bonuses_public($payload);
        $guarantee = Shortcode_CoursePage::data_guarantee_public($payload);
        $faq       = Shortcode_CoursePage::data_faq_public($payload);
        $trailer   = $trailer_url !== '' ? Shortcode_CoursePage::parse_trailer_public($trailer_url) : null;

        // Branding inline + Google Font.
        $branding    = is_array($payload['branding'] ?? null) ? $payload['branding'] : [];
        $brand_style = Shortcode_CoursePage::build_brand_style($branding);
        Shortcode_CoursePage::maybe_enqueue_google_font_public($branding['fontFamily'] ?? 'default');

        wp_enqueue_style(self::STYLE_HANDLE);

        ob_start();
        ?>
        <article class="slc-coursepitch" itemscope itemtype="https://schema.org/Course"<?php if ($brand_style !== '') echo ' style="' . esc_attr($brand_style) . '"'; ?>>

            <?php /* ── TOP BAR — countdown al inicio del curso ────────────── */ ?>
            <?php if ($start_at !== ''): ?>
            <div class="slc-cpitch__topbar" data-slc-countdown="<?php echo esc_attr($start_at); ?>" hidden>
                <div class="slc-cpitch__topbar-inner">
                    <span class="slc-cpitch__topbar-live"><span class="slc-cpitch__live-dot" aria-hidden="true"></span><?php esc_html_e('Inscripción abierta', 'studiahub-lms-connector'); ?></span>
                    <span class="slc-cpitch__topbar-center">
                        <span class="slc-cpitch__topbar-label"><?php echo esc_html($start_label); ?></span>
                        <span class="slc-cpitch__topbar-timer" data-slc-countdown-timer aria-live="off">
                            <span class="slc-cpitch__cd-unit"><b data-d>00</b><i>d</i></span>
                            <span class="slc-cpitch__cd-unit"><b data-h>00</b><i>h</i></span>
                            <span class="slc-cpitch__cd-unit"><b data-m>00</b><i>m</i></span>
                            <span class="slc-cpitch__cd-unit"><b data-s>00</b><i>s</i></span>
                        </span>
                    </span>
                    <a class="slc-cpitch__topbar-cta" href="#slc-pricing"><?php esc_html_e('Reservar mi lugar', 'studiahub-lms-connector'); ?> →</a>
                </div>
            </div>
            <?php endif; ?>

            <?php /* ── HERO — foto + floating cards, sin precio ──────────── */ ?>
            <?php
                // Primeros 3 materiales para las cajitas flotantes
                $float_mats = array_slice(array_filter($materials, fn($m) => is_array($m) ? ($m['text'] ?? '') !== '' : $m !== ''), 0, 3);
                $float_positions = ['slc-cpitch__hero-float--1', 'slc-cpitch__hero-float--2', 'slc-cpitch__hero-float--3'];
            ?>
            <section class="slc-cpitch__hero">
                <div class="slc-cpitch__hero-bg" aria-hidden="true"></div>
                <div class="slc-cpitch__wrap slc-cpitch__hero-grid">

                    <!-- Columna izquierda: texto + botones -->
                    <div class="slc-cpitch__hero-main">

                        <?php if ($social['rating'] !== null || $social['students_label'] !== ''): ?>
                        <div class="slc-cpitch__hero-proof">
                            <?php if ($social['rating'] !== null): ?>
                                <span class="slc-cpitch__stars" aria-hidden="true"><?php echo Shortcode_CoursePage::stars_public($social['rating']); ?></span>
                                <strong><?php echo esc_html(number_format((float)$social['rating'], 1, ',', '')); ?></strong>
                            <?php endif; ?>
                            <?php if ($start_date_fmt !== ''): ?>
                                <span class="slc-cpitch__proof-sep">·</span>
                                <span class="slc-cpitch__proof-date"><span class="slc-cpitch__proof-cal" aria-hidden="true"><?php echo self::icon('calendar'); ?></span><?php printf(esc_html__('Inicia %s', 'studiahub-lms-connector'), esc_html($start_date_fmt)); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <h1 class="slc-cpitch__hero-title"><?php echo esc_html($title); ?></h1>

                        <?php if ($subtitle !== '' || $short_desc !== ''): ?>
                            <p class="slc-cpitch__hero-sub"><?php echo esc_html($subtitle !== '' ? $subtitle : $short_desc); ?></p>
                        <?php endif; ?>

                        <div class="slc-cpitch__hero-ctas">
                            <a class="slc-cpitch__btn slc-cpitch__btn--lg" href="#slc-pricing">
                                <?php echo esc_html($cta_label ?: __('Quiero inscribirme', 'studiahub-lms-connector')); ?> →
                            </a>
                            <a class="slc-cpitch__btn slc-cpitch__btn--lg slc-cpitch__btn--outline" href="#slc-content-start">
                                <?php esc_html_e('¿Qué incluye?', 'studiahub-lms-connector'); ?> →
                            </a>
                        </div>

                        <ul class="slc-cpitch__hero-meta">
                            <?php foreach (self::meta_chips($type_key, $hours, $total_min, $level, $language, $has_cert, $modules_count, $lessons_count, $live_count) as $chip): ?>
                                <li class="slc-cpitch__meta-chip">
                                    <span class="slc-cpitch__meta-icon" aria-hidden="true"><?php echo $chip['icon']; ?></span>
                                    <?php echo esc_html($chip['label']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Columna derecha: foto + cajitas flotantes -->
                    <?php if ($thumbnail_url !== ''): ?>
                    <div class="slc-cpitch__hero-visual">
                        <div class="slc-cpitch__hero-photo-wrap">
                            <img class="slc-cpitch__hero-photo"
                                 src="<?php echo esc_url($thumbnail_url); ?>"
                                 alt="<?php echo esc_attr($title); ?>"
                                 loading="eager" />

                            <?php foreach (array_values($float_mats) as $fi => $mat):
                                $mat_text = is_array($mat) ? ($mat['text'] ?? '') : (string)$mat;
                                $mat_icon = is_array($mat) ? trim((string)($mat['icon'] ?? '')) : '';
                                if ($mat_text === '') continue;
                                $mat_text_short = mb_strlen($mat_text) > 38 ? mb_substr($mat_text, 0, 36) . '…' : $mat_text;
                            ?>
                            <div class="slc-cpitch__hero-float <?php echo esc_attr($float_positions[$fi] ?? ''); ?>" aria-hidden="true">
                                <?php if ($mat_icon !== ''): ?>
                                <span class="slc-cpitch__hero-float-icon">
                                    <i class="fi <?php echo esc_attr($mat_icon); ?>"></i>
                                </span>
                                <?php else: ?>
                                <span class="slc-cpitch__hero-float-icon">✓</span>
                                <?php endif; ?>
                                <span class="slc-cpitch__hero-float-text"><?php echo esc_html($mat_text_short); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </section>

            <?php /* ── POR QUÉ + OUTCOMES — sección unificada ───────────── */ ?>
            <?php if ($long_desc !== '' || !empty($outcomes)): ?>
            <section id="slc-content-start" class="slc-cpitch__section slc-cpitch__section--soft slc-cpitch__whyoutcomes">
                <div class="slc-cpitch__wrap">

                    <?php if ($long_desc !== ''): ?>
                    <div class="slc-cpitch__longdesc-grid<?php echo ($trailer === null && $thumbnail_url === '') ? ' slc-cpitch__longdesc-grid--no-media' : ''; ?>">
                        <div class="slc-cpitch__longdesc-left">
                            <div class="slc-cpitch__section-head">
                                <span class="slc-cpitch__eyebrow"><?php esc_html_e('La propuesta', 'studiahub-lms-connector'); ?></span>
                                <h2 class="slc-cpitch__h2"><?php esc_html_e('Por qué tomar este curso', 'studiahub-lms-connector'); ?></h2>
                            </div>
                            <?php if ($trailer !== null || $thumbnail_url !== ''): ?>
                            <div class="slc-cpitch__longdesc-img-wrap">
                                <?php if ($trailer !== null):
                                    $facade_thumb = $trailer['thumb'] !== '' ? $trailer['thumb'] : $thumbnail_url;
                                ?>
                                    <div class="slc-cpitch__trailer-facade slc-cpitch__longdesc-trailer"
                                         data-embed="<?php echo esc_attr($trailer['embed']); ?>"
                                         <?php if ($facade_thumb !== ''): ?>style="background-image:url('<?php echo esc_url($facade_thumb); ?>');"<?php endif; ?>
                                         role="button" tabindex="0"
                                         aria-label="<?php esc_attr_e('Reproducir trailer', 'studiahub-lms-connector'); ?>">
                                        <span class="slc-cpitch__play" aria-hidden="true">
                                            <svg viewBox="0 0 64 64" width="64" height="64"><circle cx="32" cy="32" r="32" fill="rgba(0,0,0,0.55)"/><polygon points="26,20 26,44 46,32" fill="#fff"/></svg>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <img class="slc-cpitch__longdesc-img" src="<?php echo esc_url($thumbnail_url); ?>" alt="" loading="lazy" />
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="slc-cpitch__prose"><?php echo wpautop(wp_kses_post($long_desc)); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($outcomes)): ?>
                    <div class="slc-cpitch__outcomes-block">
                        <h3 class="slc-cpitch__outcomes-h3"><?php esc_html_e('Al terminar este curso vas a poder…', 'studiahub-lms-connector'); ?></h3>
                        <div class="slc-cpitch__outcomes-grid">
                            <?php foreach ($outcomes as $item):
                                $o_title = is_array($item) ? trim((string)($item['title'] ?? '')) : '';
                                $o_desc  = is_array($item) ? trim((string)($item['desc']  ?? '')) : '';
                                $o_icon  = is_array($item) ? trim((string)($item['icon']  ?? '')) : '';
                                $o_text  = is_array($item) ? trim((string)($item['text']  ?? '')) : (string)$item;
                                if ($o_title === '' && $o_text !== '') { $o_desc = $o_text; }
                            ?>
                                <div class="slc-cpitch__outcome-card">
                                    <div class="slc-cpitch__outcome-icon">
                                        <?php echo self::svg_icon($o_icon !== '' ? $o_icon : 'fi-tr-star'); ?>
                                    </div>
                                    <?php if ($o_title !== ''): ?>
                                        <h4 class="slc-cpitch__outcome-title"><?php echo esc_html($o_title); ?></h4>
                                    <?php endif; ?>
                                    <?php if ($o_desc !== ''): ?>
                                        <p class="slc-cpitch__outcome-desc"><?php echo esc_html($o_desc); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </section>
            <?php endif; ?>

            <?php /* ── SOCIAL PROOF BAR (alumnos + rating + custom) ───── */ ?>
            <?php if (!empty($social['bar'])): ?>
            <section class="slc-cpitch__band">
                <div class="slc-cpitch__wrap slc-cpitch__band-grid">
                    <?php foreach ($social['bar'] as $stat): ?>
                        <div class="slc-cpitch__band-item">
                            <?php $b_icon = trim((string)($stat['icon'] ?? '')); ?>
                            <?php if ($b_icon !== ''): ?>
                                <div class="slc-cpitch__band-icon"><i class="fi <?php echo esc_attr($b_icon); ?>"></i></div>
                            <?php endif; ?>
                            <div class="slc-cpitch__band-num"><?php echo esc_html($stat['num']); ?></div>
                            <div class="slc-cpitch__band-label"><?php echo esc_html($stat['label']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── PARA QUIÉN ES ───────────────────────────────────── */ ?>
            <?php if (!empty($audience)): ?>
            <section class="slc-cpitch__section slc-cpitch__section--audience">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php esc_html_e('¿Es para vos?', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('A quién está dirigido', 'studiahub-lms-connector'); ?></h2>
                    </div>
                    <div class="slc-cpitch__personas" data-stagger-reveal>
                        <?php foreach ($audience as $item): ?>
                            <div class="slc-cpitch__persona slc-reveal-item">
                                <span class="slc-cpitch__persona-mark" aria-hidden="true">✓</span>
                                <span><?php echo esc_html(is_array($item) ? ($item['text'] ?? '') : (string) $item); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── REQUISITOS ──────────────────────────────────────── */ ?>
            <?php if (!empty($reqs)): ?>
            <section class="slc-cpitch__section">
                <div class="slc-cpitch__wrap slc-cpitch__reqs-grid">
                    <?php if ($reqs_img !== ''): ?>
                    <div class="slc-cpitch__reqs-img-wrap">
                        <img class="slc-cpitch__reqs-img" src="<?php echo esc_url($reqs_img); ?>" alt="" loading="lazy" />
                    </div>
                    <?php endif; ?>
                    <div class="slc-cpitch__reqs-body">
                        <div class="slc-cpitch__section-head">
                            <span class="slc-cpitch__eyebrow"><?php esc_html_e('Antes de empezar', 'studiahub-lms-connector'); ?></span>
                            <h2 class="slc-cpitch__h2"><?php esc_html_e('Requisitos', 'studiahub-lms-connector'); ?></h2>
                        </div>
                        <ul class="slc-cpitch__reqs">
                            <?php foreach ($reqs as $item): ?>
                                <li><span class="slc-cpitch__req-dot" aria-hidden="true"></span><?php echo esc_html(is_array($item) ? ($item['text'] ?? '') : (string) $item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── TEMARIO ─────────────────────────────────────────── */ ?>
            <?php if (!empty($outline)): ?>
            <section class="slc-cpitch__section slc-cpitch__section--soft">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php esc_html_e('Plan de estudio', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('Todo lo que vas a aprender', 'studiahub-lms-connector'); ?></h2>
                    </div>
                    <div class="slc-cpitch__outline-meta">
                        <span class="slc-cpitch__meta-chip">
                            <span class="slc-cpitch__meta-icon" aria-hidden="true"><?php echo self::icon('stack'); ?></span>
                            <?php echo esc_html(count($outline) . ' ' . _n('módulo', 'módulos', count($outline), 'studiahub-lms-connector')); ?>
                        </span>
                        <span class="slc-cpitch__meta-chip">
                            <span class="slc-cpitch__meta-icon" aria-hidden="true"><?php echo self::icon('play'); ?></span>
                            <?php echo esc_html(($lessons_count ?: self::count_lessons($outline)) . ' ' . _n('lección', 'lecciones', $lessons_count ?: self::count_lessons($outline), 'studiahub-lms-connector')); ?>
                        </span>
                        <?php if ($live_count > 0): ?>
                        <span class="slc-cpitch__meta-chip">
                            <span class="slc-cpitch__meta-icon" aria-hidden="true"><?php echo self::icon('camera'); ?></span>
                            <?php echo esc_html($live_count . ' ' . _n('encuentro en vivo', 'encuentros en vivo', $live_count, 'studiahub-lms-connector')); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($start_date_fmt !== ''): ?>
                        <span class="slc-cpitch__meta-chip">
                            <span class="slc-cpitch__meta-icon" aria-hidden="true"><?php echo self::icon('calendar'); ?></span>
                            <?php printf(esc_html__('Inicia %s', 'studiahub-lms-connector'), esc_html($start_date_fmt)); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="slc-cpitch__modules">
                        <?php foreach ($outline as $index => $module): ?>
                            <?php
                            $lessons = isset($module['lessons']) && is_array($module['lessons']) ? $module['lessons'] : [];
                            $lc = count($lessons);
                            $md = isset($module['durationMin']) && is_numeric($module['durationMin']) ? (int) $module['durationMin'] : 0;
                            if ($md === 0) {
                                foreach ($lessons as $l) {
                                    if (!empty($l['durationMin']) && is_numeric($l['durationMin'])) {
                                        $md += (int) $l['durationMin'];
                                    }
                                }
                            }
                            $is_live = !empty($module['isLive']) || (($module['mode'] ?? '') === 'live');
                            ?>
                            <details class="slc-cpitch__module"<?php if ($index === 0) echo ' open'; ?>>
                                <summary class="slc-cpitch__module-head">
                                    <span class="slc-cpitch__module-num"><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                                    <h3 class="slc-cpitch__module-title"><?php echo esc_html($module['title'] ?? ''); ?><?php if ($is_live): ?> <span class="slc-cpitch__live-badge"><span class="slc-cpitch__live-dot" aria-hidden="true"></span><?php esc_html_e('EN VIVO', 'studiahub-lms-connector'); ?></span><?php endif; ?></h3>
                                    <span class="slc-cpitch__module-meta">
                                        <?php echo esc_html($lc . ' ' . _n('lección', 'lecciones', $lc, 'studiahub-lms-connector')); ?>
                                        <?php if ($md > 0): ?>
                                            · <?php echo esc_html(Shortcode_CoursePage::format_duration_public($md)); ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="slc-cpitch__chevron" aria-hidden="true"></span>
                                </summary>
                                <?php if ($lc > 0): ?>
                                    <ul class="slc-cpitch__lessons">
                                        <?php foreach ($lessons as $lesson): ?>
                                            <?php $lesson_live = is_array($lesson) ? trim((string) ($lesson['liveAt'] ?? '')) : ''; ?>
                                            <li class="slc-cpitch__lesson">
                                                <?php if ($lesson_live !== ''): ?>
                                                    <span class="slc-cpitch__lesson-icon slc-cpitch__lesson-icon--live" aria-hidden="true"><svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="1.5" y="4.5" width="9" height="7" rx="1.5"/><path d="M10.5 7 14.5 5v6l-4-2z"/></svg></span>
                                                <?php else: ?>
                                                    <span class="slc-cpitch__lesson-icon" aria-hidden="true"><?php echo Shortcode_CoursePage::lesson_icon_public($lesson['type'] ?? null); ?></span>
                                                <?php endif; ?>
                                                <span><?php echo esc_html($lesson['title'] ?? ''); ?><?php if ($lesson_live !== '' && !$is_live): ?> <span class="slc-cpitch__live-badge slc-cpitch__live-badge--sm"><span class="slc-cpitch__live-dot" aria-hidden="true"></span><?php esc_html_e('EN VIVO', 'studiahub-lms-connector'); ?></span><?php endif; ?></span>
                                                <?php if ($lesson_live !== ''): ?>
                                                    <span class="slc-cpitch__lesson-dur slc-cpitch__lesson-date"><?php echo esc_html(self::format_live_date($lesson_live)); ?></span>
                                                <?php elseif (!empty($lesson['durationMin'])): ?>
                                                    <span class="slc-cpitch__lesson-dur"><?php echo esc_html(Shortcode_CoursePage::format_duration_public((int) $lesson['durationMin'])); ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── RESEÑAS — se ocultan con menos de 3 (prueba social floja) ── */ ?>
            <?php
                $valid_reviews = is_array($reviews) ? array_values(array_filter($reviews, function($r) {
                    return (string)($r['author'] ?? '') !== '' && (int)($r['rating'] ?? 0) >= 1;
                })) : [];
            ?>
            <?php if (count($valid_reviews) >= 3): ?>
            <?php
                // Pocas (3-5) → grid estático; muchas (6+) → marquee de 2 filas
                // (con pocas, el marquee duplica y se ve repetido y feo).
                $real_count  = count($valid_reviews);
                $use_marquee = $real_count >= 6;
                if ($use_marquee) {
                    while (count($valid_reviews) < 6) {
                        $valid_reviews = array_merge($valid_reviews, $valid_reviews);
                    }
                    $mid  = (int) ceil(count($valid_reviews) / 2);
                    $row1 = array_slice($valid_reviews, 0, $mid);
                    $row2 = array_slice($valid_reviews, $mid);
                }
                $stats_count = (int) ($review_stats['count'] ?? count($valid_reviews));
                $stats_avg   = (float) ($review_stats['average'] ?? 0);
            ?>
            <section class="slc-cpitch__section slc-cpitch__reviews-section">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <?php if ($stats_count > 0 && $stats_avg > 0):
                            // Estrellas con soporte de fracción (ej: 4,7 → 4 llenas + 1 al 70%)
                            $star_full  = floor($stats_avg);
                            $star_frac  = round(($stats_avg - $star_full) * 100); // porcentaje fracción
                            $star_empty = 5 - $star_full - ($star_frac > 0 ? 1 : 0);
                            $star_id    = 'slc-star-grad-' . substr(md5($stats_avg), 0, 6);
                            $pts = '8,1.5 10,6 14.5,6.3 11,9.3 12.2,13.8 8,11.2 3.8,13.8 5,9.3 1.5,6.3 6,6';
                            // Gradiente para la estrella parcial
                            $stars_html = '<svg width="0" height="0" style="position:absolute;pointer-events:none"><defs>'
                                . '<linearGradient id="' . $star_id . '" x1="0" x2="1" y1="0" y2="0">'
                                . '<stop offset="' . $star_frac . '%" stop-color="#FAB005"/>'
                                . '<stop offset="' . $star_frac . '%" stop-color="none"/>'
                                . '</linearGradient></defs></svg>';
                            // Llenas: fill dorado sólido, igual que stars_public
                            for ($si = 0; $si < $star_full; $si++) {
                                $stars_html .= '<svg viewBox="0 0 16 16" width="14" height="14" fill="#FAB005" stroke="#FAB005" stroke-width="1.3"><polygon points="' . $pts . '"/></svg>';
                            }
                            // Parcial: mismo estilo que llena pero con gradiente en el fill
                            if ($star_frac > 0) {
                                $stars_html .= '<svg viewBox="0 0 16 16" width="14" height="14" stroke="#FAB005" stroke-width="1.3">'
                                    . '<polygon points="' . $pts . '" fill="url(#' . $star_id . ')"/>'
                                    . '</svg>';
                            }
                            // Vacías: sin fill, solo contorno dorado — igual que stars_public empty
                            for ($si = 0; $si < $star_empty; $si++) {
                                $stars_html .= '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="#FAB005" stroke-width="1.3"><polygon points="' . $pts . '"/></svg>';
                            }
                        ?>
                        <div class="slc-cpitch__reviews-pill">
                            <span class="slc-cpitch__reviews-big"><?php echo esc_html(number_format($stats_avg, 1, ',', '')); ?></span>
                            <span class="slc-cpitch__stars" aria-hidden="true"><?php echo $stars_html; ?></span>
                            <span class="slc-cpitch__reviews-count"><?php printf(esc_html(_n('basado en %d reseña', 'basado en %d reseñas', $stats_count, 'studiahub-lms-connector')), $stats_count); ?></span>
                        </div>
                        <?php endif; ?>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('Alumnos reales, resultados reales', 'studiahub-lms-connector'); ?></h2>
                    </div>
                </div>

                <?php
                // Helper para renderizar una card
                $render_review_card = function(array $r, bool $hidden = false) {
                    $author  = (string) ($r['author'] ?? '');
                    $rating  = (int)    ($r['rating'] ?? 0);
                    $comment = (string) ($r['comment'] ?? '');
                    $avatar  = (string) ($r['avatarUrl'] ?? '');
                    $aria    = $hidden ? ' aria-hidden="true"' : '';
                    ?>
                    <div class="slc-cpitch__review"<?php echo $aria; ?>>
                        <div class="slc-cpitch__review-quote" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 21.1 17.2" fill="currentColor"><path d="M1.2,15c-1-1.5-1.2-3.4-1.2-4.5C0,6.1,2.2,2.3,7,0l.6,1.2c-2.8,1.2-5.2,4-5.2,6.7s0,1,.2,1.4c.7-.5,1.6-.9,2.5-.9,2.4,0,4.4,1.6,4.4,4.4s-2,4.4-4.4,4.4-3.2-.9-4-2.2ZM12.7,15c-1-1.5-1.2-3.4-1.2-4.5,0-4.4,2.2-8.2,7-10.5l.6,1.2c-2.8,1.2-5.2,4-5.2,6.7s0,1,.2,1.4c.7-.5,1.6-.9,2.5-.9,2.4,0,4.4,1.6,4.4,4.4s-2,4.4-4.4,4.4-3.2-.9-4-2.2Z"/></svg></div>
                        <?php if ($comment !== ''): ?>
                            <p class="slc-cpitch__review-text"><?php echo esc_html($comment); ?></p>
                        <?php endif; ?>
                        <div class="slc-cpitch__review-author">
                            <?php if ($avatar !== ''): ?>
                                <img class="slc-cpitch__avatar slc-cpitch__avatar--img" src="<?php echo esc_url($avatar); ?>" alt="" width="36" height="36" loading="lazy" />
                            <?php else: ?>
                                <span class="slc-cpitch__avatar"><?php echo esc_html(mb_substr($author, 0, 1)); ?></span>
                            <?php endif; ?>
                            <span class="slc-cpitch__review-name"><?php echo esc_html($author); ?></span>
                            <span class="slc-cpitch__review-stars" aria-hidden="true"><?php echo Shortcode_CoursePage::stars_public($rating); ?></span>
                        </div>
                    </div>
                    <?php
                };
                ?>

                <?php if ($use_marquee): ?>
                <!-- Fila 1: izquierda -->
                <div class="slc-cpitch__marquee" data-direction="left">
                    <div class="slc-cpitch__marquee-track">
                        <?php foreach ($row1 as $r) { $render_review_card($r); } ?>
                        <?php foreach ($row1 as $r) { $render_review_card($r, true); } ?>
                    </div>
                </div>

                <!-- Fila 2: derecha -->
                <div class="slc-cpitch__marquee" data-direction="right">
                    <div class="slc-cpitch__marquee-track">
                        <?php foreach ($row2 as $r) { $render_review_card($r); } ?>
                        <?php foreach ($row2 as $r) { $render_review_card($r, true); } ?>
                    </div>
                </div>
                <?php else: ?>
                <!-- Pocas reseñas: grid estático centrado, sin repetir. -->
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__reviews-static">
                        <?php foreach ($valid_reviews as $r) { $render_review_card($r); } ?>
                    </div>
                </div>
                <?php endif; ?>

            </section>
            <?php endif; ?>

            <?php /* ── PRICING BLOCK ───────────────────────────────────── */ ?>
            <section id="slc-pricing" class="slc-cpitch__section slc-cpitch__pricing-section">
                <div class="slc-cpitch__wrap">

                    <!-- Card de precio centrada -->
                    <div class="slc-cpitch__pricing-card">

                        <?php if ($thumbnail_url !== ''): ?>
                        <div class="slc-cpitch__pricing-hero-img">
                            <img src="<?php echo esc_url($thumbnail_url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
                        </div>
                        <?php endif; ?>

                        <h3 class="slc-cpitch__pricing-course-title"><?php echo esc_html($title); ?></h3>

                        <?php if (!empty($materials)): ?>
                        <ul class="slc-cpitch__pricing-checklist">
                            <?php foreach ($materials as $mat):
                                $mat_text = is_array($mat) ? ($mat['text'] ?? '') : (string)$mat;
                                if ($mat_text === '') continue;
                            ?>
                            <li class="slc-cpitch__pricing-check">
                                <span class="slc-cpitch__pricing-check-icon" aria-hidden="true">✓</span>
                                <span><?php echo esc_html($mat_text); ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <div class="slc-cpitch__pricing-price">
                            <?php if ($offer['original'] !== ''): ?>
                                <span class="slc-cpitch__pricing-original"><?php esc_html_e('Precio regular:', 'studiahub-lms-connector'); ?> <s><?php echo esc_html($offer['original']); ?></s></span>
                            <?php endif; ?>
                            <?php if ($offer['current'] !== ''): ?>
                            <div class="slc-cpitch__pricing-now"><?php echo esc_html($offer['current']); ?></div>
                            <?php endif; ?>
                            <?php if ($offer['installments'] !== ''): ?>
                                <span class="slc-cpitch__pricing-inst"><?php echo esc_html($offer['installments']); ?></span>
                            <?php endif; ?>
                            <?php if ($offer_deadline_label !== ''): ?>
                                <div class="slc-cpitch__pricing-offer-timer<?php echo $offer_imminent ? ' slc-cpitch__pricing-offer-timer--live' : ''; ?>"<?php if ($offer_imminent): ?> data-slc-offer-target="<?php echo esc_attr($offer_deadline_iso); ?>"<?php endif; ?>>
                                    <span aria-hidden="true">⏰</span>
                                    <span><?php esc_html_e('La oferta termina en', 'studiahub-lms-connector'); ?> <strong data-slc-offer-timer><?php echo esc_html($offer_deadline_label); ?></strong></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($sales_closed): ?>
                        <span class="slc-cpitch__btn slc-cpitch__btn--block slc-cpitch__pricing-cta slc-cpitch__pricing-cta--closed" aria-disabled="true">
                            <?php esc_html_e('Inscripciones cerradas', 'studiahub-lms-connector'); ?>
                        </span>
                        <?php else: ?>
                        <a class="slc-cpitch__btn slc-cpitch__btn--block slc-cpitch__pricing-cta" href="<?php echo esc_url($checkout_url); ?>">
                            <?php echo esc_html($cta_label ?: __('Quiero inscribirme', 'studiahub-lms-connector')); ?> →
                        </a>
                        <?php endif; ?>

                        <?php if (!empty($payment_methods)): ?>
                        <div class="slc-cpitch__pricing-logos">
                            <?php foreach ($payment_methods as $method):
                                $pm_url = trim((string)($method['logoUrl'] ?? ''));
                                $pm_name = trim((string)($method['name'] ?? ''));
                                if ($pm_url === '') continue;
                            ?>
                                <img src="<?php echo esc_url($pm_url); ?>" alt="<?php echo esc_attr($pm_name); ?>" loading="lazy" onerror="this.style.display='none'" />
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="slc-cpitch__pricing-trust">
                            <span class="slc-cpitch__pricing-trust-item"><svg class="slc-cpitch__trust-ico" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg> <?php esc_html_e('Proveedor verificado', 'studiahub-lms-connector'); ?></span>
                            <span class="slc-cpitch__pricing-trust-item">🔒 <?php esc_html_e('Pago 100% seguro', 'studiahub-lms-connector'); ?></span>
                        </div>

                    </div>

                    <?php if (!empty($bonuses)): ?>
                    <!-- Bonos incluidos -->
                    <div class="slc-cpitch__pricing-features">
                        <p class="slc-cpitch__pricing-features-label"><?php esc_html_e('BONOS INCLUIDOS EN TU INSCRIPCIÓN', 'studiahub-lms-connector'); ?></p>
                        <div class="slc-cpitch__bonuses slc-cpitch__bonuses--light">
                            <?php foreach ($bonuses as $bonus):
                                $b_img = trim((string)($bonus['imageUrl'] ?? ''));
                            ?>
                                <div class="slc-cpitch__bonus">
                                    <?php if ($b_img !== ''): ?>
                                    <div class="slc-cpitch__bonus-img-wrap">
                                        <img class="slc-cpitch__bonus-img" src="<?php echo esc_url($b_img); ?>" alt="" loading="lazy" />
                                    </div>
                                    <?php endif; ?>
                                    <div class="slc-cpitch__bonus-body">
                                        <h3 class="slc-cpitch__bonus-title"><?php echo esc_html($bonus['title']); ?></h3>
                                        <?php if (!empty($bonus['desc'])): ?>
                                            <p class="slc-cpitch__bonus-desc"><?php echo esc_html($bonus['desc']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($bonus['value'])): ?>
                                            <div class="slc-cpitch__bonus-value">
                                                <span class="slc-cpitch__bonus-value-label"><?php esc_html_e('Valor:', 'studiahub-lms-connector'); ?></span>
                                                <span class="slc-cpitch__bonus-value-num"><?php echo esc_html($bonus['value']); ?></span>
                                                <span class="slc-cpitch__bonus-free"><?php esc_html_e('Incluido gratis', 'studiahub-lms-connector'); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($guarantee !== null): ?>
                    <!-- Banner de garantía -->
                    <div class="slc-cpitch__pricing-guarantee">
                        <div class="slc-cpitch__pricing-guarantee-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="40" height="40"><circle cx="12" cy="9" r="6"/><path d="m9 9 2 2 4-4"/><path d="M9 14.5 7 22l5-3 5 3-2-7.5"/></svg>
                        </div>
                        <div>
                            <strong class="slc-cpitch__pricing-guarantee-title"><?php echo esc_html($guarantee['title']); ?></strong>
                            <p class="slc-cpitch__pricing-guarantee-text"><?php echo esc_html($guarantee['text']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </section>

            <?php /* ── INSTRUCTORES ────────────────────────────────────── */ ?>
            <?php if (!empty($instructors)): ?>
            <section class="slc-cpitch__section">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php echo esc_html(count($instructors) === 1 ? __('Docente', 'studiahub-lms-connector') : __('Docentes', 'studiahub-lms-connector')); ?></span>
                    </div>
                    <div class="slc-cpitch__instructors">
                        <?php foreach ($instructors as $ins): ?>
                            <div class="slc-cpitch__instructor">
                                <div class="slc-cpitch__instructor-photo">
                                    <?php if ($ins['photo'] !== ''): ?>
                                        <img src="<?php echo esc_url($ins['photo']); ?>"
                                             alt="<?php echo esc_attr($ins['name']); ?>"
                                             width="160" height="160"
                                             loading="lazy"
                                             decoding="async">
                                    <?php else: ?>
                                        <span class="slc-cpitch__instructor-initial"><?php echo esc_html(mb_substr($ins['name'], 0, 1)); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="slc-cpitch__instructor-body">
                                    <h3 class="slc-cpitch__instructor-name"><?php echo esc_html($ins['name']); ?></h3>
                                    <?php if ($ins['title'] !== ''): ?>
                                        <div class="slc-cpitch__instructor-role"><?php echo esc_html($ins['title']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($ins['bio'] !== ''): ?>
                                        <p class="slc-cpitch__instructor-bio"><?php echo nl2br(esc_html($ins['bio'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>




            <?php /* ── FAQ ─────────────────────────────────────────────── */ ?>
            <?php if (!empty($faq)): ?>
            <section class="slc-cpitch__section slc-cpitch__section--soft slc-cpitch__faq-section">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php esc_html_e('Dudas', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('Preguntas frecuentes', 'studiahub-lms-connector'); ?></h2>
                    </div>
                    <div class="slc-cpitch__faq">
                        <?php foreach ($faq as $qa): ?>
                            <details class="slc-cpitch__faq-item">
                                <summary class="slc-cpitch__faq-q">
                                    <span><?php echo esc_html($qa['q']); ?></span>
                                    <span class="slc-cpitch__chevron" aria-hidden="true"></span>
                                </summary>
                                <div class="slc-cpitch__faq-a"><?php echo esc_html($qa['a']); ?></div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* La barra inferior sticky se reemplazó por la barra superior
                     con countdown, que queda fija al scrollear. */ ?>

        </article>
        <?php if ($trailer !== null): ?>
        <script>
        (function(){
            document.querySelectorAll('.slc-coursepitch .slc-cpitch__trailer-facade').forEach(function(el){
                var activate = function(){
                    var embed = el.getAttribute('data-embed');
                    if (!embed) return;
                    var iframe = document.createElement('iframe');
                    iframe.src = embed;
                    iframe.setAttribute('allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture');
                    iframe.setAttribute('allowfullscreen', '');
                    iframe.style.cssText = 'width:100%;height:100%;border:0;position:absolute;inset:0;';
                    el.innerHTML = '';
                    el.appendChild(iframe);
                    el.classList.add('slc-cpitch__trailer-facade--active');
                };
                el.addEventListener('click', activate);
                el.addEventListener('keydown', function(e){
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
                });
            });
        })();
        </script>
        <?php endif; ?>
        <?php if ($start_at !== ''): ?>
        <script>
        (function(){
            // Countdown de la barra superior hacia el inicio del curso.
            var bar = document.querySelector('.slc-coursepitch .slc-cpitch__topbar[data-slc-countdown]');
            if (!bar) return;
            var target = new Date(bar.getAttribute('data-slc-countdown')).getTime();
            if (isNaN(target)) return;
            var timer = bar.querySelector('[data-slc-countdown-timer]');
            var elLabel = bar.querySelector('.slc-cpitch__topbar-label');
            var elD = bar.querySelector('[data-d]'), elH = bar.querySelector('[data-h]'),
                elM = bar.querySelector('[data-m]'), elS = bar.querySelector('[data-s]');
            var pad = function(n){ return (n < 10 ? '0' : '') + n; };
            var interval = null;
            var tick = function(){
                var diff = target - Date.now();
                if (diff <= 0) {
                    // El curso ya comenzó.
                    bar.classList.add('slc-cpitch__topbar--now');
                    if (timer) timer.textContent = '';
                    if (elLabel) elLabel.textContent = '¡El curso ya comenzó!';
                    if (interval) clearInterval(interval);
                    return;
                }
                var s = Math.floor(diff / 1000);
                elD.textContent = pad(Math.floor(s / 86400));
                elH.textContent = pad(Math.floor((s % 86400) / 3600));
                elM.textContent = pad(Math.floor((s % 3600) / 60));
                elS.textContent = pad(s % 60);
            };
            bar.hidden = false;
            tick();
            interval = setInterval(tick, 1000);
        })();
        </script>
        <?php endif; ?>
        <?php if ($offer_imminent): ?>
        <script>
        (function(){
            // Countdown vivo de la oferta — solo en las últimas 48hs.
            var el = document.querySelector('.slc-coursepitch .slc-cpitch__pricing-offer-timer[data-slc-offer-target]');
            if (!el) return;
            var target = new Date(el.getAttribute('data-slc-offer-target')).getTime();
            if (isNaN(target)) return;
            var out = el.querySelector('[data-slc-offer-timer]');
            if (!out) return;
            var pad = function(n){ return (n < 10 ? '0' : '') + n; };
            var interval = null;
            var tick = function(){
                var diff = target - Date.now();
                if (diff <= 0) { out.textContent = 'unos instantes'; if (interval) clearInterval(interval); return; }
                var s = Math.floor(diff / 1000);
                var d = Math.floor(s / 86400); s -= d * 86400;
                var h = Math.floor(s / 3600); s -= h * 3600;
                var m = Math.floor(s / 60); s -= m * 60;
                out.textContent = (d > 0 ? d + 'd ' : '') + pad(h) + 'h ' + pad(m) + 'm ' + pad(s) + 's';
            };
            tick();
            interval = setInterval(tick, 1000);
        })();
        </script>
        <?php endif; ?>
        <script>
        (function(){
            // Reveal escalonado de items al scrollear (¿Es para vos?)
            (function(){
                var containers = document.querySelectorAll('.slc-coursepitch [data-stagger-reveal]');
                if (!containers.length) return;
                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (!entry.isIntersecting) return;
                        var items = entry.target.querySelectorAll('.slc-reveal-item');
                        items.forEach(function(el, i) {
                            setTimeout(function() {
                                el.classList.add('slc-revealed');
                            }, i * 180);
                        });
                        observer.unobserve(entry.target);
                    });
                }, { threshold: 0.15 });
                containers.forEach(function(c) { observer.observe(c); });
            })();

            // Animación de entrada + conteo en la banda de stats
            (function(){
                var nums = document.querySelectorAll('.slc-coursepitch .slc-cpitch__band-num');
                if (!nums.length) return;
                function parseNum(str) {
                    var m = str.match(/[\d.,]+/);
                    return m ? parseFloat(m[0].replace(',', '.')) : null;
                }
                function formatNum(val, original) {
                    // Detecta si el original usa coma como decimal
                    var usesComma = original.indexOf(',') > -1 && original.indexOf('.') === -1;
                    var decimals = (original.match(/[.,](\d+)/) || ['',''])[1].length;
                    var str = val.toFixed(decimals);
                    if (usesComma) str = str.replace('.', ',');
                    // Reinsertamos prefijo y sufijo
                    var prefix = original.match(/^[^0-9,.-]*/)[0];
                    var suffix = original.match(/[^0-9,.-]*$/)[0];
                    return prefix + str + suffix;
                }
                function animateNum(el, target, original, delay) {
                    setTimeout(function(){
                        el.classList.add('slc-band-visible');
                        if (target === null) return;
                        var start = null;
                        var duration = 1200;
                        function step(ts) {
                            if (!start) start = ts;
                            var p = Math.min((ts - start) / duration, 1);
                            var ease = 1 - Math.pow(1 - p, 3); // ease-out cubic
                            el.textContent = formatNum(target * ease, original);
                            if (p < 1) requestAnimationFrame(step);
                            else el.textContent = original; // restaura texto exacto al final
                        }
                        requestAnimationFrame(step);
                    }, delay);
                }
                var observed = false;
                var observer = new IntersectionObserver(function(entries){
                    if (observed) return;
                    entries.forEach(function(entry){
                        if (entry.isIntersecting) {
                            observed = true;
                            nums.forEach(function(el, i){
                                var original = el.textContent.trim();
                                var target = parseNum(original);
                                animateNum(el, target, original, i * 120);
                            });
                            observer.disconnect();
                        }
                    });
                }, { threshold: 0.3 });
                var band = document.querySelector('.slc-coursepitch .slc-cpitch__band');
                if (band) observer.observe(band);
            })();

            // Parallax en cajitas flotantes del hero
            (function(){
                var floats = document.querySelectorAll('.slc-coursepitch .slc-cpitch__hero-float');
                if (!floats.length) return;
                // Cada cajita se mueve a distinta velocidad — efecto profundidad
                var speeds = [-0.12, 0.18, -0.10];
                var raf = null;
                function updateFloats() {
                    var sy = window.scrollY;
                    floats.forEach(function(el, i) {
                        el.style.transform = 'translateY(' + (sy * (speeds[i] || -0.1)) + 'px)';
                    });
                    raf = null;
                }
                window.addEventListener('scroll', function(){
                    if (!raf) raf = window.requestAnimationFrame(updateFloats);
                }, { passive: true });
            })();
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    // ── HELPERS ───────────────────────────────────────────────────────────

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

    private static function checkout_url(int $product_id): string {
        if (function_exists('wc_get_checkout_url')) {
            return add_query_arg('add-to-cart', $product_id, wc_get_checkout_url());
        }
        return '#';
    }

    private static function wc_price_fallback(int $product_id): string {
        if (function_exists('wc_get_product')) {
            $p = wc_get_product($product_id);
            if ($p && function_exists('wc_price')) {
                return wp_strip_all_tags(wc_price($p->get_price()));
            }
        }
        return '';
    }

    private static function count_lessons(array $outline): int {
        return array_sum(array_map(static fn($m) => isset($m['lessons']) && is_array($m['lessons']) ? count($m['lessons']) : 0, $outline));
    }

    /**
     * SVGs inline para íconos de outcomes/materiales — no depende de ningún CDN.
     * Estilo thin-line, viewBox 24×24, stroke="currentColor".
     */
    public static function svg_icon(string $key): string {
        $icons = [
            'fi-tr-bullseye-arrow' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/><path d="M22 2L16 8M22 2h-5M22 2v5"/></svg>',
            'fi-tr-filter'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18M7 9h10M11 14h2M10 19h4"/></svg>',
            'fi-tr-chart-line-up'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
            'fi-tr-user-circle'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="8" r="3"/><path d="M5.5 20a7 7 0 0 1 13 0"/></svg>',
            'fi-tr-envelope-open'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-6 9 6v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            'fi-tr-copy-alt'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
            'fi-tr-calculator'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><rect x="7" y="5" width="10" height="4" rx="1"/><circle cx="8" cy="13" r="1" fill="currentColor" stroke="none"/><circle cx="12" cy="13" r="1" fill="currentColor" stroke="none"/><circle cx="16" cy="13" r="1" fill="currentColor" stroke="none"/><circle cx="8" cy="17" r="1" fill="currentColor" stroke="none"/><circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/><circle cx="16" cy="17" r="1" fill="currentColor" stroke="none"/></svg>',
            'fi-tr-comment-code'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><polyline points="9 10 7 12 9 14"/><polyline points="15 10 17 12 15 14"/></svg>',
            'fi-tr-clipboard-list' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>',
            'fi-tr-box-open'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
            'fi-tr-star'           => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        ];
        return $icons[$key] ?? $icons['fi-tr-star'];
    }

    private static function truncate(string $s, int $max): string {
        if (mb_strlen($s) <= $max) return $s;
        return rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }

    private static function meta_chips(string $type_key, int $hours, int $total_min, string $level, string $language, bool $has_cert, int $modules, int $lessons, int $live = 0): array {
        // Orden: tipo → nivel → módulos → lecciones → encuentros en vivo → idioma → certificado.
        // (Las horas de contenido se omiten a propósito en esta variante.)
        $chips = [];
        if ($type_key !== '' && isset(self::TYPE_LABELS[$type_key])) {
            $chips[] = ['icon' => self::icon('type'), 'label' => self::TYPE_LABELS[$type_key]];
        }
        if ($level !== '') {
            $chips[] = ['icon' => self::icon('level'), 'label' => $level];
        }
        if ($modules > 0) {
            $chips[] = ['icon' => self::icon('stack'), 'label' => $modules . ' ' . _n('módulo', 'módulos', $modules, 'studiahub-lms-connector')];
        }
        if ($lessons > 0) {
            $chips[] = ['icon' => self::icon('play'), 'label' => $lessons . ' ' . _n('lección', 'lecciones', $lessons, 'studiahub-lms-connector')];
        }
        if ($live > 0) {
            $chips[] = ['icon' => self::icon('camera'), 'label' => $live . ' ' . _n('encuentro en vivo', 'encuentros en vivo', $live, 'studiahub-lms-connector')];
        }
        if ($language !== '') {
            $chips[] = ['icon' => self::icon('globe'), 'label' => $language];
        }
        if ($has_cert) {
            $chips[] = ['icon' => self::icon('certificate'), 'label' => __('Certificado', 'studiahub-lms-connector')];
        }
        return $chips;
    }

    /**
     * Formatea una fecha/hora de sesión en vivo a un string corto en español,
     * respetando el huso horario embebido en el ISO (ej: -03:00 = Argentina).
     * Devuelve algo como "12 jun · 18hs (ARG)". '' si el ISO no es válido.
     */
    private static function format_live_date(string $iso): string {
        try {
            $dt = new \DateTime($iso);
        } catch (\Exception $e) {
            return '';
        }
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $dia   = (int) $dt->format('j');
        $mes   = $meses[(int) $dt->format('n') - 1];
        $hora  = (int) $dt->format('G');       // hora 0-23 en el offset del propio ISO
        $min   = $dt->format('i');
        $time  = ($min === '00') ? $hora . 'hs' : $hora . ':' . $min . 'hs';
        return $dia . ' ' . $mes . ' · ' . $time . ' (ARG)';
    }

    /**
     * Tiempo restante en español, sin depender del locale de WP (human_time_diff
     * devuelve "2 weeks" si el sitio está en inglés). Semanas → días → horas →
     * minutos, mostrando siempre la unidad más grande que aplica.
     */
    private static function format_relative_es(int $seconds): string {
        if ($seconds >= 7 * 86400) {
            $n = (int) round($seconds / (7 * 86400));
            return $n . ' ' . ($n === 1 ? 'semana' : 'semanas');
        }
        if ($seconds >= 86400) {
            $n = (int) round($seconds / 86400);
            return $n . ' ' . ($n === 1 ? 'día' : 'días');
        }
        if ($seconds >= 3600) {
            $n = (int) round($seconds / 3600);
            return $n . ' ' . ($n === 1 ? 'hora' : 'horas');
        }
        $n = max(1, (int) round($seconds / 60));
        return $n . ' ' . ($n === 1 ? 'minuto' : 'minutos');
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
            'camera'      => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="1.5" y="4.5" width="9" height="7" rx="1.5"/><path d="M10.5 7 14.5 5v6l-4-2z"/></svg>',
            'calendar'    => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6.5h12M5.5 1.5v3M10.5 1.5v3"/></svg>',
        ];
        return $icons[$name] ?? '';
    }
}
