<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pantalla "Autorizar al LMS" del flow OAuth-style.
 *
 * Se invoca desde Settings::render_page() cuando la URL trae
 * ?page=studiahub-lms&slc_action=authorize. Reemplaza el render del settings
 * normal con un prompt de autorización.
 *
 * Flujo:
 *   1. LMS redirige browser → {wc}/wp-admin/admin.php?page=studiahub-lms&slc_action=authorize&...
 *   2. Settings::render_page detecta el query param, llama Authorize_Screen::render().
 *   3. Admin clickea "Sí, conectar" → POST a admin-post.php?action=slc_authorize.
 *   4. handle_submit genera code one-time, lo guarda en transient asociado al
 *      state, y redirige al return_url del LMS con state+code.
 *   5. El LMS canjea code via back-channel POST a /studiahub/v1/exchange.
 */
final class Authorize_Screen {
    public static function register_hooks(): void {
        add_action('admin_post_slc_authorize',        [self::class, 'handle_submit']);
        add_action('admin_post_slc_authorize_cancel', [self::class, 'handle_cancel']);
    }

    /**
     * Detecta si la request actual debe mostrar la pantalla authorize.
     */
    public static function is_authorize_request(): bool {
        return isset($_GET['slc_action']) && $_GET['slc_action'] === 'authorize';
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('No tenés permisos para ver esta página.');
        }

        $lms_url    = isset($_GET['lms_url'])    ? esc_url_raw(wp_unslash($_GET['lms_url']))    : '';
        $state      = isset($_GET['state'])      ? sanitize_text_field(wp_unslash($_GET['state']))      : '';
        $return_url = isset($_GET['return_url']) ? esc_url_raw(wp_unslash($_GET['return_url'])) : '';

        $error = self::validate($lms_url, $state, $return_url);

        ?>
        <div class="wrap">
            <h1>Conectar al LMS</h1>

            <?php if ($error): ?>
                <div class="notice notice-error">
                    <p><strong>Error:</strong> <?php echo esc_html($error); ?></p>
                </div>
                <p>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=' . Settings::PAGE_SLUG)); ?>" class="button">
                        Volver a settings
                    </a>
                </p>
            <?php else: ?>
                <div style="max-width:640px; background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:24px; margin-top:20px;">
                    <p style="font-size:15px; line-height:1.6;">
                        El LMS <strong><?php echo esc_html($lms_url); ?></strong> está pidiendo conectarse a este WooCommerce.
                    </p>

                    <h2 style="margin-top:24px; font-size:14px; text-transform:uppercase; color:#646970; letter-spacing:0.5px;">Lo que se va a hacer</h2>
                    <ul style="line-height:1.8; color:#1d2327;">
                        <li>Generar credenciales nuevas (API key + webhook secret unificados).</li>
                        <li>Crear un webhook de WooCommerce que apunte al LMS para sincronizar compras.</li>
                        <li>Reemplazar cualquier conexión previa con este LMS.</li>
                    </ul>

                    <p style="color:#646970; font-size:13px; margin-top:16px;">
                        Las credenciales se guardan localmente y se transmiten al LMS por un canal seguro
                        (back-channel POST). Nunca aparecen en la URL del navegador.
                    </p>

                    <div style="display:flex; gap:12px; margin-top:28px;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                            <input type="hidden" name="action" value="slc_authorize">
                            <input type="hidden" name="lms_url" value="<?php echo esc_attr($lms_url); ?>">
                            <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>">
                            <input type="hidden" name="return_url" value="<?php echo esc_attr($return_url); ?>">
                            <?php wp_nonce_field('slc_authorize'); ?>
                            <button type="submit" class="button button-primary button-hero">✓ Sí, conectar</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                            <input type="hidden" name="action" value="slc_authorize_cancel">
                            <input type="hidden" name="return_url" value="<?php echo esc_attr($return_url); ?>">
                            <?php wp_nonce_field('slc_authorize_cancel'); ?>
                            <button type="submit" class="button button-hero">Cancelar</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function validate(string $lms_url, string $state, string $return_url): ?string {
        if ($lms_url === '' || $state === '' || $return_url === '') {
            return 'Faltan parámetros requeridos (lms_url, state, return_url).';
        }
        if (strlen($state) < 16) {
            return 'state inválido.';
        }
        $lms_host    = wp_parse_url($lms_url, PHP_URL_HOST);
        $return_host = wp_parse_url($return_url, PHP_URL_HOST);
        if (!$lms_host || !$return_host) {
            return 'URLs inválidas.';
        }
        // Anti-open-redirect: el return_url tiene que vivir en el mismo origen que lms_url.
        if (strtolower($lms_host) !== strtolower($return_host)) {
            return 'lms_url y return_url están en hosts distintos.';
        }
        return null;
    }

    public static function handle_submit(): void {
        if (!current_user_can('manage_options')) wp_die('No tenés permisos.');
        check_admin_referer('slc_authorize');

        $lms_url    = isset($_POST['lms_url'])    ? esc_url_raw(wp_unslash($_POST['lms_url']))    : '';
        $state      = isset($_POST['state'])      ? sanitize_text_field(wp_unslash($_POST['state']))      : '';
        $return_url = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : '';

        $err = self::validate($lms_url, $state, $return_url);
        if ($err) wp_die(esc_html($err));

        $code = wp_generate_password(48, false, false);
        REST_Pair::store_pending($state, $code, $lms_url);

        $redirect = add_query_arg(
            [
                'state'       => $state,
                'code'        => $code,
                'wc_site_url' => home_url(),
            ],
            $return_url
        );

        // Permitir redirect al host del LMS (wp_safe_redirect lo bloquearía sino).
        add_filter('allowed_redirect_hosts', function ($hosts) use ($return_url) {
            $h = wp_parse_url($return_url, PHP_URL_HOST);
            if ($h) $hosts[] = $h;
            return $hosts;
        });
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_cancel(): void {
        if (!current_user_can('manage_options')) wp_die('No tenés permisos.');
        check_admin_referer('slc_authorize_cancel');

        $return_url = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : '';
        if ($return_url === '') {
            wp_safe_redirect(admin_url('options-general.php?page=' . Settings::PAGE_SLUG));
            exit;
        }
        $redirect = add_query_arg(['error' => 'user_cancelled'], $return_url);
        add_filter('allowed_redirect_hosts', function ($hosts) use ($return_url) {
            $h = wp_parse_url($return_url, PHP_URL_HOST);
            if ($h) $hosts[] = $h;
            return $hosts;
        });
        wp_safe_redirect($redirect);
        exit;
    }
}
