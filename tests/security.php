<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Box\Mod\Whmcstofossbilling\Api\Admin;
use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Symfony\Component\HttpFoundation\Request;

if (!defined('SYSTEM_URL')) define('SYSTEM_URL', 'http://fossbill.test/');
$staff = new class {
    public bool $allow = true;
    public array $deniedPermissions = [];
    public int $checks = 0;
    public function checkPermissionsAndThrowException(...$arguments): void
    {
        ++$this->checks;
        if (!$this->allow || in_array($arguments[1] ?? '', $this->deniedPermissions, true)) throw new \FOSSBilling\InformationException('Permission denied', null, 403);
    }
};
$service = new class {
    public function requirements(): array { return []; }
    public function sources(): array { return []; }
    public function runs(): array { return []; }
    public function store(): object { return new class { public function schemaReady(): bool { return true; } }; }
    public function testConnection(array $data): array { throw new RuntimeException('password=NEVER_RETURN_THIS'); }
};
$actor = new \Box\Mod\Staff\Entity\Admin();
$newApi = static function (Request $request, ?object $identity = null) use ($staff, $service, $actor): Admin {
    $di = new Pimple\Container();
    $di['request'] = $request;
    $di['session'] = new class {
        public function get(string $key): string { return 'test-session-csrf'; }
    };
    $di['mod_service'] = $di->protect(static fn (string $module) => $staff);
    $api = new Admin();
    $api->setDi($di);
    $api->setIdentity($identity ?? $actor);
    $api->setService($service);
    return $api;
};
$denied = static function (callable $operation, string $label): void {
    try {
        $operation();
        expect(false, $label);
    } catch (\FOSSBilling\InformationException $error) {
        expect(true, $label);
    }
};
foreach (['dashboard', 'schema_upgrade', 'public_ip', 'test_connection', 'timezone_confirm', 'mapping_get', 'mapping_save', 'analysis_prepare', 'analyze_table', 'run_create', 'run_existing', 'run_status', 'run_control', 'run_batch', 'run_retry', 'archive_search', 'report_export'] as $endpoint) {
    $request = Request::create(SYSTEM_URL . 'api/admin/whmcstofossbilling/' . $endpoint, 'POST');
    $denied(fn () => $newApi($request)->{$endpoint}([]), $endpoint . ' rejects missing CSRF before service execution');
}
$request = Request::create(SYSTEM_URL, 'GET', [], [], [], ['HTTP_X_CSRF_TOKEN' => 'test-session-csrf']);
$denied(fn () => $newApi($request)->dashboard([]), 'GET cannot invoke migration endpoints');
$request = Request::create(SYSTEM_URL, 'POST', [], [], [], ['HTTP_X_CSRF_TOKEN' => 'wrong']);
$denied(fn () => $newApi($request)->dashboard([]), 'Incorrect CSRF rejected');
$request = Request::create(SYSTEM_URL, 'POST', [], [], [], ['HTTP_X_CSRF_TOKEN' => 'test-session-csrf', 'HTTP_ORIGIN' => 'https://attacker.invalid']);
$denied(fn () => $newApi($request)->dashboard([]), 'Cross-origin requests rejected');
$request = Request::create(SYSTEM_URL, 'POST', [], [], [], ['HTTP_X_CSRF_TOKEN' => 'test-session-csrf']);
$denied(fn () => $newApi($request, new \FOSSBilling\Identity\Guest())->dashboard([]), 'Guest identity rejected even when permission stub permits');
$staff->allow = false;
$denied(fn () => $newApi($request)->dashboard([]), 'Explicit endpoint permission denial respected');
$staff->allow = true;
$staff->deniedPermissions = ['configure'];
$denied(fn () => $newApi($request)->analysis_prepare([]), 'Execute permission alone cannot prepare automatic analysis');
$denied(fn () => $newApi($request)->analyze_table([]), 'Automatic refresh retains configure permission on each table request');
$staff->deniedPermissions = [];
$staff->deniedPermissions = ['view'];
$denied(fn () => $newApi($request)->run_existing([]), 'Unfinished-run lookup requires view permission before reading saved plans');
$staff->deniedPermissions = [];
expect($newApi($request)->dashboard([]) === ['environment' => [], 'schema_upgrade_required' => false, 'sources' => [], 'runs' => []], 'Session-bound admin request accepted');
$denied(fn () => $newApi($request)->public_ip([]), 'Public-IP lookup requires explicit external-request confirmation before network access');
$denied(fn () => $newApi($request)->timezone_confirm([]), 'Timezone override requires explicit application-timezone confirmation');
try {
    $newApi($request)->test_connection(['password' => 'NEVER_RETURN_THIS']);
} catch (\FOSSBilling\InformationException $error) {
    expect(!str_contains($error->getMessage(), 'NEVER_RETURN_THIS') && $error->getPrevious() === null, 'Unexpected exception text and credential-bearing previous exception are withheld');
}
expect($staff->checks >= 19, 'Permission checks run on every exercised endpoint');
echo "Security suite finished.\n";
