<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Blindaje contra wpautop para el output de los landings.
 *
 * PROBLEMA: `wpautop` (la función del core que convierte saltos de línea en
 * <p>/<br>, enganchada a `the_content` y usada por el bloque core/shortcode)
 * está pensada para prosa tipeada por humanos, NO para HTML estructurado. No
 * entiende la estructura del documento: sobre nuestro landing mete <p> vacíos
 * en el grid, <br> dentro de los botones (<a>), </p><p> dentro del <script>
 * inline (rompe el JS) y envuelve en <p> el contenido inline pegado a los <h3>
 * de los módulos (descuadra el acordeón) — TODO esto incluso sin saltos de
 * línea, por su lógica de "inline pegado a un bloque". En Elementor no pasa
 * porque renderiza por fuera de `the_content`.
 *
 * SOLUCIÓN (de raíz, no parche): que nuestro output NO pase por wpautop. En vez
 * de devolver el HTML, el shortcode devuelve un placeholder de texto plano
 * (inmune a wpautop: sin saltos ni tags). Un filtro tardío — DESPUÉS de que
 * wpautop ya corrió — reemplaza el placeholder por el HTML real. Cuando wpautop
 * actúa, solo ve el token inofensivo; el HTML entra recién después, intacto.
 */
final class Render_Guard {

    /** @var array<string,string> token => html pendiente de restaurar. */
    private static array $pending = [];

    /**
     * Se registra al cargar el plugin (NO perezosamente): arranca el
     * output-buffer de la página en `template_redirect`. Tiene que registrarse
     * ANTES de que ese hook dispare — si lo hiciéramos dentro de protect() (que
     * corre durante el render de la página, ya pasado template_redirect), el
     * buffer nunca arrancaría.
     */
    public static function register_hooks(): void {
        add_action('template_redirect', [self::class, 'start_safety_buffer'], 0);
    }

    /**
     * Guarda el HTML y devuelve un placeholder de texto plano. El shortcode
     * hace `return Render_Guard::protect($html);` en vez de devolver el HTML.
     */
    public static function protect(string $html): string {
        // Token alfanumérico: inmune a wpautop y a wptexturize (sin comillas,
        // guiones ni saltos). Si por algún motivo no se restaura, se ve feo
        // pero no rompe el HTML. El índice basta para unicidad dentro del request.
        $token = 'SLCRENDERGUARD' . count(self::$pending) . substr(md5($html), 0, 12) . 'ENDSLCRG';
        self::$pending[$token] = $html;
        return $token;
    }

    /**
     * Restauramos el token en UN solo punto: un output-buffer de la página
     * entera. Corre sobre el HTML final, DESPUÉS de que todo (wpautop, do_blocks,
     * filtros de tema/Woo) ya ejecutó — así el HTML inyectado nunca puede ser
     * re-procesado por wpautop. Restaurar antes (en the_content, render_block,
     * etc.) es frágil: si un wpautop corre después, vuelve a romper el HTML. El
     * buffer es el único punto garantizado y a prueba de contexto.
     */
    public static function start_safety_buffer(): void {
        if (is_admin() || wp_doing_ajax() || is_feed()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        ob_start([self::class, 'restore']);
    }

    /**
     * Reemplaza los placeholders por el HTML real. Absorbe un eventual <p>…</p>
     * que wpautop haya puesto alrededor del token (para no dejar un <p> vacío
     * envolviendo el <article>). Idempotente: una vez restaurado, se olvida.
     *
     * @param mixed $content
     * @return mixed
     */
    public static function restore($content) {
        if (!is_string($content) || self::$pending === [] || strpos($content, 'SLCRENDERGUARD') === false) {
            return $content;
        }
        foreach (self::$pending as $token => $html) {
            if (strpos($content, $token) === false) {
                continue;
            }
            $replaced = 0;
            // Caso 1: wpautop envolvió el token en <p>…</p> → absorbemos el <p>.
            $content = preg_replace(
                '/<p>\s*' . preg_quote($token, '/') . '\s*<\/p>/',
                self::escape_replacement($html),
                $content,
                -1,
                $replaced
            );
            // Caso 2: token pelado (o quedaron restos) → reemplazo directo.
            if (strpos($content, $token) !== false) {
                $content = str_replace($token, $html, $content);
            }
            unset(self::$pending[$token]);
        }
        return $content;
    }

    /** `$` en el HTML rompería el reemplazo de preg_replace; lo escapamos. */
    private static function escape_replacement(string $html): string {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $html);
    }
}
