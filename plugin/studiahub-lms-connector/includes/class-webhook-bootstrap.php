<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crea automáticamente los webhooks de WC que apuntan al LMS, así el admin no
 * tiene que ir manualmente a WC → Settings → Advanced → Webhooks.
 *
 * Registramos DOS topics al mismo delivery_url:
 *   - order.created: cubre gateways que crean la orden ya en estado completed
 *     (pagos instantáneos / productos virtuales con auto-complete) y que no
 *     disparan un order.updated posterior.
 *   - order.updated: cubre el flujo normal (pending → processing/completed).
 * El LMS procesa solo las órdenes con status=completed y es idempotente
 * (unique constraints), así que recibir ambos eventos no duplica enrollments.
 *
 * Flujo:
 * - En admin_init verifica que exista un webhook por cada topic con
 *   delivery_url={lms_url}/api/webhooks/woocommerce. Si falta alguno, lo crea.
 * - Si se guarda/cambia la URL del LMS, re-verifica.
 * - Si un webhook ya existe (en cualquier estado — active o disabled), no lo
 *   toca: respeta la decisión del admin. Para forzar reset se usa el botón
 *   "Recrear webhook" de la settings page.
 */
final class WebhookBootstrap {
    /** Topics que registramos. Ambos apuntan al mismo delivery_url. */
    private const WEBHOOK_TOPICS = ['order.created', 'order.updated'];
    private const WEBHOOK_NAME  = 'StudiaHub LMS sync';
    private const LMS_WEBHOOK_PATH = '/api/webhooks/woocommerce';

    public static function register_hooks(): void {
        add_action('admin_init', [self::class, 'ensure_webhook']);
        add_action('update_option_' . Settings::OPT_LMS_URL, [self::class, 'on_lms_url_changed'], 10, 2);
        add_action('add_option_' . Settings::OPT_LMS_URL, [self::class, 'ensure_webhook']);
        add_action('admin_post_slc_recreate_webhook', [self::class, 'handle_recreate']);
    }

    /**
     * Verifica que exista un webhook por cada topic. Crea los que falten.
     */
    public static function ensure_webhook(): void {
        if (!class_exists('WC_Data_Store')) {
            return; // WC aún no cargado
        }
        $delivery_url = self::get_delivery_url();
        if (!$delivery_url) {
            return; // LMS URL no configurada todavía
        }
        foreach (self::WEBHOOK_TOPICS as $topic) {
            if (!self::find_webhook($delivery_url, $topic)) {
                self::create_webhook($delivery_url, $topic);
            }
        }
    }

    public static function on_lms_url_changed($old, $new): void {
        // Si la URL cambió y ya existían webhooks con la vieja, los
        // desactivamos (no borramos — respeta historial de deliveries).
        // Luego creamos los de la URL nueva.
        if ($old && $old !== $new) {
            $old_url = self::compute_delivery_url($old);
            foreach (self::WEBHOOK_TOPICS as $topic) {
                $existing = self::find_webhook($old_url, $topic);
                if ($existing) {
                    $existing->set_status('disabled');
                    $existing->save();
                }
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
     * Elimina los webhooks existentes con nuestro delivery URL y crea uno
     * fresco por cada topic. Se usa desde el botón "Recrear webhook".
     */
    public static function force_recreate(): bool {
        $delivery_url = self::get_delivery_url();
        if (!$delivery_url) {
            return false;
        }
        $ok = true;
        foreach (self::WEBHOOK_TOPICS as $topic) {
            $existing = self::find_webhook($delivery_url, $topic);
            if ($existing) {
                $existing->delete(true);
            }
            $ok = (bool) self::create_webhook($delivery_url, $topic) && $ok;
        }
        return $ok;
    }

    /**
     * Borra TODOS los webhooks que apuntan al endpoint del LMS, sin importar el
     * topic. Lo usan los flujos de desconexión (donde ya se borró la LMS URL,
     * así que matcheamos por path en vez de delivery_url exacto). Devuelve la
     * cantidad borrada.
     */
    public static function delete_all_for_lms(): int {
        if (!class_exists('WC_Data_Store') || !class_exists('WC_Webhook')) {
            return 0;
        }
        $data_store = \WC_Data_Store::load('webhook');
        $ids = $data_store->search_webhooks(['limit' => -1]);
        $deleted = 0;
        foreach ($ids as $id) {
            $webhook = new \WC_Webhook((int) $id);
            if (strpos($webhook->get_delivery_url(), self::LMS_WEBHOOK_PATH) !== false) {
                $webhook->delete(true);
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Devuelve el estado agregado de los webhooks: 'active' (todos los topics
     * presentes y activos), 'disabled' (todos presentes pero alguno inactivo),
     * 'missing' (falta alguno) o 'lms_not_configured'.
     */
    public static function get_status_summary(): array {
        $delivery_url = self::get_delivery_url();
        if (!$delivery_url) {
            return ['state' => 'lms_not_configured', 'webhook' => null];
        }

        $found = [];
        $failure_count = 0;
        foreach (self::WEBHOOK_TOPICS as $topic) {
            $webhook = self::find_webhook($delivery_url, $topic);
            if ($webhook) {
                $found[] = $webhook;
                $failure_count = max($failure_count, (int) $webhook->get_failure_count());
            }
        }

        if (count($found) < count(self::WEBHOOK_TOPICS)) {
            // Falta alguno → 'missing' para que el admin lo recree (salvo que no
            // haya ninguno, mismo estado). failure_count se reporta igual.
            return ['state' => 'missing', 'webhook' => null, 'failure_count' => $failure_count];
        }

        $all_active = true;
        foreach ($found as $webhook) {
            if ($webhook->get_status() !== 'active') {
                $all_active = false;
                break;
            }
        }

        return [
            'state'         => $all_active ? 'active' : 'disabled',
            'webhook'       => $found[0],
            'failure_count' => $failure_count,
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

    private static function find_webhook(string $delivery_url, string $topic): ?\WC_Webhook {
        if (!class_exists('WC_Data_Store') || !class_exists('WC_Webhook')) {
            return null;
        }
        $data_store = \WC_Data_Store::load('webhook');
        $ids = $data_store->search_webhooks(['limit' => -1]);
        foreach ($ids as $id) {
            $webhook = new \WC_Webhook((int) $id);
            if ($webhook->get_delivery_url() === $delivery_url && $webhook->get_topic() === $topic) {
                return $webhook;
            }
        }
        return null;
    }

    private static function create_webhook(string $delivery_url, string $topic): ?\WC_Webhook {
        if (!class_exists('WC_Webhook')) {
            return null;
        }
        $secret = (string) get_option(Settings::OPT_WEBHOOK_SECRET, '');
        if ($secret === '') {
            $secret = wp_generate_password(48, false, false);
            update_option(Settings::OPT_WEBHOOK_SECRET, $secret, false);
        }

        $webhook = new \WC_Webhook();
        $webhook->set_name(self::WEBHOOK_NAME . ' (' . $topic . ')');
        $webhook->set_user_id(get_current_user_id() ?: 1);
        $webhook->set_topic($topic);
        $webhook->set_secret($secret);
        $webhook->set_delivery_url($delivery_url);
        $webhook->set_status('active');
        $webhook->set_api_version('wp_api_v3');
        $webhook->save();

        return $webhook;
    }
}
