<?php
/**
 * Plugin Name: SLC Dev — Mock Payload del LMS
 * Description: Intercepta el fetch al LMS y devuelve un payload mockeado desde .docker/dev-mock/payload.json. Pensado SOLO para diseño / desarrollo local — permite trabajar las landings sin necesitar el LMS Next.js corriendo.
 * Author: StudiaHub (dev only)
 * Version: 1.0.0
 *
 * Cómo desactivarlo: borrar este archivo. Se vuelve a tomar el fetch real al LMS.
 * Cómo editar la data: abrir .docker/dev-mock/payload.json (montado en /var/www/html/.dev-mock/ adentro del container).
 *
 * Atención: este mu-plugin es ignorado en producción porque solo se monta
 * el directorio en .docker/ via docker-compose.yml. Cero riesgo de leak.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Path al JSON. El docker-compose lo monta como /var/www/html/.dev-mock/.
$slc_mock_path = '/var/www/html/.dev-mock/payload.json';

if (!file_exists($slc_mock_path)) {
    // Sin el JSON, el mu-plugin no hace nada. El fetch real al LMS sigue funcionando.
    return;
}

add_filter('slc_landing_payload_override', function ($payload, $course_id) use ($slc_mock_path) {
    // Lee fresco en cada request — así editar el JSON refleja al toque,
    // sin caches ni reinicio del container. Costo: lectura de un archivo
    // chico (~6KB) por request. Aceptable para dev.
    $raw = file_get_contents($slc_mock_path);
    if ($raw === false) {
        return $payload;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $payload;
    }

    // Sobreescribimos lmsId con el course_id real pedido para que el resto
    // del plugin (URLs, checkout, etc) funcione coherente con el contexto.
    $data['lmsId'] = $course_id;

    return $data;
}, 10, 2);

// Notice visible solo para admins, para que sea evidente que está activo el mock.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    echo '<div class="notice notice-warning" style="border-left-color:#7950F2;">';
    echo '<p><strong>🟢 SLC Dev Mock activo.</strong> El plugin está leyendo el payload de las landings desde ';
    echo '<code>.docker/dev-mock/payload.json</code> en lugar del LMS real. ';
    echo 'Para apuntar al LMS real, corré <code>make mock-off</code> en la terminal.</p>';
    echo '</div>';
});
