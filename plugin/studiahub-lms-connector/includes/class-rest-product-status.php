<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * POST /wp-json/studiahub/v1/product-status
 *
 * Baja el producto de la vidriera cuando el LMS deja de publicar el curso.
 * Body: { "wcProductId": 123, "status": "draft" }
 *
 * Hasta acá, apagar "Publicar en la tienda" en el LMS no podía tocar el
 * producto: el plugin no exponía ninguna ruta que escribiera el estado, así que
 * la página seguía viva y el cliente tenía que entrar a wp-admin a pasarla a
 * borrador a mano. La red de contención del LMS (con el toggle apagado el
 * landing-payload reporta salesClosed y el Purchase_Gate bloquea la compra)
 * sigue siendo la que protege a los WP que todavía no actualizaron el plugin;
 * esta ruta es la que además hace desaparecer la página.
 *
 * ── Por qué es una ruta nueva y NO un campo de course-sync ──────────────────
 *
 *  1. `syncCourseToWC` del LMS SOLO se dispara cuando publishToWC === true. El
 *     momento que nos importa —el toggle pasando a false— no manda ningún sync.
 *     Un campo `status` dentro de course-sync sería un mensaje que nunca llega.
 *
 *  2. Si `update_product()` escribiera el estado, cada sync de rutina (un
 *     cambio de precio, un thumbnail nuevo, el cron de ofertas) le pisaría el
 *     estado al cliente y REPUBLICARÍA un producto que él había dejado en
 *     borrador a propósito. El "No tocamos: status, catalog_visibility, stock,
 *     sku — los controla el admin de WC" de REST_Course_Sync::update_product()
 *     es un contrato, no un descuido: el estado se escribe solo cuando alguien
 *     lo pide explícitamente, que es exactamente lo que hace esta ruta.
 *
 * ── Por qué solo acepta 'draft' y no 'publish' ──────────────────────────────
 *
 * Despublicar es retirar algo que dejó de estar a la venta: la dirección
 * segura, y la que hoy no tiene forma de ejecutarse sola. Publicar es una
 * decisión comercial del dueño de la tienda, y el LMS no debería poder
 * forzarla: un producto en borrador puede estarlo porque le falta el copy, la
 * foto o el medio de pago, y republicarlo por control remoto pondría a la venta
 * algo que el cliente no dio por listo.
 *
 * No es una restricción nueva: `create_product()` ya crea todo producto en
 * 'draft' y espera que el cliente lo publique. El plugin nunca publicó nada.
 * Que prender el toggle no republique solo es, entonces, el mismo paso manual
 * que existe desde el primer curso sincronizado — a diferencia del caso
 * inverso, donde no hacer nada dejaba una página vendiendo en silencio.
 *
 * Cualquier otro valor se rechaza con 400 `slc_invalid_status`.
 *
 * Auth: misma Bearer key que el resto de los endpoints (Auth::verify_request).
 */
final class REST_Product_Status {
    /**
     * Estados que el LMS puede pedir. Ver el docblock: 'publish' queda afuera
     * a propósito. Si algún día entra otro, que sea por una decisión explícita
     * y no porque la lista estaba abierta.
     */
    private const ALLOWED_STATUSES = ['draft'];

    public static function register_hooks(): void {
        add_action('rest_api_init', [self::class, 'register_route']);
    }

    public static function register_route(): void {
        register_rest_route('studiahub/v1', '/product-status', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => [Auth::class, 'verify_request'],
        ]);
    }

    /**
     * Los errores salen como WP_Error con un code propio y explícito, no como
     * un WP_REST_Response con {error}. La razón es que el LMS tiene que poder
     * distinguir DOS 404 que significan cosas opuestas:
     *
     *   - `rest_no_route`         → este WP tiene una versión vieja del plugin
     *                               y la ruta ni existe. El producto sigue vivo
     *                               y publicado: no hay que tocar nada.
     *   - `slc_product_not_found` → la ruta corrió y ese wcProductId no está.
     *
     * El LMS interpreta un 404 de WP como "el producto fue borrado" y le limpia
     * el wcProductId al curso. Si no pudiera separarlos, un cliente que no
     * actualizó el plugin se quedaría con el producto huérfano en silencio: el
     * vínculo roto y la venta apuntando a la nada. WP_Error es lo que serializa
     * {"code": ..., "message": ..., "data": {"status": ...}}, así que el code
     * viaja en el body y el LMS lo puede chequear.
     */
    public static function handle(\WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_Error('slc_bad_body', 'Body debe ser JSON object.', ['status' => 400]);
        }

        $status = isset($body['status']) ? (string) $body['status'] : '';
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return new \WP_Error(
                'slc_invalid_status',
                sprintf(
                    'status inválido. Valores aceptados: %s.',
                    implode(', ', self::ALLOWED_STATUSES)
                ),
                ['status' => 400]
            );
        }

        $raw_id = $body['wcProductId'] ?? null;
        if (!self::is_positive_int($raw_id)) {
            return new \WP_Error(
                'slc_invalid_product_id',
                'wcProductId debe ser un entero positivo.',
                ['status' => 400]
            );
        }
        $product_id = (int) $raw_id;

        if (!function_exists('wc_get_product')) {
            return new \WP_Error('slc_wc_missing', 'WooCommerce no está disponible.', ['status' => 500]);
        }

        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product') {
            return new \WP_Error('slc_product_not_found', 'Producto inexistente en WC.', ['status' => 404]);
        }

        // Un producto en la papelera ya no tiene página que bajar: el objetivo
        // está cumplido. Y pasarlo a borrador lo SACARÍA de la papelera, o sea
        // restauraría algo que el cliente borró. Devolvemos ok informando el
        // estado real, que no es el pedido, y no tocamos nada. Ojo que esto es
        // deliberadamente distinto de GET /products/{id}, que trata la papelera
        // como 404: esa ruta pregunta "¿el vínculo todavía apunta a algo?" y
        // esta pregunta "¿la página está abajo?".
        if ($post->post_status === 'trash') {
            return self::respond($product_id, 'trash', false);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('slc_product_not_found', 'wc_get_product retornó null.', ['status' => 404]);
        }

        // Idempotente: si ya está en el estado pedido, no escribimos. Evita
        // ensuciar el historial del post y hace que un reintento del LMS sea
        // gratis.
        if ($product->get_status() === $status) {
            return self::respond($product_id, $status, false);
        }

        $product->set_status($status);
        $product->save();

        return self::respond($product_id, $status, true);
    }

    private static function respond(int $product_id, string $status, bool $changed): \WP_REST_Response {
        return new \WP_REST_Response([
            'ok'          => true,
            'wcProductId' => $product_id,
            // El estado EFECTIVO del producto al terminar, que no siempre es el
            // pedido (ver el caso papelera). El LMS puede compararlo.
            'status'      => $status,
            'changed'     => $changed,
        ], 200);
    }

    private static function is_positive_int($v): bool {
        if (is_int($v)) {
            return $v > 0;
        }
        return is_string($v) && ctype_digit($v) && (int) $v > 0;
    }
}
