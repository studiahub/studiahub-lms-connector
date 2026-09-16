<?php
/** Prueba aislada del transporte LMS; ejecutar: php tests/lms-url-security.php */
namespace {
    define('ABSPATH', __DIR__);
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }

    require __DIR__ . '/../plugin/studiahub-lms-connector/includes/class-settings.php';

    $cases = [
        'https://lms.example.com' => true,
        'http://lms.example.com' => false,
        'http://localhost:3000' => true,
        'http://127.0.0.2:3000' => true,
        'http://[::1]:3000' => true,
        'http://host.docker.internal:3000' => true,
        'http://127.evil.example' => false,
        'http://127.0.0.1.evil.example' => false,
        'ftp://lms.example.com' => false,
        'not-a-url' => false,
    ];

    $failures = [];
    foreach ($cases as $url => $expected) {
        if (\SLC\Settings::is_secure_lms_url($url) !== $expected) {
            $failures[] = $url;
        }
    }

    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    echo (count($cases) - count($failures)) . '/' . count($cases) . " assertions passed\n";
    exit($failures ? 1 : 0);
}
