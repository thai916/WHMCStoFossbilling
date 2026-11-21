<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Test harness only: the actual FOSSBilling layout remains untouched.
$loader = new Twig\Loader\ChainLoader([
    new Twig\Loader\ArrayLoader(['layout_default.html.twig' => '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{% block meta_title %}{% endblock %}</title><link rel="stylesheet" href="/vendor.css"><link rel="stylesheet" href="/fossbilling.css">{% block head %}{% endblock %}</head><body><main class="container-xl py-4">{% block content %}{% endblock %}</main></body></html>']),
    new Twig\Loader\FilesystemLoader(dirname(__DIR__) . '/templates/admin'),
]);
$twig = new Twig\Environment($loader, ['cache' => false, 'strict_variables' => true, 'autoescape' => 'html']);
$html = $twig->render('mod_whmcsmigrator_index.html.twig', [
    'migrator_csrf' => 'test-session-csrf', 'migrator_api' => '/api/admin/whmcsmigrator',
    'migrator_js' => '/wizard.js', 'migrator_css' => '/wizard.css',
    'migrator_icon' => '/icon.png',
    'migrator_ca' => \Box\Mod\Whmcsmigrator\Source\CaBundle::defaultPath(),
    'migrator_timezones' => array_values(array_unique(['UTC', ...DateTimeZone::listIdentifiers()])),
]);
if (in_array('--stdout', $argv, true)) {
    echo $html;
} else {
    expect(str_contains($html, 'Monlam Makhampa') && str_contains($html, 'vibe coded'), 'Author and vibe coded disclosure render correctly');
    expect(str_contains($html, 'not a complete billing migration'), 'Incomplete-release warning is visible');
    expect(str_contains($html, 'Test connection') && str_contains($html, 'PRESERVE HISTORY'), 'Connection and explicit authorization controls render');
    expect(str_contains($html, 'FOSSB to WHMCS Migrator') && str_contains($html, '/icon.png') && str_contains($html, 'WHMCS 8.x → FOSSBilling 0.8.9'), 'Requested branding/icon retain the actual migration direction');
    expect(str_contains($html, 'Auto-detect (recommended)') && str_contains($html, 'value="America/Chicago" selected') && !str_contains($html, 'Automatic source timezone inspection') && !str_contains($html, 'wm-timezone-evidence'), 'Version auto-detection remains while timezone uses an explicit dropdown with America/Chicago default and no automatic evidence panel');
    expect(str_contains($html, 'value="standard" selected') && str_contains($html, 'Standard MySQL Connection (No TLS) (default)') && str_contains($html, 'wm-security-acknowledged'), 'Three connection modes retain standard no-TLS as default and explicit weaker-mode confirmation');
    expect(str_contains(file_get_contents(dirname(__DIR__) . '/assets/wizard.css'), '#0b5d2a'), 'Connection success text uses a dark green colour');
    $root = dirname(__DIR__, 3);
    if (!defined('PATH_MODS')) define('PATH_MODS', $root . '/modules');
    $module = new \FOSSBilling\Module('whmcsmigrator');
    $di = new Pimple\Container();
    $di['filesystem'] = new Symfony\Component\Filesystem\Filesystem();
    $module->setDi($di);
    $manifest = $module->getManifest();
    expect($manifest['author'] === 'Monlam Makhampa' && $manifest['version'] === '0.2', 'Native module loader recognizes the manifest and author');
    expect($manifest['name'] === 'FOSSB to WHMCS Migrator' && $manifest['icon_url'] === '/modules/Whmcsmigrator/icon.png' && is_file($root . $manifest['icon_url']), 'Extension-list manifest points at the supplied local icon');
    expect($module->hasAdminController() && $module->hasService() && $module->hasSettingsPage(), 'Native module loader discovers controller, service and settings');
    expect($module->getAdminController() instanceof \Box\Mod\Whmcsmigrator\Controller\Admin, 'Native controller autoloading works without Composer edits');
    echo "Rendering and discovery suite finished.\n";
}
