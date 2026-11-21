<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Mapping;

use Box\Mod\Whmcsmigrator\Security\SafeException;
use Box\Mod\Whmcsmigrator\Source\Catalog;

final class Profile
{
    public const CLIENT_FIELDS = [
        'firstname' => 'first_name', 'lastname' => 'last_name', 'companyname' => 'company',
        'email' => 'email', 'address1' => 'address_1', 'address2' => 'address_2', 'city' => 'city',
        'state' => 'state', 'postcode' => 'postcode', 'country' => 'country', 'phonenumber' => 'phone',
        'tax_id' => 'company_vat', 'notes' => 'notes',
    ];

    public static function defaults(array $schema): array
    {
        $profile = ['version' => 1, 'fields' => []];
        foreach ($schema as $name => $table) {
            foreach ($table['columns'] as $column => $definition) {
                $target = $table['suffix'] === 'clients' ? (self::CLIENT_FIELDS[$column] ?? null) : null;
                $profile['fields'][$name][$column] = [
                    'action' => $definition['sensitive'] || $table['classification'] === 'excluded_sensitive' ? 'exclude_sensitive' : ($target ? 'native_and_archive' : 'archive'),
                    'target' => $target,
                    'transform' => 'identity',
                    'reason' => '',
                ];
            }
        }

        return $profile;
    }

    public static function validate(array $profile, array $schema): array
    {
        if (($profile['version'] ?? null) !== 1 || !is_array($profile['fields'] ?? null)) {
            throw new SafeException('Mapping profile format is invalid.');
        }
        $clean = self::defaults($schema);
        if (array_diff(array_keys($profile['fields']), array_keys($schema))) {
            throw new SafeException('The profile contains unknown source tables.');
        }
        foreach ($schema as $name => $table) {
            if (array_diff(array_keys($profile['fields'][$name] ?? []), array_keys($table['columns']))) {
                throw new SafeException('The profile contains unknown source fields.');
            }
            $used = [];
            foreach ($table['columns'] as $column => $definition) {
                $rule = $profile['fields'][$name][$column] ?? throw new SafeException('The mapping profile is incomplete.');
                if ($definition['sensitive'] || $table['classification'] === 'excluded_sensitive') {
                    continue;
                }
                if (!in_array($rule['action'] ?? '', ['archive', 'skip', 'native_and_archive'], true)) {
                    throw new SafeException('Unsupported mapping action. Arbitrary expressions, SQL and PHP are not permitted.');
                }
                if (!in_array($rule['transform'] ?? '', ['identity', 'trim', 'lowercase'], true)) {
                    throw new SafeException('Unsupported transformation.');
                }
                $reason = trim((string) ($rule['reason'] ?? ''));
                if ($rule['action'] === 'skip' && ($reason === '' || strlen($reason) > 255)) {
                    throw new SafeException('Intentionally skipped fields require a short reason.');
                }
                if ($rule['action'] === 'skip' && ($column === $table['key'] || in_array($table['suffix'], ['invoices', 'invoiceitems', 'accounts', 'credit', 'hosting', 'domains', 'pricing'], true))) {
                    throw new SafeException('Source keys and financial/service fields cannot be discarded by a profile.');
                }
                if ($rule['action'] === 'skip' && (
                    in_array($table['suffix'], ['products', 'addons', 'servers', 'productgroups', 'ticketdepartments', 'tickets', 'ticketreplies', 'ticketnotes', 'emails'], true)
                    || ($table['suffix'] === 'clients' && in_array($column, ['currency', 'groupid', 'datecreated', 'status', 'credit', 'taxexempt'], true))
                )) {
                    throw new SafeException('This field is used by a fixed native adapter or financial assessment and cannot be skipped. Deselect the category if preservation is not appropriate.');
                }
                $target = null;
                if ($rule['action'] === 'native_and_archive') {
                    $target = $rule['target'] ?? null;
                    if ($table['suffix'] !== 'clients' || !in_array($target, self::CLIENT_FIELDS, true) || isset($used[$target])) {
                        throw new SafeException('The selected native mapping is incompatible or duplicates a destination field.');
                    }
                    $used[$target] = true;
                }
                $clean['fields'][$name][$column] = ['action' => $rule['action'], 'target' => $target, 'transform' => $rule['transform'], 'reason' => $reason];
            }
        }

        return $clean;
    }

    public static function rebase(array $saved, array $schema): array
    {
        $candidate = self::defaults($schema);
        foreach ($schema as $name => $table) {
            foreach ($table['columns'] as $column => $definition) {
                if ($definition['sensitive'] || $table['classification'] === 'excluded_sensitive') continue;
                if (isset($saved['fields'][$name][$column])) $candidate['fields'][$name][$column] = $saved['fields'][$name][$column];
            }
        }
        try {
            return self::validate($candidate, $schema);
        } catch (SafeException) {
            throw new SafeException('Connection/schema inspection succeeded, but saved mappings need explicit review after this schema/policy change. No saved mapping was overwritten. Select the previous source, export/review its profile and restore valid defaults if necessary, then test again.');
        }
    }

    public static function describe(array $schema, array $profile): array
    {
        $rows = [];
        foreach ($schema as $name => $table) {
            foreach ($table['columns'] as $column => $definition) {
                $rows[] = [
                    'category' => Catalog::category($table['suffix']), 'table' => $name, 'field' => $column,
                    'type' => $definition['type'], 'classification' => $table['classification'],
                    'required' => $column === $table['key'],
                    ...$profile['fields'][$name][$column],
                ];
            }
        }

        return $rows;
    }

    public static function transform(mixed $value, string $rule): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($rule) {
            'identity' => $value,
            'trim' => trim((string) $value),
            'lowercase' => mb_strtolower(trim((string) $value), 'UTF-8'),
            default => throw new SafeException('Unsupported transformation.'),
        };
    }
}
