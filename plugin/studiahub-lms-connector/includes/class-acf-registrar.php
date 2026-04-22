<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra el field group ACF "StudiaHub — Datos del curso" sobre productos WC,
 * deja los campos en modo solo-lectura en la UI y muestra un aviso en los
 * productos ya conectados al LMS. Los valores los puebla el LMS vía el
 * endpoint course-sync (Fase 1.7).
 */
final class ACF_Registrar {
    private const FIELD_PREFIX = 'sh_course_';

    public static function register_hooks(): void {
        add_action('acf/init', [self::class, 'register_field_group']);
        add_filter('acf/prepare_field', [self::class, 'make_readonly']);
        add_action('admin_notices', [self::class, 'render_connected_notice']);
        add_action('admin_head', [self::class, 'render_readonly_css']);
    }

    /**
     * Bloquea la edición manual de los ACFs sh_course_* en WP admin.
     * `disabled` evita que el input se envíe en el submit, así `acf_save_post`
     * ni siquiera itera el campo y el valor original queda intacto en DB.
     *
     * NOTA: dentro de `acf_prepare_field`, ACF sobreescribe `$field['name']`
     * con el key. El nombre original queda en `$field['_name']`.
     */
    public static function make_readonly($field) {
        if (!is_array($field)) {
            return $field;
        }
        $original_name = $field['_name'] ?? '';
        if (strpos($original_name, self::FIELD_PREFIX) !== 0) {
            return $field;
        }
        $field['disabled'] = 1;
        $field['readonly'] = 1;
        return $field;
    }

    /**
     * CSS que refuerza el read-only de los ACFs sh_course_* en la edit page
     * de productos. Necesario sobre todo para el wysiwyg (TinyMCE no respeta
     * el `disabled` del textarea subyacente).
     */
    public static function render_readonly_css(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'product' || $screen->base !== 'post') {
            return;
        }
        echo '<style>.acf-field[data-name^="sh_course_"]{opacity:.7}'
            . '.acf-field[data-name^="sh_course_"] .acf-input{pointer-events:none}</style>';
    }

    /**
     * Aviso en la edit page de productos conectados al LMS.
     */
    public static function render_connected_notice(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'product' || $screen->base !== 'post') {
            return;
        }
        global $post;
        if (!$post || !function_exists('get_field')) {
            return;
        }
        $course_id = get_field('sh_course_id', $post->ID);
        if (empty($course_id)) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>⚠ Este producto está conectado al LMS.</strong> '
            . 'Los campos de "StudiaHub — Datos del curso" son de solo lectura y se sincronizan automáticamente desde el LMS.</p></div>';
    }

    public static function register_field_group(): void {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'                   => 'group_sh_course_data',
            'title'                 => 'StudiaHub — Datos del curso',
            'description'           => 'Datos del curso sincronizados desde el LMS. No editar manualmente.',
            'fields'                => self::get_fields(),
            'location'              => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'product',
                    ],
                ],
            ],
            'position'              => 'normal',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'menu_order'            => 0,
            'active'                => true,
            'show_in_rest'          => false,
        ]);
    }

    private static function get_fields(): array {
        return [
            [
                'key'          => 'field_sh_course_id',
                'label'        => 'ID del curso (LMS)',
                'name'         => 'sh_course_id',
                'type'         => 'text',
                'instructions' => 'UUID del curso en el LMS. Sincronizado, no modificar.',
                'wrapper'      => ['width' => '50'],
            ],
            [
                'key'           => 'field_sh_course_access_days',
                'label'         => 'Días de acceso',
                'name'          => 'sh_course_access_days',
                'type'          => 'number',
                'instructions'  => '0 = acceso de por vida.',
                'default_value' => 0,
                'min'           => 0,
                'step'          => 1,
                'wrapper'       => ['width' => '50'],
            ],
            [
                'key'       => 'field_sh_course_short_description',
                'label'     => 'Descripción corta',
                'name'      => 'sh_course_short_description',
                'type'      => 'textarea',
                'rows'      => 3,
                'new_lines' => 'br',
            ],
            [
                'key'          => 'field_sh_course_long_description',
                'label'        => 'Descripción larga',
                'name'         => 'sh_course_long_description',
                'type'         => 'wysiwyg',
                'tabs'         => 'visual',
                'toolbar'      => 'basic',
                'media_upload' => 0,
            ],
            [
                'key'     => 'field_sh_course_duration_hours',
                'label'   => 'Duración (horas)',
                'name'    => 'sh_course_duration_hours',
                'type'    => 'number',
                'min'     => 0,
                'step'    => 1,
                'wrapper' => ['width' => '33'],
            ],
            [
                'key'        => 'field_sh_course_level',
                'label'      => 'Nivel',
                'name'       => 'sh_course_level',
                'type'       => 'select',
                'choices'    => [
                    'Principiante' => 'Principiante',
                    'Intermedio'   => 'Intermedio',
                    'Avanzado'     => 'Avanzado',
                ],
                'allow_null' => 1,
                'wrapper'    => ['width' => '33'],
            ],
            [
                'key'     => 'field_sh_course_instructor',
                'label'   => 'Instructor',
                'name'    => 'sh_course_instructor',
                'type'    => 'text',
                'wrapper' => ['width' => '34'],
            ],
            [
                'key'          => 'field_sh_course_modules_count',
                'label'        => 'Módulos',
                'name'         => 'sh_course_modules_count',
                'type'         => 'number',
                'instructions' => 'Derivado. Informativo.',
                'min'          => 0,
                'step'         => 1,
                'wrapper'      => ['width' => '50'],
            ],
            [
                'key'          => 'field_sh_course_lessons_count',
                'label'        => 'Lecciones',
                'name'         => 'sh_course_lessons_count',
                'type'         => 'number',
                'instructions' => 'Derivado. Informativo.',
                'min'          => 0,
                'step'         => 1,
                'wrapper'      => ['width' => '50'],
            ],
        ];
    }
}
