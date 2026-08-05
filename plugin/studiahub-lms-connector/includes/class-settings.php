<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings → StudiaHub LMS.
 *
 * Con el flow OAuth, esta página ya NO tiene controles para generar/copiar
 * credenciales — todo lo maneja el flow desde el LMS. Acá solo mostramos:
 *   - Estado de conexión (conectado a {lms_url} / no conectado).
 *   - Botón "Desconectar" si está conectado.
 *   - Estado del WC webhook (active/disabled/missing) para debug.
 *
 * Si la URL trae ?slc_action=authorize, delegamos a Authorize_Screen.
 */
final class Settings {
    public const PAGE_SLUG                = 'studiahub-lms';
    public const OPT_API_KEY_HASH         = 'slc_api_key_hash';
    public const OPT_API_KEY_GENERATED_AT = 'slc_api_key_generated_at';
    public const OPT_LMS_URL              = 'slc_lms_url';
    public const OPT_WEBHOOK_SECRET       = 'slc_webhook_secret';

    public static function register_hooks(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_post_slc_local_disconnect', [self::class, 'handle_local_disconnect']);
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

        // Si vienen del LMS para autorizar, mostramos esa pantalla en vez del settings.
        if (class_exists('\\SLC\\Authorize_Screen') && Authorize_Screen::is_authorize_request()) {
            Authorize_Screen::render();
            return;
        }

        $has_api_key  = (bool) get_option(self::OPT_API_KEY_HASH);
        $generated_at = (int) get_option(self::OPT_API_KEY_GENERATED_AT, 0);
        $lms_url      = (string) get_option(self::OPT_LMS_URL, '');
        $is_connected = $has_api_key && $lms_url !== '';

        $msg_map = [
            'disconnected_ok' => ['success', 'Desconectado del LMS.'],
            'webhook_ok'      => ['success', 'Webhook recreado. WooCommerce vuelve a avisarle al LMS de cada compra.'],
            'webhook_err'     => ['error', 'No se pudo recrear el webhook. Revisá que el WordPress siga conectado al LMS.'],
        ];
        $flash = isset($_GET['slc_msg'])
            ? ($msg_map[sanitize_key(wp_unslash($_GET['slc_msg']))] ?? null)
            : null;

        $webhook_status = class_exists('\\SLC\\WebhookBootstrap')
            ? WebhookBootstrap::get_status_summary()
            : ['state' => 'unknown', 'webhook' => null];

        $reactivated_at = class_exists('\\SLC\\WebhookBootstrap')
            ? (int) get_option(WebhookBootstrap::OPT_REACTIVATED_AT, 0)
            : 0;

        ?>
        <div class="wrap">
            <h1>StudiaHub LMS — Conector</h1>

            <?php if ($flash): ?>
                <div class="notice notice-<?php echo esc_attr($flash[0]); ?> is-dismissible">
                    <p><?php echo esc_html($flash[1]); ?></p>
                </div>
            <?php endif; ?>

            <h2 class="title">Conexión con el LMS</h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Estado</th>
                    <td>
                        <?php if ($is_connected): ?>
                            <span style="color:#00a32a; font-size:14px;">● Conectado</span>
                            <p style="margin:6px 0 0 0; color:#1d2327;">
                                Pareado con: <strong><?php echo esc_html($lms_url); ?></strong>
                            </p>
                            <?php if ($generated_at): ?>
                                <p style="margin:4px 0 0 0; color:#646970; font-size:12px;">
                                    Credenciales generadas el <?php echo esc_html(wp_date('Y-m-d H:i', $generated_at)); ?>.
                                </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#d63638; font-size:14px;">● No conectado</span>
                            <p style="margin:8px 0 0 0; color:#646970; max-width:520px; line-height:1.6;">
                                Iniciá la conexión <strong>desde el admin del LMS</strong> (StudiaHub LMS → WooCommerce → "Conectar WordPress"). El LMS te va a redirigir acá para autorizar.
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php if ($is_connected): ?>
                    <tr>
                        <th scope="row">Acciones</th>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  onsubmit="return confirm('¿Desconectar este WC del LMS? El LMS dejará de recibir compras y vas a tener que reconectar para volver a sincronizar.');">
                                <input type="hidden" name="action" value="slc_local_disconnect">
                                <?php wp_nonce_field('slc_local_disconnect'); ?>
                                <button type="submit" class="button">Desconectar</button>
                            </form>
                            <p class="description">
                                Borra credenciales locales y desactiva el webhook. Si querés que el LMS también se entere, pediselo al admin del LMS — o ejecutá disconnect desde ahí, que llama a este WP automáticamente.
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>

            <h2 class="title" style="margin-top:32px;">Webhook WooCommerce → LMS</h2>
            <p style="color:#646970;">
                Webhook que se crea automáticamente al conectar. Se mantiene solo: si lo borrás desde WC, se vuelve a crear al recargar el admin.
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Estado</th>
                    <td>
                        <?php
                        $state    = $webhook_status['state'] ?? 'unknown';
                        $webhook  = $webhook_status['webhook'] ?? null;
                        $failures = (int) ($webhook_status['failure_count'] ?? 0);
                        switch ($state) {
                            case 'active':
                                echo '<span style="color:#00a32a;">● Activo</span>';
                                if ($failures) {
                                    echo ' <span style="color:#d63638;">(' . $failures . ' fallos recientes)</span>';
                                }
                                if ($webhook) {
                                    echo '<br><span style="color:#646970; font-size:12px;">ID #' . (int) $webhook->get_id() . ' → ' . esc_html($webhook->get_delivery_url()) . '</span>';
                                }
                                break;
                            case 'disabled':
                                echo '<span style="color:#d63638;">● Desactivado</span>';
                                if ($failures) {
                                    echo ' <span style="color:#d63638;">(' . $failures . ' fallos seguidos)</span>';
                                }
                                echo '<br><span style="color:#646970; font-size:12px;">Mientras esté desactivado, las compras se cobran pero no se inscribe a nadie. Apretá «Recrear webhook» para volver a activarlo.</span>';
                                break;
                            case 'missing':
                                echo '<span style="color:#d63638;">● No existe</span>';
                                if ($is_connected) {
                                    echo '<br><span style="color:#646970; font-size:12px;">Recargá esta página o apretá «Recrear webhook».</span>';
                                }
                                break;
                            case 'lms_not_configured':
                                echo '<span style="color:#dba617;">● Sin LMS configurado</span>';
                                break;
                            default:
                                echo '<span style="color:#646970;">—</span>';
                        }

                        if ($reactivated_at) {
                            echo '<p style="margin:8px 0 0 0; color:#646970; font-size:12px; max-width:520px; line-height:1.6;">'
                                . 'WooCommerce lo había pausado por entregas fallidas y el plugin lo reactivó automáticamente el '
                                . esc_html(wp_date('Y-m-d H:i', $reactivated_at))
                                . '. Si se repite seguido, revisá que el LMS esté respondiendo.'
                                . '</p>';
                        }
                        ?>
                    </td>
                </tr>

                <?php if ($state !== 'lms_not_configured'): ?>
                    <tr>
                        <th scope="row">Acciones</th>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  onsubmit="return confirm('¿Recrear el webhook? Se borra el actual y se crea uno nuevo con las mismas credenciales — no hace falta reconectar el LMS. Se pierde el historial de entregas de WooCommerce.');">
                                <input type="hidden" name="action" value="slc_recreate_webhook">
                                <?php wp_nonce_field('slc_recreate_webhook'); ?>
                                <button type="submit" class="button">Recrear webhook</button>
                            </form>
                            <p class="description" style="max-width:520px;">
                                Usalo si el webhook figura desactivado o inexistente. WooCommerce lo pausa solo después de varias entregas fallidas seguidas (por ejemplo, si el LMS estuvo caído) y no lo reintenta nunca más.
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }

    /**
     * "Desconectar" desde el plugin (lado WP) — solo limpia local.
     * Si el admin quiere notificar al LMS, debería hacerlo desde el LMS (que llama
     * /studiahub/v1/disconnect a este WP automáticamente).
     */
    public static function handle_local_disconnect(): void {
        if (!current_user_can('manage_options')) wp_die('No tenés permisos.');
        check_admin_referer('slc_local_disconnect');

        delete_option(self::OPT_API_KEY_HASH);
        delete_option(self::OPT_API_KEY_GENERATED_AT);
        delete_option(self::OPT_WEBHOOK_SECRET);
        delete_option(self::OPT_LMS_URL);

        // Borrar los WC webhooks del LMS (todos los topics).
        if (class_exists('\\SLC\\WebhookBootstrap')) {
            WebhookBootstrap::delete_all_for_lms();
            delete_option(WebhookBootstrap::OPT_REACTIVATED_AT);
        }

        wp_safe_redirect(add_query_arg(
            'slc_msg',
            'disconnected_ok',
            admin_url('options-general.php?page=' . self::PAGE_SLUG)
        ));
        exit;
    }
}
