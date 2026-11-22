<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source\Backup;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

/**
 * Bounded, seekable encrypted files. Raw dumps never reside on disk as plaintext.
 * GCM authenticates every block against its file path and block position.
 */
final class EncryptedFile
{
    public const BLOCK = 262144;
    private const MAGIC = "WMENC01\n";
    private static array $streams = [];
    public $context;
    private $handle;
    private string $path;
    private string $key;
    private int $position = 0;
    private int $start = 0;
    private int $length = 0;
    private int $cachedBlock = -1;
    private string $cache = '';

    public static function size(string $path): int
    {
        if (!is_file($path)) return 0;
        clearstatcache(true, $path);
        $n = filesize($path) - strlen(self::MAGIC);
        if ($n < 0 || ($n % (self::BLOCK + 28) > 0 && $n % (self::BLOCK + 28) <= 28)) throw new SafeException('Private backup file is incomplete.');
        return intdiv($n, self::BLOCK + 28) * self::BLOCK + ($n % (self::BLOCK + 28) ? $n % (self::BLOCK + 28) - 28 : 0);
    }

    public static function uri(string $path, string $key, int $start = 0, ?int $length = null): string
    {
        if (!in_array('wmbackup', stream_get_wrappers(), true)) stream_wrapper_register('wmbackup', self::class);
        $token = bin2hex(random_bytes(16));
        self::$streams[$token] = [$path, $key, $start, $length ?? self::size($path) - $start];
        return 'wmbackup://' . $token;
    }

    public static function open(string $path, string $key, int $start = 0, ?int $length = null)
    {
        $stream = fopen(self::uri($path, $key, $start, $length), 'rb');
        if (!$stream) throw new SafeException('Private backup could not be opened.');
        return $stream;
    }

    private static function decode($handle, string $path, string $key, int $block): string
    {
        fseek($handle, strlen(self::MAGIC) + $block * (self::BLOCK + 28));
        $frame = fread($handle, self::BLOCK + 28);
        if (strlen($frame) < 28) throw new SafeException('Private backup block is incomplete.');
        $plain = openssl_decrypt(substr($frame, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($frame, 0, 12), substr($frame, 12, 16), $path . ':' . $block);
        if ($plain === false) throw new SafeException('Private backup integrity check failed. No unverified data was imported.');
        return $plain;
    }

    public static function append(string $path, string $key, string $data): void
    {
        $free = disk_free_space(dirname($path));
        if ($free !== false && $free < strlen($data) + 67108864) throw new SafeException('Not enough private backup storage space. Free space before continuing.');
        $size = self::size($path);
        $handle = fopen($path, 'c+b');
        if (!$handle) throw new SafeException('Private backup storage is not writable.');
        try {
            if ($size === 0) { rewind($handle); fwrite($handle, self::MAGIC); }
            $block = intdiv($size, self::BLOCK);
            $partial = $size % self::BLOCK;
            if ($partial) $data = self::decode($handle, $path, $key, $block) . $data;
            fseek($handle, strlen(self::MAGIC) + $block * (self::BLOCK + 28));
            for ($offset = 0; $offset < strlen($data); $offset += self::BLOCK, ++$block) {
                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt(substr($data, $offset, self::BLOCK), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $path . ':' . $block);
                if ($cipher === false || fwrite($handle, $iv . $tag . $cipher) !== strlen($cipher) + 28) throw new SafeException('Private backup storage is full or unavailable.');
            }
            ftruncate($handle, ftell($handle));
            fflush($handle);
        } finally { fclose($handle); }
        @chmod($path, 0600);
    }

    public static function truncate(string $path, string $key, int $length): void
    {
        if (self::size($path) === $length) return;
        if ($length < 0 || self::size($path) < $length) throw new SafeException('Private backup checkpoint is incomplete.');
        $partial = $length % self::BLOCK;
        $handle = fopen($path, 'r+b');
        try {
            $tail = $partial ? substr(self::decode($handle, $path, $key, intdiv($length, self::BLOCK)), 0, $partial) : '';
            ftruncate($handle, 8 + intdiv($length, self::BLOCK) * (self::BLOCK + 28));
        } finally { fclose($handle); }
        if ($tail !== '') self::append($path, $key, $tail);
    }

    public function stream_open(string $uri, string $mode, int $options, ?string &$openedPath): bool
    {
        $token = substr($uri, strlen('wmbackup://'));
        if (!isset(self::$streams[$token]) || $mode !== 'rb') return false;
        [$this->path, $this->key, $this->start, $this->length] = self::$streams[$token];
        $this->handle = fopen($this->path, 'rb');
        if (!$this->handle || fread($this->handle, 8) !== self::MAGIC) throw new SafeException('Private backup header is invalid.');
        return true;
    }

    public function stream_read(int $count): string
    {
        $result = '';
        while ($count > 0 && $this->position < $this->length) {
            $absolute = $this->start + $this->position;
            $block = intdiv($absolute, self::BLOCK);
            if ($this->cachedBlock !== $block) {
                $this->cache = self::decode($this->handle, $this->path, $this->key, $block);
                $this->cachedBlock = $block;
            }
            $part = substr($this->cache, $absolute % self::BLOCK, min($count, $this->length - $this->position));
            if ($part === '') throw new SafeException('Private backup ended unexpectedly.');
            $result .= $part; $count -= strlen($part); $this->position += strlen($part);
        }
        return $result;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) { SEEK_SET => $offset, SEEK_CUR => $this->position + $offset, SEEK_END => $this->length + $offset };
        if ($position < 0 || $position > $this->length) return false;
        $this->position = $position; return true;
    }
    public function stream_tell(): int { return $this->position; }
    public function stream_eof(): bool { return $this->position >= $this->length; }
    public function stream_stat(): array { return ['size' => $this->length, 'mode' => 0100600]; }
    public function stream_close(): void { if (is_resource($this->handle)) fclose($this->handle); }
    public function stream_set_option(int $option, int $arg1, int $arg2): bool { return false; }
}
