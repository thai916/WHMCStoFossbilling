<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source\Backup;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

/** Reads one SQL member, without extracting archive names to filesystem paths. */
final class Compression
{
    /** zlib CRC combination: checkpoint a CRC without serializing native hash contexts. */
    public static function combineCrc(int $first, int $second, int $length): int
    {
        if ($length <= 0) return $first;
        $odd = [0xedb88320]; $row = 1;
        for ($i = 1; $i < 32; ++$i) { $odd[$i] = $row; $row <<= 1; }
        $even = self::square($odd); $odd = self::square($even);
        do {
            $even = self::square($odd);
            if ($length & 1) $first = self::times($even, $first);
            $length >>= 1;
            if (!$length) break;
            $odd = self::square($even);
            if ($length & 1) $first = self::times($odd, $first);
            $length >>= 1;
        } while ($length);
        return ($first ^ $second) & 0xffffffff;
    }
    private static function times(array $matrix, int $vector): int
    {
        $sum = 0; $at = 0;
        while ($vector) { if ($vector & 1) $sum ^= $matrix[$at]; $vector >>= 1; ++$at; }
        return $sum;
    }
    private static function square(array $matrix): array
    {
        $result = [];
        for ($i = 0; $i < 32; ++$i) $result[$i] = self::times($matrix, $matrix[$i]);
        return $result;
    }

    public static function zip(string $path, string $key): array
    {
        $stream = EncryptedFile::open($path, $key);
        try {
            $size = EncryptedFile::size($path);
            fseek($stream, max(0, $size - 65557));
            $tail = stream_get_contents($stream);
            $at = strrpos($tail, "PK\x05\x06");
            if ($at === false || strlen($tail) < $at + 22) throw new SafeException('ZIP directory is missing or incomplete.');
            $end = unpack('vdisk/vdirectory_disk/ventries_disk/ventries/Vsize/Voffset/vcomment', substr($tail, $at + 4, 18));
            if ($end['disk'] || $end['directory_disk'] || $end['entries'] !== $end['entries_disk'] || $at + 22 + $end['comment'] !== strlen($tail)) throw new SafeException('Multipart or incomplete ZIP files are not supported.');
            if ($end['entries'] === 65535 || $end['offset'] === 4294967295) {
                $locator = $size - strlen($tail) + $at - 20;
                if ($locator < 0) throw new SafeException('ZIP64 locator is missing.');
                fseek($stream, $locator); $record = fread($stream, 20);
                if (substr($record, 0, 4) !== "PK\x06\x07") throw new SafeException('ZIP64 locator is invalid.');
                $offset = unpack('Poffset', substr($record, 8, 8))['offset'];
                fseek($stream, $offset); $record = fread($stream, 56);
                if (substr($record, 0, 4) !== "PK\x06\x06") throw new SafeException('ZIP64 directory is invalid.');
                $end['entries'] = unpack('Pcount', substr($record, 32, 8))['count'];
                $end['offset'] = unpack('Poffset', substr($record, 48, 8))['offset'];
            }
            if ($end['entries'] > 10000) throw new SafeException('ZIP contains too many members.');
            fseek($stream, $end['offset']); $found = null;
            for ($i = 0; $i < $end['entries']; ++$i) {
                $header = fread($stream, 46);
                if (strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") throw new SafeException('ZIP member metadata is invalid.');
                $h = unpack('vflags/vmethod', substr($header, 8, 4)) + unpack('Vcrc/Vcompressed/Vsize/vname/vextra/vcomment', substr($header, 16, 18)) + unpack('Voffset', substr($header, 42, 4));
                if ($h['name'] < 1) throw new SafeException('ZIP member has no name.');
                $name = fread($stream, $h['name']);
                $extra = $h['extra'] ? fread($stream, $h['extra']) : '';
                if ($h['comment']) fseek($stream, $h['comment'], SEEK_CUR);
                if (str_contains($name, "\0") || preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)|^[/\\\\]|^[a-z]:~i', $name)) throw new SafeException('ZIP contains an unsafe member path.');
                if (!preg_match('/\.sql\z/iD', $name)) continue;
                if ($found !== null) throw new SafeException('Choose a ZIP containing exactly one SQL file.');
                if (($h['flags'] & 1) || !in_array($h['method'], [0, 8], true)) throw new SafeException('Encrypted ZIPs or this ZIP compression method are unsupported. Use plain SQL, GZIP or a standard ZIP.');
                for ($j = 0; $j + 4 <= strlen($extra);) {
                    $e = unpack('vid/vlength', substr($extra, $j, 4)); $data = substr($extra, $j + 4, $e['length']); $j += 4 + $e['length'];
                    if ($e['id'] !== 1) continue;
                    foreach (['size', 'compressed', 'offset'] as $field) if ($h[$field] === 4294967295) {
                        if (strlen($data) < 8) throw new SafeException('ZIP64 member metadata is incomplete.');
                        $h[$field] = unpack('Pvalue', substr($data, 0, 8))['value']; $data = substr($data, 8);
                    }
                }
                if ($h['size'] > Job::MAX_EXPANDED || $h['size'] < 1 || $h['compressed'] < 1 || $h['offset'] < 0 || $h['offset'] + $h['compressed'] > $size) throw new SafeException('ZIP SQL member exceeds supported storage limits.');
                $found = $h + ['member_name' => $name];
            }
            if (!$found) throw new SafeException('ZIP contains no SQL file.');
            fseek($stream, $found['offset']); $local = fread($stream, 30);
            if (substr($local, 0, 4) !== "PK\x03\x04") throw new SafeException('ZIP local header is invalid.');
            $localFlags = unpack('vflags/vmethod', substr($local, 6, 4));
            if ($localFlags['flags'] !== $found['flags'] || $localFlags['method'] !== $found['method']) throw new SafeException('ZIP local header and directory disagree.');
            $lengths = unpack('vname/vextra', substr($local, 26, 4));
            if ($lengths['name'] < 1) throw new SafeException('ZIP local member has no name.');
            if (fread($stream, $lengths['name']) !== $found['member_name']) throw new SafeException('ZIP member names disagree.');
            $found['start'] = $found['offset'] + 30 + $lengths['name'] + $lengths['extra'];
            if ($found['start'] + $found['compressed'] > $size) throw new SafeException('ZIP member is incomplete.');
            return $found;
        } finally { fclose($stream); }
    }

    public static function chunks(string $path, string $key, string $format, ?array $zip): \Generator
    {
        $stream = $format === 'zip'
            ? EncryptedFile::open($path, $key, $zip['start'], $zip['compressed'])
            : EncryptedFile::open($path, $key);
        $compressed = $format === 'gz' || ($zip['method'] ?? 0) === 8;
        $context = $compressed ? inflate_init($format === 'gz' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_RAW) : null;
        if ($compressed && $context === false) throw new SafeException('GZIP/ZIP decompression is unavailable.');
        $size = $format === 'zip' ? $zip['compressed'] : EncryptedFile::size($path);
        try {
            while (!feof($stream)) {
                $part = fread($stream, $compressed ? 1024 : 1048576);
                if ($part === false) throw new SafeException('Compressed backup could not be read.');
                if (!$compressed) { if ($part !== '') yield $part; continue; }
                $plain = @inflate_add($context, $part, feof($stream) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($plain === false) throw new SafeException('Compressed backup integrity check failed.');
                if ($plain !== '') yield $plain;
                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    if (inflate_get_read_len($context) !== $size) throw new SafeException('Concatenated or trailing compressed streams are unsupported. Upload one complete SQL stream.');
                    return;
                }
            }
            if ($compressed && inflate_get_status($context) !== ZLIB_STREAM_END) throw new SafeException('Compressed backup is incomplete.');
        } finally { fclose($stream); }
    }
}
