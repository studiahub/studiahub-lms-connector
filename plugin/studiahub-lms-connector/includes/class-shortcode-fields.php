<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcodes GRANULARES — exponen los campos SUELTOS de un curso del LMS para
 * componer landings a medida (las arma el equipo de diseño de StudiaHub, no el
 * cliente).
 *
 * A diferencia de [studiahub_course_page] / [studiahub_course_pitch] (que rinden
 * la landing COMPLETA con markup + estilos propios), estos shortcodes exponen
 * DATA, no diseño:
 *
 *   1. Campos escalares → un único shortcode genérico:
 *        [studiahub_course_field field="title"]
 *        [studiahub_course_field field="durationHours"]
 *
 *   2. Arrays / objetos → un "loop" shortcode por cada uno, con TEMPLATE INTERNO
 *      (token substitution). El contenido del shortcode es el template por-item;
 *      se repite una vez por elemento reemplazando los tokens {{campo}}:
 *
 *        [studiahub_course_bonuses]
 *          <div class="mi-bono">
 *            <img src="{{imageUrl}}"><h4>{{title}}</h4><p>{{desc}}</p>
 *          </div>
 *        [/studiahub_course_bonuses]
 *
 *      Si el loop shortcode NO tiene template interno (self-closing), rinde un
 *      markup MÍNIMO semántico con clases namespaceadas `slc-` y CERO estilos.
 *
 * Toda la data sale del MISMO payload del LMS que usan los shortcodes monolíticos
 * (Landing_Fetch::get_payload → transient 15 min + stale-while-revalidate). El
 * producto WC solo aporta el postmeta `_lms_course_id` para mapear.
 *
 * Nombres de tokens = keys REALES del payload (ver docs/granular-shortcodes.md):
 *   - bonuses  → {{title}} {{desc}} {{value}} {{imageUrl}}
 *   - faq      → {{q}} {{a}}            (a = HTML-rich)
 *   - reviews  → {{author}} {{rating}} {{comment}} {{avatarUrl}} {{createdAt}} {{stars}}
 *   - instructors → {{name}} {{title}} {{bio}} {{photoUrl}} {{initial}}
 *   - outline (módulos) → {{title}} {{index}} {{lessonsCount}} {{durationMin}} {{duration}} {{isLive}} {{lessons}}
 *   - lessons (dentro de outline) → {{title}} {{type}} {{durationMin}} {{duration}} {{free}} {{index}}
 *   - stats (socialProof.stats) → {{num}} {{label}}
 *   - arrays de strings (learningOutcomes / targetAudience / includedMaterials / requirements)
 *       → {{text}} {{index}}
 *   - guarantee (objeto único) → {{title}} {{text}}
 */
final class Shortcode_Fields {

    /**
     * Tokens que van en atributos de URL (esc_url) o que son HTML-rich
     * (wp_kses_post). Todo lo demás cae al default esc_html. La clave es el
     * nombre del token; el valor, la estrategia de escape.
     */
    private const ESCAPE_MAP = [
        // URLs
        'imageUrl'  => 'url',
        'avatarUrl' => 'url',
        'photoUrl'  => 'url',
        // HTML-rich (el admin del LMS carga rich text en estos)
        'a'         => 'kses',   // respuesta de FAQ
        'bio'       => 'kses',   // bio del instructor
        'comment'   => 'kses',   // texto de la reseña
        // Markup interno seguro generado por nosotros (no input del usuario).
        'stars'     => 'raw',    // estrellas SVG de la reseña
    ];

    /**
     * Contexto del loop de módulos, para que el loop de lecciones anidado
     * ([studiahub_course_lessons] dentro de [studiahub_course_outline]) sepa
     * de qué módulo tomar las lecciones. Se setea antes de expandir cada módulo
     * y se limpia después.
     */
    private static ?array $current_module_lessons = null;

    public static function register_hooks(): void {
        // Escalar.
        add_shortcode('studiahub_course_field', [self::class, 'render_field']);

        // Loops de arrays de objetos.
        add_shortcode('studiahub_course_bonuses',     [self::class, 'render_bonuses']);
        add_shortcode('studiahub_course_faq',         [self::class, 'render_faq']);
        add_shortcode('studiahub_course_reviews',     [self::class, 'render_reviews']);
        add_shortcode('studiahub_course_instructors', [self::class, 'render_instructors']);
        add_shortcode('studiahub_course_stats',       [self::class, 'render_stats']);

        // Loops de arrays de strings.
        add_shortcode('studiahub_course_outcomes',  [self::class, 'render_outcomes']);
        add_shortcode('studiahub_course_audience',  [self::class, 'render_audience']);
        add_shortcode('studiahub_course_materials', [self::class, 'render_materials']);
        add_shortcode('studiahub_course_requirements', [self::class, 'render_requirements']);

        // Temario anidado (módulos + lecciones).
        add_shortcode('studiahub_course_outline', [self::class, 'render_outline']);
        add_shortcode('studiahub_course_lessons', [self::class, 'render_lessons']);

        // Objeto único.
        add_shortcode('studiahub_course_guarantee', [self::class, 'render_guarantee']);
    }

    // ── RESOLUCIÓN DE PAYLOAD ─────────────────────────────────────────────
    //
    // Misma lógica que los shortcodes monolíticos: `id` explícito o producto
    // del contexto WC actual → postmeta `_lms_course_id` → payload del LMS.

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
     * Devuelve el payload del curso para el `id` (o contexto), o null si no se
     * puede resolver. Cachea por request para no re-fetchar cuando hay varios
     * shortcodes granulares en la misma página.
     */
    private static function get_payload($id_att): ?array {
        static $cache = [];

        $product_id = self::resolve_product_id((string) $id_att);
        if (!$product_id) {
            return null;
        }
        if (array_key_exists($product_id, $cache)) {
            return $cache[$product_id];
        }

        $course_id = (string) get_post_meta($product_id, '_lms_course_id', true);
        if ($course_id === '') {
            $cache[$product_id] = null;
            return null;
        }

        $payload = Landing_Fetch::get_payload($course_id);
        $cache[$product_id] = is_array($payload) ? $payload : null;
        return $cache[$product_id];
    }

    // ── MOTOR DE TOKENS ───────────────────────────────────────────────────

    /**
     * Reemplaza los tokens {{campo}} de un template con los valores de `$item`,
     * escapando cada valor según su contexto (url / html-rich / html plano).
     *
     * - Tokens no presentes en el item → string vacío.
     * - El escape lo decide ESCAPE_MAP por nombre de token; default esc_html.
     * - Se hace de forma segura: solo se sustituyen tokens conocidos (los del
     *   item), nunca un str_replace ciego sobre input arbitrario.
     */
    private static function expand_template(string $template, array $item): string {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function ($m) use ($item) {
                $key = $m[1];
                if (!array_key_exists($key, $item)) {
                    return '';
                }
                return self::escape_value($key, $item[$key]);
            },
            $template
        );
    }

    /**
     * Escapa un valor de token según su clave. Los valores ya vienen sanitizados
     * como strings desde los normalizadores de cada loop.
     */
    private static function escape_value(string $key, $value): string {
        $strategy = self::ESCAPE_MAP[$key] ?? 'html';
        $value = (string) $value;
        switch ($strategy) {
            case 'url':
                return esc_url($value);
            case 'kses':
                return wp_kses_post($value);
            case 'raw':
                // Solo para markup interno que generamos nosotros (ej estrellas
                // SVG). NUNCA mapear input del usuario a 'raw'.
                return $value;
            case 'html':
            default:
                return esc_html($value);
        }
    }

    /**
     * Renderiza un loop. Para cada item:
     *   - Si hay template interno (content) → expand_template.
     *   - Si no → el fallback mínimo semántico ($fallback callback).
     *
     * @param array         $items    items ya normalizados (arrays asociativos).
     * @param string|null   $content  template interno crudo del shortcode.
     * @param callable      $fallback fn(array $item): string — markup mínimo.
     * @param string        $wrap_class clase del <ul> contenedor del fallback.
     */
    private static function render_loop(array $items, ?string $content, callable $fallback, string $wrap_class): string {
        if (empty($items)) {
            return '';
        }

        $has_template = is_string($content) && trim($content) !== '';

        if ($has_template) {
            $out = '';
            foreach ($items as $item) {
                // do_shortcode: permite shortcodes anidados en el template
                // (ej [studiahub_course_lessons] dentro del outline).
                $out .= do_shortcode(self::expand_template($content, $item));
            }
            return $out;
        }

        // Fallback: markup mínimo, semántico, sin estilos.
        $out = '<ul class="' . esc_attr($wrap_class) . '">';
        foreach ($items as $item) {
            $out .= $fallback($item);
        }
        $out .= '</ul>';
        return $out;
    }

    // ── ESCALAR ───────────────────────────────────────────────────────────

    /**
     * [studiahub_course_field field="title" id="42"]
     *
     * Devuelve el valor pelado del campo escalar. HTML-rich (longDescription)
     * pasa por wp_kses_post; el resto por esc_html. Campo inexistente/vacío →
     * string vacío, sin warnings.
     */
    public static function render_field($atts): string {
        $atts = shortcode_atts([
            'field' => '',
            'id'    => '',
        ], $atts, 'studiahub_course_field');

        $field = trim((string) $atts['field']);
        if ($field === '') {
            return '';
        }

        $payload = self::get_payload($atts['id']);
        if (!is_array($payload)) {
            return '';
        }

        // Campos VIRTUALES derivados de reviewStats (que es un objeto → el lookup
        // escalar de abajo lo saltearía). Exponen el resumen "4.8 · 120 reseñas".
        // Sin reseñas → vacío, para que el bloque se oculte solo.
        if ($field === 'reviewsAverage' || $field === 'reviewsCount') {
            $stats = is_array($payload['reviewStats'] ?? null) ? $payload['reviewStats'] : [];
            $count = (int) ($stats['count'] ?? 0);
            if ($count < 1) {
                return '';
            }
            if ($field === 'reviewsCount') {
                return esc_html((string) $count);
            }
            return esc_html((string) ($stats['average'] ?? ''));
        }

        // Solo campos escalares (string / int / float / bool). Los arrays y
        // objetos se exponen con sus loop shortcodes dedicados.
        if (!array_key_exists($field, $payload)) {
            return '';
        }
        $value = $payload[$field];
        if (is_array($value) || $value === null) {
            return '';
        }

        // Booleans → texto legible (útil para hasCertificate / salesClosed).
        if (is_bool($value)) {
            return $value
                ? esc_html__('Sí', 'studiahub-lms-connector')
                : esc_html__('No', 'studiahub-lms-connector');
        }

        // longDescription es el único campo escalar HTML-rich del payload.
        if ($field === 'longDescription') {
            return wp_kses_post((string) $value);
        }

        return esc_html((string) $value);
    }

    // ── ARRAYS DE OBJETOS ─────────────────────────────────────────────────

    /** [studiahub_course_bonuses] — {{title}} {{desc}} {{value}} {{imageUrl}} */
    public static function render_bonuses($atts, $content = null): string {
        $payload = self::get_payload(self::id_att($atts));
        if (!is_array($payload)) {
            return '';
        }
        $items = [];
        foreach ((is_array($payload['bonuses'] ?? null) ? $payload['bonuses'] : []) as $b) {
            if (!is_array($b)) continue;
            $title = trim((string) ($b['title'] ?? ''));
            if ($title === '') continue;
            $items[] = [
                'title'    => $title,
                'desc'     => trim((string) ($b['desc'] ?? '')),
                'value'    => trim((string) ($b['value'] ?? '')),
                'imageUrl' => trim((string) ($b['imageUrl'] ?? '')),
            ];
        }
        return self::render_loop($items, $content, static function ($item) {
            $out = '<li class="slc-bonus">';
            $out .= '<span class="slc-bonus__title">' . esc_html($item['title']) . '</span>';
            if ($item['desc'] !== '') {
                $out .= '<span class="slc-bonus__desc">' . esc_html($item['desc']) . '</span>';
            }
            if ($item['value'] !== '') {
                $out .= '<span class="slc-bonus__value">' . esc_html($item['value']) . '</span>';
            }
            return $out . '</li>';
        }, 'slc-bonuses');
    }

    /** [studiahub_course_faq] — {{q}} {{a}} (a = HTML-rich) */
    public static function render_faq($atts, $content = null): string {
        $payload = self::get_payload(self::id_att($atts));
        if (!is_array($payload)) {
            return '';
        }
        $items = [];
        foreach ((is_array($payload['faq'] ?? null) ? $payload['faq'] : []) as $qa) {
            if (!is_array($qa)) continue;
            $q = trim((string) ($qa['q'] ?? ''));
            $a = trim((string) ($qa['a'] ?? ''));
            if ($q === '' || $a === '') continue;
            $items[] = ['q' => $q, 'a' => $a];
        }
        return self::render_loop($items, $content, static function ($item) {
            return '<li class="slc-faq__item">'
                . '<span class="slc-faq__q">' . esc_html($item['q']) . '</span>'
                . '<div class="slc-faq__a">' . wp_kses_post($item['a']) . '</div>'
                . '</li>';
        }, 'slc-faq');
    }

    /** [studiahub_course_reviews] — {{author}} {{rating}} {{comment}} {{avatarUrl}} {{createdAt}} {{stars}} */
    public static function render_reviews($atts, $content = null): string {
        $payload = self::get_payload(self::id_att($atts));
        if (!is_array($payload)) {
            return '';
        }
        $items = [];
        foreach ((is_array($payload['reviews'] ?? null) ? $payload['reviews'] : []) as $r) {
            if (!is_array($r)) continue;
            $author = trim((string) ($r['author'] ?? ''));
            $rating = (int) ($r['rating'] ?? 0);
            if ($author === '' || $rating < 1) continue;
            $items[] = [
                'author'    => $author,
                'rating'    => (string) $rating,
                'comment'   => trim((string) ($r['comment'] ?? '')),
                'avatarUrl' => trim((string) ($r['avatarUrl'] ?? '')),
                'createdAt' => trim((string) ($r['createdAt'] ?? '')),
                // Conveniencia: estrellas ya renderizadas (SVG). Como es markup
                // seguro generado por nosotros, se inyecta raw (no lo escapamos).
                'stars'     => self::stars_html($rating),
            ];
        }
        // `stars` es markup interno → lo dejamos pasar sin escapar. Reusa el
        // motor pero excluye ese token del escape via ESCAPE_MAP especial.
        return self::render_loop($items, $content, static function ($item) {
            $out = '<li class="slc-review">';
            $out .= '<span class="slc-review__stars">' . $item['stars'] . '</span>';
            if ($item['comment'] !== '') {
                $out .= '<p class="slc-review__comment">' . wp_kses_post($item['comment']) . '</p>';
            }
            $out .= '<span class="slc-review__author">' . esc_html($item['author']) . '</span>';
            return $out . '</li>';
        }, 'slc-reviews');
    }

    /** [studiahub_course_instructors] — {{name}} {{title}} {{bio}} {{photoUrl}} {{initial}} */
    public static function render_instructors($atts, $content = null): string {
        $payload = self::get_payload(self::id_att($atts));
        if (!is_array($payload)) {
            return '';
        }
        $items = [];
        foreach ((is_array($payload['instructors'] ?? null) ? $payload['instructors'] : []) as $i) {
            if (!is_array($i)) continue;
            $name = trim((string) ($i['name'] ?? ''));
            if ($name === '') continue;
            $items[] = [
                'name'     => $name,
                'title'    => trim((string) ($i['title'] ?? '')),
                'bio'      => trim((string) ($i['bio'] ?? '')),
                'photoUrl' => trim((string) ($i['photoUrl'] ?? '')),
                'initial'  => function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1),
            ];
        }
        return self::render_loop($items, $content, static function ($item) {
            $out = '<li class="slc-instructor">';
            $out .= '<span class="slc-instructor__name">' . esc_html($item['name']) . '</span>';
            if ($item['title'] !== '') {
                $out .= '<span class="slc-instructor__title">' . esc_html($item['title']) . '</span>';
            }
            if ($item['bio'] !== '') {
                $out .= '<div class="slc-instructor__bio">' . wp_kses_post($item['bio']) . '</div>';
            }
            return $out . '</li>';
        }, 'slc-instructors');
    }

    /** [studiahub_course_stats] — socialProof.stats: {{num}} {{label}} */
    public static function render_stats($atts, $content = null): string {
        $payload = self::get_payload(self::id_att($atts));
        if (!is_array($payload)) {
            return '';
        }
        $sp = is_array($payload['socialProof'] ?? null) ? $payload['socialProof'] : [];
        $items = [];
        foreach ((is_array($sp['stats'] ?? null) ? $sp['stats'] : []) as $s) {
            if (!is_array($s)) continue;
            $num   = trim((string) ($s['num'] ?? ''));
            $label = trim((string) ($s['label'] ?? ''));
            if ($num === '' || $label === '') continue;
            $items[] = ['num' => $num, 'label' => $label];
        }
        return self::render_loop($items, $content, static function ($item) {
            return '<li class="slc-stat">'
                . '<span class="slc-stat__num">' . esc_html($item['num']) . '</span>'
                . '<span class="slc-stat__label">' . esc_html($item['label']) . '</span>'
                . '</li>';
        }, 'slc-stats');
    }

    // ── ARRAYS DE STRINGS ─────────────────────────────────────────────────
    //
    // learningOutcomes / targetAudience / includedMaterials / requirements.
    // Token único {{text}} (+ {{index}}, 1-based).

    /** [studiahub_course_outcomes] — {{text}} {{index}} */
    public static function render_outcomes($atts, $content = null): string {
        return self::render_string_list('learningOutcomes', $atts, $content, 'slc-outcomes', 'slc-outcome');
    }

    /** [studiahub_course_audience] — {{text}} {{index}} */
    public static function render_audience($atts, $content = null): string {
        return self::render_string_list('targetAudience', $atts, $content, 'slc-audience', 'slc-audience__item');
    }

    /** [studiahub_course_materials] — {{text}} {{index}} */
    public static function render_materials($atts, $content = null): string {
        return self::render_string_list('includedMaterials', $atts, $content, 'slc-materials', 'slc-material');
    }

    /** [studiahub_course_requirements] — {{text}} {{index}} */
    public static function render_requirements($atts, $content = null): string {
        return self::render_string_list('requirements', $atts, $content, 'slc-requirements', 'slc-requirement');
    }

    private static function render_string_list(string $key, $atts, $content, string $wrap_class, string $item_class): string {
        $payload = self::get_payload(self::id_att($atts));
        if (!is_array($payload)) {
            return '';
        }
        $raw = is_array($payload[$key] ?? null) ? $payload[$key] : [];
        $items = [];
        $index = 0;
        foreach ($raw as $entry) {
            // Los items son strings, pero toleramos {text: "..."} por robustez.
            $text = is_array($entry) ? trim((string) ($entry['text'] ?? '')) : trim((string) $entry);
            if ($text === '') continue;
            $index++;
            $items[] = ['text' => $text, 'index' => (string) $index];
        }
        return self::render_loop($items, $content, static function ($item) use ($item_class) {
            return '<li class="' . esc_attr($item_class) . '">' . esc_html($item['text']) . '</li>';
        }, $wrap_class);
    }

    // ── TEMARIO ANIDADO (outline) ─────────────────────────────────────────

    /**
     * [studiahub_course_outline] — loop de MÓDULOS.
     * Tokens: {{title}} {{index}} {{lessonsCount}} {{durationMin}} {{duration}}
     *         {{isLive}} {{lessons}}
     *
     * El token {{lessons}} NO existe como valor: se usa anidando el shortcode
     * [studiahub_course_lessons] dentro del template del módulo. Ese shortcode
     * lee las lecciones del módulo que se está expandiendo (contexto estático).
     * El fallback (sin template) rinde módulos con su lista de lecciones anidada.
     */
    public static function render_outline($atts, $content = null): string {
        $payload = self::get_payload(self::id_att($atts));
        if (!is_array($payload)) {
            return '';
        }
        $outline = is_array($payload['outline'] ?? null) ? $payload['outline'] : [];
        if (empty($outline)) {
            return '';
        }

        $has_template = is_string($content) && trim($content) !== '';
        $index = 0;
        $out = $has_template ? '' : '<ul class="slc-outline">';

        foreach ($outline as $module) {
            if (!is_array($module)) continue;
            $lessons = is_array($module['lessons'] ?? null) ? $module['lessons'] : [];
            $index++;
            $dur_min = isset($module['durationMin']) && is_numeric($module['durationMin'])
                ? (int) $module['durationMin']
                : 0;

            $module_item = [
                'title'        => trim((string) ($module['title'] ?? '')),
                'index'        => (string) $index,
                'lessonsCount' => (string) count($lessons),
                'durationMin'  => (string) $dur_min,
                'duration'     => $dur_min > 0 ? self::format_duration($dur_min) : '',
                'isLive'       => !empty($module['isLive'])
                    ? esc_html__('Sí', 'studiahub-lms-connector')
                    : esc_html__('No', 'studiahub-lms-connector'),
            ];

            // Publicamos las lecciones del módulo actual para el loop anidado.
            self::$current_module_lessons = $lessons;

            if ($has_template) {
                $out .= do_shortcode(self::expand_template($content, $module_item));
            } else {
                $out .= '<li class="slc-outline__module">';
                if ($module_item['title'] !== '') {
                    $out .= '<span class="slc-outline__module-title">' . esc_html($module_item['title']) . '</span>';
                }
                $out .= self::render_lessons_fallback($lessons);
                $out .= '</li>';
            }

            self::$current_module_lessons = null;
        }

        if (!$has_template) {
            $out .= '</ul>';
        }
        return $out;
    }

    /**
     * [studiahub_course_lessons] — loop de LECCIONES del módulo actual.
     * SOLO válido dentro del template de [studiahub_course_outline].
     * Tokens: {{title}} {{type}} {{durationMin}} {{duration}} {{free}} {{index}}
     */
    public static function render_lessons($atts, $content = null): string {
        if (!is_array(self::$current_module_lessons)) {
            return '';
        }
        $items = self::normalize_lessons(self::$current_module_lessons);
        return self::render_loop($items, $content, static function ($item) {
            $out = '<li class="slc-lesson">';
            $out .= '<span class="slc-lesson__title">' . esc_html($item['title']) . '</span>';
            if ($item['duration'] !== '') {
                $out .= '<span class="slc-lesson__duration">' . esc_html($item['duration']) . '</span>';
            }
            return $out . '</li>';
        }, 'slc-lessons');
    }

    private static function render_lessons_fallback(array $lessons): string {
        $items = self::normalize_lessons($lessons);
        if (empty($items)) {
            return '';
        }
        $out = '<ul class="slc-lessons">';
        foreach ($items as $item) {
            $out .= '<li class="slc-lesson">';
            $out .= '<span class="slc-lesson__title">' . esc_html($item['title']) . '</span>';
            if ($item['duration'] !== '') {
                $out .= '<span class="slc-lesson__duration">' . esc_html($item['duration']) . '</span>';
            }
            $out .= '</li>';
        }
        return $out . '</ul>';
    }

    private static function normalize_lessons(array $lessons): array {
        $items = [];
        $index = 0;
        foreach ($lessons as $l) {
            if (!is_array($l)) continue;
            $title = trim((string) ($l['title'] ?? ''));
            if ($title === '') continue;
            $index++;
            $dur = isset($l['durationMin']) && is_numeric($l['durationMin']) ? (int) $l['durationMin'] : 0;
            $items[] = [
                'title'       => $title,
                'type'        => trim((string) ($l['type'] ?? '')),
                'durationMin' => (string) $dur,
                'duration'    => $dur > 0 ? self::format_duration($dur) : '',
                'free'        => !empty($l['free'])
                    ? esc_html__('Sí', 'studiahub-lms-connector')
                    : esc_html__('No', 'studiahub-lms-connector'),
                'index'       => (string) $index,
            ];
        }
        return $items;
    }

    // ── OBJETO ÚNICO ──────────────────────────────────────────────────────

    /**
     * [studiahub_course_guarantee] — objeto único {title, text}. Tokens:
     * {{title}} {{text}}. Si el tenant deshabilitó la garantía → string vacío.
     */
    public static function render_guarantee($atts, $content = null): string {
        $payload = self::get_payload(self::id_att($atts));
        if (!is_array($payload)) {
            return '';
        }
        $g = $payload['guarantee'] ?? null;
        if (!is_array($g)) {
            return '';
        }
        $title = trim((string) ($g['title'] ?? ''));
        $text  = trim((string) ($g['text'] ?? ''));
        if ($title === '' && $text === '') {
            return '';
        }
        $item = ['title' => $title, 'text' => $text];

        if (is_string($content) && trim($content) !== '') {
            return self::expand_template($content, $item);
        }

        // Fallback mínimo (no es un loop → un solo bloque, no <ul>).
        $out = '<div class="slc-guarantee">';
        if ($title !== '') {
            $out .= '<span class="slc-guarantee__title">' . esc_html($title) . '</span>';
        }
        if ($text !== '') {
            $out .= '<p class="slc-guarantee__text">' . esc_html($text) . '</p>';
        }
        return $out . '</div>';
    }

    // ── UTILIDADES ────────────────────────────────────────────────────────

    /** Extrae el atributo `id` de forma segura desde los atts crudos. */
    private static function id_att($atts): string {
        if (is_array($atts) && isset($atts['id'])) {
            return (string) $atts['id'];
        }
        return '';
    }

    private static function format_duration(int $minutes): string {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m === 0 ? $h . ' h' : $h . ' h ' . $m . ' min';
    }

    /** 5 estrellas SVG (llenas hasta $rating). Markup interno seguro. */
    private static function stars_html(int $rating): string {
        $rating = max(0, min(5, $rating));
        $out = '';
        for ($i = 1; $i <= 5; $i++) {
            $fill = $i <= $rating ? 'currentColor' : 'none';
            $out .= '<svg viewBox="0 0 16 16" width="16" height="16" fill="' . $fill . '" stroke="currentColor" stroke-width="1.3" class="slc-star"><polygon points="8,1.5 10,6 14.5,6.3 11,9.3 12.2,13.8 8,11.2 3.8,13.8 5,9.3 1.5,6.3 6,6"/></svg>';
        }
        return $out;
    }
}
