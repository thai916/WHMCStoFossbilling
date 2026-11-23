<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Migration;

/**
 * Conservative adaptive row sizing for one drive call.
 *
 * The reader already bounds each page by bytes and the engine bounds local work.
 * This class only chooses how many rows to offer to those existing guards; it
 * never bypasses idempotency keys, source locks, checkpoints or API pacing.
 */
final class BatchSizer
{
    public const MIN_ROWS = 1;
    public const MAX_ROWS = 2000;
    public const START_ROWS = 500;

    private const FAST_MS = 2500;
    private const SLOW_MS = 4500;
    private const PAGE_SOFT_BYTES = 4194304;
    private const PAGE_HARD_BYTES = 6291456;

    public static function clamp(int $rows): int
    {
        return max(self::MIN_ROWS, min(self::MAX_ROWS, $rows));
    }

    public static function initial(int $requested, ?array $previous): int
    {
        $candidate = $requested;
        if (is_array($previous)) {
            foreach (['next_limit', 'batch_limit', 'requested_limit'] as $key) {
                if (isset($previous[$key]) && is_numeric($previous[$key])) {
                    $candidate = (int) $previous[$key];
                    break;
                }
            }
        }

        return self::clamp($candidate > 0 ? $candidate : self::START_ROWS);
    }

    public static function next(int $current, array $result): int
    {
        $current = self::clamp($current);
        $processed = max(0, (int) ($result['batch_processed'] ?? 0));
        $elapsed = max(0, (int) ($result['elapsed_ms'] ?? 0));
        $bytes = max(0, (int) ($result['page_bytes'] ?? 0));
        $exhausted = (bool) ($result['page_exhausted'] ?? false);

        if ($processed === 0) {
            return self::clamp(intdiv($current, 2));
        }
        // The engine's hard work cap is five seconds. Back off before or after a
        // batch that approaches it, and when a page approaches the 8 MiB budget.
        if ($elapsed >= self::SLOW_MS || $bytes >= self::PAGE_HARD_BYTES) {
            return self::clamp(max(self::MIN_ROWS, intdiv($current * 3, 4)));
        }
        if (($processed >= $current || $exhausted) && $elapsed <= self::FAST_MS && $bytes <= self::PAGE_SOFT_BYTES) {
            return self::clamp($current + max(100, intdiv($current, 2)));
        }

        return $current;
    }
}
