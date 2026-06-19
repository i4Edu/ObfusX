<?php

declare(strict_types=1);

namespace ObfusX;

final class Obfuscator
{
    private const SOURCE_NOTICE = '/* Protected by ObfusX */';

    private const RESERVED_VARS = [
        '$GLOBALS' => true,
        '$_SERVER' => true,
        '$_GET' => true,
        '$_POST' => true,
        '$_FILES' => true,
        '$_REQUEST' => true,
        '$_SESSION' => true,
        '$_ENV' => true,
        '$_COOKIE' => true,
        '$php_errormsg' => true,
        '$http_response_header' => true,
        '$argc' => true,
        '$argv' => true,
        '$this' => true,
    ];

    /**
     * @return array{code:string, map:array<string,string>}
     */
    public static function obfuscate(string $code): array
    {
        $tokens = token_get_all($code);
        $out = [];
        $map = [];
        $inPhp = false;
        $noticeAdded = false;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                $out[] = $token;
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                $inPhp = true;
                $out[] = $text;
                if (!$noticeAdded) {
                    $out[] = self::SOURCE_NOTICE . PHP_EOL;
                    $noticeAdded = true;
                }
                continue;
            }

            if ($id === T_CLOSE_TAG) {
                $inPhp = false;
                $out[] = $text;
                continue;
            }

            if ($id === T_INLINE_HTML) {
                $out[] = $text;
                continue;
            }

            if ($id === T_VARIABLE) {
                if (!isset(self::RESERVED_VARS[$text])) {
                    $map[$text] = $map[$text] ?? self::tokenName('v_', $text);
                    $out[] = $map[$text];
                } else {
                    $out[] = $text;
                }
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING && strlen($text) > 2) {
                $plain = self::decodeLiteral($text);
                if ($plain !== null) {
                    $encoded = base64_encode($plain);
                    $out[] = "obfusx_s('{$encoded}')";
                    continue;
                }
            }

            $out[] = $text;
        }

        $code = implode('', $out) . self::dummyFlow($inPhp);

        return [
            'code' => $code,
            'map' => $map,
        ];
    }

    /**
     * Build an opaque, no-op control-flow snippet appended after the source.
     *
     * When the source ends inside a PHP block the snippet is emitted as plain
     * statements; otherwise it is wrapped in its own `<?php ... ?>` block so it
     * stays valid for inline-HTML / multi-block templates.
     */
    private static function dummyFlow(bool $inPhp): string
    {
        $statement = "if ((strlen(__FILE__) ^ strlen(__FILE__)) !== 0) { echo 'never'; }";

        return $inPhp
            ? "\n" . $statement . "\n"
            : "\n<?php\n" . $statement . "\n";
    }

    private static function tokenName(string $prefix, string $seed): string
    {
        return '$' === $seed[0]
            ? '$' . $prefix . substr(hash('sha256', $seed), 0, 12)
            : $prefix . substr(hash('sha256', $seed), 0, 12);
    }

    private static function decodeLiteral(string $literal): ?string
    {
        $quote = $literal[0] ?? '';
        if ($quote !== "'" && $quote !== '"') {
            return null;
        }

        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\'", "\\\\"], ["'", "\\"], $body);
        }

        return stripcslashes($body);
    }
}
