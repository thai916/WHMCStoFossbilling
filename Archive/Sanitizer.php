<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Archive;

/** Free text may contain historical credentials. Redactions are disclosed, not silently omitted. */
final class Sanitizer
{
    public static function text(string $value): string
    {
        $value = preg_replace('/\b(password|passwd|secret|api[_ -]?key|access[_ -]?token|auth[_ -]?code|epp[_ -]?code|cvv|cvc)\b\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s<]+)/iu', '$1=[REDACTED]', $value) ?? '[UNREADABLE TEXT]';
        $value = preg_replace('/([?&](?:token|key|auth|hash|signature|password|code|secret)=)[^&\s"\'<>]+/iu', '$1[REDACTED]', $value) ?? '[UNREADABLE TEXT]';
        $value = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[REDACTED PRIVATE KEY]', $value) ?? '[UNREADABLE TEXT]';
        // Deliberately conservative: redact long card-like digit sequences in historical free text.
        return preg_replace('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', '[REDACTED NUMBER]', $value) ?? '[UNREADABLE TEXT]';
    }
}
