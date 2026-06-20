<?php

declare(strict_types=1);

namespace ObfusX;

final class DirectoryEncoder
{
    /**
     * @param array<int,string> $ignorePatterns
     * @return array{encoded:int,copied:int,skipped:int}
     */
    public static function encode(string $inDir, string $outDir, string $masterKey, array $ignorePatterns = []): array
    {
        $sourceRoot = realpath($inDir);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new \RuntimeException('Input directory does not exist.');
        }

        $outputRoot = rtrim($outDir, DIRECTORY_SEPARATOR);
        if ($outputRoot === '') {
            throw new \RuntimeException('Output directory cannot be empty.');
        }

        self::assertOutputDirectory($sourceRoot, $outputRoot);

        $patterns = $ignorePatterns === [] ? self::loadIgnorePatterns($sourceRoot) : $ignorePatterns;
        $cacheFile = self::cacheFile();
        $cache = $cacheFile === null ? [] : self::loadCache($cacheFile);
        $nextCache = [];
        $encoded = 0;
        $copied = 0;
        $skipped = 0;
        $targetRoot = $outputRoot . DIRECTORY_SEPARATOR . basename($sourceRoot);

        self::ensureDirectory($targetRoot);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($sourceRoot, $patterns, $cacheFile): bool {
                    $pathname = $current->getPathname();
                    if ($cacheFile !== null && self::pathsEqual($pathname, $cacheFile)) {
                        return false;
                    }

                    $relativePath = self::relativePath($sourceRoot, $pathname);

                    return !self::matchesIgnorePattern($relativePath, $patterns, $current->isDir());
                }
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $pathname = $item->getPathname();
            $relativePath = self::relativePath($sourceRoot, $pathname);
            $targetPath = self::targetPath($targetRoot, $relativePath, $item->isDir());

            if ($item->isDir()) {
                self::ensureDirectory($targetPath);
                continue;
            }

            $hash = hash_file('sha256', $pathname);
            if ($hash === false) {
                throw new \RuntimeException('Failed to checksum source file: ' . $pathname);
            }

            $realPath = realpath($pathname);
            $cacheKey = $realPath !== false ? $realPath : $pathname;
            $nextCache[$cacheKey] = $hash;

            if ($cacheFile !== null && isset($cache[$cacheKey]) && hash_equals($cache[$cacheKey], $hash) && is_file($targetPath)) {
                $skipped++;
                continue;
            }

            self::ensureDirectory(dirname($targetPath));
            if (self::isPhpFile($pathname)) {
                Encoder::encodeFile($pathname, $targetPath, $masterKey);
                $encoded++;
                continue;
            }

            if (!copy($pathname, $targetPath)) {
                throw new \RuntimeException('Failed to copy file: ' . $pathname);
            }

            $copied++;
        }

        if ($cacheFile !== null) {
            self::writeCache($cacheFile, $nextCache);
        }

        return [
            'encoded' => $encoded,
            'copied' => $copied,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function loadIgnorePatterns(string $inDir): array
    {
        $ignoreFile = rtrim($inDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.obxignore';
        if (!is_file($ignoreFile)) {
            return [];
        }

        $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Failed to read .obxignore file.');
        }

        $patterns = [];
        foreach ($lines as $line) {
            $pattern = trim($line);
            if ($pattern === '' || str_starts_with($pattern, '#')) {
                continue;
            }

            if (str_starts_with($pattern, './')) {
                $pattern = substr($pattern, 2);
            }

            if (str_starts_with($pattern, '/')) {
                $pattern = substr($pattern, 1);
            }

            $patterns[] = $pattern;
        }

        return $patterns;
    }

    private static function cacheFile(): ?string
    {
        $cacheFile = getenv('OBFUSX_CACHE_FILE');

        return is_string($cacheFile) && $cacheFile !== '' ? $cacheFile : null;
    }

    private static function assertOutputDirectory(string $sourceRoot, string $outputRoot): void
    {
        $normalizedSource = self::normalizePath($sourceRoot);
        $normalizedOutput = self::normalizePath($outputRoot);

        if ($normalizedOutput === $normalizedSource || str_starts_with($normalizedOutput . '/', $normalizedSource . '/')) {
            throw new \RuntimeException('Output directory must not be inside the input directory.');
        }
    }

    /**
     * @param array<int,string> $patterns
     */
    private static function matchesIgnorePattern(string $relativePath, array $patterns, bool $isDir): bool
    {
        $normalizedPath = self::normalizeRelativePath($relativePath);
        $basename = basename($normalizedPath);

        foreach ($patterns as $pattern) {
            $normalizedPattern = self::normalizeRelativePath(ltrim($pattern, '/'));
            if ($normalizedPattern === '') {
                continue;
            }

            $directoryPattern = rtrim($normalizedPattern, '/');
            if ($directoryPattern !== $normalizedPattern) {
                if ($normalizedPath === $directoryPattern || str_starts_with($normalizedPath, $directoryPattern . '/')) {
                    return true;
                }
            }

            if (self::fnmatch($normalizedPattern, $normalizedPath)) {
                return true;
            }

            if (!str_contains($normalizedPattern, '/') && self::fnmatch($normalizedPattern, $basename)) {
                return true;
            }

            if ($isDir && self::fnmatch(rtrim($normalizedPattern, '/') . '/*', $normalizedPath . '/child')) {
                return true;
            }
        }

        return false;
    }

    private static function fnmatch(string $pattern, string $string): bool
    {
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, $string);
        }
        $regex = '#^' . strtr(preg_quote($pattern, '#'), [
            '\\*' => '[^/]*',
            '\\?' => '[^/]',
        ]) . '$#';
        return (bool) preg_match($regex, $string);
    }

    private static function isPhpFile(string $pathname): bool
    {
        return strtolower(pathinfo($pathname, PATHINFO_EXTENSION)) === 'php';
    }

    private static function relativePath(string $sourceRoot, string $pathname): string
    {
        $normalizedSource = rtrim(str_replace('\\', '/', $sourceRoot), '/');
        $normalizedPath = str_replace('\\', '/', $pathname);

        return ltrim(substr($normalizedPath, strlen($normalizedSource)), '/');
    }

    private static function targetPath(string $targetRoot, string $relativePath, bool $isDir): string
    {
        if ($isDir) {
            return $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        }

        $targetRelative = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (self::isPhpFile($relativePath)) {
            $targetRelative = preg_replace('/\.php$/i', '.obx', $targetRelative);
            if (!is_string($targetRelative)) {
                throw new \RuntimeException('Failed to build output path.');
            }
        }

        return $targetRoot . DIRECTORY_SEPARATOR . $targetRelative;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create directory: ' . $directory);
        }
    }

    /**
     * @return array<string,string>
     */
    private static function loadCache(string $cacheFile): array
    {
        if (!is_file($cacheFile)) {
            return [];
        }

        $json = file_get_contents($cacheFile);
        if ($json === false) {
            throw new \RuntimeException('Failed to read cache file.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid cache file format.');
        }

        $cache = [];
        foreach ($decoded as $path => $hash) {
            if (is_string($path) && is_string($hash)) {
                $cache[$path] = $hash;
            }
        }

        return $cache;
    }

    /**
     * @param array<string,string> $cache
     */
    private static function writeCache(string $cacheFile, array $cache): void
    {
        self::ensureDirectory(dirname($cacheFile));

        $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($cacheFile, $json) === false) {
            throw new \RuntimeException('Failed to write cache file.');
        }
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function normalizeRelativePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private static function pathsEqual(string $left, string $right): bool
    {
        $leftRealPath = realpath($left);
        $rightRealPath = realpath($right);

        return self::normalizePath($leftRealPath !== false ? $leftRealPath : $left) === self::normalizePath($rightRealPath !== false ? $rightRealPath : $right);
    }
}
