<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Controller;

use Symfony\Component\HttpFoundation\Response;

final class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function fetchNavigation(): array
    {
        return ['subpages' => [[
            'location' => 'extensions', 'index' => 300, 'label' => 'WHMCStoFossbilling',
            'uri' => $this->di['url']->adminLink('whmcstofossbilling'), 'class' => '',
        ]]];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/whmcstofossbilling', 'get_index', [], static::class);
        $app->get('/whmcstofossbilling/asset/:name', 'get_asset', ['name' => 'wizard\.(?:js|css)'], static::class);
    }

    private function authorize(): void
    {
        $this->di['is_admin_logged'];
        $this->di['mod_service']('staff')->checkPermissionsAndThrowException('whmcstofossbilling', 'view', null, $this->di['loggedin_admin']);
    }

    public function get_index(\Box_App $app): string
    {
        $this->authorize();

        return $app->render('mod_whmcstofossbilling_index', [
            'migrator_csrf' => $this->di['session']->get('csrf_token'),
            // Use the current admin origin, retaining any installation subdirectory.
            // A configured canonical hostname/scheme must not drop the browser's session cookies.
            'migrator_api' => parse_url($this->di['url']->link('api/admin/whmcstofossbilling'), PHP_URL_PATH),
            'migrator_js' => $this->di['url']->adminLink('whmcstofossbilling/asset/wizard.js'),
            'migrator_css' => $this->di['url']->adminLink('whmcstofossbilling/asset/wizard.css'),
            'migrator_icon' => $this->di['url']->link('modules/Whmcstofossbilling/icon.png'),
            'migrator_ca' => \Box\Mod\Whmcstofossbilling\Source\CaBundle::defaultPath(),
            'migrator_timezones' => array_values(array_unique(['UTC', ...\DateTimeZone::listIdentifiers()])),
        ]);
    }

    public function get_asset(\Box_App $app, string $name): Response
    {
        $this->authorize();
        if (!in_array($name, ['wizard.js', 'wizard.css'], true)) {
            return new Response('', 404);
        }

        return new Response(file_get_contents(dirname(__DIR__) . '/assets/' . $name), 200, [
            'Content-Type' => str_ends_with($name, '.js') ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
