<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Api;

use Box\Mod\Whmcsmigrator\Report\Exporter;
use Box\Mod\Whmcsmigrator\Security\SafeException;
use Box\Mod\Whmcsmigrator\Security\Sensitive;
use Symfony\Component\HttpFoundation\Response;

final class Admin extends \FOSSBilling\Api\AbstractApi
{
    private function guarded(string $permission, callable $operation): mixed
    {
        $this->checkPermissions('whmcsmigrator', $permission);
        if (\FOSSBilling\Api\Identity::typeFromObject($this->getIdentity()) !== 'admin') {
            throw new \FOSSBilling\InformationException('An authenticated administrator is required.', null, 403);
        }
        // Enforce POST + CSRF even when global CSRF protection is disabled or a token API is used.
        $request = $this->di['request'];
        $token = $request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('CSRFToken');
        $expected = $this->di['session']->get('csrf_token');
        if (!$request->isMethod('POST') || !is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
            throw new \FOSSBilling\InformationException('A session-bound POST request with a valid CSRF token is required.', null, 403);
        }
        $origin = $request->headers->get('Origin');
        $url = \Symfony\Component\HttpFoundation\Request::create((string) \FOSSBilling\Config::getProperty('url'));
        // SYSTEM_URL is normalized by FOSSBilling; a scheme-less configured URL is not.
        $expectedOrigin = defined('SYSTEM_URL') ? \Symfony\Component\HttpFoundation\Request::create(SYSTEM_URL)->getSchemeAndHttpHost() : $url->getSchemeAndHttpHost();
        if (($origin !== null && strtolower($origin) !== strtolower($expectedOrigin)) || $request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            throw new \FOSSBilling\InformationException('Cross-origin migration requests are not allowed.', null, 403);
        }
        try {
            $result = $operation();
            if ($result instanceof Response) {
                return $result;
            }

            return $result;
        } catch (\Throwable $error) {
            // Do not attach the original exception: trace arguments may contain production credentials.
            throw new \FOSSBilling\InformationException(Sensitive::error($error), null, 0, true);
        }
    }

    public function dashboard(array $data = []): array
    {
        return $this->guarded('view', fn (): array => [
            'environment' => $this->getService()->requirements(),
            'schema_upgrade_required' => !$this->getService()->store()->schemaReady(),
            'sources' => $this->getService()->store()->schemaReady() ? $this->getService()->sources() : [],
            'runs' => $this->getService()->store()->schemaReady() ? $this->getService()->runs() : [],
        ]);
    }

    public function schema_upgrade(array $data = []): array
    {
        return $this->guarded('configure', function () use ($data): array {
            $this->checkPermissions('whmcsmigrator', 'execute');
            if (($data['confirmation'] ?? '') !== 'UPGRADE EXTENSION TABLES') throw new SafeException('Explicit extension-schema upgrade confirmation is required.');

            return ['installed' => $this->getService()->install()];
        });
    }

    public function public_ip(array $data = []): array
    {
        return $this->guarded('configure', function () use ($data): array {
            if (($data['confirmation'] ?? '') !== 'LOOK UP PUBLIC IP') throw new SafeException('Confirm the external public-IP lookup first. The lookup provider will see the server’s outbound IP.');

            return \Box\Mod\Whmcsmigrator\Source\PublicIp::lookup();
        });
    }

    public function test_connection(#[\SensitiveParameter] array $data): array
    {
        return $this->guarded('configure', fn (): array => $this->getService()->testConnection($data));
    }

    public function mapping_get(array $data): array
    {
        return $this->guarded('configure', fn (): array => $this->getService()->mapping((int) ($data['source_id'] ?? 0)));
    }

    public function timezone_confirm(array $data): array
    {
        return $this->guarded('configure', function () use ($data): array {
            if (($data['confirmation'] ?? '') !== 'VERIFY SOURCE TIMEZONE') throw new SafeException('Explicitly verify the WHMCS application timezone before applying an override.');

            return $this->getService()->confirmSourceTimezone((int) ($data['source_id'] ?? 0), (string) ($data['timezone'] ?? ''));
        });
    }

    public function mapping_save(array $data): array
    {
        return $this->guarded('configure', fn (): array => $this->getService()->saveMapping((int) ($data['source_id'] ?? 0), $data['profile'] ?? []));
    }

    public function analyze_table(array $data): array
    {
        return $this->guarded('configure', fn (): array => $this->getService()->analyze((int) ($data['source_id'] ?? 0), (string) ($data['table'] ?? '')));
    }

    public function run_create(array $data): array
    {
        return $this->guarded('execute', function () use ($data): array {
            $identity = $this->getIdentity();
            $id = method_exists($identity, 'getId') ? $identity->getId() : $identity->id;

            return $this->getService()->createRun($data, (int) $id);
        });
    }

    public function run_status(array $data): array
    {
        return $this->guarded('view', fn (): array => $this->getService()->status((string) ($data['run_id'] ?? '')));
    }

    public function run_control(array $data): array
    {
        return $this->guarded('execute', fn (): array => $this->getService()->control((string) ($data['run_id'] ?? ''), (string) ($data['action'] ?? '')));
    }

    public function run_batch(array $data): array
    {
        return $this->guarded('execute', fn (): array => $this->getService()->batch((string) ($data['run_id'] ?? ''), (string) ($data['request_id'] ?? ''), (int) ($data['limit'] ?? 50)));
    }

    public function run_retry(array $data): array
    {
        return $this->guarded('execute', fn (): array => $this->getService()->retry((string) ($data['run_id'] ?? '')));
    }

    public function archive_search(array $data): Response
    {
        return $this->guarded('archive', fn (): Response => new \Symfony\Component\HttpFoundation\JsonResponse(
            ['result' => $this->getService()->archive($data), 'error' => null],
            200,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        ));
    }

    public function report_export(array $data): Response
    {
        return $this->guarded('export', fn (): Response => (new Exporter($this->getService()))->response((string) ($data['run_id'] ?? ''), (string) ($data['format'] ?? 'json')));
    }
}
