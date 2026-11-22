<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source;

use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Source\Backup\EncryptedFile;
use Box\Mod\Whmcstofossbilling\Source\Backup\Job;
use Box\Mod\Whmcstofossbilling\Source\Backup\KeyIndex;
use FOSSBilling\Config;

final class DumpReader
{
    private Job $job;
    private array $state;
    public function __construct(private array $options)
    {
        $this->job = new Job((string) Config::getProperty('info.salt'));
        $this->state = $this->job->read($options['backup_id']);
        if ($this->state['phase'] !== 'ready') throw new SafeException('This SQL backup is not ready for migration.');
    }
    public function inspect(): array { return $this->state['schema']; }
    public function bounds(string $name, array $definition): array
    {
        $this->assertReadable($name, $definition);
        return ['count' => $this->state['tables'][$name]['count'], 'maximum' => $this->state['tables'][$name]['maximum']];
    }
    private function assertReadable(string $name, array $definition): void
    {
        if (!isset($this->state['schema'][$name]) || $this->state['schema'][$name] !== $definition || $definition['classification'] !== 'archive') throw new SafeException('This SQL table is excluded or needs a reviewed extractor.');
        $directory = Job::directory($this->options['backup_id']);
        if (EncryptedFile::size($directory . '/' . $name . '.rows') !== ($this->state['tables'][$name]['bytes'] ?? 0)) throw new SafeException('Prepared SQL data is incomplete or changed. Stop and review the source backup.');
        $expected = ((int) ($this->state['tables'][$name]['count'] ?? 0)) * KeyIndex::RECORD;
        if (EncryptedFile::size($directory . '/' . $name . '.sorted') !== $expected) throw new SafeException('Prepared SQL key index is incomplete. Remove this backup and upload it again.');
    }
    public function batch(string $name, array $definition, string $after, string $maximum, int $limit): array { return $this->page($name, $definition, $after, $maximum, $limit)['rows']; }
    public function page(string $name, array $definition, string $after, string $maximum, int $limit): array
    {
        $this->assertReadable($name, $definition);
        if (!ctype_digit($after) || !ctype_digit($maximum) || $limit < 1 || $limit > 100) throw new SafeException('Invalid SQL backup cursor.');
        $count = (int) $this->state['tables'][$name]['count'];
        if (!$count) return ['rows' => [], 'exhausted' => true];
        $directory = Job::directory($this->options['backup_id']);
        $index = EncryptedFile::open($directory . '/' . $name . '.sorted', $this->job->key());
        $rowStream = EncryptedFile::open($directory . '/' . $name . '.rows', $this->job->key());
        $rows = []; $bytes = 0; $exhausted = false;
        try {
            // First sorted record whose key is greater than the committed cursor.
            $low = 0; $high = $count;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                $key = KeyIndex::key($this->record($index, $middle));
                if (bccomp($key, $after, 0) <= 0) $low = $middle + 1;
                else $high = $middle;
            }
            for ($position = $low; $position < $count; ++$position) {
                $entry = $this->record($index, $position);
                $key = KeyIndex::key($entry);
                if (bccomp($key, $maximum, 0) > 0) { $exhausted = true; break; }
                if (fseek($rowStream, KeyIndex::offset($entry)) !== 0) throw new SafeException('SQL backup row offset is incomplete. No cursor was advanced.');
                $line = fgets($rowStream, 4194306);
                if ($line === false) throw new SafeException('Prepared SQL row is missing at its indexed offset.');
                $row = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                if (!is_array($row) || !isset($row[$definition['key']]) || bccomp((string) $row[$definition['key']], $key, 0) !== 0) throw new SafeException('Prepared SQL key index does not match its row data. Stop and review the source backup.');
                if ($rows && $bytes + strlen($line) > 8388608) break;
                $rows[] = $row; $bytes += strlen($line);
                if (count($rows) >= $limit) break;
            }
            if ($position >= $count) $exhausted = true;
        } finally { fclose($index); fclose($rowStream); }
        return ['rows' => $rows, 'exhausted' => $exhausted];
    }

    private function record($stream, int $position): string
    {
        if (fseek($stream, $position * KeyIndex::RECORD) !== 0) throw new SafeException('SQL backup index could not be seeked.');
        $record = fread($stream, KeyIndex::RECORD);
        if (!is_string($record) || strlen($record) !== KeyIndex::RECORD) throw new SafeException('SQL backup index record is incomplete.');
        return $record;
    }
}
