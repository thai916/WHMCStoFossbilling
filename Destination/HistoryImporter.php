<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Destination;

use Box\Mod\Whmcstofossbilling\Archive\Sanitizer;
use Box\Mod\Whmcstofossbilling\Mapping\EventDate;
use Box\Mod\Whmcstofossbilling\Security\SafeException;

final class HistoryImporter
{
    public const ENTITIES = [
        'ticketdepartments' => 'support_helpdesk', 'tickets' => 'support_ticket',
        'ticketreplies' => 'support_ticket_message', 'ticketnotes' => 'support_ticket_note',
        'emails' => 'activity_client_email',
    ];

    private readonly Persistence $p;

    public function __construct(private readonly Store $store, private readonly int $source, private readonly string $prefix, private readonly ?string $timezone)
    {
        $this->p = new Persistence($store, $source, $prefix);
    }

    public function import(string $suffix, array $row, string $checksum, string $run): string
    {
        $entity = self::ENTITIES[$suffix];
        $key = (string) $row['id'];
        if ($this->p->unchanged($suffix, $key, $entity, $checksum)) {
            if ($suffix === 'tickets') {
                $message = $this->p->mapping('tickets', $key, 'support_ticket_message');
                if ($message) $this->p->verify($message);
            }

            return 'skipped';
        }
        $data = match ($suffix) {
            'ticketdepartments' => [
                'name' => $row['name'] ?? '', 'email' => $row['email'] ?? null,
                'close_after' => 0, 'can_reopen' => 0, 'created_at' => null, 'updated_at' => null,
            ],
            'tickets' => $this->ticket($row),
            'ticketreplies', 'ticketnotes' => $this->message($suffix, $row),
            'emails' => $this->email($row),
        };
        $id = $this->p->insert($entity, $data);
        $this->p->track($suffix, $key, $entity, $id, $checksum, $run);
        if ($suffix === 'tickets') {
            $message = $this->p->insert('support_ticket_message', [
                'support_ticket_id' => $id, 'client_id' => $data['client_id'], 'admin_id' => null,
                'content' => self::text($row['message'] ?? ''), 'attachment' => null,
                'created_at' => $data['created_at'], 'updated_at' => $data['created_at'],
            ]);
            $this->p->track('tickets', $key, 'support_ticket_message', $message, $checksum, $run);
        }

        return 'imported';
    }

    private function ticket(array $row): array
    {
        if (strtolower((string) ($row['status'] ?? '')) !== 'closed') {
            throw new SafeException('Open ticket import is blocked until auto-close/notification isolation is verified. Original conversation remains private in the archive.');
        }
        $date = $this->requiredDate($row['date'] ?? null);
        $client = empty($row['userid']) ? null : $this->p->resolve('clients', (string) $row['userid'], 'client');
        $priority = match (strtolower((string) ($row['urgency'] ?? 'medium'))) {
            'low' => 50, 'medium' => 100, 'high' => 200,
            default => throw new SafeException('Ticket priority needs an explicit reviewed mapping.'),
        };

        return [
            'client_id' => $client, 'support_helpdesk_id' => empty($row['did']) ? null : $this->p->resolve('ticketdepartments', (string) $row['did'], 'support_helpdesk'),
            'subject' => $row['title'] ?? '', 'priority' => $priority, 'status' => 'closed',
            // Do not import WHMCS ticket access tokens or generate a public guest link.
            'access_hash' => null, 'author_name' => $row['name'] ?? null, 'author_email' => $row['email'] ?? null,
            'created_at' => $date, 'updated_at' => empty($row['lastreply']) ? $date : $this->requiredDate($row['lastreply']),
        ];
    }

    private function message(string $suffix, array $row): array
    {
        $ticket = $this->p->resolve('tickets', (string) ($row[$suffix === 'ticketnotes' ? 'ticketid' : 'tid'] ?? ''), 'support_ticket');
        $native = $this->store->one('SELECT status FROM support_ticket WHERE id=?', [$ticket]);
        if ($native['status'] !== 'closed') throw new SafeException('Destination ticket is no longer closed. Historical message insertion refused.');
        $date = $this->requiredDate($row['date'] ?? null);
        $author = trim((string) ($row['admin'] ?? ''));
        $content = ($author !== '' ? '[Legacy staff: ' . self::text($author) . "]\n" : '') . self::text($row['message'] ?? '');
        if ($suffix === 'ticketnotes') {
            return ['support_ticket_id' => $ticket, 'admin_id' => null, 'note' => $content, 'created_at' => $date, 'updated_at' => $date];
        }
        $last = $this->store->one('SELECT created_at FROM support_ticket_message WHERE support_ticket_id=? ORDER BY id DESC LIMIT 1', [$ticket]);
        if ($last && $date < $last['created_at']) throw new SafeException('Reply would violate native chronological message order. A chronological staging pass is required.');
        $updated = $this->store->one('SELECT updated_at FROM support_ticket WHERE id=?', [$ticket]);
        if ($updated['updated_at'] === null || $date > $updated['updated_at']) $this->p->update('support_ticket', $ticket, ['updated_at' => $date]);

        return ['support_ticket_id' => $ticket, 'admin_id' => null, 'client_id' => $author !== '' || empty($row['userid']) ? null : $this->p->resolve('clients', (string) $row['userid'], 'client'), 'content' => $content, 'attachment' => null, 'created_at' => $date, 'updated_at' => $date];
    }

    private function email(array $row): array
    {
        $date = $this->requiredDate($row['date'] ?? null);

        return [
            'client_id' => $this->p->resolve('clients', (string) ($row['userid'] ?? ''), 'client'),
            'subject' => $row['subject'] ?? '', 'sender' => $row['sender'] ?? null,
            'recipients' => $row['to'] ?? null, 'content_text' => self::text($row['message'] ?? ''),
            'content_html' => null, 'created_at' => $date, 'updated_at' => $date,
        ];
    }

    private function requiredDate(mixed $value): string
    {
        return EventDate::utc($value, $this->timezone) ?? throw new SafeException('A required historical event date is missing.');
    }

    private static function text(mixed $value): string
    {
        return Sanitizer::text(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
