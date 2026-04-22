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
    }

    private function register_hooks(): void {
        ACF_Registrar::register_hooks();
        Settings::register_hooks();
        REST_Health::register_hooks();
        REST_Course_Sync::register_hooks();
    }
}
