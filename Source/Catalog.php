<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source;

final class Catalog
{
    public const GROUPS = [
        'Reference data' => ['currencies', 'clientgroups', 'tax', 'ticketdepartments', 'ticketstatuses', 'ticketpriorities'],
        'Clients and contacts' => ['clients', 'contacts', 'users', 'users_clients', 'customfields', 'customfieldsvalues'],
        'Servers and products' => ['servers', 'servergroups', 'servergroupsrel', 'productgroups', 'products', 'pricing', 'productconfiggroups', 'productconfiglinks', 'productconfigoptions', 'productconfigoptionssub', 'addons', 'addonmodules'],
        'Services and domains' => ['orders', 'hosting', 'hostingaddons', 'hostingconfigoptions', 'domains', 'domainpricing', 'domaincontacts', 'domainadditionalfields', 'domainlog', 'cancelrequests', 'upgrades', 'hostingusage'],
        'Financial history' => ['invoices', 'invoiceitems', 'accounts', 'credit', 'invoicepayment', 'invoicepayments', 'invoice_transactions', 'transactions', 'billableitems', 'quotes', 'quoteitems', 'promotions', 'affiliates', 'affiliatesaccounts', 'affiliateshistory', 'affiliatespending', 'affiliateswithdrawals'],
        'Support and history' => ['tickets', 'ticketreplies', 'ticketnotes', 'ticketlog', 'ticketmaillog', 'notes', 'emails', 'activitylog', 'adminlog', 'log_register', 'emailtemplates', 'announcements', 'knowledgebase', 'knowledgebasecats', 'knowledgebaselinks', 'downloads', 'downloadcats', 'networkissues'],
    ];

    public static function known(string $suffix): bool
    {
        return in_array($suffix, array_merge(...array_values(self::GROUPS)), true);
    }

    public static function category(string $suffix): string
    {
        foreach (self::GROUPS as $category => $tables) {
            if (in_array($suffix, $tables, true)) {
                return $category;
            }
        }

        return 'Additional discovered tables';
    }
}
