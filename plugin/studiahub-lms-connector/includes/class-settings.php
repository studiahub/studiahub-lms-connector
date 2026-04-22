<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings → StudiaHub LMS: API key (hash-only), URL del LMS y webhook secret.
 * El plaintext de la API key se muestra una única vez vía transient efímero.
 */
final class Settings {
    public const PAGE_SLUG                 = 'studiahub-lms';
    public const OPT_API_KEY_HASH          = 'slc_api_key_hash';
    public const OPT_API_KEY_GENERATED_AT  = 'slc_api_key_generated_at';
    public const OPT_LMS_URL               = 'slc_lms_url';
    public const OPT_WEBHOOK_SECRET        = 'slc_webhook_secret';
    private const TRANSIENT_PLAINTEXT_KEY  = 'slc_plaintext_api_key_';

    public static function register_hooks(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_slc_regenerate_api_key',        [self::class, 'handle_regenerate_api_key']);
        add_action('admin_post_slc_save_lms_url',              [self::class, 'handle_save_lms_url']);
        add_action('admin_post_slc_regenerate_webhook_secret', [self::class, 'handle_regenerate_webhook_secret']);
    }

    public static function register_menu(): void {
        add_options_page(
            'StudiaHub LMS',
            'StudiaHub LMS',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('No tenés permisos para ver esta página.');
        }

        $transient_key = self::TRANSIENT_PLAINTEXT_KEY . get_current_user_id();
        $plaintext_key = get_transient($transient_key);
        if ($plaintext_key !== false) {
            delete_transient($transient_key);
        }

        $has_api_key    = (bool) get_option(self::OPT_API_KEY_HASH);
        $generated_at   = (int) get_option(self::OPT_API_KEY_GENERATED_AT, 0);
        $lms_url        = (string) get_option(self::OPT_LMS_URL, '');
        $webhook_secret = (string) get_option(self::OPT_WEBHOOK_SECRET, '');
        $webhook_url    = $lms_url ? trailingslashit($lms_url) . 'api/webhooks/woocommerce' : '';

        $msg_map = [
            'api_key_ok' => ['success', 'API key regenerada. Copiala abajo — no se va a mostrar de nuevo.'],
            'url_ok'     => ['success', 'URL del LMS guardada.'],
            'secret_ok'  => ['success', 'Webhook secret generado.'],
            'webhook_ok' => ['success', 'Webhook recreado. Ya está activo apuntando al LMS.'],
            'webhook_err'=> ['error',   'No se pudo crear el webhook. Revisá que la URL del LMS esté configurada.'],
        ];
        $flash = isset($_GET['slc_msg']) ? ($msg_map[$_GET['slc_msg']] ?? null) : null;

        $webhook_status = class_exists('\SLC\WebhookBootstrap') ? \SLC\WebhookBootstrap::get_status_summary() : ['state' => 'unknown', 'webhook' => null];

        ?>
        <div class="wrap">
            <h1>StudiaHub LMS — Conector</h1>
            <p>Configurá la integración entre este WooCommerce y el LMS.</p>

            <?php if ($flash): ?>
                <div class="notice notice-<?php echo esc_attr($flash[0]); ?> is-dismissible">
                    <p><?php echo esc_html($flash[1]); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($plaintext_key): ?>
                <div class="notice notice-warning" style="border-left-color:#dba617;">
                    <p><strong>⚠ Copiá la API key ahora — no se va a mostrar de nuevo.</strong></p>
                    <p>
                        <code id="slc-new-key" style="padding:8px; background:#f0f0f1; display:inline-block; font-size:13px; user-select:all;"><?php echo esc_html($plaintext_key); ?></code>
                        <button type="button" class="button button-primary" data-slc-copy="slc-new-key">Copiar</button>
                    </p>
                </div>
            <?php endif; ?>

            <h2 class="title">API Key</h2>
            <p>Usada por el LMS para autenticar requests al endpoint de sync de cursos. Se guarda hasheada — el plaintext no se persiste.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Estado</th>
                    <td>
                        <?php if ($has_api_key): ?>
                            <span style="color:#00a32a;">● Generada</span>
                            <?php if ($generated_at): ?>
                                <span style="color:#646970;"> (el <?php echo esc_html(wp_date('Y-m-d H:i', $generated_at)); ?>)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#d63638;">● No generada</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Acción</th>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"<?php if ($has_api_key): ?> onsubmit="return confirm('¿Regenerar la API key? La anterior va a dejar de funcionar inmediatamente.');"<?php endif; ?>>
                            <input type="hidden" name="action" value="slc_regenerate_api_key">
                            <?php wp_nonce_field('slc_regenerate_api_key'); ?>
                            <button type="submit" class="button <?php echo $has_api_key ? '' : 'button-primary'; ?>">
                                <?php echo $has_api_key ? 'Regenerar API key' : 'Generar API key'; ?>
                            </button>
                        </form>
                    </td>
                </tr>
            </table>

            <h2 class="title">URL del LMS</h2>
            <p>La URL base del LMS al que este WP está conectado. Se usa para derivar la URL del webhook.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="slc_save_lms_url">
                <?php wp_nonce_field('slc_save_lms_url'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="slc_lms_url">URL base</label></th>
                        <td>
                            <input type="url" id="slc_lms_url" name="slc_lms_url" value="<?php echo esc_attr($lms_url); ?>" placeholder="https://academia.cliente.com" class="regular-text code">
                            <p class="description">Producción: <code>https://academia.cliente.com</code>. Dev local: <code>http://host.docker.internal:3000</code>.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar URL', 'primary', 'submit', false); ?>
            </form>

            <h2 class="title">Webhook secret (WC → LMS)</h2>
            <p>Secret compartido para firmar los webhooks de WooCommerce que recibe el LMS. Configuralo en <strong>WC → Settings → Advanced → Webhooks</strong>.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Secret actual</th>
                    <td>
                        <?php if ($webhook_secret): ?>
                            <code id="slc-webhook-secret" style="padding:8px; background:#f0f0f1; display:inline-block; font-size:13px; user-select:all;"><?php echo esc_html($webhook_secret); ?></code>
                            <button type="button" class="button" data-slc-copy="slc-webhook-secret">Copiar</button>
                        <?php else: ?>
                            <em style="color:#646970;">No generado todavía.</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($webhook_url): ?>
                <tr>
                    <th scope="row">URL del webhook</th>
                    <td>
                        <code id="slc-webhook-url" style="padding:8px; background:#f0f0f1; display:inline-block; font-size:13px; user-select:all;"><?php echo esc_html($webhook_url); ?></code>
                        <button type="button" class="button" data-slc-copy="slc-webhook-url">Copiar</button>
                        <p class="description">Pegá esta URL en el campo "Delivery URL" del webhook de WC.</p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row">Acción</th>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"<?php if ($webhook_secret): ?> onsubmit="return confirm('¿Regenerar el secret? Vas a tener que actualizarlo en WC → Webhooks y cualquier webhook viejo va a fallar.');"<?php endif; ?>>
                            <input type="hidden" name="action" value="slc_regenerate_webhook_secret">
                            <?php wp_nonce_field('slc_regenerate_webhook_secret'); ?>
                            <button type="submit" class="button">
                                <?php echo $webhook_secret ? 'Regenerar secret' : 'Generar secret'; ?>
                            </button>
                        </form>
                    </td>
                </tr>
            </table>

            <h2 class="title">Webhook WC → LMS (automático)</h2>
            <p>El plugin crea y mantiene el webhook automáticamente. Solo necesitás tener la URL del LMS configurada.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Estado</th>
                    <td>
                        <?php
                        $state = $webhook_status['state'] ?? 'unknown';
                        $webhook = $webhook_status['webhook'] ?? null;
                        switch ($state) {
                            case 'active':
                                echo '<span style="color:#00a32a;">● Activo</span>';
                                if (!empty($webhook_status['failure_count'])) {
                                    echo ' <span style="color:#d63638;">(' . (int) $webhook_status['failure_count'] . ' fallos recientes)</span>';
                                }
                                if ($webhook) {
                                    echo '<br><span style="color:#646970; font-size:12px;">ID #' . (int) $webhook->get_id() . ' → ' . esc_html($webhook->get_delivery_url()) . '</span>';
                                }
                                break;
                            case 'disabled':
                                echo '<span style="color:#d63638;">● Desactivado</span>';
                                echo '<br><span style="color:#646970; font-size:12px;">WC lo desactivó por fallos seguidos. Tocá "Recrear webhook" para reactivar.</span>';
                                break;
                            case 'missing':
                                echo '<span style="color:#d63638;">● No existe</span>';
                                echo '<br><span style="color:#646970; font-size:12px;">Tocá "Recrear webhook" para crearlo ahora.</span>';
                                break;
                            case 'lms_not_configured':
                                echo '<span style="color:#dba617;">● URL del LMS no configurada</span>';
                                echo '<br><span style="color:#646970; font-size:12px;">Completá la URL arriba y el webhook se crea solo.</span>';
                                break;
                            default:
                                echo '<span style="color:#646970;">—</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Acción</th>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"<?php if ($webhook): ?> onsubmit="return confirm('¿Recrear el webhook? Se elimina el existente y se crea uno nuevo con la config actual.');"<?php endif; ?>>
                            <input type="hidden" name="action" value="slc_recreate_webhook">
                            <?php wp_nonce_field('slc_recreate_webhook'); ?>
                            <button type="submit" class="button" <?php disabled($state === 'lms_not_configured'); ?>>
                                <?php echo $webhook ? 'Recrear webhook' : 'Crear webhook'; ?>
                            </button>
                        </form>
                    </td>
                </tr>
            </table>

            <script>
            (function () {
                document.querySelectorAll('[data-slc-copy]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var target = document.getElementById(btn.getAttribute('data-slc-copy'));
                        if (!target) return;
                        navigator.clipboard.writeText(target.textContent.trim()).then(function () {
                            var original = btn.textContent;
                            btn.textContent = 'Copiado ✓';
                            setTimeout(function () { btn.textContent = original; }, 2000);
                        });
                    });
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function handle_regenerate_api_key(): void {
        self::verify_admin_post('slc_regenerate_api_key');

        $plaintext = wp_generate_password(64, false, false);
        $hash      = wp_hash_password($plaintext);

        update_option(self::OPT_API_KEY_HASH, $hash, false);
        update_option(self::OPT_API_KEY_GENERATED_AT, time(), false);
        set_transient(self::TRANSIENT_PLAINTEXT_KEY . get_current_user_id(), $plaintext, 120);

        self::redirect_back('api_key_ok');
    }

    public static function handle_save_lms_url(): void {
        self::verify_admin_post('slc_save_lms_url');

        $raw = isset($_POST['slc_lms_url']) ? wp_unslash($_POST['slc_lms_url']) : '';
        $url = esc_url_raw(trim((string) $raw));
        update_option(self::OPT_LMS_URL, $url, false);

        self::redirect_back('url_ok');
    }

    public static function handle_regenerate_webhook_secret(): void {
        self::verify_admin_post('slc_regenerate_webhook_secret');

        $secret = wp_generate_password(48, false, false);
        update_option(self::OPT_WEBHOOK_SECRET, $secret, false);

        self::redirect_back('secret_ok');
    }

    private static function verify_admin_post(string $nonce_action): void {
        if (!current_user_can('manage_options')) {
            wp_die('No tenés permisos.');
        }
        check_admin_referer($nonce_action);
    }

    private static function redirect_back(string $msg): void {
        wp_safe_redirect(add_query_arg(
            'slc_msg',
            $msg,
            admin_url('options-general.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }
}
