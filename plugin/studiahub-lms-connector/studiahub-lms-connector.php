<?php
/**
 * Plugin Name:       StudiaHub LMS Connector
 * Plugin URI:        https://github.com/studiahub/studiahub-lms-connector
 * Description:       Conecta WooCommerce con StudiaHub LMS para sync unidireccional de cursos y procesamiento de webhooks de compra.
 * Version:           0.4.0
 * Author:            StudiaHub
 * Author URI:        https://studiahub.com
 * License:           MIT
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Text Domain:       studiahub-lms-connector
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SLC_VERSION', '0.4.0');
define('SLC_PLUGIN_FILE', __FILE__);
define('SLC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SLC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SLC_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook(__FILE__, function () {
    $missing = slc_get_missing_dependencies();
    if (!empty($missing)) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html(sprintf(
                'StudiaHub LMS Connector requiere los siguientes plugins activos: %s. Activalos primero y volvé a intentar.',
                implode(', ', $missing)
            )),
            'Plugin dependency error',
            ['back_link' => true]
        );
    }
});

/**
 * Al desactivar: limpiamos el filter de entrega síncrona de webhooks WC
 * (woocommerce_webhook_deliver_async) y flusheamos las rewrite rules para que
 * no queden rutas REST huérfanas. El filter se vuelve a registrar al reactivar
 * via plugins_loaded → Plugin::register_hooks().
 */
register_deactivation_hook(__FILE__, function () {
    remove_filter('woocommerce_webhook_deliver_async', '__return_false');
    flush_rewrite_rules();
});

add_action('plugins_loaded', function () {
    $missing = slc_get_missing_dependencies();
    if (!empty($missing)) {
        add_action('admin_notices', function () use ($missing) {
            printf(
                '<div class="notice notice-error"><p><strong>StudiaHub LMS Connector</strong> requiere: %s</p></div>',
                esc_html(implode(', ', $missing))
            );
        });
        return;
    }
    \SLC\Plugin::instance();
});

function slc_get_missing_dependencies(): array {
    $missing = [];
    if (!class_exists('WooCommerce')) {
        $missing[] = 'WooCommerce';
    }
    if (!class_exists('ACF')) {
        $missing[] = 'Advanced Custom Fields';
    }
    return $missing;
}
