<?php

declare(strict_types=1);

namespace Box\Mod\Whmcsmigrator\Security;

final class Vault
{
    private string $key;

    public function __construct(#[\SensitiveParameter] string $installationSecret)
    {
        if (strlen($installationSecret) < 16) {
            throw new SafeException('The destination installation secret is not suitable for credential storage.');
        }
        $this->key = hash_hkdf('sha256', $installationSecret, 32, 'whmcsmigrator-v1');
    }

    public function seal(#[\SensitiveParameter] array $data): string
    {
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, 'whmcsmigrator-v1');
        if ($cipher === false) {
            throw new SafeException('Credential encryption failed.');
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    public function open(#[\SensitiveParameter] string $data): array
    {
        $bytes = base64_decode($data, true);
        if ($bytes === false || strlen($bytes) < 29) {
            throw new SafeException('Stored credentials are invalid. Test the connection again.');
        }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'whmcsmigrator-v1');
        if ($plain === false) {
            throw new SafeException('Stored credentials could not be authenticated. Test the connection again.');
        }

        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    }
}
