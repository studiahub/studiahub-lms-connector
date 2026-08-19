<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Vigilancia de la landing: cron horario + aviso en el admin.
 *
 * POR QUÉ EXISTE
 * --------------
 * La landing puede quedar sirviendo contenido viejo sin que nada falle: sin
 * error, sin excepción y con HTTP 200 en toda la cadena. Pasó en producción —
 * dos semanas mostrando fechas y precios viejos, y nadie se enteró hasta que un
 * humano miró la página. No se puede alertar sobre errores que no ocurren, así
 * que acá se vigila el RESULTADO: hace cuánto que no traemos contenido del LMS.
 *
 * QUÉ HACE EL CRON
 * ----------------
 * Recorre los cursos publicados y les pide el payload. `get_payload()` respeta
 * el TTL, así que en un sitio con visitas la mayoría de las veces no hace nada.
 * El valor está en el sitio SIN visitas: sin esto, una landing que nadie abre no
 * refresca nunca, y el primer visitante del día se come el contenido viejo. Acá
 * el refresco pasa a estar garantizado por reloj y no por tráfico.
 *
 * O sea que además de detectar el problema, lo previene.
 *
 * EL AVISO
 * --------
 * `admin_notices` corre en CADA pantalla del admin, así que no puede hacer
 * queries: solo lee la option que el cron dejó precomputada.
 */
final class Landing_Health {
    public const CRON_HOOK = 'slc_landing_health';

    /** Resumen precomputado que lee el aviso. Chico a propósito: es autoload. */
    private const OPT_REPORT = 'slc_landing_health';

    /** A partir de acá el contenido servido se considera viejo. */
    private const STALE_AFTER = 3 * HOUR_IN_SECONDS;

    /**
     * Techo de fetches por corrida. Sin esto, un sitio con muchos cursos y el
     * LMS lento convertiría el cron en un proceso de varios minutos. Los que
     * quedan afuera se refrescan en la corrida siguiente.
     */
    private const MAX_FETCH_PER_RUN = 25;

    public static function register_hooks(): void {
        add_action('init', [self::class, 'schedule']);
        add_action(self::CRON_HOOK, [self::class, 'run']);
        add_action('admin_notices', [self::class, 'render_notice']);
    }

    /**
     * Gratis en cada request: `wp_next_scheduled()` lee la option `cron`, que es
     * autoload. Mismo patrón que WebhookBootstrap::schedule_self_heal().
     */
    public static function schedule(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Refresca lo que haga falta y deja el resumen para el aviso.
     */
    public static function run(): void {
        // Sin conexión al LMS no hay nada que vigilar, y avisar sería ruido: el
        // sitio todavía no terminó de configurarse.
        if ((string) get_option(Settings::OPT_LMS_URL, '') === '') {
            delete_option(self::OPT_REPORT);
            return;
        }

        $course_ids = self::published_course_ids();
        $fetched    = 0;
        $stale      = [];

        // Los más atrasados primero. Sin esto el orden sería siempre el mismo y,
        // como el cron es horario pero la copia fresca dura 15 minutos, en cada
        // corrida están todos vencidos: los primeros se llevarían el presupuesto
        // entero y del que quedó afuera en adelante no se refrescaría NUNCA,
        // quedando marcado como viejo para siempre. Ordenar por antigüedad hace
        // que el turno rote solo.
        usort($course_ids, static function (string $a, string $b): int {
            $at_a = (int) get_option('slc_landing_at_' . md5($a), 0);
            $at_b = (int) get_option('slc_landing_at_' . md5($b), 0);
            return $at_a <=> $at_b;
        });

        foreach ($course_ids as $course_id) {
            $status = Landing_Fetch::status($course_id);

            // Refrescamos solo lo que ya venció. get_payload() decide si sale a
            // la red o no; acá solo acotamos cuántos intentos por corrida.
            $needs_refresh = $status['served_from'] !== 'fresh';
            if ($needs_refresh && $fetched < self::MAX_FETCH_PER_RUN) {
                Landing_Fetch::get_payload($course_id);
                $fetched++;
                $status = Landing_Fetch::status($course_id);
            }

            $problem = self::diagnose($status);
            if ($problem !== null) {
                $stale[] = [
                    'courseId' => $course_id,
                    'age'      => $status['age_seconds'],
                    'reason'   => $problem,
                ];
            }
        }

        if (empty($stale)) {
            delete_option(self::OPT_REPORT);
            return;
        }

        update_option(self::OPT_REPORT, [
            'at'      => time(),
            'total'   => count($course_ids),
            // Guardamos unos pocos para el detalle; el resto solo cuenta.
            'stale'   => array_slice($stale, 0, 10),
            'count'   => count($stale),
        ], true);
    }

    /**
     * ¿Este curso tiene un problema que valga la pena avisarle al cliente?
     *
     * @param array{served_from:string, age_seconds:?int, backoff_active:bool, has_payload:bool} $status
     * @return string|null Motivo, o null si está sano.
     */
    private static function diagnose(array $status): ?string {
        // Contenido fresco: está todo bien, sin importar si todavía no tenemos
        // registrado cuándo lo trajimos.
        if ($status['served_from'] === 'fresh') {
            return null;
        }

        // Ni siquiera hay copia de respaldo: la landing no se puede dibujar.
        if (!$status['has_payload']) {
            return 'sin_contenido';
        }

        // El último intento falló hace menos de un minuto. Es la señal más
        // directa de que la cadena hacia el LMS está cortada.
        if ($status['backoff_active']) {
            return 'no_alcanza_al_lms';
        }

        $age = $status['age_seconds'];

        // Sin el dato de la edad NO acusamos. Es lo que pasa con el contenido
        // que quedó guardado por una versión anterior del plugin, que todavía no
        // registraba la marca de tiempo: se completa solo en el primer fetch
        // exitoso. Avisar acá sería llenar de alertas a todos los clientes el
        // mismo día que se actualizan, sin que nada esté roto.
        if ($age === null) {
            return null;
        }

        return $age > self::STALE_AFTER ? 'contenido_viejo' : null;
    }

    /**
     * Aviso en el admin. Solo lee una option autoload: cero queries por pantalla.
     */
    public static function render_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $report = get_option(self::OPT_REPORT);
        if (!is_array($report) || empty($report['count'])) {
            return;
        }

        $count = (int) $report['count'];
        $url   = admin_url('options-general.php?page=' . Settings::PAGE_SLUG);

        printf(
            '<div class="notice notice-warning"><p><strong>StudiaHub LMS</strong> — %s '
            . 'La página de venta puede estar mostrando precios o fechas que ya no son los del curso. '
            . '<a href="%s">Ver el estado de la conexión</a>.</p></div>',
            esc_html(
                $count === 1
                    ? 'Hay 1 curso cuyo contenido no se actualiza desde el LMS.'
                    : sprintf('Hay %d cursos cuyo contenido no se actualiza desde el LMS.', $count)
            ),
            esc_url($url)
        );
    }

    /**
     * Los course_id de los productos PUBLICADOS. Un borrador no tiene landing a
     * la vista, así que no es algo por lo que valga la pena avisar.
     *
     * @return string[]
     */
    private static function published_course_ids(): array {
        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_lms_course_id',
                    'compare' => 'EXISTS',
                ],
            ],
            'no_found_rows'  => true,
        ]);

        // `fields => ids` no carga la meta cache, así que sin esto cada
        // get_post_meta de abajo sería una query propia.
        if (!empty($product_ids)) {
            update_meta_cache('post', $product_ids);
        }

        $ids = [];
        foreach ($product_ids as $pid) {
            $cid = (string) get_post_meta((int) $pid, '_lms_course_id', true);
            if ($cid !== '') {
                $ids[$cid] = true;
            }
        }

        return array_keys($ids);
    }
}
