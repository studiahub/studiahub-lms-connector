<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_classes();
        $this->register_hooks();
    }

    private function load_classes(): void {
        require_once SLC_PLUGIN_DIR . 'includes/class-acf-registrar.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-settings.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-auth.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-rest-health.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-rest-course-sync.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-rest-categories.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-rest-products.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-rest-pair.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-authorize-screen.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-webhook-bootstrap.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-shortcode-outline.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-shortcode-list.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-shortcode-instructor.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-shortcode-meta.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-shortcode-badge.php';
        require_once SLC_PLUGIN_DIR . 'includes/class-shortcode-trailer.php';
    }

    private function register_hooks(): void {
        ACF_Registrar::register_hooks();
        Settings::register_hooks();
        REST_Health::register_hooks();
        REST_Course_Sync::register_hooks();
        REST_Categories::register_hooks();
        REST_Products::register_hooks();
        REST_Pair::register_hooks();
        Authorize_Screen::register_hooks();
        WebhookBootstrap::register_hooks();
        Shortcode_Outline::register_hooks();
        Shortcode_List::register_hooks();
        Shortcode_Instructor::register_hooks();
        Shortcode_Meta::register_hooks();
        Shortcode_Badge::register_hooks();
        Shortcode_Trailer::register_hooks();

        // Entrega SÍNCRONA de webhooks WC. El default de WC es encolarlos en
        // Action Scheduler y procesarlos via wp-cron, lo que introduce delay
        // variable (depende del tráfico del WP) y falla en sitios sin tráfico
        // constante. Para un LMS que necesita crear el enrollment ni bien se
        // completa el pago, queremos entrega inmediata en el shutdown del
        // mismo request. El impacto en el tiempo del checkout es ~100-500ms
        // del round-trip al LMS.
        add_filter('woocommerce_webhook_deliver_async', '__return_false');
    }
}
