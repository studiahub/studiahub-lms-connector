<?php
namespace SLC;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetch del landing payload del LMS, con caché en capas y stale-while-revalidate.
 *
 * CAPAS (de más fresca a más vieja)
 * ---------------------------------
 * 1. `slc_landing_<hash>`        transient, 15 min  → la copia fresca.
 * 2. `slc_landing_<hash>_stale`  transient, 7 días  → colchón de corto plazo.
 * 3. `slc_landing_lkg_<hash>`    OPTION, sin TTL    → última copia buena (LKG).
 *
 * Por qué la tercera capa: los transients no son almacenamiento confiable. Con
 * un object cache persistente (Redis/Memcached, lo normal en cualquier hosting
 * decente) viven en memoria y se pueden desalojar en CUALQUIER momento; y aun
 * sin object cache, el de 7 días expira. Cuando eso pasaba justo mientras el LMS
 * estaba caído, la landing no daba error: se servía vacía. Medido, la página del
 * curso pasaba de 106 KB a 39 KB, sin una sola mención del temario — o sea una
 * página de venta sin producto, que es peor que un error, porque nadie se entera.
 * La option vive en la tabla `wp_options` (no se desaloja, no expira) y se
 * reescribe en cada fetch exitoso.
 *
 * BACKOFF
 * -------
 * `slc_landing_fail_<hash>` (60s) marca que el LMS acaba de fallar. Mientras
 * existe, ni intentamos el fetch: vamos derecho a la copia vieja. Sin esto, con
 * el LMS caído CADA visita se come el timeout de 5s antes de renderizar, y una
 * caída del LMS se convierte en una caída de la tienda del cliente.
 *
 * CURSO BORRADO
 * -------------
 * Un 404/410 del LMS no es una caída, es "este curso ya no existe": ahí sí
 * borramos todo, incluido el LKG. Si no, la landing de un curso dado de baja
 * seguiría vendiéndolo para siempre.
 *
 * Auth: usamos OPT_WEBHOOK_SECRET como Bearer. Es el "secret unificado"
 * generado en el pair: el LMS lo guarda como API key (cifrada at-rest),
 * el plugin lo guarda en plaintext (acá) + hash (para verificar requests
 * entrantes en Auth).
 */
final class Landing_Fetch {
    public const TTL_FRESH    = 900;     // 15 min
    private const TTL_STALE   = 604800;  // 7 días
    private const TTL_BACKOFF = 60;      // 1 min sin reintentar tras una falla
    private const TIMEOUT_S   = 5;

    /** Memo por request: la misma landing se pide varias veces por render. */
    private static array $memo = [];

    /**
     * Memo SEPARADO del camino cache-only (get_cached_payload).
     *
     * No puede compartir $memo con get_payload(): `woocommerce_is_purchasable`
     * corre en cada render de producto ANTES del contenido y entra por el camino
     * cache-only. Si eso memoizara en $memo, el shortcode que viene después se
     * encontraría la copia vieja ya resuelta y devolvería sin fetchear nunca —
     * y como el LKG solo se pisa con un fetch exitoso, la landing quedaba
     * congelada para siempre. Pasó en producción: un mes sin actualizarse.
     */
    private static array $memo_cached = [];

    public static function get_payload(string $course_id): ?array {
        // 0. Filter de override — usado por el mu-plugin de dev para inyectar
        // un payload mockeado y permitir trabajar el diseño sin LMS corriendo.
        // Si el filter devuelve un array, se devuelve directamente saltando
        // cache + fetch. Cualquier otra cosa (null/false/etc) sigue el flow normal.
        $override = apply_filters('slc_landing_payload_override', null, $course_id);
        if (is_array($override)) {
            return $override;
        }

        if (array_key_exists($course_id, self::$memo)) {
            return self::$memo[$course_id];
        }

        $keys = self::keys($course_id);

        // 1. Fresh hit.
        $fresh = get_transient($keys['fresh']);
        if (is_array($fresh)) {
            return self::$memo[$course_id] = $fresh;
        }

        // 2. Fetch al LMS, salvo que acabe de fallar (backoff).
        if (get_transient($keys['backoff']) === false) {
            $result = self::fetch_from_lms($course_id);

            if (is_array($result['payload'])) {
                // 2a. Éxito → refrescar las tres capas.
                set_transient($keys['fresh'], $result['payload'], self::TTL_FRESH);
                set_transient($keys['stale'], $result['payload'], self::TTL_STALE);
                self::store_last_known_good($keys['lkg'], $result['payload']);
                // Marca de "esto lo trajimos del LMS recién". Sin esto no hay forma
                // de distinguir un payload de hace cinco minutos de uno de hace tres
                // semanas: las tres capas se ven igual de frescas al leerlas.
                update_option($keys['at'], time(), false);
                return self::$memo[$course_id] = $result['payload'];
            }

            if ($result['gone']) {
                // 2b. El curso ya no existe en el LMS: la copia vieja dejó de
                // ser buena. Sin esto la landing seguiría vendiendo un curso
                // dado de baja hasta que alguien la borrara a mano.
                self::purge($course_id);
                return self::$memo[$course_id] = null;
            }

            // 2c. Falla transitoria → no volver a intentar por un rato.
            set_transient($keys['backoff'], 1, self::TTL_BACKOFF);
        }

        // 3. Servir la última copia buena que tengamos.
        return self::$memo[$course_id] = self::read_cached($keys);
    }

    /**
     * Igual que get_payload() pero SIN salir a la red: solo lo que ya está
     * cacheado (fresh → stale → LKG).
     *
     * Existe para los caminos que corren en cada request de WooCommerce y no
     * pueden permitirse un HTTP de 5s: el gate de compra (`Purchase_Gate`)
     * evalúa `is_purchasable` en cada producto de cada listado. Usar
     * get_payload() ahí convertiría una caída del LMS en una tienda inusable.
     */
    public static function get_cached_payload(string $course_id): ?array {
        $override = apply_filters('slc_landing_payload_override', null, $course_id);
        if (is_array($override)) {
            return $override;
        }
        // Si get_payload() ya resolvió en este request, esa respuesta es la mejor
        // que tenemos (puede venir de un fetch fresco) y vale para este camino.
        if (array_key_exists($course_id, self::$memo)) {
            return self::$memo[$course_id];
        }
        if (array_key_exists($course_id, self::$memo_cached)) {
            return self::$memo_cached[$course_id];
        }

        $cached = self::read_cached(self::keys($course_id));
        // Un "no hay nada cacheado" NO se memoiza: no es una respuesta, es la
        // ausencia de una. Si se guardara, un get_payload() posterior del mismo
        // request —que sí puede ir al LMS— devolvería este null sin siquiera
        // intentarlo. Pasa en el flujo real: is_purchasable() consulta la caché
        // y, un par de líneas después, el carrito necesita la respuesta de verdad.
        if ($cached === null) {
            return null;
        }
        // Va al memo cache-only: NO puede envenenar el de get_payload().
        return self::$memo_cached[$course_id] = $cached;
    }

    /**
     * Invalida las copias con TTL para que el próximo render vaya al LMS.
     *
     * NO toca el LKG a propósito: un cache-bust dice "el curso cambió", no "el
     * curso desapareció". Si justo en ese momento el LMS no contesta, la landing
     * tiene que seguir mostrando el contenido anterior en vez de vaciarse. El
     * LKG se pisa solo con el próximo fetch exitoso, o se borra en purge().
     */
    public static function bust(string $course_id): void {
        $keys = self::keys($course_id);
        delete_transient($keys['fresh']);
        delete_transient($keys['stale']);
        delete_transient($keys['backoff']);
        unset(self::$memo[$course_id], self::$memo_cached[$course_id]);
    }

    /** Borra TODO lo cacheado del curso, incluida la última copia buena. */
    public static function purge(string $course_id): void {
        $keys = self::keys($course_id);
        delete_transient($keys['fresh']);
        delete_transient($keys['stale']);
        delete_transient($keys['backoff']);
        delete_option($keys['lkg']);
        delete_option($keys['at']);
        unset(self::$memo[$course_id], self::$memo_cached[$course_id]);
    }

    /**
     * Radiografía de la caché de un curso, para diagnóstico.
     *
     * NO fetchea, NO escribe y NO memoiza: es seguro llamarla desde un endpoint
     * o desde el admin sin alterar lo que ve el visitante. Lo aclaro porque el
     * bug que motivó todo esto se nos escondió justamente porque cada intento de
     * medirlo lo arreglaba sin querer.
     *
     * @return array{served_from:string, last_success_at:?int, age_seconds:?int,
     *               backoff_active:bool, has_payload:bool, content_version:?string}
     */
    public static function status(string $course_id): array {
        // Si hay un override (el mock de dev), es lo que realmente se está
        // renderizando. Reportar la caché de abajo sería mentir, y el cron
        // terminaría avisando de un problema inexistente en cada entorno local.
        $override = apply_filters('slc_landing_payload_override', null, $course_id);
        if (is_array($override)) {
            return [
                'served_from'     => 'override',
                'last_success_at' => null,
                'age_seconds'     => null,
                'backoff_active'  => false,
                'has_payload'     => true,
                'content_version' => isset($override['contentVersion'])
                    ? (string) $override['contentVersion']
                    : null,
            ];
        }

        $keys    = self::keys($course_id);
        $payload = null;
        $from    = 'none';

        // Mismo orden que read_cached(), para reportar la capa que se serviría.
        $fresh = get_transient($keys['fresh']);
        if (is_array($fresh)) {
            $payload = $fresh;
            $from    = 'fresh';
        } else {
            $stale = get_transient($keys['stale']);
            if (is_array($stale)) {
                $payload = $stale;
                $from    = 'stale';
            } else {
                $lkg = get_option($keys['lkg']);
                if (is_array($lkg)) {
                    $payload = $lkg;
                    $from    = 'lkg';
                }
            }
        }

        $at = (int) get_option($keys['at'], 0);

        return [
            'served_from'     => $from,
            'last_success_at' => $at > 0 ? $at : null,
            'age_seconds'     => $at > 0 ? max(0, time() - $at) : null,
            'backoff_active'  => get_transient($keys['backoff']) !== false,
            'has_payload'     => is_array($payload),
            // Huella de contenido que manda el LMS. Es lo que le permite al canario
            // comparar lo que estamos sirviendo contra lo que el LMS tiene ahora,
            // sin depender de que alguien mire la página.
            'content_version' => is_array($payload) && isset($payload['contentVersion'])
                ? (string) $payload['contentVersion']
                : null,
        ];
    }

    /**
     * @return array{fresh:string, stale:string, backoff:string, lkg:string, at:string}
     */
    private static function keys(string $course_id): array {
        $hash = md5($course_id);
        return [
            'fresh'   => 'slc_landing_' . $hash,
            'stale'   => 'slc_landing_' . $hash . '_stale',
            'backoff' => 'slc_landing_fail_' . $hash,
            // Nombre de option: hasta 191 chars en WP, el hash entra holgado.
            'lkg'     => 'slc_landing_lkg_' . $hash,
            // Timestamp del último fetch exitoso, en una option APARTE.
            //
            // Aparte y no adentro del LKG a propósito: el LKG guarda el payload
            // plano y read_cached() lo devuelve tal cual. Envolverlo para meterle
            // el timestamp haría que se sirva el envoltorio como si fuera el
            // payload — landing vacía — y encima habría que migrar los LKG que ya
            // están guardados en cada cliente. Separado no se migra nada, y volver
            // a una versión anterior del plugin simplemente ignora esta option.
            'at'      => 'slc_landing_at_' . $hash,
        ];
    }

    /** @param array{fresh:string, stale:string, backoff:string, lkg:string} $keys */
    private static function read_cached(array $keys): ?array {
        $fresh = get_transient($keys['fresh']);
        if (is_array($fresh)) {
            return $fresh;
        }
        $stale = get_transient($keys['stale']);
        if (is_array($stale)) {
            return $stale;
        }
        $lkg = get_option($keys['lkg']);
        return is_array($lkg) ? $lkg : null;
    }

    private static function store_last_known_good(string $key, array $payload): void {
        // autoload false: son payloads grandes y solo se leen en la landing del
        // curso; cargarlos en cada request de WordPress sería un desperdicio.
        // update_option() crea la option si no existe, con este mismo autoload.
        update_option($key, $payload, false);
    }

    /**
     * @return array{payload: ?array, gone: bool}
     *         `gone` = el LMS dice que el curso no existe (404/410), no que se cayó.
     */
    private static function fetch_from_lms(string $course_id): array {
        $lms_url = (string) get_option(Settings::OPT_LMS_URL, '');
        $api_key = (string) get_option(Settings::OPT_WEBHOOK_SECRET, '');
        if ($lms_url === '' || $api_key === '') {
            return ['payload' => null, 'gone' => false];
        }

        $url = rtrim($lms_url, '/') . '/api/wc/courses/' . rawurlencode($course_id) . '/landing-payload';
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT_S,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('[slc landing-fetch] WP error: ' . $response->get_error_message());
            return ['payload' => null, 'gone' => false];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('[slc landing-fetch] HTTP ' . $code . ' for ' . $url);
            return ['payload' => null, 'gone' => in_array($code, [404, 410], true)];
        }

        $body    = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        return ['payload' => is_array($decoded) ? $decoded : null, 'gone' => false];
    }
}
