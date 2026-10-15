<?php

declare(strict_types=1);

namespace Aura\Api;

final class Config
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    public static function string(string $key, ?string $default = null): string
    {
        $value = getenv($key);

        if ($value === false) {
            if ($default === null) {
                throw new \RuntimeException(sprintf('La variable %s est obligatoire.', $key));
            }

            return $default;
        }

        return $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = getenv($key);
        return $value === false ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', self::string($key, '')),
        )));
    }
}

