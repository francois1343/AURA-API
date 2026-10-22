<?php
declare(strict_types=1);

namespace Aura\Api\Repository;

use Aura\Api\Catalog\Csv;
use Aura\Api\Catalog\Training;
use Aura\Api\Catalog\Validator;
use Aura\Api\Http\ApiException;
use PDO;

final class TrainingCatalogRepository
{
    public function __construct(private readonly PDO $db) {}

    public function current(bool $lock = false): array
    {
        $row = $this->db->query('SELECT * FROM training_catalog WHERE id = 1' . ($lock ? ' FOR UPDATE' : ''))->fetch();
        if (!$row) throw new ApiException(503, 'catalog_not_initialized', 'Le catalogue formations n’a pas encore été initialisé côté serveur.');
        return ['catalog' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR), 'notes' => json_decode($row['notes'], true, flags: JSON_THROW_ON_ERROR), 'revision' => (int) $row['revision'], 'updatedAt' => $row['updated_at'], 'updatedBy' => $row['updated_by']];
    }

    public function initialize(array $catalog): void
    {
        Validator::assert($catalog);
        $this->db->beginTransaction();
        try {
            if ($this->db->query('SELECT id FROM training_catalog WHERE id = 1 FOR UPDATE')->fetch()) throw new ApiException(409, 'already_initialized', 'Le catalogue existe déjà ; aucune donnée n’a été remplacée.');
            $payload = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->db->prepare('INSERT INTO training_catalog (id, revision, payload, notes, updated_by) VALUES (1, 1, ?, ?, ?)')->execute([$payload, '{}', 'initialisation']);
            $this->db->prepare('INSERT INTO training_catalog_versions (revision, payload, notes, updated_by) VALUES (1, ?, ?, ?)')->execute([$payload, '{}', 'initialisation']);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    private function revision(array $request): int
    {
        if (!is_int($request['revision'] ?? null) || $request['revision'] < 1) throw new ApiException(422, 'invalid_revision', 'Une révision de catalogue valide est requise.');
        return $request['revision'];
    }

    private function assertRevision(int $expected, array $current): void
    {
        if ($expected !== $current['revision']) throw new ApiException(409, 'revision_conflict', 'Le catalogue a changé. Vos saisies sont conservées ; rechargez la version récente ou recréez l’aperçu avant de publier.');
    }

    private function mutate(int $expected, string $actor, callable $apply): array
    {
        $this->db->beginTransaction();
        try {
            $current = $this->current(true);
            $this->assertRevision($expected, $current);
            $next = $apply($current);
            Training::invalidateStructuralChanges($current['catalog'], $next['catalog']);
            Validator::assert($next['catalog']);
            $ids = array_keys(Training::entries($next['catalog']));
            $notes = array_intersect_key($next['notes'], array_flip($ids));
            $payload = json_encode($next['catalog'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $notesJson = json_encode((object) $notes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $revision = $current['revision'] + 1;
            $this->db->prepare('UPDATE training_catalog SET revision = ?, payload = ?, notes = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1')->execute([$revision, $payload, $notesJson, $actor]);
            $this->db->prepare('INSERT INTO training_catalog_versions (revision, payload, notes, updated_by) VALUES (?, ?, ?, ?)')->execute([$revision, $payload, $notesJson, $actor]);
            // Return exactly the version written, never a later concurrent commit.
            $written = $this->current();
            $this->db->commit();
            return $written;
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    private function validateChanges(array $request, array $allowed): void
    {
        if (array_diff(array_keys($request), $allowed) || !is_array($request['changes'] ?? null) || array_is_list($request['changes']) || array_diff(array_keys($request['changes']), Validator::EDITABLE) || !is_string($request['note'] ?? null) || mb_strlen($request['note']) > 10000) throw new ApiException(422, 'invalid_update', 'Champs de modification invalides. Les identifiants et regroupements ne se modifient pas dans ce formulaire.');
    }

    public function save(string $id, array $request, string $actor): array
    {
        $this->validateChanges($request, ['revision', 'changes', 'note']);
        return $this->mutate($this->revision($request), $actor, function ($current) use ($id, $request) {
            $found = false;
            foreach ($current['catalog']['trainings'] as &$training) {
                if ($training['id'] === $id) {
                    $moved = isset($request['changes']['sectorId']) && $training['sectorId'] !== $request['changes']['sectorId'];
                    $training = array_replace($training, $request['changes']);
                    if ($moved) {
                        $training['orientationStatus'] = 'pending';
                        if (isset($training['variants'])) foreach ($training['variants'] as &$variant) { $variant['sectorId'] = $training['sectorId']; $variant['orientationStatus'] = 'pending'; }
                        unset($variant);
                    }
                    $found = true; break;
                }
                foreach ($training['variants'] ?? [] as $index => $variant) if ($variant['id'] === $id) {
                    $training['variants'][$index] = array_replace($variant, $request['changes']);
                    $found = true; break 2;
                }
            }
            unset($training);
            if (!$found) throw new ApiException(404, 'training_not_found', 'Cette formation n’existe pas.');
            $current['notes'][$id] = $request['note'];
            return $current;
        });
    }

    public function create(array $request, string $actor): array
    {
        $this->validateChanges($request, ['revision', 'id', 'parentId', 'changes', 'note']);
        $id = $request['id'] ?? '';
        $parentId = $request['parentId'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9-]{1,100}$/', $id) || ($parentId !== null && !is_string($parentId))) throw new ApiException(422, 'invalid_identifier', 'Identifiant invalide : 1 à 100 lettres minuscules, chiffres ou tirets.');
        return $this->mutate($this->revision($request), $actor, function ($current) use ($id, $parentId, $request) {
            if (isset(Training::entries($current['catalog'])[$id])) throw new ApiException(409, 'duplicate_identifier', 'Cet identifiant est déjà utilisé. Choisissez-en un autre.');
            $training = array_replace(Training::blank($id, '', '', ''), $request['changes']);
            $training['orientationStatus'] = 'pending';
            if ($parentId === null) $current['catalog']['trainings'][] = $training;
            else {
                $found = false;
                foreach ($current['catalog']['trainings'] as &$parent) if ($parent['id'] === $parentId && isset($parent['variants'])) { $parent['variants'][] = $training; $found = true; break; }
                unset($parent);
                if (!$found) throw new ApiException(422, 'invalid_parent', 'Choisissez un regroupement existant pour ajouter une variante.');
            }
            $current['notes'][$id] = $request['note'];
            return $current;
        });
    }

    public function deletionPreview(string $id, array $request): array
    {
        if (array_diff(array_keys($request), ['revision'])) throw new ApiException(422, 'invalid_preview', 'Aperçu de suppression invalide.');
        $current = $this->current();
        $this->assertRevision($this->revision($request), $current);
        $proposal = Training::removal($current['catalog'], $id);
        return ['revision' => $current['revision'], 'id' => $id, 'title' => Training::entries($current['catalog'])[$id]['training']['title'], 'diff' => $proposal['diff'], 'promotedId' => $proposal['promotedId'], 'remainingEntries' => count($proposal['catalog']['trainings'])];
    }

    public function remove(string $id, array $request, string $actor): array
    {
        if (array_diff(array_keys($request), ['revision', 'confirmation', 'removedCount']) || ($request['confirmation'] ?? null) !== $id || !is_int($request['removedCount'] ?? null)) throw new ApiException(422, 'confirmation_required', 'Confirmez explicitement la suppression affichée dans l’aperçu.');
        return $this->mutate($this->revision($request), $actor, function ($current) use ($id, $request) {
            $proposal = Training::removal($current['catalog'], $id);
            if ($request['removedCount'] !== count($proposal['diff']['removed'])) throw new ApiException(409, 'deletion_preview_changed', 'L’aperçu de suppression a changé. Recommencez la confirmation.');
            $current['catalog'] = $proposal['catalog'];
            return $current;
        });
    }

    public function importPreview(array $request): array
    {
        if (array_diff(array_keys($request), ['revision', 'csv', 'csvBase64', 'mode']) || !is_string($request['mode'] ?? null) || (isset($request['csv']) === isset($request['csvBase64']))) throw new ApiException(422, 'invalid_import', 'Un fichier CSV et le mode d’import sont requis.');
        if (isset($request['csvBase64'])) {
            $csv = is_string($request['csvBase64']) ? base64_decode($request['csvBase64'], true) : false;
            if ($csv === false) throw new ApiException(422, 'invalid_import', 'Encodage du fichier invalide.');
        } else $csv = $request['csv'];
        if (!is_string($csv)) throw new ApiException(422, 'invalid_import', 'Le contenu du CSV est invalide.');
        $current = $this->current();
        $this->assertRevision($this->revision($request), $current);
        $proposal = Csv::proposal($csv, $current['catalog'], $request['mode']);
        $token = bin2hex(random_bytes(32));
        $expires = time() + 900;
        $_SESSION['catalog_import'] = ['token' => $token, 'revision' => $current['revision'], 'catalog' => $proposal['catalog'], 'expires' => $expires, 'removedCount' => count($proposal['diff']['removed'])];
        return ['token' => $token, 'revision' => $current['revision'], 'mode' => $request['mode'], 'expiresAt' => gmdate(DATE_ATOM, $expires), 'diff' => $proposal['diff'], 'warnings' => $proposal['warnings'], 'recordCount' => $proposal['recordCount']];
    }

    public function importCommit(array $request, string $actor): array
    {
        if (array_diff(array_keys($request), ['revision', 'token', 'confirmation']) || !is_string($request['token'] ?? null)) throw new ApiException(422, 'invalid_import', 'La confirmation d’import est invalide.');
        $preview = $_SESSION['catalog_import'] ?? null;
        if (!$preview || !hash_equals($preview['token'], $request['token']) || $preview['expires'] < time()) throw new ApiException(409, 'import_preview_expired', 'L’aperçu est absent ou expiré. Analysez de nouveau le fichier.');
        if ($this->revision($request) !== $preview['revision']) throw new ApiException(409, 'revision_conflict', 'La révision de l’aperçu est incorrecte.');
        if ($preview['removedCount'] && ($request['confirmation'] ?? '') !== 'SUPPRIMER ' . $preview['removedCount']) throw new ApiException(422, 'confirmation_required', 'Saisissez la confirmation du nombre exact de fiches supprimées.');
        $saved = $this->mutate($preview['revision'], $actor, function ($current) use ($preview) {
            $current['catalog'] = $preview['catalog'];
            return $current;
        });
        unset($_SESSION['catalog_import']);
        return $saved;
    }

    public function exportCsv(): array
    {
        $current = $this->current();
        return ['filename' => 'aura-catalogue-' . gmdate('Y-m-d') . '-r' . $current['revision'] . '.csv', 'csv' => Csv::export($current['catalog']), 'revision' => $current['revision']];
    }
}
