<?php
/**
 * Plugin Name: StudiaHub Dev — Allow internal hosts
 * Description: Habilita wp_safe_remote_* hacia host.docker.internal y localhost para que WC pueda validar y entregar webhooks al LMS corriendo en el host durante desarrollo. NO instalar en producción.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('http_request_host_is_external', function ($is_external, $host) {
    $dev_hosts = ['host.docker.internal', 'localhost', '127.0.0.1'];
    if (in_array($host, $dev_hosts, true)) {
        return true;
    }
    return $is_external;
}, 10, 2);
