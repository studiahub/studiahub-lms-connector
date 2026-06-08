<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Metabox en la edit page del producto WC. Dos roles:
 *
 *  1. PRODUCTO SIMPLE (sincronizado desde el LMS): muestra read-only el course
 *      vinculado (`_lms_course_id`, escrito por el course-sync) con link al LMS.
 *
 *  2. COMBO (creado a mano en WC): un toggle "Este producto es un combo de
 *     cursos" + un multiselect de cursos del LMS. Al guardar persiste
 *     `_lms_is_combo` + `_lms_course_ids` (JSON array de course UUIDs). La venta
 *     del combo vive 100% en WP; el LMS recibe los N course IDs en el webhook y
 *     crea N enrollments con el mismo wcOrderId. El LMS NO modela el combo.
 */
final class Product_Metabox {
    /** 'yes' si el producto es un combo de cursos. */
    public const META_IS_COMBO  = '_lms_is_combo';
    /** JSON array de course UUIDs del LMS que incluye el combo. */
    public const META_COURSE_IDS = '_lms_course_ids';

    private const NONCE_ACTION = 'slc_save_combo';
    private const NONCE_FIELD  = 'slc_combo_nonce';

    public static function register_hooks(): void {
        add_action('add_meta_boxes_product', [self::class, 'add_box']);
        add_action('save_post_product', [self::class, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function add_box(): void {
        add_meta_box(
            'slc_lms_course_link',
            __('StudiaHub LMS', 'studiahub-lms-connector'),
            [self::class, 'render'],
            'product',
            'side',
            'high'
        );
    }

    /**
     * Solo cargamos el JS/CSS del combo en la pantalla de edición de producto.
     */
    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_style(
            'slc-combo-admin',
            SLC_PLUGIN_URL . 'assets/css/combo-admin.css',
            [],
            SLC_VERSION
        );

        wp_enqueue_script(
            'slc-combo-admin',
            SLC_PLUGIN_URL . 'assets/js/combo-admin.js',
            ['wp-api-fetch'],
            SLC_VERSION,
            true
        );

        wp_localize_script('slc-combo-admin', 'slcCombo', [
            'coursesEndpoint' => esc_url_raw(rest_url('studiahub/v1/lms-courses')),
            'restNonce'       => wp_create_nonce('wp_rest'),
            'i18n'            => [
                'loading'        => __('Cargando cursos del LMS…', 'studiahub-lms-connector'),
                'loadError'      => __('No se pudieron cargar los cursos. Reintentá o revisá la conexión con el LMS.', 'studiahub-lms-connector'),
                'empty'          => __('No hay cursos disponibles todavía. Sincronizá al menos un curso desde el LMS.', 'studiahub-lms-connector'),
                'fallbackNotice' => __('Mostrando cursos derivados de productos ya sincronizados. Si falta alguno, sincronizalo primero desde el LMS.', 'studiahub-lms-connector'),
                'searchPlaceholder' => __('Buscar curso…', 'studiahub-lms-connector'),
                'selectedCount'  => __('%d cursos seleccionados', 'studiahub-lms-connector'),
                'retry'          => __('Reintentar', 'studiahub-lms-connector'),
            ],
        ]);
    }

    public static function render(\WP_Post $post): void {
        $course_id = (string) get_post_meta($post->ID, '_lms_course_id', true);
        $is_combo  = get_post_meta($post->ID, self::META_IS_COMBO, true) === 'yes';

        // --- Bloque producto simple (sincronizado) ---
        // Solo lo mostramos si NO es combo y tiene course id del sync.
        if ($course_id !== '' && !$is_combo) {
            self::render_simple_block($course_id);
            echo '<hr style="margin:16px 0;border:0;border-top:1px solid #e2e4e7;">';
        }

        self::render_combo_block($post, $is_combo);
    }

    private static function render_simple_block(string $course_id): void {
        $lms_url  = (string) get_option(Settings::OPT_LMS_URL, '');
        $edit_url = $lms_url !== ''
            ? rtrim($lms_url, '/') . '/admin/courses/' . rawurlencode($course_id)
            : '';

        echo '<p><strong>' . esc_html__('Curso vinculado', 'studiahub-lms-connector') . '</strong><br>';
        echo '<code style="font-size:11px;">' . esc_html($course_id) . '</code></p>';

        if ($edit_url !== '') {
            echo '<p><a href="' . esc_url($edit_url) . '" target="_blank" rel="noopener" class="button" style="width:100%;text-align:center;">'
               . esc_html__('Editar en el LMS →', 'studiahub-lms-connector')
               . '</a></p>';
        }

        echo '<p style="color:#646970;font-size:11px;margin:8px 0 0;">'
           . esc_html__('El contenido del curso se gestiona desde el LMS. La landing lee los datos en vivo.', 'studiahub-lms-connector')
           . '</p>';
    }

    /**
     * Toggle "es combo" + multiselect. El multiselect lo hidrata combo-admin.js
     * via el endpoint /studiahub/v1/lms-courses; acá renderizamos el estado
     * inicial (los course ids ya seleccionados) como inputs hidden + un
     * contenedor que el JS reemplaza por la UI rica.
     */
    private static function render_combo_block(\WP_Post $post, bool $is_combo): void {
        $selected = self::get_selected_course_ids($post->ID);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        echo '<div class="slc-combo">';

        echo '<label class="slc-combo__toggle">';
        printf(
            '<input type="checkbox" id="slc-combo-enabled" name="%s" value="yes" %s> ',
            esc_attr(self::META_IS_COMBO),
            checked($is_combo, true, false)
        );
        echo '<span>' . esc_html__('Este producto es un combo de cursos', 'studiahub-lms-connector') . '</span>';
        echo '</label>';

        echo '<p class="slc-combo__hint">'
           . esc_html__('Al comprarlo, el alumno recibe acceso a todos los cursos seleccionados.', 'studiahub-lms-connector')
           . '</p>';

        // Región del picker. Hidden por CSS si el toggle está off (lo maneja el JS).
        echo '<div class="slc-combo__picker" data-selected="' . esc_attr((string) wp_json_encode(array_values($selected))) . '"'
           . ($is_combo ? '' : ' hidden') . '>';

        echo '<p class="slc-combo__label">' . esc_html__('Cursos incluidos', 'studiahub-lms-connector') . '</p>';

        // Fallback no-JS / estado inicial: los course ids seleccionados viajan
        // como hidden inputs para no perder data si el JS no corre. El JS los
        // limpia y re-emite según la selección del usuario.
        echo '<div class="slc-combo__mount" id="slc-combo-mount">';
        foreach ($selected as $cid) {
            printf(
                '<input type="hidden" class="slc-combo__seed" name="%s[]" value="%s">',
                esc_attr(self::META_COURSE_IDS),
                esc_attr($cid)
            );
        }
        echo '<p class="slc-combo__js-needed">'
           . esc_html__('Activá JavaScript para elegir los cursos.', 'studiahub-lms-connector')
           . '</p>';
        echo '</div>'; // .slc-combo__mount

        echo '</div>'; // .slc-combo__picker
        echo '</div>'; // .slc-combo
    }

    /**
     * Lee los course ids del combo desde el postmeta. Soporta el formato canónico
     * (JSON array string) y, defensivamente, un array nativo serializado por WP.
     *
     * @return array<int, string>
     */
    public static function get_selected_course_ids(int $product_id): array {
        $raw = get_post_meta($product_id, self::META_COURSE_IDS, true);

        $list = [];
        if (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $list = is_array($decoded) ? $decoded : [];
        }

        // Sanitizar: strings no vacíos, dedup, reindex.
        $clean = [];
        foreach ($list as $cid) {
            $cid = is_string($cid) ? trim($cid) : '';
            if ($cid !== '' && !in_array($cid, $clean, true)) {
                $clean[] = $cid;
            }
        }
        return $clean;
    }

    public static function save(int $post_id, \WP_Post $post): void {
        // Guards estándar de save_post.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if ($post->post_type !== 'product') {
            return;
        }
        if (!isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(sanitize_key($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $is_combo = isset($_POST[self::META_IS_COMBO]) && $_POST[self::META_IS_COMBO] === 'yes';

        if (!$is_combo) {
            // Apagar el combo: limpiamos ambos metas para no dejar basura ni que
            // el webhook lo siga tratando como combo.
            delete_post_meta($post_id, self::META_IS_COMBO);
            delete_post_meta($post_id, self::META_COURSE_IDS);
            return;
        }

        update_post_meta($post_id, self::META_IS_COMBO, 'yes');

        // Course ids: vienen como array de strings en $_POST[META_COURSE_IDS].
        $posted = isset($_POST[self::META_COURSE_IDS]) && is_array($_POST[self::META_COURSE_IDS])
            ? $_POST[self::META_COURSE_IDS]
            : [];

        $clean = [];
        foreach ($posted as $cid) {
            // Course ids del LMS son UUIDs/cuids: caracteres seguros, sin HTML.
            $cid = sanitize_text_field((string) wp_unslash($cid));
            $cid = trim($cid);
            if ($cid !== '' && !in_array($cid, $clean, true)) {
                $clean[] = $cid;
            }
        }

        // Persistimos como JSON array string → escalar, viaja limpio al order item
        // meta del webhook (donde WC espera valores escalares).
        update_post_meta($post_id, self::META_COURSE_IDS, wp_json_encode(array_values($clean)));
    }
}
