<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * POST /wp-json/studiahub/v1/course-sync
 * Crea o actualiza un producto WC a partir de un curso del LMS.
 * LMS es single source of truth — push unidireccional.
 */
final class REST_Course_Sync {
    private const VALID_LEVELS       = ['Principiante', 'Intermedio', 'Avanzado'];
    private const VALID_COURSE_TYPES = ['on_demand', 'live', 'in_person', 'hybrid'];

    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(
            'studiahub/v1',
            '/course-sync',
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [Auth::class, 'verify_request'],
            ]
        );
    }

    public static function handle(\WP_REST_Request $request) {
        $body      = $request->get_json_params();
        $validated = self::validate_payload($body);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $wcProductId = $validated['wcProductId'];
        $course      = $validated['course'];

        if ($wcProductId === null) {
            $result = self::create_product($course);
            if (is_wp_error($result)) {
                return $result;
            }
            return new \WP_REST_Response(['wcProductId' => $result, 'status' => 'created'], 200);
        }

        $result = self::update_product($wcProductId, $course);
        if (is_wp_error($result)) {
            return $result;
        }
        return new \WP_REST_Response(['wcProductId' => $result, 'status' => 'updated'], 200);
    }

    private static function validate_payload($body) {
        if (!is_array($body)) {
            return new \WP_Error('slc_bad_body', 'Body debe ser JSON object.', ['status' => 400]);
        }
        $course = $body['course'] ?? null;
        if (!is_array($course)) {
            return new \WP_Error('slc_missing_course', 'Falta el objeto course.', ['status' => 400]);
        }
        if (empty($course['lmsId']) || !is_string($course['lmsId'])) {
            return new \WP_Error('slc_invalid_lms_id', 'course.lmsId requerido (string).', ['status' => 400]);
        }
        if (empty($course['title']) || !is_string($course['title'])) {
            return new \WP_Error('slc_invalid_title', 'course.title requerido (string).', ['status' => 400]);
        }

        $wcProductId = $body['wcProductId'] ?? null;
        if ($wcProductId !== null && !self::is_positive_int($wcProductId)) {
            return new \WP_Error('slc_invalid_product_id', 'wcProductId debe ser int positivo o null.', ['status' => 400]);
        }

        $level = $course['level'] ?? null;
        if ($level !== null && $level !== '' && !in_array($level, self::VALID_LEVELS, true)) {
            return new \WP_Error(
                'slc_invalid_level',
                'course.level debe ser uno de: ' . implode(', ', self::VALID_LEVELS) . '.',
                ['status' => 400]
            );
        }

        $courseType = $course['courseType'] ?? null;
        if ($courseType !== null && $courseType !== '' && !in_array($courseType, self::VALID_COURSE_TYPES, true)) {
            return new \WP_Error(
                'slc_invalid_course_type',
                'course.courseType debe ser uno de: ' . implode(', ', self::VALID_COURSE_TYPES) . '.',
                ['status' => 400]
            );
        }

        return [
            'wcProductId' => $wcProductId !== null ? (int) $wcProductId : null,
            'course'      => $course,
        ];
    }

    private static function is_positive_int($v): bool {
        if (is_int($v)) {
            return $v > 0;
        }
        return is_string($v) && ctype_digit($v) && (int) $v > 0;
    }

    private static function create_product(array $course) {
        if (!class_exists('WC_Product_Simple')) {
            return new \WP_Error('slc_wc_missing', 'WooCommerce no está disponible.', ['status' => 500]);
        }

        $product_id = 0;
        try {
            $product = new \WC_Product_Simple();
            $product->set_name($course['title']);
            // Descripciones se guardan SOLO en ACFs sh_course_*. Los campos
            // nativos quedan vacíos; la landing las lee desde Elementor.
            $product->set_description('');
            $product->set_short_description('');
            $product->set_status('draft');
            $product->set_catalog_visibility('visible');
            $product->set_virtual(true);
            $product->set_sold_individually(true);
            $product->set_manage_stock(false);
            if (isset($course['price']) && is_numeric($course['price'])) {
                $product->set_regular_price((string) $course['price']);
            }
            $product_id = (int) $product->save();
            if ($product_id <= 0) {
                throw new \RuntimeException('WC_Product_Simple::save() retornó 0.');
            }

            self::set_lms_meta($product_id, $course);
            self::set_product_acfs($product_id, $course);

            if (!empty($course['category'])) {
                self::assign_category($product_id, (string) $course['category'], false);
            }

            if (!empty($course['thumbnailUrl'])) {
                $thumb = self::download_and_set_thumbnail($product_id, (string) $course['thumbnailUrl']);
                if (is_wp_error($thumb)) {
                    throw new \RuntimeException('Thumbnail: ' . $thumb->get_error_message());
                }
            }

            return $product_id;

        } catch (\Throwable $e) {
            if ($product_id > 0) {
                wp_delete_post($product_id, true);
            }
            return new \WP_Error('slc_create_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    private static function update_product(int $product_id, array $course) {
        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product') {
            return new \WP_Error('slc_product_not_found', 'Producto no existe en WC.', ['status' => 404]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('slc_product_not_found', 'wc_get_product retornó null.', ['status' => 404]);
        }

        $product->set_name($course['title']);
        // Descripciones se guardan SOLO en ACFs. Limpiamos las nativas en cada
        // sync para evitar drift si alguien las llenó desde WP por error.
        $product->set_description('');
        $product->set_short_description('');
        if (isset($course['price']) && is_numeric($course['price'])) {
            $product->set_regular_price((string) $course['price']);
        }
        $product->save();
        // No tocamos: status, catalog_visibility, stock, sku — los controla el admin de WC.

        self::set_lms_meta($product_id, $course);
        self::set_product_acfs($product_id, $course);

        if (!empty($course['category'])) {
            self::assign_category($product_id, (string) $course['category'], true);
        }

        if (!empty($course['thumbnailUrl'])) {
            $existing_hash = (string) get_post_meta($product_id, '_lms_thumbnail_hash', true);
            $new_hash      = md5((string) $course['thumbnailUrl']);
            if ($existing_hash !== $new_hash) {
                $thumb = self::download_and_set_thumbnail($product_id, (string) $course['thumbnailUrl']);
                if (is_wp_error($thumb)) {
                    return $thumb;
                }
            }
        }

        return $product_id;
    }

    private static function set_lms_meta(int $product_id, array $course): void {
        update_post_meta($product_id, '_lms_course_id', (string) $course['lmsId']);
        update_post_meta($product_id, '_lms_access_days', (int) ($course['accessDays'] ?? 0));
    }

    private static function set_product_acfs(int $product_id, array $course): void {
        if (!function_exists('update_field')) {
            return;
        }

        $map = [
            'sh_course_id'                    => (string) ($course['lmsId'] ?? ''),
            'sh_course_subtitle'              => (string) ($course['subtitle'] ?? ''),
            'sh_course_short_description'     => (string) ($course['shortDescription'] ?? ''),
            'sh_course_long_description'      => (string) ($course['longDescription'] ?? ''),
            'sh_course_course_type'           => (string) ($course['courseType'] ?? ''),
            'sh_course_duration_hours'        => isset($course['durationHours']) ? (int) $course['durationHours'] : 0,
            'sh_course_level'                 => (string) ($course['level'] ?? ''),
            'sh_course_language'              => (string) ($course['language'] ?? ''),
            'sh_course_has_certificate'       => !empty($course['hasCertificate']) ? 1 : 0,
            'sh_course_highlight_badge'       => (string) ($course['highlightBadge'] ?? ''),
            'sh_course_price_display'         => (string) ($course['priceDisplay'] ?? ''),
            'sh_course_cta_label'             => (string) ($course['ctaLabel'] ?? ''),
            'sh_course_trailer_url'           => (string) ($course['trailerUrl'] ?? ''),
            'sh_course_instructor'            => (string) ($course['instructor'] ?? ''),
            'sh_course_instructor_title'      => (string) ($course['instructorTitle'] ?? ''),
            'sh_course_instructor_bio'        => (string) ($course['instructorBio'] ?? ''),
            'sh_course_instructor_photo_url'  => (string) ($course['instructorPhotoUrl'] ?? ''),
            'sh_course_modules_count'         => isset($course['modulesCount']) ? (int) $course['modulesCount'] : 0,
            'sh_course_lessons_count'         => isset($course['lessonsCount']) ? (int) $course['lessonsCount'] : 0,
            'sh_course_total_duration_min'    => isset($course['totalDurationMin']) ? (int) $course['totalDurationMin'] : 0,
            'sh_course_access_days'           => isset($course['accessDays']) ? (int) $course['accessDays'] : 0,
        ];
        foreach ($map as $field => $value) {
            update_field($field, $value, $product_id);
        }

        // Listas: guardadas como JSON. Las consume el shortcode
        // [studiahub_course_list field="..."].
        $list_fields = [
            'learningOutcomes'  => 'sh_course_learning_outcomes',
            'targetAudience'    => 'sh_course_target_audience',
            'includedMaterials' => 'sh_course_included_materials',
            'requirements'      => 'sh_course_requirements',
        ];
        foreach ($list_fields as $payload_key => $acf_field) {
            $items = isset($course[$payload_key]) && is_array($course[$payload_key])
                ? array_values(array_filter($course[$payload_key], static fn($s) => is_string($s) && $s !== ''))
                : [];
            $json = wp_json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            update_field($acf_field, $json, $product_id);
        }

        // Outline (módulos + lecciones) guardado como JSON. Lo consume el
        // shortcode [studiahub_course_outline] al renderizar.
        if (isset($course['outline']) && is_array($course['outline'])) {
            $json = wp_json_encode($course['outline'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            update_field('sh_course_outline', $json, $product_id);
        }
    }

    private static function assign_category(int $product_id, string $name, bool $append): void {
        $term = term_exists($name, 'product_cat');
        if (!$term) {
            $term = wp_insert_term($name, 'product_cat');
            if (is_wp_error($term)) {
                return;
            }
        }
        $term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
        if ($term_id <= 0) {
            return;
        }
        wp_set_object_terms($product_id, [$term_id], 'product_cat', $append);
    }

    private static function download_and_set_thumbnail(int $product_id, string $url) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('slc_thumbnail_http', "Thumbnail HTTP {$code} desde {$url}.", ['status' => 502]);
        }
        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return new \WP_Error('slc_thumbnail_empty', 'Thumbnail body vacío.', ['status' => 502]);
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $ext          = self::extension_from_mime($content_type);
        $filename     = 'lms-thumb-' . substr(md5($url . microtime()), 0, 12) . '.' . $ext;

        $upload = wp_upload_bits($filename, null, $body);
        if (!empty($upload['error'])) {
            return new \WP_Error('slc_thumbnail_upload', (string) $upload['error'], ['status' => 500]);
        }

        $old_thumb_id = get_post_thumbnail_id($product_id);
        if ($old_thumb_id) {
            wp_delete_attachment($old_thumb_id, true);
        }

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $content_type !== '' ? $content_type : 'image/jpeg',
            'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $upload['file'], $product_id);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            return new \WP_Error('slc_thumbnail_attach', 'wp_insert_attachment falló.', ['status' => 500]);
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        set_post_thumbnail($product_id, $attachment_id);
        update_post_meta($product_id, '_lms_thumbnail_hash', md5($url));

        return true;
    }

    private static function extension_from_mime(string $mime): string {
        $main = strtolower(trim(explode(';', $mime)[0]));
        $map  = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        return $map[$main] ?? 'jpg';
    }
}
