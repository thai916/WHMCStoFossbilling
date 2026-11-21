<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Source;

use Box\Mod\Whmcsmigrator\Security\SafeException;

/** Explicit admin-requested lookup only. Never accepts a caller-supplied URL. */
final class PublicIp
{
    public const ENDPOINT = 'https://api.ipify.org';

    public static function lookup(): array
    {
        if (!extension_loaded('curl')) throw new SafeException('Public-IP lookup requires PHP cURL. Ask your network administrator for the outbound IP instead.');
        $body = '';
        $curl = curl_init(self::ENDPOINT);
        try {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => CaBundle::defaultPath(),
                CURLOPT_HTTPHEADER => ['Accept: text/plain'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 128) return 0;
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($curl);
            if ($ok === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new SafeException('Public-IP lookup failed. No IP was guessed. Check outbound HTTPS connectivity or ask your network administrator.');
            }

            return ['ip' => self::parse($body), 'provider' => self::ENDPOINT, 'checked_at' => gmdate('Y-m-d H:i:s')];
        } finally {
            curl_close($curl);
        }
    }

    public static function parse(string $body): string
    {
        $ip = trim($body);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new SafeException('IP lookup did not return a public IPv4 address. No address was guessed.');
        }

        return $ip;
    }
}
