<?php
declare(strict_types=1);

namespace Aura\Api\Storage;

use Aura\Api\Config;
use Aura\Api\Http\ApiException;

/** Local, persistent files. Never place this directory under a web document root. */
final class PrivateFiles
{
    public readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $directory ??= Config::string('AURA_STORAGE_DIR', dirname(__DIR__, 2) . '/storage');
        if (!str_starts_with($directory, '/') || is_link($directory)) throw new \RuntimeException('AURA_STORAGE_DIR doit être un dossier privé absolu.');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Impossible de créer le stockage privé.');
        $resolved = realpath($directory);
        $public = realpath(dirname(__DIR__, 2) . '/public');
        $documentRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? (realpath($_SERVER['DOCUMENT_ROOT']) ?: null) : null;
        foreach (array_filter([$public, $documentRoot]) as $root) if ($resolved === $root || str_starts_with($resolved . '/', $root . '/')) throw new \RuntimeException('Le stockage doit être hors du dossier public.');
        if (!$resolved || !is_writable($resolved)) throw new \RuntimeException('Le stockage privé doit être accessible en écriture.');
        $this->directory = $resolved;
    }

    public function path(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/D', $name) || $name === '.' || $name === '..') throw new \RuntimeException('Nom de fichier interne invalide.');
        $path = $this->directory . '/' . $name;
        if (is_link($path)) throw new \RuntimeException('Les liens symboliques ne sont pas autorisés dans le stockage.');
        return $path;
    }

    public function locked(string $name, bool $exclusive, callable $operation): mixed
    {
        $handle = fopen($this->path($name . '.lock'), 'c');
        if (!$handle) throw new \RuntimeException('Stockage indisponible.');
        chmod($this->path($name . '.lock'), 0600);
        try {
            if (!flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) throw new \RuntimeException('Impossible de verrouiller le stockage.');
            return $operation();
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function json(string $name, ?array $default = null): array
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            if ($default !== null) return $default;
            throw new ApiException(503, 'storage_not_initialized', 'Le catalogue CSV n’a pas encore été initialisé.');
        }
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Fichier de données invalide.');
        return $data;
    }

    public function writeJson(string $name, array $value): void
    {
        $this->write($name, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function write(string $name, string $content): void
    {
        $temporary = tempnam($this->directory, '.writing-');
        if ($temporary === false) throw new \RuntimeException('Impossible de préparer le fichier.');
        try {
            chmod($temporary, 0600);
            $handle = fopen($temporary, 'wb');
            if (!$handle) throw new \RuntimeException('Impossible d’écrire le fichier.');
            try {
                $offset = 0;
                while ($offset < strlen($content)) {
                    $written = fwrite($handle, substr($content, $offset));
                    if ($written === false || $written === 0) throw new \RuntimeException('Écriture interrompue. Le catalogue précédent est conservé.');
                    $offset += $written;
                }
                if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new \RuntimeException('Impossible de finaliser le fichier.');
            } finally { fclose($handle); }
            if (!rename($temporary, $this->path($name))) throw new \RuntimeException('Impossible de publier le fichier.');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
}
