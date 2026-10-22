<?php
declare(strict_types=1);

namespace Aura\Api\Catalog;

use Aura\Api\Http\ApiException;

final class Training
{
    public static function blank(string $id, string $title, string $sectorId, string $sourceUrl): array
    {
        return ['id' => $id, 'title' => $title, 'sectorId' => $sectorId,
            'occupationDescription' => '', 'description' => '', 'topics' => [], 'prerequisites' => [],
            'extraInfo' => '', 'duration' => '', 'schedule' => '', 'locations' => [],
            'foremUrl' => '', 'videoUrl' => '', 'photo' => '', 'photoAlt' => '', 'photoCredit' => '',
            'verifiedAt' => null, 'contentStatus' => 'pending', 'sourceUrl' => $sourceUrl,
            'shortage' => ['indicated' => false, 'source' => 'catalogue-aura', 'verifiedAt' => null],
            'badge' => '', 'orientationStatus' => 'pending'];
    }

    public static function entries(array $catalog): array
    {
        $entries = [];
        foreach ($catalog['trainings'] as $group) {
            $item = $group;
            unset($item['variants']);
            $entries[$item['id']] = ['training' => $item, 'parentId' => null, 'isGroup' => isset($group['variants'])];
            foreach ($group['variants'] ?? [] as $variant) $entries[$variant['id']] = ['training' => $variant, 'parentId' => $group['id'], 'isGroup' => false];
        }
        return $entries;
    }

    public static function changes(array $before, array $after): array
    {
        $old = self::entries($before);
        $new = self::entries($after);
        $diff = ['added' => [], 'updated' => [], 'removed' => [], 'unchanged' => 0];
        foreach ($new as $id => $entry) {
            $summary = ['id' => (string) $id, 'title' => $entry['training']['title'], 'parentId' => $entry['parentId']];
            if (!isset($old[$id])) $diff['added'][] = [...$summary, 'training' => $entry['training']];
            elseif ($entry != $old[$id]) {
                $changes = [];
                foreach (array_unique([...array_keys($old[$id]['training']), ...array_keys($entry['training'])]) as $field) {
                    $beforeValue = $old[$id]['training'][$field] ?? null;
                    $afterValue = $entry['training'][$field] ?? null;
                    if ($beforeValue !== $afterValue) $changes[] = ['field' => $field, 'before' => $beforeValue, 'after' => $afterValue];
                }
                foreach (['parentId', 'isGroup'] as $field) if ($entry[$field] !== $old[$id][$field]) $changes[] = ['field' => $field, 'before' => $old[$id][$field], 'after' => $entry[$field]];
                $diff['updated'][] = [...$summary, 'changes' => $changes];
            }
            else $diff['unchanged']++;
        }
        foreach ($old as $id => $entry) if (!isset($new[$id])) $diff['removed'][] = ['id' => (string) $id, 'title' => $entry['training']['title'], 'parentId' => $entry['parentId']];
        $diff['orderChanged'] = array_values(array_intersect(array_keys($old), array_keys($new))) !== array_values(array_intersect(array_keys($new), array_keys($old)));
        return $diff;
    }

    public static function invalidateStructuralChanges(array $before, array &$after): void
    {
        $old = self::entries($before);
        $oldGroups = [];
        foreach ($before['trainings'] as $group) if (isset($group['variants'])) $oldGroups[$group['id']] = array_column($group['variants'], 'id');
        $mark = static function (array &$item, ?string $parentId, bool $isGroup) use ($old, $oldGroups): void {
            $previous = $old[$item['id']] ?? null;
            if (!$previous || $previous['parentId'] !== $parentId || $previous['isGroup'] !== $isGroup || $previous['training']['sectorId'] !== $item['sectorId']) $item['orientationStatus'] = 'pending';
            if ($isGroup && array_column($item['variants'], 'id') !== ($oldGroups[$item['id']] ?? [])) $item['orientationStatus'] = 'pending';
        };
        foreach ($after['trainings'] as &$training) {
            $mark($training, null, isset($training['variants']));
            if (isset($training['variants'])) foreach ($training['variants'] as &$variant) $mark($variant, $training['id'], false);
            unset($variant);
        }
        unset($training);
    }

    public static function removal(array $catalog, string $id): array
    {
        $next = $catalog;
        $found = false;
        $promoted = null;
        foreach ($next['trainings'] as $index => $training) {
            if ($training['id'] === $id) { array_splice($next['trainings'], $index, 1); $found = true; break; }
            if (!isset($training['variants'])) continue;
            $variants = array_values(array_filter($training['variants'], fn ($variant) => $variant['id'] !== $id));
            if (count($variants) === count($training['variants'])) continue;
            $found = true;
            if (count($variants) === 1) {
                // Keep the surviving formation and its identity, never delete it implicitly.
                $next['trainings'][$index] = $variants[0];
                $next['trainings'][$index]['orientationStatus'] = 'pending';
                $promoted = $variants[0]['id'];
            } else $next['trainings'][$index]['variants'] = $variants;
            break;
        }
        if (!$found) throw new ApiException(404, 'training_not_found', 'Cette formation n’existe pas.');
        if (!$next['trainings']) throw new ApiException(422, 'catalog_must_not_be_empty', 'La dernière formation ne peut pas être supprimée. Le catalogue doit rester non vide.');
        self::invalidateStructuralChanges($catalog, $next);
        Validator::assert($next);
        return ['catalog' => $next, 'diff' => self::changes($catalog, $next), 'promotedId' => $promoted];
    }
}
