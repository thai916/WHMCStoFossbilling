<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source;

use Box\Mod\Whmcstofossbilling\Security\SafeException;
use Box\Mod\Whmcstofossbilling\Source\Backup\EncryptedFile;
use Box\Mod\Whmcstofossbilling\Source\Backup\Job;
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
        if (EncryptedFile::size(Job::directory($this->options['backup_id']) . '/' . $name . '.rows') !== ($this->state['tables'][$name]['bytes'] ?? 0)) throw new SafeException('Prepared SQL data is incomplete or changed. Stop and review the source backup.');
    }
    public function batch(string $name, array $definition, string $after, string $maximum, int $limit): array { return $this->page($name, $definition, $after, $maximum, $limit)['rows']; }
    public function page(string $name, array $definition, string $after, string $maximum, int $limit): array
    {
        $this->assertReadable($name, $definition);
        if (!ctype_digit($after) || !ctype_digit($maximum) || $limit < 1 || $limit > 100) throw new SafeException('Invalid SQL backup cursor.');
        if (!$this->state['tables'][$name]['count']) return ['rows' => [], 'exhausted' => true];
        $offset = 0;
        $index = $this->state['tables'][$name]['index']; $low = 0; $high = count($index) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if (bccomp((string) $index[$middle][0], $after, 0) <= 0) { $offset = $index[$middle][1]; $low = $middle + 1; }
            else $high = $middle - 1;
        }
        $stream = EncryptedFile::open(Job::directory($this->options['backup_id']) . '/' . $name . '.rows', $this->job->key());
        if (fseek($stream, $offset) !== 0) { fclose($stream); throw new SafeException('SQL backup index is incomplete. No cursor was advanced.'); }
        $rows = []; $bytes = 0; $exhausted = false;
        try {
            while (($line = fgets($stream, 4194306)) !== false) {
                $row = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                $key = (string) $row[$definition['key']];
                if (bccomp($key, $after, 0) <= 0) continue;
                if (bccomp($key, $maximum, 0) > 0) { $exhausted = true; break; }
                if (count($rows) >= $limit || ($rows && $bytes + strlen($line) > 8388608)) break;
                $rows[] = $row; $bytes += strlen($line);
            }
            if (feof($stream)) $exhausted = true;
        } finally { fclose($stream); }
        return ['rows' => $rows, 'exhausted' => $exhausted];
    }
}
