<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Metabox liviano en la edit page del producto WC. Sin ACFs, el admin
 * perdía visibilidad de "qué curso es este producto". Este metabox
 * restaura esa visibilidad con un link directo al curso en el LMS.
 */
final class Product_Metabox {
    public static function register_hooks(): void {
        add_action('add_meta_boxes_product', [self::class, 'add_box']);
    }

    public static function add_box(): void {
        add_meta_box(
            'slc_lms_course_link',
            __('Curso del LMS', 'studiahub-lms-connector'),
            [self::class, 'render'],
            'product',
            'side',
            'high'
        );
    }

    public static function render(\WP_Post $post): void {
        $course_id = (string) get_post_meta($post->ID, '_lms_course_id', true);
        if ($course_id === '') {
            echo '<p style="color:#666;">' . esc_html__('Este producto aún no está sincronizado con un curso del LMS.', 'studiahub-lms-connector') . '</p>';
            return;
        }
        $lms_url  = (string) get_option(Settings::OPT_LMS_URL, '');
        $edit_url = $lms_url !== ''
            ? rtrim($lms_url, '/') . '/admin/courses/' . rawurlencode($course_id)
            : '';

        echo '<p><strong>' . esc_html__('Course ID:', 'studiahub-lms-connector') . '</strong><br>';
        echo '<code style="font-size:11px;">' . esc_html($course_id) . '</code></p>';

        if ($edit_url !== '') {
            echo '<p><a href="' . esc_url($edit_url) . '" target="_blank" rel="noopener" class="button button-primary" style="width:100%;text-align:center;">'
               . esc_html__('Editar en el LMS →', 'studiahub-lms-connector')
               . '</a></p>';
        }

        echo '<p style="color:#666;font-size:11px;margin-top:12px;">'
           . esc_html__('Todo el contenido del curso (instructor, temario, descripción, etc.) se gestiona desde el LMS. La landing del producto lee los datos en vivo.', 'studiahub-lms-connector')
           . '</p>';
    }
}
