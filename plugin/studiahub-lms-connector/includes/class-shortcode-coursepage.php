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
 * Toda la data sale EN VIVO del LMS via GET /api/wc/courses/[id]/landing-payload
 * con WP transient 15 min + stale-while-revalidate. El producto WC solo aporta
 * el postmeta `_lms_course_id` para mapear, el precio nativo (`_price`) y el
 * checkout.
 *
 * El payload incluye, además del contenido del curso (outline, instructors[],
 * materiales, etc), la data "de marketing" gestionada desde el admin del LMS:
 *   - compareAtPrice (texto libre multimoneda) / installmentsLabel / offerDeadlineAt
 *   - bonuses[]
 *   - faq[] (ya mergeado: course.faq || tenant.defaultFaq || [])
 *   - socialProof (studentsCount real + label override + stats custom)
 *   - guarantee (null si tenant la deshabilitó)
 *   - reviews[] + reviewStats (rating REAL de alumnos aprobado)
 *
 * Cada sección oculta si no hay data — la landing nunca muestra placeholders
 * fake.
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

        // ── DATA DEL CURSO (payload del LMS, en vivo + cache) ─────────────
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

        // Instructores ahora vienen como array (uno o más). Cada item:
        // { id, name, title, bio, photoUrl }.
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

        // Los arrays JSON ya vienen decodificados en el payload.
        $outcomes  = is_array($payload['learningOutcomes'] ?? null) ? $payload['learningOutcomes'] : [];
        $audience  = is_array($payload['targetAudience'] ?? null) ? $payload['targetAudience'] : [];
        $materials = is_array($payload['includedMaterials'] ?? null) ? $payload['includedMaterials'] : [];
        $reqs      = is_array($payload['requirements'] ?? null) ? $payload['requirements'] : [];

        $modules_count = (int) ($payload['modulesCount'] ?? 0);
        $lessons_count = (int) ($payload['lessonsCount'] ?? 0);
        $total_min     = (int) ($payload['totalDurationMin'] ?? 0);
        $outline       = is_array($payload['outline'] ?? null) ? $payload['outline'] : [];

        $reviews      = is_array($payload['reviews'] ?? null) ? $payload['reviews'] : [];
        $review_stats = is_array($payload['reviewStats'] ?? null) ? $payload['reviewStats'] : [];

        if ($cta_label === '') {
            $cta_label = __('Inscribirme ahora', 'studiahub-lms-connector');
        }
        if ($price_disp === '') {
            $price_disp = self::wc_price_fallback($product_id);
        }

        $checkout_url = self::checkout_url($product_id);

        // ── DATA DE MARKETING (payload del LMS, controlado desde el admin) ─
        $social    = self::data_social_proof($payload);
        $offer     = self::data_offer_pricing($payload, $price_disp);
        $bonuses   = self::data_bonuses($payload);
        $guarantee = self::data_guarantee($payload);
        $faq       = self::data_faq($payload);

        $trailer = $trailer_url !== '' ? self::parse_trailer($trailer_url) : null;
        $thumbnail_url = trim((string) ($payload['thumbnailUrl'] ?? ''));

        // Categoría (no se mostraba antes — la sumamos al hero como pre-title).
        $category = trim((string) ($payload['category'] ?? ''));

        // ── BRANDING DEL TENANT ───────────────────────────────────────────
        // El payload trae branding con {primaryColor, secondaryColor, fontFamily}.
        // Aplicamos colores y tipografía via CSS custom properties INLINE en el wrapper —
        // así una sola hoja CSS sirve a N tenants con su skin propio.
        // NOTA: el logo del tenant NO se renderiza acá. La landing vive dentro
        // del header/footer del sitio WP del cliente, que ya muestra su logo.
        $branding = is_array($payload['branding'] ?? null) ? $payload['branding'] : [];
        $brand_style = self::build_brand_style($branding);

        wp_enqueue_style(self::STYLE_HANDLE);
        self::maybe_enqueue_google_font($branding['fontFamily'] ?? 'default');

        ob_start();
        echo self::render_json_ld($payload, $product_id);
        ?>
        <article class="slc-coursepage" itemscope itemtype="https://schema.org/Course"<?php if ($brand_style !== '') echo ' style="' . esc_attr($brand_style) . '"'; ?>>

            <?php /* ── HERO gigante centrado (estilo DTC, trailer dominante) ── */ ?>
            <section class="slc-cp__hero">
                <div class="slc-cp__wrap slc-cp__hero-inner">
                    <?php if ($badge !== '' || $category !== ''): ?>
                        <div class="slc-cp__hero-badges">
                            <?php if ($badge !== ''): ?>
                                <span class="slc-cp__badge"><?php echo esc_html($badge); ?></span>
                            <?php endif; ?>
                            <?php if ($category !== ''): ?>
                                <span class="slc-cp__badge slc-cp__badge--soft"><?php echo esc_html($category); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <h1 class="slc-cp__hero-title"><?php echo esc_html($title); ?></h1>

                    <?php if ($subtitle !== ''): ?>
                        <p class="slc-cp__hero-subtitle"><?php echo esc_html($subtitle); ?></p>
                    <?php elseif ($short_desc !== ''): ?>
                        <p class="slc-cp__hero-subtitle"><?php echo esc_html($short_desc); ?></p>
                    <?php endif; ?>

                    <?php if ($social['rating'] !== null || $social['students_label'] !== ''): ?>
                        <div class="slc-cp__hero-proof">
                            <?php if ($social['rating'] !== null): ?>
                                <span class="slc-cp__stars" aria-hidden="true"><?php echo self::stars($social['rating']); ?></span>
                                <strong><?php echo esc_html(number_format((float) $social['rating'], 1, ',', '')); ?></strong>
                            <?php endif; ?>
                            <?php if ($social['rating'] !== null && $social['students_label'] !== ''): ?>
                                <span class="slc-cp__proof-sep">·</span>
                            <?php endif; ?>
                            <?php if ($social['students_label'] !== ''): ?>
                                <span><?php echo esc_html($social['students_label']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($trailer !== null):
                        $facade_thumb = $trailer['thumb'] !== '' ? $trailer['thumb'] : $thumbnail_url;
                    ?>
                        <div class="slc-cp__hero-trailer">
                            <div class="slc-cp__trailer-facade"
                                 data-embed="<?php echo esc_attr($trailer['embed']); ?>"
                                 <?php if ($facade_thumb !== ''): ?>style="background-image:url('<?php echo esc_url($facade_thumb); ?>');"<?php endif; ?>
                                 role="button"
                                 tabindex="0"
                                 aria-label="<?php echo esc_attr__('Reproducir trailer', 'studiahub-lms-connector'); ?>">
                                <button type="button" class="slc-cp__trailer-play" aria-hidden="true">
                                    <svg viewBox="0 0 64 64" width="80" height="80"><circle cx="32" cy="32" r="32" fill="rgba(0,0,0,0.7)"/><polygon points="26,20 26,44 46,32" fill="#fff"/></svg>
                                </button>
                            </div>
                        </div>
                    <?php elseif ($thumbnail_url !== ''): ?>
                        <div class="slc-cp__hero-trailer">
                            <img class="slc-cp__hero-thumb" src="<?php echo esc_url($thumbnail_url); ?>" alt="" loading="lazy" />
                        </div>
                    <?php endif; ?>

                    <div class="slc-cp__hero-cta">
                        <?php if ($offer['original'] !== '' || $offer['discount_label'] !== ''): ?>
                            <div class="slc-cp__price-row">
                                <?php if ($offer['original'] !== ''): ?>
                                    <span class="slc-cp__price-old"><?php echo esc_html($offer['original']); ?></span>
                                <?php endif; ?>
                                <?php if ($offer['discount_label'] !== ''): ?>
                                    <span class="slc-cp__price-off"><?php echo esc_html($offer['discount_label']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="slc-cp__price-now"><?php echo esc_html($offer['current']); ?></div>
                        <?php if ($offer['installments'] !== ''): ?>
                            <div class="slc-cp__price-inst"><?php echo esc_html($offer['installments']); ?></div>
                        <?php endif; ?>
                        <a class="slc-cp__cta slc-cp__cta--lg" href="<?php echo esc_url($checkout_url); ?>"><?php echo esc_html($cta_label); ?></a>
                        <?php if ($offer['deadline'] !== ''): ?>
                            <div class="slc-cp__urgency slc-cp__urgency--center">
                                <span class="slc-cp__urgency-dot" aria-hidden="true"></span>
                                <?php echo esc_html($offer['deadline']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($guarantee !== null): ?>
                            <div class="slc-cp__guarantee-mini slc-cp__guarantee-mini--center">
                                <?php echo self::icon('shield'); ?>
                                <span><?php echo esc_html($guarantee['short']); ?></span>
                            </div>
                        <?php endif; ?>
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
            </section>

            <?php /* ── HERO GIGANTE (preservado de V2 — full-width centrado) ──
                 Se renderiza debajo del hero asymmetric con un modifier
                 `--big` que aísla su CSS. Diseño va a iterar sobre esto. */ ?>
            <section class="slc-cp__hero slc-cp__hero--big">
                <div class="slc-cp__hero-bg" aria-hidden="true"></div>
                <div class="slc-cp__wrap slc-cp__herobig-inner">
                    <?php if ($badge !== '' || $category !== ''): ?>
                        <div class="slc-cp__hero-badges">
                            <?php if ($badge !== ''): ?>
                                <span class="slc-cp__badge"><?php echo esc_html($badge); ?></span>
                            <?php endif; ?>
                            <?php if ($category !== ''): ?>
                                <span class="slc-cp__badge slc-cp__badge--soft"><?php echo esc_html($category); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <h1 class="slc-cp__herobig-title"><?php echo esc_html($title); ?></h1>

                    <?php if ($subtitle !== '' || $short_desc !== ''): ?>
                        <p class="slc-cp__herobig-subtitle"><?php echo esc_html($subtitle !== '' ? $subtitle : $short_desc); ?></p>
                    <?php endif; ?>

                    <?php if ($social['rating'] !== null || $social['students_label'] !== ''): ?>
                        <div class="slc-cp__herobig-proof">
                            <?php if ($social['rating'] !== null): ?>
                                <span class="slc-cp__stars" aria-hidden="true"><?php echo self::stars($social['rating']); ?></span>
                                <strong><?php echo esc_html(number_format((float) $social['rating'], 1, ',', '')); ?></strong>
                            <?php endif; ?>
                            <?php if ($social['rating'] !== null && $social['students_label'] !== ''): ?>
                                <span class="slc-cp__proof-sep">·</span>
                            <?php endif; ?>
                            <?php if ($social['students_label'] !== ''): ?>
                                <span><?php echo esc_html($social['students_label']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($trailer !== null):
                        $facade_thumb = $trailer['thumb'] !== '' ? $trailer['thumb'] : $thumbnail_url;
                    ?>
                        <div class="slc-cp__herobig-trailer">
                            <div class="slc-cp__trailer-facade"
                                 data-embed="<?php echo esc_attr($trailer['embed']); ?>"
                                 <?php if ($facade_thumb !== ''): ?>style="background-image:url('<?php echo esc_url($facade_thumb); ?>');"<?php endif; ?>
                                 role="button"
                                 tabindex="0"
                                 aria-label="<?php echo esc_attr__('Reproducir trailer', 'studiahub-lms-connector'); ?>">
                                <button type="button" class="slc-cp__trailer-play" aria-hidden="true">
                                    <svg viewBox="0 0 64 64" width="80" height="80"><circle cx="32" cy="32" r="32" fill="rgba(0,0,0,0.7)"/><polygon points="26,20 26,44 46,32" fill="#fff"/></svg>
                                </button>
                            </div>
                        </div>
                    <?php elseif ($thumbnail_url !== ''): ?>
                        <div class="slc-cp__herobig-trailer">
                            <img class="slc-cp__herobig-thumb" src="<?php echo esc_url($thumbnail_url); ?>" alt="" loading="lazy" />
                        </div>
                    <?php endif; ?>

                    <div class="slc-cp__herobig-cta">
                        <?php if ($offer['original'] !== ''): ?>
                            <div class="slc-cp__herobig-price-row">
                                <span class="slc-cp__price-old"><?php echo esc_html($offer['original']); ?></span>
                                <?php if ($offer['discount_label'] !== ''): ?>
                                    <span class="slc-cp__price-off"><?php echo esc_html($offer['discount_label']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="slc-cp__herobig-price-now"><?php echo esc_html($offer['current']); ?></div>
                        <?php if ($offer['installments'] !== ''): ?>
                            <div class="slc-cp__herobig-price-inst"><?php echo esc_html($offer['installments']); ?></div>
                        <?php endif; ?>
                        <a class="slc-cp__cta slc-cp__cta--xl" href="<?php echo esc_url($checkout_url); ?>"><?php echo esc_html($cta_label); ?></a>
                        <?php if ($offer['deadline'] !== ''): ?>
                            <div class="slc-cp__urgency slc-cp__urgency--center">
                                <span class="slc-cp__urgency-dot" aria-hidden="true"></span>
                                <?php echo esc_html($offer['deadline']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <ul class="slc-cp__herobig-meta">
                        <?php foreach (self::meta_chips($type_key, $hours, $total_min, $level, $language, $has_cert, $modules_count, $lessons_count) as $chip): ?>
                            <li class="slc-cp__meta-chip">
                                <span class="slc-cp__meta-icon" aria-hidden="true"><?php echo $chip['icon']; ?></span>
                                <?php echo esc_html($chip['label']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <?php /* ── BARRA SOCIAL PROOF ──────────────────────────────── */ ?>
            <?php if (!empty($social['bar'])): ?>
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
            <?php endif; ?>

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
                                    <h3 class="slc-cp__module-title"><?php echo esc_html($module['title'] ?? ''); ?></h3>
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

            <?php /* ── INSTRUCTORES ────────────────────────────────────── */ ?>
            <?php if (!empty($instructors)): ?>
            <section class="slc-cp__section slc-cp__section--soft">
                <div class="slc-cp__wrap slc-cp__wrap--narrow">
                    <h2 class="slc-cp__h2"><?php
                        echo esc_html(count($instructors) === 1
                            ? __('Tu instructor', 'studiahub-lms-connector')
                            : __('Tus instructores', 'studiahub-lms-connector'));
                    ?></h2>
                    <?php foreach ($instructors as $ins): ?>
                    <div class="slc-cp__instructor">
                        <div class="slc-cp__instructor-photo">
                            <?php if ($ins['photo'] !== ''): ?>
                                <img src="<?php echo esc_url($ins['photo']); ?>"
                                     alt="<?php echo esc_attr($ins['name']); ?>"
                                     width="110"
                                     height="110"
                                     loading="lazy"
                                     decoding="async">
                            <?php else: ?>
                                <span class="slc-cp__instructor-initial"><?php echo esc_html(mb_substr($ins['name'], 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="slc-cp__instructor-body">
                            <div class="slc-cp__instructor-name"><?php echo esc_html($ins['name']); ?></div>
                            <?php if ($ins['title'] !== ''): ?>
                                <div class="slc-cp__instructor-role"><?php echo esc_html($ins['title']); ?></div>
                            <?php endif; ?>
                            <?php if ($ins['bio'] !== ''): ?>
                                <div class="slc-cp__instructor-bio"><?php echo nl2br(esc_html($ins['bio'])); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
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
            <?php if (!empty($bonuses)): ?>
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
                                    <?php if ($bonus['desc'] !== ''): ?>
                                        <div class="slc-cp__bonus-desc"><?php echo esc_html($bonus['desc']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($bonus['value'] !== ''): ?>
                                    <span class="slc-cp__bonus-value"><?php echo esc_html($bonus['value']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── RESEÑAS DE ALUMNOS (solo si hay reales aprobadas) ── */ ?>
            <?php if (!empty($reviews)): ?>
            <section class="slc-cp__section slc-cp__section--soft">
                <div class="slc-cp__wrap">
                    <h2 class="slc-cp__h2"><?php esc_html_e('Lo que dicen nuestros alumnos', 'studiahub-lms-connector'); ?></h2>

                    <?php
                    $stats_count = (int) ($review_stats['count'] ?? count($reviews));
                    $stats_avg   = (float) ($review_stats['average'] ?? 0);
                    if ($stats_count > 0 && $stats_avg > 0):
                    ?>
                        <div class="slc-cp__reviews-summary">
                            <span class="slc-cp__reviews-avg"><?php echo esc_html(number_format($stats_avg, 1, ',', '')); ?></span>
                            <span class="slc-cp__stars" aria-hidden="true"><?php echo self::stars((int) round($stats_avg)); ?></span>
                            <span class="slc-cp__reviews-count">
                                <?php
                                printf(
                                    esc_html(
                                        _n(
                                            '%d reseña de alumnos',
                                            '%d reseñas de alumnos',
                                            $stats_count,
                                            'studiahub-lms-connector'
                                        )
                                    ),
                                    $stats_count
                                );
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="slc-cp__cards3">
                        <?php foreach (array_slice($reviews, 0, 6) as $r): ?>
                            <?php
                            $author  = (string) ($r['author'] ?? '');
                            $rating  = (int) ($r['rating'] ?? 0);
                            $comment = (string) ($r['comment'] ?? '');
                            $avatar  = (string) ($r['avatarUrl'] ?? '');
                            if ($author === '' || $rating < 1) { continue; }
                            ?>
                            <div class="slc-cp__testimonial">
                                <span class="slc-cp__stars" aria-hidden="true"><?php echo self::stars($rating); ?></span>
                                <?php if ($comment !== ''): ?>
                                    <p class="slc-cp__testimonial-text"><?php echo esc_html($comment); ?></p>
                                <?php endif; ?>
                                <div class="slc-cp__testimonial-author">
                                    <?php if ($avatar !== ''): ?>
                                        <img
                                            class="slc-cp__avatar slc-cp__avatar--img"
                                            src="<?php echo esc_url($avatar); ?>"
                                            alt=""
                                            width="40"
                                            height="40"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    <?php else: ?>
                                        <span class="slc-cp__avatar"><?php echo esc_html(mb_substr($author, 0, 1)); ?></span>
                                    <?php endif; ?>
                                    <span class="slc-cp__testimonial-name"><?php echo esc_html($author); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php /* ── GARANTÍA ────────────────────────────────────────── */ ?>
            <?php if ($guarantee !== null): ?>
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
            <?php endif; ?>

            <?php /* ── FAQ ─────────────────────────────────────────────── */ ?>
            <?php if (!empty($faq)): ?>
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
            <?php endif; ?>

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
                    <?php
                    $trust_parts = [];
                    if ($guarantee !== null) {
                        $trust_parts[] = $guarantee['short'];
                    }
                    if ($social['students_label'] !== '') {
                        $trust_parts[] = $social['students_label'];
                    }
                    if (!empty($trust_parts)):
                    ?>
                        <div class="slc-cp__final-trust"><?php echo esc_html(implode(' · ', $trust_parts)); ?></div>
                    <?php endif; ?>
                </div>
            </section>

        </article>
        <?php if ($trailer !== null): ?>
        <script>
        (function(){
            document.querySelectorAll('.slc-cp__trailer-facade').forEach(function(el){
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
                    el.classList.add('slc-cp__trailer-facade--active');
                };
                el.addEventListener('click', activate);
                el.addEventListener('keydown', function(e){
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
                });
            });
        })();
        </script>
        <?php endif; ?>
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

    /**
     * Formatea un número a string de precio. El payload trae números crudos
     * (compareAtPrice) — los pasamos por wc_price() para respetar la config
     * de moneda del WC del cliente; si WC no está, fallback a número formateado.
     */
    private static function format_price($amount): string {
        if (!is_numeric($amount)) {
            return '';
        }
        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price((float) $amount));
        }
        return '$' . number_format_i18n((float) $amount, 2);
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

    /**
     * Detecta el provider del trailer y devuelve [embed, thumb, provider].
     * Se usa para el facade pattern (thumbnail estática + play, iframe se
     * carga on-click). Devuelve null si la URL no es válida.
     */
    private static function parse_trailer(string $url): ?array {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('~(?:youtube\.com/watch\?(?:.*&)?v=|youtu\.be/|youtube\.com/shorts/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            $id = $m[1];
            return [
                'embed'    => 'https://www.youtube.com/embed/' . $id . '?autoplay=1&rel=0',
                'thumb'    => 'https://i.ytimg.com/vi/' . $id . '/maxresdefault.jpg',
                'provider' => 'youtube',
            ];
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return [
                'embed'    => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1',
                'thumb'    => '', // Vimeo thumbnail requiere oEmbed; cae al thumbnail del curso.
                'provider' => 'vimeo',
            ];
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return ['embed' => $url, 'thumb' => '', 'provider' => 'generic'];
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

    // ── DATA DE MARKETING (del payload LMS) ───────────────────────────────

    /**
     * Social proof: rating REAL de reseñas + count REAL de enrollments (o
     * label override del admin) + stats custom configurados por el tenant.
     *
     * Devuelve:
     *   - rating: float|null (null si no hay reviews aprobadas)
     *   - students_label: string ('' si no querés mostrar conteo)
     *   - bar: array de {num, label} para la franja bajo el hero
     */
    private static function data_social_proof(array $payload): array {
        $sp           = is_array($payload['socialProof'] ?? null) ? $payload['socialProof'] : [];
        $review_stats = is_array($payload['reviewStats'] ?? null) ? $payload['reviewStats'] : [];

        $rating_count = (int) ($review_stats['count'] ?? 0);
        $rating_avg   = (float) ($review_stats['average'] ?? 0);
        $rating       = ($rating_count > 0 && $rating_avg > 0) ? $rating_avg : null;

        $students_count = isset($sp['studentsCount']) && is_numeric($sp['studentsCount'])
            ? (int) $sp['studentsCount']
            : 0;
        $students_override = trim((string) ($sp['studentsLabel'] ?? ''));

        if ($students_override !== '') {
            $students_label = $students_override;
        } elseif ($students_count > 0) {
            $students_label = '+' . number_format_i18n($students_count) . ' alumnos';
        } else {
            $students_label = '';
        }

        // Stats para la barra: 1) alumnos real, 2) rating real, 3-5) custom (máx 3).
        $bar = [];
        if ($students_count > 0 || $students_override !== '') {
            $bar[] = [
                'num'   => $students_override !== ''
                    ? $students_override
                    : '+' . number_format_i18n($students_count),
                'label' => __('Alumnos inscriptos', 'studiahub-lms-connector'),
                'icon'  => 'fi-tr-users',
            ];
        }
        if ($rating !== null) {
            $bar[] = [
                'num'   => number_format($rating, 1, ',', '') . ' ★',
                'label' => __('Valoración promedio', 'studiahub-lms-connector'),
                'icon'  => '',
            ];
        }
        $custom_stats = is_array($sp['stats'] ?? null) ? $sp['stats'] : [];
        foreach (array_slice($custom_stats, 0, 3) as $stat) {
            if (!is_array($stat)) continue;
            $num   = trim((string) ($stat['num'] ?? ''));
            $label = trim((string) ($stat['label'] ?? ''));
            if ($num === '' || $label === '') continue;
            $bar[] = [
                'num'   => $num,
                'label' => $label,
                'icon'  => trim((string) ($stat['icon'] ?? '')),
            ];
        }

        return [
            'rating'         => $rating,
            'students_label' => $students_label,
            'bar'            => $bar,
        ];
    }

    /**
     * Pricing de oferta: precio tachado, descuento, cuotas, deadline relativo.
     * Todo opcional — strings vacíos cuando no aplica para que el template los
     * oculte.
     */
    private static function data_offer_pricing(array $payload, string $real_price): array {
        $price        = isset($payload['price']) && is_numeric($payload['price']) ? (float) $payload['price'] : null;
        // compareAtPrice ahora es texto libre multimoneda (ej "USD 199 / ARS 250.000").
        // Se renderiza tal cual, sin formato wc_price ni cálculo de descuento.
        $original     = trim((string) ($payload['compareAtPrice'] ?? ''));
        $installments = trim((string) ($payload['installmentsLabel'] ?? ''));
        $deadline_iso = trim((string) ($payload['offerDeadlineAt'] ?? ''));

        // Discount auto-calc removido: con texto libre multimoneda no es
        // confiable comparar. Si el admin quiere mostrar "-30%", lo carga
        // manualmente en highlightBadge.
        $discount_label = '';

        // Deadline: el LMS ya manda null si está vencida. Formateamos como
        // tiempo relativo en castellano: "Termina en 3 días".
        $deadline_label = '';
        if ($deadline_iso !== '') {
            $deadline_ts = strtotime($deadline_iso);
            $now         = function_exists('current_time') ? (int) current_time('timestamp') : time();
            if ($deadline_ts && $deadline_ts > $now) {
                $diff = function_exists('human_time_diff')
                    ? human_time_diff($now, $deadline_ts)
                    : floor(($deadline_ts - $now) / 86400) . ' días';
                $deadline_label = sprintf(
                    /* translators: %s = tiempo relativo, ej "3 días" */
                    __('Termina en %s', 'studiahub-lms-connector'),
                    $diff
                );
            }
        }

        return [
            'original'       => $original,
            'current'        => $real_price !== '' ? $real_price : self::format_price($price),
            'discount_label' => $discount_label,
            'installments'   => $installments,
            'deadline'       => $deadline_label,
        ];
    }

    /**
     * Bonus stack — viene como array del payload. Cada item: {title, desc, value}.
     * Devolvemos [] si no hay nada para que el template oculte la sección.
     */
    private static function data_bonuses(array $payload): array {
        $raw = is_array($payload['bonuses'] ?? null) ? $payload['bonuses'] : [];
        $out = [];
        foreach ($raw as $b) {
            if (!is_array($b)) continue;
            $title = trim((string) ($b['title'] ?? ''));
            if ($title === '') continue;
            $out[] = [
                'title'    => $title,
                'desc'     => trim((string) ($b['desc'] ?? '')),
                'value'    => trim((string) ($b['value'] ?? '')),
                'imageUrl' => trim((string) ($b['imageUrl'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Garantía: el tenant la puede desactivar (payload trae null). Devolvemos
     * null en ese caso y el template oculta tanto la sección como el mini-badge
     * del hero card.
     */
    private static function data_guarantee(array $payload): ?array {
        $g = $payload['guarantee'] ?? null;
        if (!is_array($g)) {
            return null;
        }
        $title = trim((string) ($g['title'] ?? ''));
        $text  = trim((string) ($g['text'] ?? ''));
        if ($title === '' && $text === '') {
            return null;
        }
        // `short` se deriva del title — lo usamos en el mini-badge del hero y
        // en la barra de trust del CTA final.
        return [
            'title' => $title,
            'text'  => $text,
            'short' => $title !== '' ? $title : $text,
        ];
    }

    /**
     * FAQ — el LMS ya mergea Course.faq → Tenant.defaultFaq → []. Devolvemos
     * [] si no hay nada y el template oculta la sección.
     */
    private static function data_faq(array $payload): array {
        $raw = is_array($payload['faq'] ?? null) ? $payload['faq'] : [];
        $out = [];
        foreach ($raw as $qa) {
            if (!is_array($qa)) continue;
            $q = trim((string) ($qa['q'] ?? ''));
            $a = trim((string) ($qa['a'] ?? ''));
            if ($q === '' || $a === '') continue;
            $out[] = ['q' => $q, 'a' => $a];
        }
        return $out;
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
        // Preferimos el nombre de la academia que viene del LMS (tenantName) sobre
        // el de WP — los clientes a veces tienen el sitio con nombre comercial
        // distinto a la academia.
        $tenant_name = trim((string) ($payload['tenantName'] ?? ''));
        if ($tenant_name === '') {
            $tenant_name = (string) get_bloginfo('name');
        }

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
        // Multiple instructors → array of Person entries para schema.org Course.
        $instructors_raw = is_array($payload['instructors'] ?? null) ? $payload['instructors'] : [];
        $persons = [];
        foreach ($instructors_raw as $i) {
            if (!is_array($i)) continue;
            $name = trim((string) ($i['name'] ?? ''));
            if ($name === '') continue;
            $person = ['@type' => 'Person', 'name' => $name];
            if (!empty($i['title'])) $person['jobTitle'] = (string) $i['title'];
            $persons[] = $person;
        }
        if (!empty($persons)) {
            $data['instructor'] = count($persons) === 1 ? $persons[0] : $persons;
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

        // aggregateRating real cuando hay reseñas aprobadas.
        $review_stats = is_array($payload['reviewStats'] ?? null) ? $payload['reviewStats'] : [];
        $rs_count = (int) ($review_stats['count'] ?? 0);
        $rs_avg   = (float) ($review_stats['average'] ?? 0);
        if ($rs_count > 0 && $rs_avg > 0) {
            $data['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => round($rs_avg, 1),
                'reviewCount' => $rs_count,
                'bestRating'  => 5,
                'worstRating' => 1,
            ];
        }

        return '<script type="application/ld+json">'
             . wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
             . '</script>';
    }

    // ── BRANDING DINÁMICO ─────────────────────────────────────────────────

    /**
     * Construye el `style="--var: ..."` inline a aplicar al wrapper del
     * shortcode. Lee branding.primaryColor / secondaryColor / fontFamily
     * y deriva el resto (tonos suaves, dark, gradientes). Los hex inválidos
     * caen al default — nunca rompemos el render.
     *
     * `branding.logoUrl` está disponible en el payload pero NO se renderiza:
     * la landing se embebe dentro del sitio del cliente que ya tiene su
     * propio header con logo.
     */
    public static function build_brand_style(array $branding): string {
        $primary   = self::sanitize_hex($branding['primaryColor'] ?? '', '#7950F2');
        $secondary = self::sanitize_hex($branding['secondaryColor'] ?? '', $primary);
        $font      = trim((string) ($branding['fontFamily'] ?? 'default'));

        $primary_rgb   = self::hex_to_rgb_str($primary);
        $secondary_rgb = self::hex_to_rgb_str($secondary);
        $primary_dark  = self::darken_hex($primary, 0.15);

        $vars = [
            '--shub-accent: '       . $primary,
            '--shub-accent-dark: '  . $primary_dark,
            '--shub-accent-soft: rgba(' . $primary_rgb . ', 0.10)',
            '--shub-accent-rgb: '   . $primary_rgb,
            '--shub-secondary: '    . $secondary,
            '--shub-secondary-rgb: '. $secondary_rgb,
            '--shub-cta-grad: linear-gradient(135deg, ' . $primary . ' 0%, ' . $primary_dark . ' 100%)',
            '--shub-hero-grad: linear-gradient(135deg, rgba(' . $primary_rgb . ',0.06) 0%, rgba(' . $secondary_rgb . ',0.10) 60%, rgba(' . $primary_rgb . ',0.04) 100%)',
            '--shub-avatar-grad: linear-gradient(135deg, ' . $secondary . ', ' . $primary . ')',
        ];

        if ($font !== '' && $font !== 'default') {
            // Quote families con espacios para que CSS las parsee bien.
            $needs_quotes = strpos($font, ' ') !== false && strpos($font, '"') === false && strpos($font, "'") === false;
            $font_value = $needs_quotes ? '"' . $font . '"' : $font;
            $vars[] = '--shub-font: ' . $font_value . ', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
        }

        return implode('; ', $vars);
    }

    /**
     * Si el tenant configuró una Google Font no genérica, la inyectamos en
     * el `<head>`. Solo weights 400/600/700 — la landing no necesita más.
     * Solo corre una vez por request aunque haya múltiples shortcodes en
     * la página.
     */
    private static function maybe_enqueue_google_font(string $font): void {
        static $loaded = [];
        $font = trim($font);
        if ($font === '' || $font === 'default') return;
        // System / safe fonts → no Google Fonts request.
        $system_fonts = ['system-ui', 'Inter', 'Roboto', 'Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Verdana'];
        if (in_array($font, $system_fonts, true) && $font !== 'Inter' && $font !== 'Roboto') return;
        if (isset($loaded[$font])) return;
        $loaded[$font] = true;

        $family = rawurlencode($font) . ':wght@400;600;700';
        $handle = 'slc-coursepage-font-' . sanitize_key($font);
        wp_enqueue_style(
            $handle,
            'https://fonts.googleapis.com/css2?family=' . str_replace('%20', '+', $family) . '&display=swap',
            [],
            null
        );
    }

    private static function sanitize_hex(string $hex, string $fallback): string {
        $hex = trim($hex);
        if ($hex === '') return $fallback;
        if ($hex[0] !== '#') $hex = '#' . $hex;
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) return strtoupper($hex);
        if (preg_match('/^#[0-9A-Fa-f]{3}$/', $hex)) {
            // Expand #ABC → #AABBCC
            $r = $hex[1]; $g = $hex[2]; $b = $hex[3];
            return strtoupper('#' . $r . $r . $g . $g . $b . $b);
        }
        return $fallback;
    }

    private static function hex_to_rgb_str(string $hex): string {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return $r . ', ' . $g . ', ' . $b;
    }

    // ── PUBLIC WRAPPERS (consumidos por Shortcode_CoursePitch) ────────────
    //
    // No queremos duplicar lógica de pricing / social proof / iconos entre
    // los dos shortcodes — la data se prepara igual, lo único que cambia
    // es el HTML/CSS. Exponemos thin wrappers que llaman a los métodos
    // privados existentes.

    public static function data_social_proof_public(array $payload): array { return self::data_social_proof($payload); }
    public static function data_offer_pricing_public(array $payload, string $real_price): array { return self::data_offer_pricing($payload, $real_price); }
    public static function data_bonuses_public(array $payload): array { return self::data_bonuses($payload); }
    public static function data_guarantee_public(array $payload): ?array { return self::data_guarantee($payload); }
    public static function data_faq_public(array $payload): array { return self::data_faq($payload); }
    public static function parse_trailer_public(string $url): ?array { return self::parse_trailer($url); }
    public static function format_duration_public(int $minutes): string { return self::format_duration($minutes); }
    public static function stars_public(float $rating): string { return self::stars($rating); }
    public static function lesson_icon_public(?string $type): string { return self::lesson_icon($type); }
    public static function maybe_enqueue_google_font_public(string $font): void { self::maybe_enqueue_google_font($font); }

    private static function darken_hex(string $hex, float $amount): string {
        $hex = ltrim($hex, '#');
        $r = max(0, (int) (hexdec(substr($hex, 0, 2)) * (1 - $amount)));
        $g = max(0, (int) (hexdec(substr($hex, 2, 2)) * (1 - $amount)));
        $b = max(0, (int) (hexdec(substr($hex, 4, 2)) * (1 - $amount)));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

}
