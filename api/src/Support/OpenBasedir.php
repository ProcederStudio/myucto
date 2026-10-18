<?php

declare(strict_types=1);

namespace MyInvoice\Support;

/**
 * Souborové dotazy na cesty, které mohou ležet mimo `open_basedir`.
 *
 * Na sdíleném hostingu (ISPConfig apod.) zapíše `is_file('/usr/bin/php')` nebo
 * `is_file('/.dockerenv')` při každém požadavku varování do php-errors.log, i když
 * kód odpověď „neexistuje" korektně ošetří. Cesta mimo povolené stromy se proto
 * vůbec nezkoumá a bere se jako neexistující: pro detekci prostředí i hledání
 * binárek je to správná odpověď, protože webový proces na ni stejně nedosáhne.
 *
 * Porovnání je lexikální jako v PHP: položka `open_basedir` je prefix cesty.
 */
final class OpenBasedir
{
    public static function allows(string $path, ?string $openBasedir = null): bool
    {
        $openBasedir ??= (string) ini_get('open_basedir');
        if (trim($openBasedir) === '') {
            return true;
        }
        $target = self::normalize($path);
        if ($target === null) {
            return false;
        }
        foreach (explode(PATH_SEPARATOR, $openBasedir) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $prefix = self::normalize($entry);
            if ($prefix !== null && str_starts_with($target, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public static function isFile(string $path): bool
    {
        return self::allows($path) && is_file($path);
    }

    public static function isExecutableFile(string $path): bool
    {
        return self::isFile($path) && is_executable($path);
    }

    /** Absolutní cesta s lomítky, na Windows malými písmeny; relativní k cwd. */
    private static function normalize(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $path = str_replace('\\', '/', $path);
        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1;
        if (!$absolute) {
            $cwd = getcwd();
            if ($cwd === false) {
                return null;
            }
            $path = rtrim(str_replace('\\', '/', $cwd), '/') . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '..') {
                if (count($parts) > 1) {
                    array_pop($parts);
                }
                continue;
            }
            if ($segment === '.' || ($segment === '' && $i > 0)) {
                continue;
            }
            $parts[] = $segment;
        }
        $normalized = implode('/', $parts);
        if ($normalized === '') {
            $normalized = '/';
        }
        if (str_ends_with($path, '/') && !str_ends_with($normalized, '/')) {
            $normalized .= '/';
        }
        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
