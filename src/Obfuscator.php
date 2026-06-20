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
        '$__obfx_sa' => true,
    ];

    /**
     * @return array{code:string, map:array<string,string>, features:array<string,bool>}
     */
    public static function obfuscate(string $code): array
    {
        $tokens = token_get_all($code);
        $out = [];
        $map = [];
        $inPhp = false;
        $noticeAdded = false;
        $stringArrayEnabled = self::stringArrayEnabled() && self::firstPhpOpenTagIsStandard($tokens);
        $stringLiterals = [];
        $stringIndexes = [];
        $braceStack = [];
        $functionDepth = 0;
        $pendingClassLike = false;
        $awaitingFunctionBody = false;
        $inFunctionSignature = false;
        $arrowFunctionSignature = false;
        $constDeclaration = false;
        $staticDeclaration = false;
        $attributeDepth = 0;
        $previousSignificantId = null;
        $previousSignificantText = null;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '{') {
                    if ($awaitingFunctionBody) {
                        $braceStack[] = 'function';
                        $functionDepth++;
                        $awaitingFunctionBody = false;
                        $inFunctionSignature = false;
                        $out[] = '{';
                        if ($stringArrayEnabled) {
                            $out[] = 'global $__obfx_sa;';
                        }
                    } elseif ($pendingClassLike) {
                        $braceStack[] = 'class';
                        $pendingClassLike = false;
                        $out[] = '{';
                    } else {
                        $braceStack[] = 'other';
                        $out[] = '{';
                    }

                    $previousSignificantId = null;
                    $previousSignificantText = '{';
                    continue;
                }

                if ($token === '}') {
                    $context = array_pop($braceStack);
                    if ($context === 'function') {
                        $functionDepth--;
                    }
                    $out[] = '}';
                    $previousSignificantId = null;
                    $previousSignificantText = '}';
                    continue;
                }

                if ($token === ';') {
                    $constDeclaration = false;
                    $staticDeclaration = false;
                    if ($awaitingFunctionBody) {
                        $awaitingFunctionBody = false;
                        $inFunctionSignature = false;
                    }
                    $out[] = ';';
                    $previousSignificantId = null;
                    $previousSignificantText = ';';
                    continue;
                }

                if ($token === '[') {
                    if ($attributeDepth > 0) {
                        $attributeDepth++;
                    }
                    $out[] = '[';
                    $previousSignificantId = null;
                    $previousSignificantText = '[';
                    continue;
                }

                if ($token === ']') {
                    if ($attributeDepth > 0) {
                        $attributeDepth--;
                    }
                    $out[] = ']';
                    $previousSignificantId = null;
                    $previousSignificantText = ']';
                    continue;
                }

                $out[] = $token;
                if (trim($token) !== '') {
                    $previousSignificantId = null;
                    $previousSignificantText = $token;
                }
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

            $currentContext = $braceStack[count($braceStack) - 1] ?? null;
            $preserveStringLiteral = $attributeDepth > 0
                || $constDeclaration
                || $staticDeclaration
                || $currentContext === 'class'
                || $inFunctionSignature;

            if ($id === T_VARIABLE) {
                if (!isset(self::RESERVED_VARS[$text])) {
                    $map[$text] = $map[$text] ?? self::tokenName('v_', $text);
                    $out[] = $map[$text];
                } else {
                    $out[] = $text;
                }
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING && strlen($text) > 2 && !$preserveStringLiteral) {
                $plain = self::decodeLiteral($text);
                if ($plain !== null) {
                    if ($stringArrayEnabled) {
                        $index = $stringIndexes[$plain] ?? null;
                        if ($index === null) {
                            $index = count($stringLiterals);
                            $stringIndexes[$plain] = $index;
                            $stringLiterals[] = $plain;
                        }
                        $out[] = '$__obfx_sa[' . $index . ']';
                    } else {
                        $encoded = base64_encode($plain);
                        $out[] = "obfusx_s('{$encoded}')";
                    }
                    continue;
                }
            }

            $out[] = $text;

            if ($id === T_ATTRIBUTE) {
                $attributeDepth++;
            } elseif ($id === T_CONST && $previousSignificantId !== T_USE) {
                $constDeclaration = true;
            } elseif ($id === T_DOUBLE_ARROW && $arrowFunctionSignature) {
                $arrowFunctionSignature = false;
                $inFunctionSignature = false;
            } elseif ($id === T_FN) {
                $arrowFunctionSignature = true;
                $inFunctionSignature = true;
            } elseif ($id === T_FUNCTION && $previousSignificantId !== T_USE) {
                $awaitingFunctionBody = true;
                $inFunctionSignature = true;
            } elseif ($id === T_STATIC) {
                $nextToken = self::nextSignificantToken($tokens, $i + 1);
                $staticDeclaration = $functionDepth > 0
                    && $nextToken !== null
                    && is_int($nextToken[0])
                    && $nextToken[0] === T_VARIABLE;
            } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $previousSignificantText !== '::') {
                $pendingClassLike = true;
            }

            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previousSignificantId = $id;
                $previousSignificantText = $text;
            }
        }

        $obfuscatedCode = implode('', $out) . self::dummyFlow($inPhp);
        $features = [
            'strarray' => false,
        ];

        if ($stringArrayEnabled && $stringLiterals !== []) {
            $updatedCode = self::injectStringArrayDeclaration($obfuscatedCode, $stringLiterals);
            $features['strarray'] = $updatedCode !== $obfuscatedCode;
            $obfuscatedCode = $updatedCode;
        }

        if (self::junkEnabled()) {
            $obfuscatedCode .= self::deadCodeBlocks();
        }

        return [
            'code' => $obfuscatedCode,
            'map' => $map,
            'features' => $features,
        ];
    }

    public static function flattenControlFlow(string $code): string
    {
        if (getenv('OBFUSX_FLATTEN') !== '1') {
            return $code;
        }

        $segments = self::firstStandardPhpBlock($code);
        if ($segments === null) {
            return $code;
        }

        $split = self::splitTopLevelBody($segments['body']);
        if ($split['statements'] === []) {
            return $code;
        }

        $stateVar = '$__obfx_st_' . substr(hash('sha256', $code), 0, 8);
        $dispatcher = [$stateVar . ' = 0;', 'while (true) {', '    switch (' . $stateVar . ') {'];

        foreach ($split['statements'] as $index => $statement) {
            $dispatcher[] = '        case ' . $index . ':';
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                $dispatcher[] = self::indent($trimmed, '            ');
            }
            $dispatcher[] = '            ' . $stateVar . ' = ' . ($index + 1) . ';';
            $dispatcher[] = '            break;';
        }

        $dispatcher[] = '        default:';
        $dispatcher[] = '            break 2;';
        $dispatcher[] = '    }';
        $dispatcher[] = '}';

        $body = $split['preamble'];
        if ($body !== '' && !str_ends_with($body, "\n")) {
            $body .= PHP_EOL;
        }
        $body .= implode(PHP_EOL, $dispatcher);

        return $segments['before'] . $segments['open_tag'] . $body . $segments['after'];
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

    /**
     * @param array<int, string|array{int,string,int}> $tokens
     * @return array{0:int|string,1:string}|null
     */
    private static function nextSignificantToken(array $tokens, int $startIndex): ?array
    {
        $count = count($tokens);
        for ($i = $startIndex; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                if (trim($token) === '') {
                    continue;
                }
                return [$token, $token];
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return [$token[0], $token[1]];
        }

        return null;
    }

    /**
     * @param array<int, string|array{int,string,int}> $tokens
     */
    private static function firstPhpOpenTagIsStandard(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_OPEN_TAG) {
                return true;
            }

            if ($token[0] === T_OPEN_TAG_WITH_ECHO) {
                return false;
            }
        }

        return false;
    }

    private static function stringArrayEnabled(): bool
    {
        return getenv('OBFUSX_STRARRAY') === '1';
    }

    private static function junkEnabled(): bool
    {
        return getenv('OBFUSX_JUNK') === '1';
    }

    /**
     * @param array<int, string> $literals
     */
    private static function injectStringArrayDeclaration(string $code, array $literals): string
    {
        $segments = self::firstStandardPhpBlock($code);
        if ($segments === null) {
            return $code;
        }

        $split = self::splitTopLevelBody($segments['body']);
        $declaration = self::buildStringArrayDeclaration($literals);

        $body = $split['preamble'];
        if ($body !== '' && !str_ends_with($body, "\n")) {
            $body .= PHP_EOL;
        }
        $body .= $declaration;
        if ($split['statements'] !== []) {
            $body .= PHP_EOL . implode('', $split['statements']);
        }

        return $segments['before'] . $segments['open_tag'] . $body . $segments['after'];
    }

    /**
     * @param array<int, string> $literals
     */
    private static function buildStringArrayDeclaration(array $literals): string
    {
        $encoded = array_map(
            static fn (string $value): string => "'" . base64_encode($value) . "'",
            $literals
        );

        return '$__obfx_sa = array_map(\'base64_decode\', [' . implode(', ', $encoded) . ']);';
    }

    private static function deadCodeBlocks(): string
    {
        $seed = hash('sha256', uniqid('', true) . microtime(true));
        $vars = [
            '$__obfx_j_' . substr($seed, 0, 10),
            '$__obfx_j_' . substr($seed, 10, 10),
            '$__obfx_j_' . substr($seed, 20, 10),
        ];

        return PHP_EOL . implode(PHP_EOL, [
            'if (false) { ' . $vars[0] . ' = sha1(uniqid((string) mt_rand(), true)); echo ' . $vars[0] . '; }',
            'if ((1 ^ 1) === 1) { ' . $vars[1] . ' = array_sum([3, 5, 8]); print ' . $vars[1] . '; }',
            'if (strlen(__FILE__) < 0) { ' . $vars[2] . ' = substr(hash(\'sha256\', __FILE__), 0, 12); var_dump(' . $vars[2] . '); }',
        ]) . PHP_EOL;
    }

    /**
     * @return array{before:string, open_tag:string, body:string, after:string}|null
     */
    private static function firstStandardPhpBlock(string $code): ?array
    {
        $tokens = token_get_all($code);
        $before = '';
        $openTag = '';
        $body = '';
        $after = '';
        $found = false;
        $closed = false;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (!$found) {
                if (is_array($token) && $token[0] === T_OPEN_TAG) {
                    $found = true;
                    $openTag = $text;
                    continue;
                }

                $before .= $text;
                continue;
            }

            if (!$closed) {
                if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                    $closed = true;
                    $after .= $text;
                    continue;
                }

                $body .= $text;
                continue;
            }

            $after .= $text;
        }

        if (!$found) {
            return null;
        }

        return [
            'before' => $before,
            'open_tag' => $openTag,
            'body' => $body,
            'after' => $after,
        ];
    }

    /**
     * @return array{preamble:string, statements:array<int,string>}
     */
    private static function splitTopLevelBody(string $body): array
    {
        $tokens = token_get_all("<?php\n" . $body);
        array_shift($tokens);

        $preamble = '';
        $statements = [];
        $current = '';
        $capturePreamble = true;
        $braceDepth = 0;
        $parenDepth = 0;
        $bracketDepth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($capturePreamble && $current === '' && self::isTriviaToken($token)) {
                $preamble .= $text;
                continue;
            }

            $current .= $text;

            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $bracketDepth++;
            } elseif ($text === '{') {
                $braceDepth++;
            } elseif ($text === '}') {
                $braceDepth--;
            } elseif ($text === '(') {
                $parenDepth++;
            } elseif ($text === ')') {
                $parenDepth--;
            } elseif ($text === '[') {
                $bracketDepth++;
            } elseif ($text === ']') {
                $bracketDepth--;
            }

            $atTopLevel = $braceDepth === 0 && $parenDepth === 0 && $bracketDepth === 0;
            $shouldFinalize = $atTopLevel && $text === ';';
            if (!$shouldFinalize && $atTopLevel && $text === '}') {
                $shouldFinalize = self::shouldFinalizeAfterBlock(self::nextSignificantToken($tokens, $i + 1));
            }

            if (!$shouldFinalize) {
                continue;
            }

            if ($capturePreamble && self::isPreambleStatement($current)) {
                $preamble .= $current;
            } else {
                $capturePreamble = false;
                $statements[] = $current;
            }
            $current = '';
        }

        if ($current !== '') {
            if (trim($current) === '') {
                if ($statements === []) {
                    $preamble .= $current;
                } else {
                    $lastIndex = count($statements) - 1;
                    $statements[$lastIndex] .= $current;
                }
            } elseif ($capturePreamble && self::isPreambleStatement($current)) {
                $preamble .= $current;
            } else {
                $statements[] = $current;
            }
        }

        return [
            'preamble' => $preamble,
            'statements' => $statements,
        ];
    }

    /**
     * @param string|array{int,string,int} $token
     */
    private static function isTriviaToken(string|array $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @param array{0:int|string,1:string}|null $nextToken
     */
    private static function shouldFinalizeAfterBlock(?array $nextToken): bool
    {
        if ($nextToken === null) {
            return true;
        }

        if ($nextToken[1] === ';') {
            return false;
        }

        return !is_int($nextToken[0]) || !in_array($nextToken[0], [T_ELSE, T_ELSEIF, T_CATCH, T_FINALLY, T_WHILE], true);
    }

    private static function isPreambleStatement(string $statement): bool
    {
        $tokens = token_get_all("<?php\n" . $statement);
        array_shift($tokens);

        $significant = [];
        foreach ($tokens as $token) {
            if (self::isTriviaToken($token)) {
                continue;
            }

            if (is_array($token)) {
                $significant[] = $token[0];
            } else {
                $significant[] = $token;
            }

            if (count($significant) === 3) {
                break;
            }
        }

        if ($significant === []) {
            return true;
        }

        $first = $significant[0];
        if (is_int($first) && in_array($first, [T_DECLARE, T_NAMESPACE, T_USE, T_CONST, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_READONLY], true)) {
            return true;
        }

        if ($first === T_ATTRIBUTE && isset($significant[1]) && is_int($significant[1])) {
            return in_array($significant[1], [T_CONST, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_READONLY], true)
                || (isset($significant[2]) && is_int($significant[2]) && in_array($significant[2], [T_CLASS, T_FUNCTION], true));
        }

        return is_int($first)
            && in_array($first, [T_ABSTRACT, T_FINAL], true)
            && isset($significant[1])
            && is_int($significant[1])
            && in_array($significant[1], [T_CLASS, T_FUNCTION], true);
    }

    private static function indent(string $text, string $indent): string
    {
        return preg_replace('/^/m', $indent, $text) ?? $indent . $text;
    }
}
