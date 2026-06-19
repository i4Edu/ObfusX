<?php

declare(strict_types=1);

namespace ObfusX;

final class Encoder
{
    public static function encodeFile(string $inputFile, string $outputFile, string $masterKey): void
    {
        if (!is_file($inputFile)) {
            throw new \RuntimeException('Input file does not exist.');
        }

        $code = file_get_contents($inputFile);
        if ($code === false) {
            throw new \RuntimeException('Failed to read input file.');
        }

        self::assertEncodablePhpSource($code);

        $obfuscated = Obfuscator::obfuscate($code);
        $encrypted = Crypto::encrypt($obfuscated['code'], $masterKey);
        $encrypted['meta'] = [
            'obfuscated_at' => gmdate('c'),
            'identifier_count' => count($obfuscated['map']),
        ];
        $encrypted = self::addSignature($encrypted);

        $json = json_encode($encrypted, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($outputFile, $json) === false) {
            throw new \RuntimeException('Failed to write output file.');
        }
    }

    /**
     * Return non-sensitive metadata describing an encoded file.
     *
     * The protected source is never decrypted: only the algorithm, signing
     * status and recorded meta information are exposed.
     *
     * @return array<string,mixed>
     */
    public static function describeFile(string $encodedFile): array
    {
        if (!is_file($encodedFile)) {
            throw new \RuntimeException('Encoded file does not exist.');
        }

        $json = file_get_contents($encodedFile);
        if ($json === false) {
            throw new \RuntimeException('Failed to read encoded file.');
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid encoded file format.');
        }

        $meta = (isset($payload['meta']) && is_array($payload['meta'])) ? $payload['meta'] : [];

        return [
            'alg' => $payload['alg'] ?? 'unknown',
            'signed' => isset($payload['signature']),
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function addSignature(array $payload): array
    {
        $signingKey = getenv('OBFUSX_SIGNING_KEY') ?: '';
        if ($signingKey === '') {
            return $payload;
        }

        $message = implode('|', [
            (string) ($payload['ciphertext'] ?? ''),
            (string) ($payload['iv'] ?? ''),
            (string) ($payload['tag'] ?? ''),
            (string) ($payload['salt'] ?? ''),
        ]);
        $payload['signature'] = hash_hmac('sha256', $message, $signingKey);
        $payload['signed'] = true;

        return $payload;
    }

    private static function assertEncodablePhpSource(string $code): void
    {
        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new \RuntimeException('Input file must contain valid PHP code.', 0, $e);
        }

        $hasOpenTag = false;
        $hasExecutablePhpToken = false;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            [$id] = $token;

            if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                $hasOpenTag = true;
                if ($id === T_OPEN_TAG_WITH_ECHO) {
                    $hasExecutablePhpToken = true;
                }
                continue;
            }

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_CLOSE_TAG], true)) {
                continue;
            }

            $hasExecutablePhpToken = true;
        }

        if (!$hasOpenTag || !$hasExecutablePhpToken) {
            throw new \RuntimeException('Input file must contain PHP code enclosed in PHP tags.');
        }
    }
}
