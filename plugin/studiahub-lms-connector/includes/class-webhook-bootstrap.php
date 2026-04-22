<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crea automáticamente el webhook de WC que apunta al LMS, así el admin no
 * tiene que ir manualmente a WC → Settings → Advanced → Webhooks.
 *
 * Flujo:
 * - En admin_init verifica que exista un webhook con topic=order.updated y
 *   delivery_url={lms_url}/api/webhooks/woocommerce. Si no, lo crea.
 * - Si se guarda/cambia la URL del LMS, re-verifica.
 * - Si el webhook ya existe (en cualquier estado — active o disabled), no lo
 *   toca: respeta la decisión del admin. Para forzar reset se usa el botón
 *   "Recrear webhook" de la settings page.
 */
final class WebhookBootstrap {
    private const WEBHOOK_TOPIC = 'order.updated';
    private const WEBHOOK_NAME  = 'StudiaHub LMS sync';
    private const LMS_WEBHOOK_PATH = '/api/webhooks/woocommerce';

    public static function register_hooks(): void {
        add_action('admin_init', [self::class, 'ensure_webhook']);
        add_action('update_option_' . Settings::OPT_LMS_URL, [self::class, 'on_lms_url_changed'], 10, 2);
        add_action('add_option_' . Settings::OPT_LMS_URL, [self::class, 'ensure_webhook']);
        add_action('admin_post_slc_recreate_webhook', [self::class, 'handle_recreate']);
    }

    /**
     * Verifica que el webhook exista. Si no, lo crea.
     */
    public static function ensure_webhook(): void {
        if (!class_exists('WC_Data_Store')) {
            return; // WC aún no cargado
        }
        $delivery_url = self::get_delivery_url();
        if (!$delivery_url) {
            return; // LMS URL no configurada todavía
        }
        if (self::find_webhook($delivery_url)) {
            return; // ya existe
        }
        self::create_webhook($delivery_url);
    }

    public static function on_lms_url_changed($old, $new): void {
        // Si la URL cambió y ya existía un webhook con la vieja, lo
        // desactivamos (no borramos — respeta historial de deliveries).
        // Luego creamos uno con la URL nueva.
        if ($old && $old !== $new) {
            $old_url = self::compute_delivery_url($old);
            $existing = self::find_webhook($old_url);
            if ($existing) {
                $existing->set_status('disabled');
                $existing->save();
            }
        }
        self::ensure_webhook();
    }

    public static function handle_recreate(): void {
        if (!current_user_can('manage_options')) {
            wp_die('No tenés permisos.');
        }
        check_admin_referer('slc_recreate_webhook');

        $msg = self::force_recreate() ? 'webhook_ok' : 'webhook_err';
        wp_safe_redirect(add_query_arg(
            'slc_msg',
            $msg,
            admin_url('options-general.php?page=' . Settings::PAGE_SLUG)
        ));
        exit;
    }

    /**
     * Elimina cualquier webhook existente con nuestro delivery URL y crea
     * uno fresco. Se usa desde el botón "Recrear webhook".
     */
    public static function force_recreate(): bool {
        $delivery_url = self::get_delivery_url();
        if (!$delivery_url) {
            return false;
        }
        $existing = self::find_webhook($delivery_url);
        if ($existing) {
            $existing->delete(true);
        }
        return (bool) self::create_webhook($delivery_url);
    }

    /**
     * Devuelve el estado actual del webhook: 'active', 'disabled', 'missing'
     * o 'lms_not_configured'.
     */
    public static function get_status_summary(): array {
        $delivery_url = self::get_delivery_url();
        if (!$delivery_url) {
            return ['state' => 'lms_not_configured', 'webhook' => null];
        }
        $webhook = self::find_webhook($delivery_url);
        if (!$webhook) {
            return ['state' => 'missing', 'webhook' => null];
        }
        return [
            'state'         => $webhook->get_status() === 'active' ? 'active' : 'disabled',
            'webhook'       => $webhook,
            'failure_count' => (int) $webhook->get_failure_count(),
        ];
    }

    private static function get_delivery_url(): ?string {
        $lms_url = (string) get_option(Settings::OPT_LMS_URL, '');
        if ($lms_url === '') {
            return null;
        }
        return self::compute_delivery_url($lms_url);
    }

    private static function compute_delivery_url(string $lms_url): string {
        return rtrim($lms_url, '/') . self::LMS_WEBHOOK_PATH;
    }

    private static function find_webhook(string $delivery_url): ?\WC_Webhook {
        if (!class_exists('WC_Data_Store') || !class_exists('WC_Webhook')) {
            return null;
        }
        $data_store = \WC_Data_Store::load('webhook');
        $ids = $data_store->search_webhooks(['limit' => -1]);
        foreach ($ids as $id) {
            $webhook = new \WC_Webhook((int) $id);
            if ($webhook->get_delivery_url() === $delivery_url && $webhook->get_topic() === self::WEBHOOK_TOPIC) {
                return $webhook;
            }
        }
        return null;
    }

    private static function create_webhook(string $delivery_url): ?\WC_Webhook {
        if (!class_exists('WC_Webhook')) {
            return null;
        }
        $secret = (string) get_option(Settings::OPT_WEBHOOK_SECRET, '');
        if ($secret === '') {
            $secret = wp_generate_password(48, false, false);
            update_option(Settings::OPT_WEBHOOK_SECRET, $secret, false);
        }

        $webhook = new \WC_Webhook();
        $webhook->set_name(self::WEBHOOK_NAME);
        $webhook->set_user_id(get_current_user_id() ?: 1);
        $webhook->set_topic(self::WEBHOOK_TOPIC);
        $webhook->set_secret($secret);
        $webhook->set_delivery_url($delivery_url);
        $webhook->set_status('active');
        $webhook->set_api_version('wp_api_v3');
        $webhook->save();

        return $webhook;
    }
}
