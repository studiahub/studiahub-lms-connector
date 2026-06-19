<?php
namespace SLC;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-actualización del plugin desde GitHub Releases.
 *
 * El repo (studiahub/studiahub-lms-connector) es PÚBLICO, así que el chequeo de
 * updates no necesita ningún token: cada WP consulta las Releases de GitHub y,
 * cuando hay una versión nueva, WordPress la muestra (y la auto-instala via cron)
 * igual que cualquier plugin del repo oficial.
 *
 * Publicar una versión nueva (ver también bin/release.sh):
 *   1. Bump de "Version" en el header del plugin + "Stable tag" en readme.txt.
 *   2. bin/package.sh  ->  genera dist/studiahub-lms-connector-vX.Y.Z.zip
 *   3. gh release create vX.Y.Z dist/studiahub-lms-connector-vX.Y.Z.zip ...
 *      El .zip DEBE ir como ASSET del release: el plugin no vive en la raíz del
 *      repo (está en plugin/studiahub-lms-connector/), así que el zipball
 *      automático de GitHub instalaría la estructura mal.
 */
final class Updater {
    private const REPO_URL    = 'https://github.com/studiahub/studiahub-lms-connector/';
    private const SLUG        = 'studiahub-lms-connector';
    private const ASSET_REGEX = '/studiahub-lms-connector-v[\d.]+\.zip$/i';

    public static function register_hooks(): void {
        $loader = SLC_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
        if (!is_readable($loader)) {
            return; // sin la librería no rompemos el plugin
        }
        require_once $loader;

        if (!class_exists(PucFactory::class)) {
            return;
        }

        $checker = PucFactory::buildUpdateChecker(self::REPO_URL, SLC_PLUGIN_FILE, self::SLUG);

        // Tomar SIEMPRE el .zip adjunto como asset del release. Con
        // REQUIRE_RELEASE_ASSETS, si un release no trae el .zip simplemente no se
        // ofrece update (preferible a instalar mal desde el zipball del repo).
        $vcs = $checker->getVcsApi();
        if (method_exists($vcs, 'enableReleaseAssets')) {
            $vcs->enableReleaseAssets(self::ASSET_REGEX, $vcs::REQUIRE_RELEASE_ASSETS);
        }

        // Auto-update: el cron de cada WP instala la versión nueva solo (~12h).
        // Válvula de escape por sitio: define('SLC_AUTO_UPDATE', false) en wp-config.php
        add_filter('auto_update_plugin', [self::class, 'auto_update'], 10, 2);
    }

    /**
     * Fuerza la auto-actualización SOLO de este plugin (no toca el resto del sitio).
     *
     * @param bool|null $update Decisión previa de WP (puede venir null).
     * @param object    $item   Objeto del update; trae ->slug del plugin.
     * @return bool|null
     */
    public static function auto_update($update, $item) {
        if (defined('SLC_AUTO_UPDATE') && SLC_AUTO_UPDATE === false) {
            return $update;
        }
        if (isset($item->slug) && $item->slug === self::SLUG) {
            return true;
        }
        return $update;
    }
}
