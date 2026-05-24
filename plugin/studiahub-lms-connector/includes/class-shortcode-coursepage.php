<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode [studiahub_course_page] — renderiza la PÁGINA DE VENTA COMPLETA del
 * curso en HTML, full-bleed (a todo el ancho), pensada para insertarse como
 * UN solo shortcode en una plantilla en blanco de Elementor.
 *
 * Toda la data "de curso" sale EN VIVO del LMS, via GET
 * /api/wc/courses/[id]/landing-payload — con WP transient 15 min +
 * stale-while-revalidate. El producto WC solo aporta el postmeta
 * `_lms_course_id` para mapear, el precio nativo (`_price`) y el checkout.
 *
 * La data "de marketing" (testimonios, FAQ, bonos, garantía, social proof,
 * pricing de oferta) todavía NO existe en el LMS, así que se HARDCODEA acá con
 * ejemplos realistas — marcados con `// HARDCODE: futuro campo LMS`. Cuando el
 * LMS exponga esos campos, se reemplazan los métodos hardcode_*() por lectura
 * del payload.
 *
 * Usage:
 *   [studiahub_course_page]            → producto del contexto WC actual
 *   [studiahub_course_page id="42"]    → fuerza el producto con ID 42
 *
 * Diseño: tokens como CSS custom properties en `.slc-coursepage` para que cada
 * tenant pueda re-skinearlo. Alineado al design system del panel admin del LMS.
 */
final class Shortcode_CoursePage {
    public const SHORTCODE_TAG = 'studiahub_course_page';
    public const STYLE_HANDLE  = 'slc-coursepage';

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

    /**
     * Registramos (no enqueueamos) en wp_enqueue_scripts. El enqueue real
     * se hace en render() cuando el shortcode efectivamente corre, así no
     * cargamos el CSS en páginas que no lo usan.
     */
    public static function register_styles(): void {
        if (!defined('SLC_VERSION')) {
            return;
        }
        wp_register_style(
            self::STYLE_HANDLE,
            SLC_PLUGIN_URL . 'assets/css/coursepage.css',
            [],
            SLC_VERSION
        );
    }

    public static function render($atts): string {
        $atts = shortcode_atts([
            'id' => '',
        ], $atts, self::SHORTCODE_TAG);

        $product_id = self::resolve_product_id($atts['id']);
        if (!$product_id) {
            return '';
        }

        // El producto WC solo aporta el mapeo al curso del LMS via postmeta.
        $course_id = (string) get_post_meta($product_id, '_lms_course_id', true);
        if ($course_id === '') {
            return '<!-- studiahub_course_page: producto sin _lms_course_id, ¿está sincronizado? -->';
        }

        $payload = Landing_Fetch::get_payload($course_id);
        if (!is_array($payload)) {
            return '<!-- studiahub_course_page: LMS no respondió y no hay cache. -->';
        }

        // ── DATA REAL (payload del LMS, en vivo + cache) ──────────────────
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
        $price_disp  = trim((string) ($payload['priceDisplay'] ?? ''));
        $cta_label   = trim((string) ($payload['ctaLabel'] ?? ''));
        $trailer_url = trim((string) ($payload['trailerUrl'] ?? ''));

        $instructor_data  = is_array($payload['instructor'] ?? null) ? $payload['instructor'] : [];
        $instructor       = trim((string) ($instructor_data['name'] ?? ''));
        $instructor_title = trim((string) ($instructor_data['title'] ?? ''));
        $instructor_bio   = trim((string) ($instructor_data['bio'] ?? ''));
        $instructor_photo = trim((string) ($instructor_data['photoUrl'] ?? ''));

        // Los arrays JSON ya vienen decodificados en el payload.
        $outcomes  = is_array($payload['learningOutcomes'] ?? null) ? $payload['learningOutcomes'] : [];
        $audience  = is_array($payload['targetAudience'] ?? null) ? $payload['targetAudience'] : [];
        $materials = is_array($payload['includedMaterials'] ?? null) ? $payload['includedMaterials'] : [];
        $reqs      = is_array($payload['requirements'] ?? null) ? $payload['requirements'] : [];

        $modules_count = (int) ($payload['modulesCount'] ?? 0);
        $lessons_count = (int) ($payload['lessonsCount'] ?? 0);
        $total_min     = (int) ($payload['totalDurationMin'] ?? 0);
        $outline       = is_array($payload['outline'] ?? null) ? $payload['outline'] : [];

        if ($cta_label === '') {
            $cta_label = __('Inscribirme ahora', 'studiahub-lms-connector');
        }
        if ($price_disp === '') {
            $price_disp = self::wc_price_fallback($product_id);
        }

        $checkout_url = self::checkout_url($product_id);

        // ── DATA HARDCODEADA (marketing — futuro LMS) ─────────────────────
        $social      = self::hardcode_social_proof();
        $offer       = self::hardcode_offer_pricing($price_disp);
        $bonuses     = self::hardcode_bonuses();
        $testimonials = self::hardcode_testimonials();
        $guarantee   = self::hardcode_guarantee();
        $faq         = self::hardcode_faq();

        $trailer_embed = $trailer_url !== '' ? self::to_embed_url($trailer_url) : null;

        wp_enqueue_style(self::STYLE_HANDLE);

        ob_start();
        echo self::render_json_ld($payload, $product_id);
        ?>
        <div class="slc-coursepage">

            <?php /* ── HERO ────────────────────────────────────────────── */ ?>
            <section class="slc-cp__hero">
                <div class="slc-cp__wrap slc-cp__hero-grid">
                    <div class="slc-cp__hero-main">
                        <?php if ($badge !== ''): ?>
                            <span class="slc-cp__badge"><?php echo esc_html($badge); ?></span>
                        <?php endif; ?>
                        <h1 class="slc-cp__hero-title"><?php echo esc_html($title); ?></h1>
                        <?php if ($subtitle !== ''): ?>
                            <p class="slc-cp__hero-subtitle"><?php echo esc_html($subtitle); ?></p>
                        <?php elseif ($short_desc !== ''): ?>
                            <p class="slc-cp__hero-subtitle"><?php echo esc_html($short_desc); ?></p>
                        <?php endif; ?>

                        <div class="slc-cp__hero-proof">
                            <span class="slc-cp__stars" aria-hidden="true"><?php echo self::stars($social['rating']); ?></span>
                            <strong><?php echo esc_html(number_format((float) $social['rating'], 1)); ?></strong>
                            <span class="slc-cp__proof-sep">·</span>
                            <span><?php echo esc_html($social['students_label']); ?></span>
                        </div>

                        <ul class="slc-cp__hero-meta">
                            <?php foreach (self::meta_chips($type_key, $hours, $total_min, $level, $language, $has_cert, $modules_count, $lessons_count) as $chip): ?>
                                <li class="slc-cp__meta-chip">
                                    <span class="slc-cp__meta-icon" aria-hidden="true"><?php echo $chip['icon']; ?></span>
                                    <?php echo esc_html($chip['label']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <aside class="slc-cp__hero-card">
                        <?php if ($trailer_embed !== null): ?>
                            <div class="slc-cp__video">
                                <iframe src="<?php echo esc_url($trailer_embed); ?>" title="<?php echo esc_attr($title); ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe>
                            </div>
                        <?php endif; ?>
                        <div class="slc-cp__price-block">
                            <?php if ($offer['original'] !== ''): ?>
                                <div class="slc-cp__price-row">
                                    <span class="slc-cp__price-old"><?php echo esc_html($offer['original']); ?></span>
                                    <span class="slc-cp__price-off"><?php echo esc_html($offer['discount_label']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="slc-cp__price-now"><?php echo esc_html($offer['current']); ?></div>
                            <?php if ($offer['installments'] !== ''): ?>
                                <div class="slc-cp__price-inst"><?php echo esc_html($offer['installments']); ?></div>
                            <?php endif; ?>
                            <a class="slc-cp__cta" href="<?php echo esc_url($checkout_url); ?>"><?php echo esc_html($cta_label); ?></a>
                            <?php if ($offer['deadline'] !== ''): ?>
                                <div class="slc-cp__urgency">
                                    <span class="slc-cp__urgency-dot" aria-hidden="true"></span>
                                    <?php echo esc_html($offer['deadline']); ?>
                                </div>
                            <?php endif; ?>
                            <div class="slc-cp__guarantee-mini">
                                <?php echo self::icon('shield'); ?>
                                <span><?php echo esc_html($guarantee['short']); ?></span>
                            </div>
                        </div>
                    </aside>
                </div>
            </section>

            <?php /* ── BARRA SOCIAL PROOF ──────────────────────────────── */ ?>
            <section class="slc-cp__bar">
                <div class="slc-cp__wrap slc-cp__bar-grid">
                    <?php foreach ($social['bar'] as $stat): ?>
                        <div class="slc-cp__bar-item">
                            <div class="slc-cp__bar-num"><?php echo esc_html($stat['num']); ?></div>
                            <div class="slc-cp__bar-label"><?php echo esc_html($stat['label']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php /* ── QUÉ VAS A APRENDER ──────────────────────────────── */ ?>
            <?php if (!empty($outcomes)): ?>
            <section class="slc-cp__section">
                <div class="slc-cp__wrap">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Qué vas a aprender', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cp__checks">
                        <?php foreach ($outcomes as $item): ?>
                            <div class="slc-cp__check">
                                <span class="slc-cp__check-icon" aria-hidden="true"><?php echo self::icon('check'); ?></span>
                                <span><?php echo esc_html(is_array($item) ? ($item['text'] ?? '') : (string) $item); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── PARA QUIÉN ES ───────────────────────────────────── */ ?>
            <?php if (!empty($audience)): ?>
            <section class="slc-cp__section slc-cp__section--soft">
                <div class="slc-cp__wrap">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Para quién es este curso', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cp__cards3">
                        <?php foreach ($audience as $item): ?>
                            <div class="slc-cp__minicard">
                                <span class="slc-cp__minicard-icon" aria-hidden="true"><?php echo self::icon('user'); ?></span>
                                <span><?php echo esc_html(is_array($item) ? ($item['text'] ?? '') : (string) $item); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── TEMARIO (outline accordion) ─────────────────────── */ ?>
            <?php if (!empty($outline)): ?>
            <section class="slc-cp__section">
                <div class="slc-cp__wrap slc-cp__wrap--narrow">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Contenido del curso', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cp__outline-meta">
                        <span><?php echo esc_html(count($outline) . ' módulos'); ?></span>
                        <span class="slc-cp__dot">·</span>
                        <span><?php echo esc_html(($lessons_count ?: self::count_lessons($outline)) . ' lecciones'); ?></span>
                        <?php if ($total_min > 0): ?>
                            <span class="slc-cp__dot">·</span>
                            <span><?php echo esc_html(self::format_duration($total_min)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="slc-cp__modules">
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
                            <details class="slc-cp__module"<?php if ($index === 0) echo ' open'; ?>>
                                <summary class="slc-cp__summary">
                                    <span class="slc-cp__chevron" aria-hidden="true"></span>
                                    <span class="slc-cp__module-title"><?php echo esc_html($module['title'] ?? ''); ?></span>
                                    <span class="slc-cp__module-badge"><?php echo esc_html($lc . ' ' . ($lc === 1 ? 'lección' : 'lecciones')); ?></span>
                                    <?php if ($md > 0): ?>
                                        <span class="slc-cp__module-badge slc-cp__module-badge--time"><?php echo esc_html(self::format_duration($md)); ?></span>
                                    <?php endif; ?>
                                </summary>
                                <?php if ($lc > 0): ?>
                                    <ul class="slc-cp__lessons">
                                        <?php foreach ($lessons as $lesson): ?>
                                            <li class="slc-cp__lesson">
                                                <span class="slc-cp__lesson-icon" aria-hidden="true"><?php echo self::lesson_icon($lesson['type'] ?? null); ?></span>
                                                <span class="slc-cp__lesson-title"><?php echo esc_html($lesson['title'] ?? ''); ?></span>
                                                <?php if (!empty($lesson['durationMin'])): ?>
                                                    <span class="slc-cp__lesson-dur"><?php echo esc_html(self::format_duration((int) $lesson['durationMin'])); ?></span>
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

            <?php /* ── INSTRUCTOR ──────────────────────────────────────── */ ?>
            <?php if ($instructor !== ''): ?>
            <section class="slc-cp__section slc-cp__section--soft">
                <div class="slc-cp__wrap slc-cp__wrap--narrow">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Tu instructor', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cp__instructor">
                        <div class="slc-cp__instructor-photo">
                            <?php if ($instructor_photo !== ''): ?>
                                <img src="<?php echo esc_url($instructor_photo); ?>" alt="<?php echo esc_attr($instructor); ?>" loading="lazy">
                            <?php else: ?>
                                <span class="slc-cp__instructor-initial"><?php echo esc_html(mb_substr($instructor, 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="slc-cp__instructor-body">
                            <div class="slc-cp__instructor-name"><?php echo esc_html($instructor); ?></div>
                            <?php if ($instructor_title !== ''): ?>
                                <div class="slc-cp__instructor-role"><?php echo esc_html($instructor_title); ?></div>
                            <?php endif; ?>
                            <?php if ($instructor_bio !== ''): ?>
                                <div class="slc-cp__instructor-bio"><?php echo nl2br(esc_html($instructor_bio)); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── DESCRIPCIÓN LARGA ───────────────────────────────── */ ?>
            <?php if ($long_desc !== ''): ?>
            <section class="slc-cp__section">
                <div class="slc-cp__wrap slc-cp__wrap--narrow">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Sobre el curso', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cp__prose"><?php echo wpautop(wp_kses_post($long_desc)); ?></div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── QUÉ INCLUYE ─────────────────────────────────────── */ ?>
            <?php if (!empty($materials)): ?>
            <section class="slc-cp__section slc-cp__section--soft">
                <div class="slc-cp__wrap">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Qué incluye', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cp__cards3">
                        <?php foreach ($materials as $item): ?>
                            <div class="slc-cp__minicard">
                                <span class="slc-cp__minicard-icon" aria-hidden="true"><?php echo self::icon('box'); ?></span>
                                <span><?php echo esc_html(is_array($item) ? ($item['text'] ?? '') : (string) $item); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── BONOS ───────────────────────────────────────────── */ ?>
            <section class="slc-cp__section">
                <div class="slc-cp__wrap slc-cp__wrap--narrow">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Bonos exclusivos', 'studiahub-lms-connector'); ?></h2>
                    <p class="slc-cp__lead"><?php esc_html_e('Si te inscribís hoy, te llevás estos extras sin costo adicional:', 'studiahub-lms-connector'); ?></p>
                    <div class="slc-cp__bonuses">
                        <?php foreach ($bonuses as $bonus): ?>
                            <div class="slc-cp__bonus">
                                <span class="slc-cp__bonus-icon" aria-hidden="true"><?php echo self::icon('gift'); ?></span>
                                <div class="slc-cp__bonus-body">
                                    <div class="slc-cp__bonus-title"><?php echo esc_html($bonus['title']); ?></div>
                                    <div class="slc-cp__bonus-desc"><?php echo esc_html($bonus['desc']); ?></div>
                                </div>
                                <span class="slc-cp__bonus-value"><?php echo esc_html($bonus['value']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <?php /* ── TESTIMONIOS ─────────────────────────────────────── */ ?>
            <section class="slc-cp__section slc-cp__section--soft">
                <div class="slc-cp__wrap">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Lo que dicen nuestros alumnos', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cp__cards3">
                        <?php foreach ($testimonials as $t): ?>
                            <div class="slc-cp__testimonial">
                                <span class="slc-cp__stars" aria-hidden="true"><?php echo self::stars($t['rating']); ?></span>
                                <p class="slc-cp__testimonial-text"><?php echo esc_html($t['text']); ?></p>
                                <div class="slc-cp__testimonial-author">
                                    <span class="slc-cp__avatar" style="background:<?php echo esc_attr($t['avatar_bg']); ?>;"><?php echo esc_html(mb_substr($t['name'], 0, 1)); ?></span>
                                    <span class="slc-cp__testimonial-name"><?php echo esc_html($t['name']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <?php /* ── GARANTÍA ────────────────────────────────────────── */ ?>
            <section class="slc-cp__section">
                <div class="slc-cp__wrap slc-cp__wrap--narrow">
                    <div class="slc-cp__guarantee">
                        <span class="slc-cp__guarantee-badge" aria-hidden="true"><?php echo self::icon('shield'); ?></span>
                        <div>
                            <h3 class="slc-cp__guarantee-title"><?php echo esc_html($guarantee['title']); ?></h3>
                            <p class="slc-cp__guarantee-text"><?php echo esc_html($guarantee['text']); ?></p>
                        </div>
                    </div>
                </div>
            </section>

            <?php /* ── FAQ ─────────────────────────────────────────────── */ ?>
            <section class="slc-cp__section slc-cp__section--soft">
                <div class="slc-cp__wrap slc-cp__wrap--narrow">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Preguntas frecuentes', 'studiahub-lms-connector'); ?></h2>
                    <div class="slc-cp__faq">
                        <?php foreach ($faq as $qa): ?>
                            <details class="slc-cp__faq-item">
                                <summary class="slc-cp__faq-q">
                                    <span class="slc-cp__chevron" aria-hidden="true"></span>
                                    <span><?php echo esc_html($qa['q']); ?></span>
                                </summary>
                                <div class="slc-cp__faq-a"><?php echo esc_html($qa['a']); ?></div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <?php /* ── REQUISITOS ──────────────────────────────────────── */ ?>
            <?php if (!empty($reqs)): ?>
            <section class="slc-cp__section">
                <div class="slc-cp__wrap slc-cp__wrap--narrow">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Requisitos', 'studiahub-lms-connector'); ?></h2>
                    <ul class="slc-cp__reqs">
                        <?php foreach ($reqs as $item): ?>
                            <li><span class="slc-cp__req-dot" aria-hidden="true"></span><?php echo esc_html(is_array($item) ? ($item['text'] ?? '') : (string) $item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── CTA FINAL ───────────────────────────────────────── */ ?>
            <section class="slc-cp__final">
                <div class="slc-cp__wrap slc-cp__final-inner">
                    <h2 class="slc-cp__final-title"><?php esc_html_e('Empezá hoy mismo', 'studiahub-lms-connector'); ?></h2>
                    <p class="slc-cp__final-sub"><?php echo esc_html($subtitle !== '' ? $subtitle : $title); ?></p>
                    <div class="slc-cp__final-price">
                        <?php if ($offer['original'] !== ''): ?>
                            <span class="slc-cp__price-old slc-cp__price-old--lg"><?php echo esc_html($offer['original']); ?></span>
                        <?php endif; ?>
                        <span class="slc-cp__final-now"><?php echo esc_html($offer['current']); ?></span>
                    </div>
                    <a class="slc-cp__cta slc-cp__cta--lg" href="<?php echo esc_url($checkout_url); ?>"><?php echo esc_html($cta_label); ?></a>
                    <?php if ($offer['deadline'] !== ''): ?>
                        <div class="slc-cp__urgency slc-cp__urgency--center">
                            <span class="slc-cp__urgency-dot" aria-hidden="true"></span>
                            <?php echo esc_html($offer['deadline']); ?>
                        </div>
                    <?php endif; ?>
                    <div class="slc-cp__final-trust"><?php echo esc_html($guarantee['short']); ?> · <?php echo esc_html($social['students_label']); ?></div>
                </div>
            </section>

        </div>
        <?php
        return (string) ob_get_clean();
    }

    // ── RESOLUCIÓN DE PRODUCTO ────────────────────────────────────────────
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

    // ── HELPERS DE DATA REAL ──────────────────────────────────────────────
    private static function count_lessons(array $outline): int {
        return array_sum(array_map(static fn($m) => isset($m['lessons']) && is_array($m['lessons']) ? count($m['lessons']) : 0, $outline));
    }

    private static function meta_chips(string $type_key, int $hours, int $total_min, string $level, string $language, bool $has_cert, int $modules, int $lessons): array {
        $chips = [];
        if ($type_key !== '' && isset(self::TYPE_LABELS[$type_key])) {
            $chips[] = ['icon' => self::icon('type'), 'label' => self::TYPE_LABELS[$type_key]];
        }
        if ($hours > 0) {
            $chips[] = ['icon' => self::icon('clock'), 'label' => $hours . ' h de contenido'];
        } elseif ($total_min > 0) {
            $chips[] = ['icon' => self::icon('clock'), 'label' => self::format_duration($total_min)];
        }
        if ($level !== '') {
            $chips[] = ['icon' => self::icon('level'), 'label' => $level];
        }
        if ($language !== '') {
            $chips[] = ['icon' => self::icon('globe'), 'label' => $language];
        }
        if ($modules > 0) {
            $chips[] = ['icon' => self::icon('stack'), 'label' => $modules . ' ' . ($modules === 1 ? 'módulo' : 'módulos')];
        }
        if ($lessons > 0) {
            $chips[] = ['icon' => self::icon('play'), 'label' => $lessons . ' lecciones'];
        }
        if ($has_cert) {
            $chips[] = ['icon' => self::icon('certificate'), 'label' => 'Certificado'];
        }
        return $chips;
    }

    private static function to_embed_url(string $url): ?string {
        $url = trim($url);
        if (preg_match('~(?:youtube\.com/watch\?(?:.*&)?v=|youtu\.be/|youtube\.com/shorts/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1] . '?rel=0';
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        return null;
    }

    private static function format_duration(int $minutes): string {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m === 0 ? $h . ' h' : $h . ' h ' . $m . ' min';
    }

    private static function stars(float $rating): string {
        $full = (int) round($rating);
        $out = '';
        for ($i = 1; $i <= 5; $i++) {
            $fill = $i <= $full ? 'currentColor' : 'none';
            $out .= '<svg viewBox="0 0 16 16" width="16" height="16" fill="' . $fill . '" stroke="currentColor" stroke-width="1.3"><polygon points="8,1.5 10,6 14.5,6.3 11,9.3 12.2,13.8 8,11.2 3.8,13.8 5,9.3 1.5,6.3 6,6"/></svg>';
        }
        return $out;
    }

    // ── DATA HARDCODEADA (marketing — futuros campos LMS) ─────────────────
    private static function hardcode_social_proof(): array {
        // HARDCODE: futuro campo LMS — social proof / rating agregado
        return [
            'rating'         => 4.8,
            'students_label' => '+2.400 alumnos',
            'bar' => [
                ['num' => '+2.400', 'label' => 'Alumnos inscriptos'],
                ['num' => '4.8 ★', 'label' => 'Valoración promedio'],
                ['num' => '98%', 'label' => 'Lo recomiendan'],
                ['num' => '24/7', 'label' => 'Acceso de por vida'],
            ],
        ];
    }

    private static function hardcode_offer_pricing(string $real_price): array {
        // HARDCODE: futuro campo LMS — pricing de oferta (tachado, descuento,
        // cuotas, deadline de urgencia). El precio "current" usa el real si existe.
        $current = $real_price !== '' ? $real_price : '$49.900';
        return [
            'original'       => '$89.900',
            'current'        => $current,
            'discount_label' => '-44%',
            'installments'   => 'o 3 cuotas sin interés',
            'deadline'       => 'Oferta válida hasta el domingo',
        ];
    }

    private static function hardcode_bonuses(): array {
        // HARDCODE: futuro campo LMS — bonus stack
        return [
            ['title' => 'Plantillas descargables', 'desc' => 'Pack de plantillas listas para usar en tus proyectos.', 'value' => 'Valor $12.000'],
            ['title' => 'Sesión de Q&A en vivo', 'desc' => 'Acceso a una sesión mensual de preguntas con el instructor.', 'value' => 'Valor $20.000'],
            ['title' => 'Comunidad privada', 'desc' => 'Grupo exclusivo para networking y feedback entre alumnos.', 'value' => 'Valor $8.000'],
        ];
    }

    private static function hardcode_testimonials(): array {
        // HARDCODE: futuro campo LMS — reseñas / testimonios
        return [
            ['name' => 'Martina G.', 'rating' => 5, 'avatar_bg' => '#7950F2', 'text' => 'El curso superó mis expectativas. Las explicaciones son clarísimas y pude aplicar todo desde la primera semana.'],
            ['name' => 'Lucas R.', 'rating' => 5, 'avatar_bg' => '#228BE6', 'text' => 'Venía probando otros cursos y ninguno me enganchó como este. El temario está muy bien armado.'],
            ['name' => 'Sofía P.', 'rating' => 4, 'avatar_bg' => '#12B886', 'text' => 'Excelente relación precio-valor. Los bonos por sí solos ya justifican la inscripción.'],
            ['name' => 'Diego M.', 'rating' => 5, 'avatar_bg' => '#FD7E14', 'text' => 'El instructor responde rápido y la comunidad es un golazo. Lo recomiendo 100%.'],
        ];
    }

    private static function hardcode_guarantee(): array {
        // HARDCODE: futuro campo LMS — garantía money-back
        return [
            'title' => 'Garantía de 30 días',
            'text'  => 'Si en los primeros 30 días sentís que el curso no es para vos, te devolvemos el 100% de tu dinero. Sin preguntas, sin vueltas.',
            'short' => 'Garantía de 30 días',
        ];
    }

    private static function hardcode_faq(): array {
        // HARDCODE: futuro campo LMS — FAQ
        return [
            ['q' => '¿Por cuánto tiempo tengo acceso al curso?', 'a' => 'El acceso es de por vida. Una vez que te inscribís, podés ver el contenido las veces que quieras, sin vencimiento.'],
            ['q' => '¿Necesito conocimientos previos?', 'a' => 'No. El curso arranca desde lo básico y avanza de forma progresiva, así que podés tomarlo aunque empieces de cero.'],
            ['q' => '¿Cómo accedo a las clases?', 'a' => 'Apenas se confirma tu pago recibís un email con tus credenciales para entrar a la plataforma y empezar a aprender.'],
            ['q' => '¿El curso entrega certificado?', 'a' => 'Sí. Al completar el 100% del contenido se genera automáticamente tu certificado de finalización descargable en PDF.'],
            ['q' => '¿Puedo pagar en cuotas?', 'a' => 'Sí, podés abonar en cuotas sin interés con tarjeta de crédito según los medios de pago disponibles en el checkout.'],
            ['q' => '¿Qué pasa si no me gusta?', 'a' => 'Contás con 30 días de garantía. Si no quedás conforme, escribinos y te devolvemos el 100% de tu inversión.'],
        ];
    }

    // ── ICONOS SVG INLINE ─────────────────────────────────────────────────
    private static function lesson_icon(?string $type): string {
        $icons = [
            'VIDEO' => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="6,4 12,8 6,12" fill="currentColor"/></svg>',
            'TEXT'  => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="3" y1="5" x2="13" y2="5"/><line x1="3" y1="8" x2="13" y2="8"/><line x1="3" y1="11" x2="10" y2="11"/></svg>',
            'PDF'   => '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 2h6l3 3v9H4z"/><path d="M10 2v3h3"/></svg>',
        ];
        return $icons[$type ?? ''] ?? $icons['VIDEO'];
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
            'check'       => '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3,8.5 6.5,12 13,4"/></svg>',
            'user'        => '<svg viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="5" r="3"/><path d="M2.5 14c0-3 2.5-4.5 5.5-4.5s5.5 1.5 5.5 4.5"/></svg>',
            'box'         => '<svg viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2 14 5v6l-6 3-6-3V5z"/><path d="M2 5l6 3 6-3M8 8v6"/></svg>',
            'gift'        => '<svg viewBox="0 0 16 16" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="6" width="11" height="8" rx="1"/><path d="M2.5 6h11M8 6v8"/><path d="M8 6c-1-2-4-2-4 0M8 6c1-2 4-2 4 0"/></svg>',
            'shield'      => '<svg viewBox="0 0 16 16" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.5 13.5 4v4c0 3.5-2.5 5.5-5.5 6.5C5 13.5 2.5 11.5 2.5 8V4z"/><polyline points="5.8,8 7.3,9.5 10.2,6.2"/></svg>',
        ];
        return $icons[$name] ?? '';
    }

    /**
     * Genera structured data Schema.org/Course para SEO (Google Rich Results).
     */
    private static function render_json_ld(array $payload, int $product_id): string {
        $url         = get_permalink($product_id) ?: '';
        $tenant_name = get_bloginfo('name');

        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Course',
            'name'        => (string) ($payload['title'] ?? get_the_title($product_id)),
            'description' => (string) ($payload['shortDescription'] ?? ''),
            'provider'    => [
                '@type' => 'Organization',
                'name'  => $tenant_name,
                'url'   => home_url('/'),
            ],
        ];

        if (!empty($payload['thumbnailUrl'])) {
            $data['image'] = (string) $payload['thumbnailUrl'];
        }
        $instructor = is_array($payload['instructor'] ?? null) ? $payload['instructor'] : [];
        if (!empty($instructor['name'])) {
            $data['instructor'] = [
                '@type' => 'Person',
                'name'  => (string) $instructor['name'],
            ];
            if (!empty($instructor['title'])) {
                $data['instructor']['jobTitle'] = (string) $instructor['title'];
            }
        }
        if (!empty($payload['language'])) {
            $data['inLanguage'] = (string) $payload['language'];
        }
        $total_min = (int) ($payload['totalDurationMin'] ?? 0);
        if ($total_min > 0) {
            // ISO 8601 duration, ej PT15H30M.
            $h   = intdiv($total_min, 60);
            $m   = $total_min % 60;
            $iso = 'PT' . ($h > 0 ? $h . 'H' : '') . ($m > 0 ? $m . 'M' : '');
            if ($iso !== 'PT') {
                $data['timeRequired'] = $iso;
            }
        }
        if ($url !== '') {
            $data['url'] = $url;
        }

        return '<script type="application/ld+json">'
             . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
             . '</script>';
    }

}
