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

            <?php /* ── HERO asymmetric: texto izq + card derecha (robusto con/sin trailer) ─── */ ?>
            <section class="slc-cpitch__hero">
                <div class="slc-cpitch__hero-bg" aria-hidden="true"></div>
                <div class="slc-cpitch__wrap slc-cpitch__hero-grid">
                    <div class="slc-cpitch__hero-main">
                        <?php if ($badge !== '' || $category !== ''): ?>
                            <div class="slc-cpitch__pretitle">
                                <?php if ($badge !== ''): ?>
                                    <span class="slc-cpitch__pretitle-pill"><?php echo esc_html($badge); ?></span>
                                <?php endif; ?>
                                <?php if ($category !== ''): ?>
                                    <span class="slc-cpitch__pretitle-cat"><?php echo esc_html($category); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <h1 class="slc-cpitch__hero-title"><?php echo esc_html($title); ?></h1>

                        <?php if ($subtitle !== '' || $short_desc !== ''): ?>
                            <p class="slc-cpitch__hero-sub"><?php echo esc_html($subtitle !== '' ? $subtitle : $short_desc); ?></p>
                        <?php endif; ?>

                        <?php if ($social['rating'] !== null || $social['students_label'] !== ''): ?>
                            <div class="slc-cpitch__hero-proof">
                                <?php if ($social['rating'] !== null): ?>
                                    <span class="slc-cpitch__stars" aria-hidden="true"><?php echo Shortcode_CoursePage::stars_public($social['rating']); ?></span>
                                    <strong><?php echo esc_html(number_format((float) $social['rating'], 1, ',', '')); ?></strong>
                                <?php endif; ?>
                                <?php if ($social['rating'] !== null && $social['students_label'] !== ''): ?>
                                    <span class="slc-cpitch__proof-sep">·</span>
                                <?php endif; ?>
                                <?php if ($social['students_label'] !== ''): ?>
                                    <span><?php echo esc_html($social['students_label']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <ul class="slc-cpitch__hero-meta">
                            <?php foreach (self::meta_chips($type_key, $hours, $total_min, $level, $language, $has_cert, $modules_count, $lessons_count) as $chip): ?>
                                <li class="slc-cpitch__meta-chip">
                                    <span class="slc-cpitch__meta-icon" aria-hidden="true"><?php echo $chip['icon']; ?></span>
                                    <?php echo esc_html($chip['label']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <aside class="slc-cpitch__hero-card">
                        <?php if ($trailer !== null):
                            $facade_thumb = $trailer['thumb'] !== '' ? $trailer['thumb'] : $thumbnail_url;
                        ?>
                            <div class="slc-cpitch__hero-media">
                                <div class="slc-cpitch__trailer-facade"
                                     data-embed="<?php echo esc_attr($trailer['embed']); ?>"
                                     <?php if ($facade_thumb !== ''): ?>style="background-image:url('<?php echo esc_url($facade_thumb); ?>');"<?php endif; ?>
                                     role="button"
                                     tabindex="0"
                                     aria-label="<?php echo esc_attr__('Reproducir trailer', 'studiahub-lms-connector'); ?>">
                                    <span class="slc-cpitch__play" aria-hidden="true">
                                        <svg viewBox="0 0 64 64" width="72" height="72"><circle cx="32" cy="32" r="32" fill="rgba(0,0,0,0.7)"/><polygon points="26,20 26,44 46,32" fill="#fff"/></svg>
                                    </span>
                                </div>
                            </div>
                        <?php elseif ($thumbnail_url !== ''): ?>
                            <div class="slc-cpitch__hero-media">
                                <img class="slc-cpitch__hero-thumb" src="<?php echo esc_url($thumbnail_url); ?>" alt="" loading="lazy" />
                            </div>
                        <?php endif; ?>
                        <div class="slc-cpitch__hero-cardbody">
                            <div class="slc-cpitch__hero-price">
                                <?php if ($offer['original'] !== ''): ?>
                                    <span class="slc-cpitch__price-old"><?php echo esc_html($offer['original']); ?></span>
                                <?php endif; ?>
                                <span class="slc-cpitch__price-now"><?php echo esc_html($offer['current']); ?></span>
                                <?php if ($offer['installments'] !== ''): ?>
                                    <span class="slc-cpitch__price-inst"><?php echo esc_html($offer['installments']); ?></span>
                                <?php endif; ?>
                            </div>
                            <a class="slc-cpitch__btn slc-cpitch__btn--block" href="<?php echo esc_url($checkout_url); ?>"><?php echo esc_html($cta_label); ?></a>
                            <?php if ($offer['deadline'] !== ''): ?>
                                <div class="slc-cpitch__deadline">
                                    <span class="slc-cpitch__deadline-dot" aria-hidden="true"></span>
                                    <?php echo esc_html($offer['deadline']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($guarantee !== null): ?>
                                <div class="slc-cpitch__hero-guarantee">
                                    <svg viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 1l6 2v5c0 4-3 6-6 7-3-1-6-3-6-7V3l6-2z"/></svg>
                                    <span><?php echo esc_html($guarantee['short']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </aside>
                </div>
            </section>

            <?php /* ── DESCRIPCIÓN LARGA ───────────────────────────────── */ ?>
            <?php if ($long_desc !== ''): ?>
            <section class="slc-cpitch__section slc-cpitch__section--soft">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php esc_html_e('La propuesta', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('Por qué tomar este curso', 'studiahub-lms-connector'); ?></h2>
                    </div>
                    <div class="slc-cpitch__longdesc-grid">
                        <?php if ($thumbnail_url !== ''): ?>
                        <div class="slc-cpitch__longdesc-img-wrap">
                            <img class="slc-cpitch__longdesc-img" src="<?php echo esc_url($thumbnail_url); ?>" alt="" loading="lazy" />
                        </div>
                        <?php endif; ?>
                        <div class="slc-cpitch__prose"><?php echo wpautop(wp_kses_post($long_desc)); ?></div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── HOOK / OUTCOMES (qué vas a lograr) ─────────────── */ ?>
            <?php if (!empty($outcomes)): ?>
            <section class="slc-cpitch__section">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php esc_html_e('Lo que te llevás', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('Al terminar este curso vas a poder…', 'studiahub-lms-connector'); ?></h2>
                    </div>
                    <div class="slc-cpitch__checks">
                        <?php foreach ($outcomes as $item): ?>
                            <div class="slc-cpitch__check">
                                <span class="slc-cpitch__check-icon" aria-hidden="true">✓</span>
                                <span><?php echo esc_html(is_array($item) ? ($item['text'] ?? '') : (string) $item); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── SOCIAL PROOF BAR (alumnos + rating + custom) ───── */ ?>
            <?php if (!empty($social['bar'])): ?>
            <section class="slc-cpitch__band">
                <div class="slc-cpitch__wrap slc-cpitch__band-grid">
                    <?php foreach ($social['bar'] as $stat): ?>
                        <div class="slc-cpitch__band-item">
                            <div class="slc-cpitch__band-num"><?php echo esc_html($stat['num']); ?></div>
                            <div class="slc-cpitch__band-label"><?php echo esc_html($stat['label']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── PARA QUIÉN ES ───────────────────────────────────── */ ?>
            <?php if (!empty($audience)): ?>
            <section class="slc-cpitch__section slc-cpitch__section--soft">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php esc_html_e('Para vos', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('¿Es para vos?', 'studiahub-lms-connector'); ?></h2>
                    </div>
                    <div class="slc-cpitch__personas">
                        <?php foreach ($audience as $item): ?>
                            <div class="slc-cpitch__persona">
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
                        <span><?php echo esc_html(count($outline) . ' ' . _n('módulo', 'módulos', count($outline), 'studiahub-lms-connector')); ?></span>
                        <span class="slc-cpitch__dot">·</span>
                        <span><?php echo esc_html(($lessons_count ?: self::count_lessons($outline)) . ' ' . _n('lección', 'lecciones', $lessons_count ?: self::count_lessons($outline), 'studiahub-lms-connector')); ?></span>
                        <?php if ($total_min > 0): ?>
                            <span class="slc-cpitch__dot">·</span>
                            <span><?php echo esc_html(Shortcode_CoursePage::format_duration_public($total_min)); ?></span>
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
                            ?>
                            <details class="slc-cpitch__module"<?php if ($index === 0) echo ' open'; ?>>
                                <summary class="slc-cpitch__module-head">
                                    <span class="slc-cpitch__module-num"><?php echo esc_html(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></span>
                                    <h3 class="slc-cpitch__module-title"><?php echo esc_html($module['title'] ?? ''); ?></h3>
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
                                            <li class="slc-cpitch__lesson">
                                                <span class="slc-cpitch__lesson-icon" aria-hidden="true"><?php echo Shortcode_CoursePage::lesson_icon_public($lesson['type'] ?? null); ?></span>
                                                <span><?php echo esc_html($lesson['title'] ?? ''); ?></span>
                                                <?php if (!empty($lesson['durationMin'])): ?>
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

            <?php /* ── RESEÑAS — infinite marquee dos filas ────────────── */ ?>
            <?php if (!empty($reviews)): ?>
            <?php
                // Filtrar reseñas válidas
                $valid_reviews = array_values(array_filter($reviews, function($r) {
                    return (string)($r['author'] ?? '') !== '' && (int)($r['rating'] ?? 0) >= 1;
                }));
                // Aseguramos mínimo 6 para las dos filas; si hay menos, duplicamos
                while (count($valid_reviews) < 6) {
                    $valid_reviews = array_merge($valid_reviews, $valid_reviews);
                }
                $mid   = (int) ceil(count($valid_reviews) / 2);
                $row1  = array_slice($valid_reviews, 0, $mid);
                $row2  = array_slice($valid_reviews, $mid);
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

            </section>
            <?php endif; ?>

            <?php /* ── INSTRUCTORES ────────────────────────────────────── */ ?>
            <?php if (!empty($instructors)): ?>
            <section class="slc-cpitch__section slc-cpitch__section--soft">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php esc_html_e('Quién enseña', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php
                            echo esc_html(count($instructors) === 1
                                ? __('Tu instructor', 'studiahub-lms-connector')
                                : __('Tus instructores', 'studiahub-lms-connector'));
                        ?></h2>
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

            <?php /* ── MATERIALES INCLUIDOS ─────────────────────────────── */ ?>
            <?php if (!empty($materials)): ?>
            <section class="slc-cpitch__section">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow"><?php esc_html_e('Recursos del curso', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('Esto viene incluido en tu inscripción', 'studiahub-lms-connector'); ?></h2>
                    </div>
                    <div class="slc-cpitch__materials-list">
                        <?php foreach ($materials as $item):
                            $mat_text = is_array($item) ? ($item['text'] ?? '') : (string) $item;
                            $mat_icon = is_array($item) ? trim((string) ($item['icon'] ?? '')) : '';
                        ?>
                            <div class="slc-cpitch__material">
                                <span class="slc-cpitch__material-icon" aria-hidden="true">
                                    <?php if ($mat_icon !== ''): ?>
                                        <i class="fi <?php echo esc_attr($mat_icon); ?>"></i>
                                    <?php else: ?>
                                        <i class="fi fi-tr-box-open"></i>
                                    <?php endif; ?>
                                </span>
                                <span class="slc-cpitch__material-text"><?php echo esc_html($mat_text); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── BONOS (con VALOR muy visible) ───────────────────── */ ?>
            <?php if (!empty($bonuses)): ?>
            <section class="slc-cpitch__section slc-cpitch__bonuses-section">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__section-head">
                        <span class="slc-cpitch__eyebrow slc-cpitch__eyebrow--accent"><?php esc_html_e('Bonos exclusivos', 'studiahub-lms-connector'); ?></span>
                        <h2 class="slc-cpitch__h2"><?php esc_html_e('Si te inscribís hoy, también te llevás:', 'studiahub-lms-connector'); ?></h2>
                    </div>
                    <div class="slc-cpitch__bonuses">
                        <?php foreach ($bonuses as $bonus):
                            $b_img = trim((string) ($bonus['imageUrl'] ?? ''));
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
                    <div class="slc-cpitch__bonuses-cta">
                        <a class="slc-cpitch__btn slc-cpitch__btn--lg" href="<?php echo esc_url($checkout_url); ?>"><?php echo esc_html($cta_label ?: __('Quiero inscribirme', 'studiahub-lms-connector')); ?></a>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── GARANTÍA — sello grande CSS-only ──────────────── */ ?>
            <?php if ($guarantee !== null): ?>
            <section class="slc-cpitch__section">
                <div class="slc-cpitch__wrap slc-cpitch__wrap--narrow">
                    <div class="slc-cpitch__guarantee">
                        <div class="slc-cpitch__seal" aria-hidden="true">
                            <div class="slc-cpitch__seal-inner">
                                <div class="slc-cpitch__seal-top"><?php esc_html_e('GARANTÍA', 'studiahub-lms-connector'); ?></div>
                                <div class="slc-cpitch__seal-mid">100%</div>
                                <div class="slc-cpitch__seal-bot"><?php esc_html_e('SATISFACCIÓN', 'studiahub-lms-connector'); ?></div>
                            </div>
                        </div>
                        <div class="slc-cpitch__guarantee-body">
                            <h3 class="slc-cpitch__guarantee-title"><?php echo esc_html($guarantee['title']); ?></h3>
                            <p class="slc-cpitch__guarantee-text"><?php echo esc_html($guarantee['text']); ?></p>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── CTA FINAL + PAGO SEGURO ─────────────────────────── */ ?>
            <section class="slc-cpitch__final">
                <div class="slc-cpitch__wrap slc-cpitch__final-inner">
                    <span class="slc-cpitch__eyebrow slc-cpitch__eyebrow--on-dark"><?php esc_html_e('Última oportunidad', 'studiahub-lms-connector'); ?></span>
                    <h2 class="slc-cpitch__final-title"><?php esc_html_e('Inscribite ahora y empezá hoy', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cpitch__final-price">
                        <?php if ($offer['original'] !== ''): ?>
                            <span class="slc-cpitch__price-old slc-cpitch__price-old--lg"><?php echo esc_html($offer['original']); ?></span>
                        <?php endif; ?>
                        <span class="slc-cpitch__final-now"><?php echo esc_html($offer['current']); ?></span>
                        <?php if ($offer['installments'] !== ''): ?>
                            <span class="slc-cpitch__final-inst"><?php echo esc_html($offer['installments']); ?></span>
                        <?php endif; ?>
                    </div>
                    <a class="slc-cpitch__btn slc-cpitch__btn--xl slc-cpitch__btn--on-dark" href="<?php echo esc_url($checkout_url); ?>"><?php echo esc_html($cta_label); ?></a>
                    <?php if ($offer['deadline'] !== ''): ?>
                        <div class="slc-cpitch__deadline slc-cpitch__deadline--center">
                            <span class="slc-cpitch__deadline-dot" aria-hidden="true"></span>
                            <?php echo esc_html($offer['deadline']); ?>
                        </div>
                    <?php endif; ?>
                    <?php
                    $trust_parts = [];
                    if ($guarantee !== null) $trust_parts[] = $guarantee['short'];
                    if ($social['students_label'] !== '') $trust_parts[] = $social['students_label'];
                    if ($tenant_name !== '') $trust_parts[] = sprintf(__('Curso oficial de %s', 'studiahub-lms-connector'), $tenant_name);
                    if (!empty($trust_parts)):
                    ?>
                        <div class="slc-cpitch__final-trust"><?php echo esc_html(implode(' · ', $trust_parts)); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($payment_methods)): ?>
                    <div class="slc-cpitch__final-payment">
                        <span class="slc-cpitch__final-payment-label">🔒 <?php esc_html_e('Pago seguro', 'studiahub-lms-connector'); ?></span>
                        <div class="slc-cpitch__payment-logos">
                            <?php foreach ($payment_methods as $method):
                                $pm_name = trim((string) ($method['name'] ?? ''));
                                $pm_url  = trim((string) ($method['logoUrl'] ?? ''));
                                if ($pm_name === '' && $pm_url === '') continue;
                            ?>
                                <span class="slc-cpitch__payment-item">
                                    <?php if ($pm_url !== ''): ?>
                                        <img
                                            class="slc-cpitch__payment-logo slc-cpitch__payment-logo--light"
                                            src="<?php echo esc_url($pm_url); ?>"
                                            alt="<?php echo esc_attr($pm_name); ?>"
                                            loading="lazy"
                                            onerror="this.parentElement.querySelector('.slc-cpitch__payment-name').style.display='inline'; this.style.display='none';"
                                        />
                                    <?php endif; ?>
                                    <span class="slc-cpitch__payment-name slc-cpitch__payment-name--light" <?php if ($pm_url !== '') echo 'style="display:none"'; ?>><?php echo esc_html($pm_name); ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

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

            <?php /* ── STICKY CTA (aparece al hacer scroll) ────────────── */ ?>
            <div class="slc-cpitch__sticky" data-slc-sticky>
                <div class="slc-cpitch__wrap slc-cpitch__sticky-inner">
                    <div class="slc-cpitch__sticky-text">
                        <strong class="slc-cpitch__sticky-title"><?php echo esc_html(self::truncate($title, 60)); ?></strong>
                        <span class="slc-cpitch__sticky-price">
                            <?php if ($offer['original'] !== ''): ?>
                                <span class="slc-cpitch__price-old"><?php echo esc_html($offer['original']); ?></span>
                            <?php endif; ?>
                            <?php echo esc_html($offer['current']); ?>
                        </span>
                    </div>
                    <a class="slc-cpitch__btn slc-cpitch__btn--md" href="<?php echo esc_url($checkout_url); ?>"><?php echo esc_html($cta_label); ?></a>
                </div>
            </div>

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
        <script>
        (function(){
            // Sticky CTA: aparece después de 600px de scroll y se oculta cerca del CTA final
            // para no taparlo.
            var sticky = document.querySelector('.slc-coursepitch .slc-cpitch__sticky');
            var finalCta = document.querySelector('.slc-coursepitch .slc-cpitch__final');
            if (!sticky) return;
            var ticking = false;
            var update = function(){
                ticking = false;
                var y = window.pageYOffset;
                var show = y > 600;
                if (finalCta) {
                    var rect = finalCta.getBoundingClientRect();
                    if (rect.top < window.innerHeight) show = false;
                }
                sticky.classList.toggle('slc-cpitch__sticky--visible', show);
            };
            window.addEventListener('scroll', function(){
                if (!ticking) { window.requestAnimationFrame(update); ticking = true; }
            }, { passive: true });
            update();
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

    private static function truncate(string $s, int $max): string {
        if (mb_strlen($s) <= $max) return $s;
        return rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }

    private static function meta_chips(string $type_key, int $hours, int $total_min, string $level, string $language, bool $has_cert, int $modules, int $lessons): array {
        $chips = [];
        if ($type_key !== '' && isset(self::TYPE_LABELS[$type_key])) {
            $chips[] = ['icon' => self::icon('type'), 'label' => self::TYPE_LABELS[$type_key]];
        }
        if ($hours > 0) {
            $chips[] = ['icon' => self::icon('clock'), 'label' => $hours . ' h de contenido'];
        } elseif ($total_min > 0) {
            $chips[] = ['icon' => self::icon('clock'), 'label' => Shortcode_CoursePage::format_duration_public($total_min)];
        }
        if ($level !== '') {
            $chips[] = ['icon' => self::icon('level'), 'label' => $level];
        }
        if ($language !== '') {
            $chips[] = ['icon' => self::icon('globe'), 'label' => $language];
        }
        if ($modules > 0) {
            $chips[] = ['icon' => self::icon('stack'), 'label' => $modules . ' ' . _n('módulo', 'módulos', $modules, 'studiahub-lms-connector')];
        }
        if ($lessons > 0) {
            $chips[] = ['icon' => self::icon('play'), 'label' => $lessons . ' ' . _n('lección', 'lecciones', $lessons, 'studiahub-lms-connector')];
        }
        if ($has_cert) {
            $chips[] = ['icon' => self::icon('certificate'), 'label' => __('Certificado', 'studiahub-lms-connector')];
        }
        return $chips;
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
}
