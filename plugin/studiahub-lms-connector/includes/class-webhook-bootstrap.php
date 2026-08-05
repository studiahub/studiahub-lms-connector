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
 * - Verifica que exista un webhook por cada topic con
 *   delivery_url={lms_url}/api/webhooks/woocommerce. Si falta alguno, lo crea.
 * - Si se guarda/cambia la URL del LMS, re-verifica.
 * - Si un webhook ya existe y está activo, no lo toca. Si está desactivado,
 *   distingue quién lo apagó: si fue WooCommerce por entregas fallidas lo
 *   reactiva (ver maybe_reactivate); si lo apagó una persona, lo respeta. Para
 *   forzar un reset completo está el botón "Recrear webhook" de la settings page.
 *
 * Cuándo corre esa verificación (ver register_hooks): en `admin_init`, en un
 * cron horario y después de cada compra. Las dos últimas existen porque el
 * `admin_init` solo no alcanza — ver self_heal_on_order().
 */
final class WebhookBootstrap {
    /** Topics que registramos. Ambos apuntan al mismo delivery_url. */
    private const WEBHOOK_TOPICS = ['order.created', 'order.updated'];
    private const WEBHOOK_NAME  = 'StudiaHub LMS sync';
    private const LMS_WEBHOOK_PATH = '/api/webhooks/woocommerce';

    /** Segundos de timeout de la entrega HTTP. Ver filter_http_args(). */
    private const DELIVERY_TIMEOUT = 10;

    /** Guarda cuándo reactivamos por última vez un webhook que WC había pausado. */
    public const OPT_REACTIVATED_AT = 'slc_webhook_reactivated_at';

    /** Evento de wp-cron que re-verifica los webhooks sin intervención humana. */
    public const CRON_HOOK = 'slc_ensure_webhooks';

    /** Marca de la última verificación disparada por una compra. */
    public const OPT_LAST_SELF_HEAL = 'slc_webhook_last_self_heal';

    /**
     * Ventana mínima entre verificaciones disparadas por una compra. Una ráfaga
     * de pedidos no tiene por qué escanear los webhooks una vez por pedido.
     */
    private const SELF_HEAL_THROTTLE = 15 * MINUTE_IN_SECONDS;

    public static function register_hooks(): void {
        add_action('admin_init', [self::class, 'ensure_webhook']);
        add_action('update_option_' . Settings::OPT_LMS_URL, [self::class, 'on_lms_url_changed'], 10, 2);
        add_action('add_option_' . Settings::OPT_LMS_URL, [self::class, 'ensure_webhook']);
        add_action('admin_post_slc_recreate_webhook', [self::class, 'handle_recreate']);
        add_filter('woocommerce_webhook_http_args', [self::class, 'filter_http_args'], 10, 3);

        // Auto-sanación sin humanos: cron horario + red de contención en cada
        // compra. Ver self_heal_on_order() para por qué van los dos.
        add_action('init', [self::class, 'schedule_self_heal']);
        add_action(self::CRON_HOOK, [self::class, 'ensure_webhook']);
        add_action('woocommerce_new_order', [self::class, 'self_heal_on_order']);
    }

    /**
     * Programa el cron horario que re-verifica los webhooks.
     *
     * Corre en cada request, pero es gratis: `wp_next_scheduled()` lee la option
     * `cron`, que es autoload — no agrega ni una query.
     */
    public static function schedule_self_heal(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function unschedule_self_heal(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Re-verifica los webhooks cuando entra un pedido.
     *
     * POR QUÉ NO ALCANZA CON `admin_init`: cuando WooCommerce pausa nuestros
     * webhooks por entregas fallidas (un redeploy del LMS devolviendo 502 de
     * Traefik alcanza), no los vuelve a activar nunca. Hasta ahora la
     * reactivación colgaba solo de `admin_init`, o sea de que un humano abriera
     * wp-admin. Si el cliente no entra a WordPress —y no tiene por qué, el LMS
     * es otra aplicación— cada compra se cobra y ninguna llega al LMS, en
     * silencio y para siempre.
     *
     * POR QUÉ ADEMÁS DEL CRON: el cron horario es el mecanismo principal, pero
     * wp-cron depende del tráfico y hay hostings donde está apagado
     * (`DISABLE_WP_CRON`) sin un cron de sistema que lo reemplace. Un pedido, en
     * cambio, es tráfico garantizado y es exactamente el momento en que esto
     * importa.
     *
     * LO QUE ESTE HOOK NO ARREGLA: el pedido que lo dispara. WooCommerce engancha
     * los webhooks activos en `init` con prioridad 0 (WC()->init() →
     * load_webhooks()), mucho antes de que exista el pedido, así que uno pausado
     * no entrega ESTA compra por más que lo reactivemos ahora. Reactivarlo antes
     * costaría escanear los webhooks en cada request. Lo que sí garantiza es que
     * la compra siguiente llegue, y el LMS reconcilia por su lado los pedidos de
     * la ventana perdida.
     */
    public static function self_heal_on_order(): void {
        $last = (int) get_option(self::OPT_LAST_SELF_HEAL, 0);
        if (time() - $last < self::SELF_HEAL_THROTTLE) {
            return;
        }
        // Antes de verificar, no después: si ensure_webhook() muriera, no
        // queremos que cada pedido siguiente reintente lo mismo.
        update_option(self::OPT_LAST_SELF_HEAL, time(), false);
        self::ensure_webhook();
    }

    /**
     * Baja el timeout de entrega de NUESTROS webhooks (los que apuntan al LMS).
     *
     * La entrega síncrona es deliberada: el plugin fuerza
     * woocommerce_webhook_deliver_async => false (ver Plugin::register_hooks)
     * para que el enrollment se cree ni bien se completa el pago, sin depender
     * de wp-cron. Lo que sobra es el timeout de WC core, que arma la request con
     * 'timeout' => MINUTE_IN_SECONDS y 'blocking' => true (WC_Webhook::deliver).
     *
     * Con dos topics registrados eso son hasta 120s de un worker de PHP-FPM
     * clavado dentro del request del checkout o del IPN del gateway. El caso
     * malo no es el LMS caído (ahí falla rápido) sino el LMS lento: la DB
     * despertando del autosuspend, un redeploy en curso. Con unas pocas compras
     * concurrentes se acaban los workers y se cae el WP entero, landings de
     * venta incluidas — y si el gateway timeoutea su IPN, reintenta y multiplica.
     *
     * 10s le sobran a un LMS sano (~100-500ms de round-trip) y le alcanzan a uno
     * despertando, sin poner en riesgo al WordPress. Si aun así falla, el LMS
     * reconcilia las órdenes por su lado y el admin tiene el botón
     * "Recrear webhook" en la settings page.
     *
     * Solo tocamos los webhooks cuyo delivery URL apunta al endpoint del LMS:
     * los de otros plugins del cliente se quedan con el default de WC.
     *
     * @param array $http_args  Args para wp_safe_remote_post().
     * @param mixed $arg        Primer argumento del hook (null si es el ping).
     * @param int   $webhook_id ID del webhook que se está entregando.
     */
    public static function filter_http_args($http_args, $arg, $webhook_id) {
        if (!is_array($http_args) || !self::is_lms_webhook((int) $webhook_id)) {
            return $http_args;
        }
        $http_args['timeout'] = self::DELIVERY_TIMEOUT;
        return $http_args;
    }

    /**
     * Verifica que exista un webhook activo por cada topic. Crea los que falten
     * y reactiva los que WC haya pausado por fallos.
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
            $existing = self::find_webhook($delivery_url, $topic);
            if (!$existing) {
                self::create_webhook($delivery_url, $topic);
                continue;
            }
            self::maybe_reactivate($existing);
        }
    }

    /**
     * Un webhook en 'disabled' puede estarlo por dos motivos muy distintos:
     *
     *   - Lo apagó una persona desde WC → Ajustes → Avanzado → Webhooks. Esa
     *     decisión se respeta.
     *   - Lo apagó WooCommerce solo. WC_Webhook::failed_delivery() cuenta como
     *     falla cualquier respuesta fuera del rango 2xx/301/302 y desactiva el
     *     webhook cuando el contador supera el umbral. Un redeploy del LMS
     *     devolviendo 502 de Traefik alcanza. WC no reintenta ninguna entrega,
     *     así que a partir de ese momento toda compra se cobra y ninguna llega
     *     al LMS, para siempre y en silencio. Eso no es una decisión del admin.
     *
     * Los distinguimos por el contador de fallas: WC solo desactiva cuando
     * supera su propio umbral (5 por default, filtrable), y no lo resetea al
     * hacerlo. Un 'disabled' con el contador por encima del umbral es obra de
     * WC; con el contador bajo, de una persona.
     *
     * Al reactivar reseteamos el contador — si no, la primera falla siguiente lo
     * vuelve a apagar — y dejamos rastro en una option para poder avisarle al
     * admin en la settings page.
     */
    private static function maybe_reactivate(\WC_Webhook $webhook): void {
        if ($webhook->get_status() === 'active') {
            return;
        }
        $max_failures = (int) apply_filters('woocommerce_max_webhook_delivery_failures', 5);
        if ((int) $webhook->get_failure_count() <= $max_failures) {
            return; // Lo apagó una persona, no WC.
        }

        $webhook->set_status('active');
        $webhook->set_failure_count(0);
        // Sin esto, el data store de WC dispara un deliver_ping() bloqueante al
        // guardar un webhook activo con entrega pendiente — justo lo que no
        // queremos colgado de un admin_init si el LMS está lento.
        $webhook->set_pending_delivery(false);
        $webhook->save();

        update_option(self::OPT_REACTIVATED_AT, time(), false);
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
        // Webhooks nuevos: el aviso de reactivación automática ya no aplica.
        delete_option(self::OPT_REACTIVATED_AT);
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

    /**
     * ¿El webhook apunta al endpoint del LMS? Matcheamos por path (igual que
     * delete_all_for_lms) para cubrir también los que quedaron con una LMS URL
     * vieja. Cacheado en memoria: en un mismo request se pueden entregar los
     * dos topics.
     *
     * Público porque Webhook_Payload necesita el mismo criterio para no tocar
     * los webhooks de otros plugins del cliente.
     */
    public static function is_lms_webhook(int $webhook_id): bool {
        if ($webhook_id <= 0 || !class_exists('WC_Webhook')) {
            return false;
        }
        static $cache = [];
        if (!isset($cache[$webhook_id])) {
            $webhook = new \WC_Webhook($webhook_id);
            $cache[$webhook_id] = strpos($webhook->get_delivery_url(), self::LMS_WEBHOOK_PATH) !== false;
        }
        return $cache[$webhook_id];
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
