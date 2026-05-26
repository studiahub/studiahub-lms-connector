<?php
/**
 * Plugin Name: ZZ Test Shortcode Render
 * Description: Test endpoint /?slc_test_render=1[&id=NN][&variant=pitch] que renderiza el shortcode de la landing en una página minimal.
 *              SOLO PARA DEV LOCAL — se quita después.
 */

add_action('template_redirect', function () {
    if (!isset($_GET['slc_test_render'])) return;
    $variant = isset($_GET['variant']) && $_GET['variant'] === 'pitch'
        ? '[studiahub_course_pitch'
        : '[studiahub_course_page';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $sc = $id > 0 ? $variant . ' id="' . $id . '"]' : $variant . ']';

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SLC test render</title>
<?php wp_head(); ?>
<style>
body { margin: 0; padding: 0; background: #fff; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
.slc-test-bar { position: fixed; top: 0; left: 0; right: 0; background: #0F172A; color: #fff; padding: 8px 16px; font-size: 12px; z-index: 99999; }
.slc-test-bar a { color: #FAB005; margin-left: 12px; }
.slc-test-content { padding-top: 36px; }
</style>
</head><body>
<div class="slc-test-bar">SLC test render — shortcode: <code><?php echo esc_html($sc); ?></code>
  <a href="?slc_test_render=1&variant=page<?php if ($id) echo '&id=' . $id; ?>">page</a>
  <a href="?slc_test_render=1&variant=pitch<?php if ($id) echo '&id=' . $id; ?>">pitch</a>
</div>
<div class="slc-test-content"><?php echo do_shortcode($sc); ?></div>
<?php wp_footer(); ?>
</body></html><?php
    exit;
});
