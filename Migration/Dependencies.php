<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Migration;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class Dependencies
{
    public static function expand(array $schema, array $selection): array
    {
        $names = [];
        foreach ($schema as $name => $definition) $names[$definition['suffix']] = $name;
        $dependencies = [
            'clients' => ['currencies', 'clientgroups'],
            'products' => ['productgroups', 'currencies', 'servers', 'servergroups', 'servergroupsrel', 'pricing'],
            'addons' => ['products', 'pricing', 'currencies'],
            'pricing' => ['products', 'addons', 'currencies'],
            'tickets' => ['clients', 'ticketdepartments'],
            'ticketreplies' => ['tickets'], 'ticketnotes' => ['tickets'], 'emails' => ['clients'],
            'invoices' => ['invoiceitems', 'clients', 'currencies'], 'accounts' => ['invoices', 'clients', 'currencies'],
            'credit' => ['clients', 'currencies'],
            'hosting' => ['clients', 'products', 'servers', 'currencies'],
        ];
        $selection = array_values(array_unique($selection));
        for ($index = 0; $index < count($selection); ++$index) {
            $name = $selection[$index];
            if (!is_string($name) || !isset($schema[$name])) throw new SafeException('Unknown source selection.');
            foreach ($dependencies[$schema[$name]['suffix']] ?? [] as $suffix) {
                if (isset($names[$suffix]) && !in_array($names[$suffix], $selection, true)) $selection[] = $names[$suffix];
            }
        }

        return $selection;
    }
}
