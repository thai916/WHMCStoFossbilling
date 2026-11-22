<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Source\Backup;

use Box\Mod\Whmcstofossbilling\Security\SafeException;

/** MySQL dump tokenizer, never a SQL executor. Extended INSERTs are read one tuple at a time. */
final class Lexer
{
    private string $buffer = '';
    private int $at = 0;
    private int $offset;
    private ?array $lookahead = null;
    public function __construct(private $stream, int $offset = 0) {
        if ($offset === 0 && fread($stream, 3) === "\xef\xbb\xbf") $offset = 3;
        fseek($stream, $offset); $this->offset = $offset;
    }
    public function offset(): int { return $this->lookahead[2] ?? $this->offset; }
    private function char(): string
    {
        if ($this->at >= strlen($this->buffer)) { $this->buffer = fread($this->stream, 65536); $this->at = 0; }
        return $this->buffer[$this->at] ?? '';
    }
    private function take(): string { $char = $this->char(); if ($char !== '') { ++$this->at; ++$this->offset; } return $char; }
    public function peek(): array { return $this->lookahead ??= $this->token(); }
    public function next(): array { $token = $this->peek(); $this->lookahead = null; return $token; }
    public function expect(string $value): void
    {
        if (strtoupper($this->next()[1]) !== $value) throw new SafeException('Unsupported or incomplete SQL syntax near byte ' . $this->offset . '. No uploaded SQL was executed.');
    }
    public function skipStatement(): void
    {
        do { $token = $this->next(); } while ($token[0] !== 'end' && $token[1] !== ';');
    }
    public static function assertMetadata(string $text): void
    {
        if (preg_match('/NO_BACKSLASH_ESCAPES|ANSI_QUOTES/i', $text)) throw new SafeException('Unsupported SQL quoting mode. Export UTF-8 SQL with standard MySQL escaping.');
        foreach (['/\bNAMES\s+([^\s;*]+)/i', '/\bCHARACTER_SET_CLIENT\s*=\s*([^\s;*]+)/i'] as $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[1] as $value) {
                $value = strtolower(trim($value, "'\""));
                if (!str_starts_with($value, '@') && !in_array($value, ['utf8', 'utf8mb3', 'utf8mb4'], true)) throw new SafeException('Unsupported SQL encoding. Export the backup as UTF-8.');
            }
        }
    }
    private function token(): array
    {
        while (true) {
            while ($this->char() !== '' && ctype_space($this->char())) $this->take();
            $start = $this->offset; $char = $this->take();
            if ($char === '') return ['end', '', $start];
            if ($char === '#') { while ($this->char() !== '' && $this->take() !== "\n") {} continue; }
            if ($char === '-' && $this->char() === '-') {
                $this->take();
                if ($this->char() !== '' && !ctype_space($this->char())) throw new SafeException('Unsupported SQL comment syntax.');
                while ($this->char() !== '' && $this->take() !== "\n") {} continue;
            }
            if ($char === '/' && $this->char() === '*') {
                $this->take(); $comment = ''; $previous = '';
                while (true) {
                    $part = $this->take();
                    if ($part === '') throw new SafeException('SQL comment is incomplete.');
                    if ($previous === '*' && $part === '/') break;
                    if (strlen($comment) >= 65536) throw new SafeException('SQL comment exceeds the supported metadata limit.');
                    $comment .= $part;
                    $previous = $part;
                }
                // SQL modes that change quoting cannot be ignored safely.
                if (preg_match('/\A(?:!|M!)/i', $comment)) {
                    self::assertMetadata($comment);
                    $statement = preg_replace('/\A(?:M!|!)\d*\s*/i', '', $comment);
                    if (!preg_match('/\A(?:SET\b|ALTER\s+TABLE\s+[`A-Za-z0-9_]+\s+(?:ENABLE|DISABLE)\s+KEYS\b)/i', $statement)) throw new SafeException('Unsupported executable SQL comment. Export ordinary table definitions and INSERT statements so no history is silently omitted.');
                }
                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char; $value = '';
                while (true) {
                    $part = $this->take();
                    if ($part === '') throw new SafeException('A quoted SQL value is incomplete.');
                    if ($part === $quote) {
                        if ($this->char() === $quote) { $this->take(); $part = $quote; } else break;
                    } elseif ($part === '\\' && $quote !== '`') {
                        $part = $this->take();
                        if ($part === '') throw new SafeException('A SQL escape is incomplete.');
                        $part = match ($part) { '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a", '\\', "'", '"' => $part, '%', '_' => '\\' . $part, default => $part };
                    }
                    $value .= $part;
                    if (strlen($value) > 4194304) throw new SafeException('A SQL field exceeds the supported extraction limit. Export large objects separately; no value was truncated.');
                }
                return [$quote === '`' ? 'word' : 'string', $value, $start];
            }
            if (preg_match('/[A-Za-z0-9_$.-]/', $char)) {
                $value = $char;
                while ($this->char() !== '' && preg_match('/[A-Za-z0-9_$.-]/', $this->char())) {
                    $value .= $this->take();
                    if (strlen($value) > 1024) throw new SafeException('Unsupported SQL token length.');
                }
                return ['word', $value, $start];
            }
            return ['symbol', $char, $start];
        }
    }
}
