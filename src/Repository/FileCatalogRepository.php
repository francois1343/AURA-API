<?php
declare(strict_types=1);

namespace Aura\Api\Repository;

use Aura\Api\Catalog\Csv;
use Aura\Api\Catalog\Training;
use Aura\Api\Catalog\Validator;
use Aura\Api\Http\ApiException;
use Aura\Api\Storage\PrivateFiles;

/** A single active CSV and at most one previous CSV; JSON holds only metadata. */
final class FileCatalogRepository
{
    public function __construct(private readonly PrivateFiles $files = new PrivateFiles()) {}

    private function state(): array { return $this->files->json('catalogue.json'); }

    private function readCsv(array $version): string
    {
        if (!preg_match('/^catalogue-[a-f0-9]{24}\.csv$/D', $version['file'] ?? '')) throw new \RuntimeException('Référence de catalogue invalide.');
        $text = file_get_contents($this->files->path($version['file']));
        if ($text === false || !hash_equals($version['sha256'], hash('sha256', $text))) throw new \RuntimeException('Le fichier catalogue est absent ou endommagé.');
        return $text;
    }

    private function catalog(array $state, array $version): array
    {
        $base = ['kind' => 'forem-training-catalog', 'schemaVersion' => 2, 'sectors' => $state['sectors'], 'trainings' => []];
        return Csv::proposal($this->readCsv($version), $base, 'replace', minimal: true, trustedSnapshot: true)['catalog'];
    }

    private function summary(array $version): array
    {
        return ['filename' => 'catalogue.csv', 'uploadedName' => $version['uploadedName'], 'updatedAt' => $version['updatedAt'], 'updatedBy' => $version['updatedBy'], 'entries' => $version['entries'], 'formations' => $version['formations'], 'bytes' => $version['bytes'], 'sha256' => $version['sha256']];
    }

    private function snapshot(array $state): array
    {
        return ['revision' => $state['revision'], 'active' => $this->summary($state['active']), 'previous' => isset($state['previous']) ? $this->summary($state['previous']) : null];
    }

    public function current(): array
    {
        return $this->files->locked('catalogue', false, function () {
            $state = $this->state();
            return $this->catalog($state, $state['active']);
        });
    }

    public function status(): array
    {
        return $this->files->locked('catalogue', false, function () {
            $state = $this->state();
            $this->readCsv($state['active']);
            return $this->snapshot($state);
        });
    }

    private function version(array $catalog, string $name, string $actor): array
    {
        $csv = Csv::export($catalog);
        // Verify the stored representation is self-contained before switching it live.
        $base = [...$catalog, 'trainings' => []];
        Csv::proposal($csv, $base, 'replace', minimal: true, trustedSnapshot: true);
        $file = 'catalogue-' . bin2hex(random_bytes(12)) . '.csv';
        $this->files->write($file, $csv);
        return ['file' => $file, 'uploadedName' => $name, 'updatedAt' => gmdate(DATE_ATOM), 'updatedBy' => $actor, 'entries' => count($catalog['trainings']), 'formations' => count(array_filter(Training::entries($catalog), fn ($item) => !$item['isGroup'])), 'bytes' => strlen($csv), 'sha256' => hash('sha256', $csv)];
    }

    public function initialize(array $catalog, string $actor = 'initialisation'): void
    {
        Validator::assert($catalog);
        $this->files->locked('catalogue', true, function () use ($catalog, $actor) {
            if (is_file($this->files->path('catalogue.json'))) throw new ApiException(409, 'already_initialized', 'Le catalogue CSV existe déjà ; aucun fichier n’a été remplacé.');
            $state = ['revision' => 1, 'sectors' => $catalog['sectors'], 'active' => $this->version($catalog, 'catalogue-initial.csv', $actor), 'previous' => null];
            try { $this->files->writeJson('catalogue.json', $state); }
            catch (\Throwable $error) { @unlink($this->files->path($state['active']['file'])); throw $error; }
        });
    }

    private function expected(array $request, array $state): void
    {
        if (!is_int($request['revision'] ?? null) || $request['revision'] !== $state['revision']) throw new ApiException(409, 'revision_conflict', 'Le catalogue a changé. Rechargez le dashboard avant de réessayer.');
    }

    public function import(string $temporary, string $name, int $revision, string $actor): array
    {
        $size = filesize($temporary);
        if (!$size || $size > Csv::MAX_BYTES) throw new ApiException(422, 'invalid_csv_size', 'Choisissez un CSV non vide de 1 Mo maximum.');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') throw new ApiException(422, 'csv_required', 'Choisissez un fichier .csv, pas un classeur Excel.');
        $csv = file_get_contents($temporary);
        if ($csv === false) throw new ApiException(422, 'invalid_upload', 'Le fichier reçu est illisible.');
        return $this->files->locked('catalogue', true, function () use ($csv, $name, $revision, $actor) {
            $state = $this->state();
            $this->expected(['revision' => $revision], $state);
            $proposal = Csv::proposal($csv, $this->catalog($state, $state['active']), 'replace', minimal: true);
            $cleanName = mb_substr(basename(str_replace('\\', '/', $name)), 0, 200);
            $next = $this->version($proposal['catalog'], $cleanName, $actor);
            if (hash_equals($state['active']['sha256'], $next['sha256'])) {
                unlink($this->files->path($next['file']));
                return [...$this->snapshot($state), 'unchanged' => true, 'warnings' => $proposal['warnings']];
            }
            $oldPrevious = $state['previous'];
            $state = [...$state, 'revision' => $state['revision'] + 1, 'previous' => $state['active'], 'active' => $next];
            // One atomic pointer switch publishes a complete CSV and its backup together.
            try { $this->files->writeJson('catalogue.json', $state); }
            catch (\Throwable $error) { @unlink($this->files->path($next['file'])); throw $error; }
            if ($oldPrevious && $oldPrevious['file'] !== $state['active']['file'] && $oldPrevious['file'] !== $state['previous']['file']) @unlink($this->files->path($oldPrevious['file']));
            return [...$this->snapshot($state), 'unchanged' => false, 'warnings' => $proposal['warnings']];
        });
    }

    public function restore(array $request, string $actor): array
    {
        return $this->files->locked('catalogue', true, function () use ($request, $actor) {
            $state = $this->state();
            $this->expected($request, $state);
            if (($request['confirmation'] ?? '') !== 'REVENIR') throw new ApiException(422, 'confirmation_required', 'Confirmez le retour au catalogue précédent.');
            if (!$state['previous']) throw new ApiException(409, 'no_previous_catalog', 'Aucune version précédente n’est disponible.');
            $this->catalog($state, $state['previous']);
            $previous = $state['previous'];
            $state = [...$state, 'revision' => $state['revision'] + 1, 'previous' => $state['active'], 'active' => $previous, 'lastRestoredBy' => $actor, 'lastRestoredAt' => gmdate(DATE_ATOM)];
            $this->files->writeJson('catalogue.json', $state);
            return $this->snapshot($state);
        });
    }

    public function export(): array
    {
        return $this->files->locked('catalogue', false, function () {
            $state = $this->state();
            return ['filename' => 'aura-catalogue-r' . $state['revision'] . '.csv', 'csv' => $this->readCsv($state['active']), 'revision' => $state['revision']];
        });
    }
}
