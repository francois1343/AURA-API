<?php
declare(strict_types=1);

namespace Aura\Api\Catalog;

use Aura\Api\Http\ApiException;

final class Csv
{
    public const FORMAT = 'aura-catalogue-v1';
    public const LEGACY = ['formation_id', 'intitule', 'secteur_id', 'secteur', 'description_metier', 'description', 'contenu', 'prerequis', 'informations_complementaires', 'duree', 'horaires', 'ville', 'adresse', 'plan_acces_url', 'lien_formation_forem', 'penurie_signalee_dans_liste', 'source_url', 'date_verification', 'variante_id', 'intitule_variante', 'fonction_critique_signalee_dans_liste', 'lien_video_metier'];
    public const HEADERS = [...self::LEGACY, 'format_csv', 'type_ligne', 'statut_contenu', 'photo_url', 'photo_alt', 'credit_photo', 'source_penurie', 'date_verification_penurie', 'source_fonction_critique', 'date_verification_fonction_critique', 'statut_orientation'];
    public const MAX_BYTES = 1048576;

    private static function fail(string $message, array $details = []): never
    {
        throw new ApiException(422, 'invalid_csv', $message, $details);
    }

    private static function needsProtection(string $value): bool
    {
        return preg_match("/^(?:'|[\\t\\r\\n]|[ \\t\\r\\n]*[=+@-])/u", $value) === 1;
    }

    private static function cell(string $value): string
    {
        // Reversible text guard for cells interpreted as formulas by spreadsheets.
        if (self::needsProtection($value)) $value = "'" . $value;
        return '"' . str_replace('"', '""', $value) . '"';
    }

    public static function export(array $catalog): string
    {
        $rows = [self::HEADERS];
        foreach ($catalog['trainings'] as $group) {
            $items = [[$group, isset($group['variants']) ? 'regroupement' : 'formation']];
            foreach ($group['variants'] ?? [] as $variant) $items[] = [$variant, 'variante'];
            foreach ($items as [$item, $kind]) {
                $sector = array_values(array_filter($catalog['sectors'], fn ($sector) => $sector['id'] === $item['sectorId']))[0];
                foreach ($item['locations'] ?: [['city' => '', 'address' => '', 'accessUrl' => '']] as $location) {
                    $rows[] = [$group['id'], $group['title'], $item['sectorId'], $sector['label'], $item['occupationDescription'] ?? '', $item['description'],
                        json_encode($item['topics'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($item['prerequisites'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        $item['extraInfo'], $item['duration'], $item['schedule'], $location['city'], $location['address'], $location['accessUrl'], $item['foremUrl'],
                        $item['shortage']['indicated'] ? 'oui' : '', $item['sourceUrl'], $item['verifiedAt'] ?? '', $kind === 'variante' ? $item['id'] : '', $kind === 'variante' ? $item['title'] : '',
                        ($item['critical']['indicated'] ?? false) ? 'oui' : '', $item['videoUrl'] ?? '', self::FORMAT, $kind, ['pending' => 'à compléter', 'partial' => 'en cours', 'ready' => 'validée'][$item['contentStatus']],
                        $item['photo'], $item['photoAlt'], $item['photoCredit'], $item['shortage']['source'] ?? '', $item['shortage']['verifiedAt'] ?? '',
                        $item['critical']['source'] ?? '', $item['critical']['verifiedAt'] ?? '', isset($item['orientationStatus']) ? 'à calibrer' : ''];
                }
            }
        }
        return "\xEF\xBB\xBF" . implode("\r\n", array_map(fn ($row) => implode(';', array_map(fn ($value) => self::cell((string) $value), $row)), $rows)) . "\r\n";
    }

    /** Strict RFC-style records, with physical start lines for useful errors. */
    public static function parse(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) self::fail('Le CSV dépasse la limite de 1 Mo.');
        $warnings = [];
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $encoding = str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $text = mb_convert_encoding(substr($text, 2), 'UTF-8', $encoding);
            $warnings[] = 'Le fichier UTF-16 a été converti en UTF-8.';
        } elseif (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            $warnings[] = 'Le fichier Windows-1252 a été converti en UTF-8.';
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
        if (str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) self::fail('Encodage CSV invalide. Utilisez UTF-8.');
        $physicalLines = preg_split('/\r\n|\n|\r/', $text);
        $firstLine = '';
        foreach ($physicalLines as $physicalLine) if (trim($physicalLine) !== '') { $firstLine = $physicalLine; break; }
        $delimiter = ';'; $best = 0;
        foreach ([';', ',', "\t"] as $candidate) {
            $count = count(str_getcsv($firstLine, $candidate, '"', ''));
            if ($count > $best) { $best = $count; $delimiter = $candidate; }
        }
        $records = []; $row = []; $value = ''; $state = 'start'; $line = 1; $startLine = 1;
        $push = static function () use (&$records, &$row, &$value, &$startLine): void {
            $row[] = $value;
            if (array_filter($row, fn ($cell) => trim($cell) !== '')) $records[] = ['line' => $startLine, 'cells' => $row];
            $row = []; $value = '';
        };
        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $char = $text[$index];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($text[$index + 1] ?? '') === '"') { $value .= '"'; $index++; }
                    else $state = 'closed';
                } else {
                    $value .= $char;
                    if ($char === "\n" || ($char === "\r" && ($text[$index + 1] ?? '') !== "\n")) $line++;
                }
                continue;
            }
            if ($char === $delimiter) { $row[] = $value; $value = ''; $state = 'start'; }
            elseif ($char === "\r" || $char === "\n") {
                $push(); $state = 'start';
                if ($char === "\r" && ($text[$index + 1] ?? '') === "\n") $index++;
                $line++; $startLine = $line;
                if (count($records) > 5001) self::fail('Le fichier contient plus de 5 000 lignes de données.');
            } elseif ($char === '"' && $state === 'start') $state = 'quoted';
            elseif ($char === '"' || $state === 'closed') self::fail("Ligne $line : guillemets CSV mal placés.");
            else { $value .= $char; $state = 'plain'; }
        }
        if ($state === 'quoted') self::fail("Ligne $startLine : guillemet non fermé.");
        if ($row || $value !== '' || $state === 'closed') $push();
        if (count($records) > 5001) self::fail('Le fichier contient plus de 5 000 lignes de données.');
        if (count($records) < 2) self::fail('Le CSV ne contient aucune formation. Le catalogue actif est conservé.');
        return ['records' => $records, 'warnings' => $warnings];
    }

    private static function flag(string $value, int $line): bool
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['oui', 'true', '1'], true)) return true;
        if (in_array($value, ['', 'non', 'false', '0'], true)) return false;
        self::fail("Ligne $line : utilisez oui/non pour les mentions de pénurie et de fonction critique.");
    }

    private static function date(string $value, int $line): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $parts)) $value = "$parts[3]-$parts[2]-$parts[1]";
        if (!Validator::date($value)) self::fail("Ligne $line : date invalide ; utilisez AAAA-MM-JJ ou JJ/MM/AAAA.");
        return $value;
    }

    private static function lines(string $value, bool $versioned, int $line, string $field): array
    {
        if (!$versioned || !str_starts_with(ltrim($value), '[')) return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $value))), fn ($value) => $value !== ''));
        try { $list = json_decode($value, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::fail("Ligne $line : $field doit être une liste JSON, par exemple [\"Élément 1\",\"Élément 2\"]."); }
        if (!is_array($list) || !array_is_list($list) || array_filter($list, fn ($value) => !is_string($value))) self::fail("Ligne $line : liste $field invalide.");
        return $list;
    }

    public static function proposal(string $csv, array $current, string $mode, bool $minimal = false, bool $trustedSnapshot = false): array
    {
        if (!in_array($mode, ['merge', 'replace'], true)) self::fail('Mode d’import inconnu.');
        $parsed = self::parse($csv);
        $records = $parsed['records'];
        $headers = array_map('trim', array_shift($records)['cells']);
        if (count(array_unique($headers)) !== count($headers)) self::fail('Les en-têtes du CSV sont dupliqués.');
        $versioned = in_array('format_csv', $headers, true);
        $expected = $versioned ? self::HEADERS : self::LEGACY;
        $missing = array_diff($expected, $headers);
        $unknown = array_diff($headers, $expected);
        if ($missing || $unknown) self::fail('Le fichier ne correspond pas au CSV du catalogue AURA.', array_merge($missing ? ['Colonnes manquantes : ' . implode(', ', $missing)] : [], $unknown ? ['Colonnes inconnues : ' . implode(', ', $unknown)] : []));
        $old = Training::entries($current);
        $byId = []; $warnings = $parsed['warnings'];
        if (!$versioned) $warnings[] = 'Ancien CSV de travail : les photos, métadonnées et fiches de regroupement existantes sont conservées lorsqu’elles ne figurent pas dans ce format.';
        foreach ($records as $record) {
            $line = $record['line'];
            if (count($record['cells']) !== count($headers)) self::fail("Ligne $line : nombre de colonnes incorrect.");
            $row = array_combine($headers, $record['cells']);
            if ($versioned) {
                if ($row['format_csv'] !== self::FORMAT) self::fail("Ligne $line : version de CSV inconnue.");
                foreach ($row as &$value) if (str_starts_with($value, "'") && self::needsProtection(substr($value, 1))) $value = substr($value, 1);
                unset($value);
            }
            $groupId = trim($row['formation_id']); $variantId = trim($row['variante_id']);
            $id = $variantId !== '' ? $variantId : $groupId;
            if (!preg_match('/^[a-z0-9-]{1,100}$/', $id) || !preg_match('/^[a-z0-9-]{1,100}$/', $groupId)) self::fail("Ligne $line : identifiant invalide (lettres minuscules, chiffres et tirets uniquement).");
            if ($id === $groupId && $variantId !== '') self::fail("Ligne $line : une variante doit avoir un identifiant différent de son regroupement.");
            $title = $variantId !== '' ? $row['intitule_variante'] : $row['intitule'];
            $sectorId = trim($row['secteur_id']);
            if (!in_array($sectorId, array_column($current['sectors'], 'id'), true)) self::fail("Ligne $line : secteur inconnu « $sectorId ».");
            $item = $old[$id]['training'] ?? Training::blank($id, $title, $sectorId, $row['source_url']);
            $item = array_replace($item, ['id' => $id, 'title' => $title, 'sectorId' => $sectorId, 'occupationDescription' => $row['description_metier'],
                'description' => $row['description'], 'topics' => self::lines($row['contenu'], $versioned, $line, 'contenu'), 'prerequisites' => self::lines($row['prerequis'], $versioned, $line, 'prerequis'),
                'extraInfo' => $row['informations_complementaires'], 'duration' => $row['duree'], 'schedule' => $row['horaires'], 'foremUrl' => $row['lien_formation_forem'],
                'sourceUrl' => $row['source_url'], 'verifiedAt' => self::date($row['date_verification'], $line), 'videoUrl' => $row['lien_video_metier']]);
            if ($minimal && trim($item['sourceUrl']) === '') $item['sourceUrl'] = $old[$id]['training']['sourceUrl'] ?? '';
            if (($old[$id]['training']['sectorId'] ?? $sectorId) !== $sectorId) $item['orientationStatus'] = 'pending';
            $shortage = self::flag($row['penurie_signalee_dans_liste'], $line);
            $critical = self::flag($row['fonction_critique_signalee_dans_liste'], $line);
            $item['shortage'] = ['indicated' => $shortage, 'source' => $versioned ? $row['source_penurie'] : ($item['shortage']['source'] ?? $row['source_url']), 'verifiedAt' => $versioned ? self::date($row['date_verification_penurie'], $line) : ($item['shortage']['verifiedAt'] ?? null)];
            if ($minimal && trim($item['shortage']['source']) === '') $item['shortage']['source'] = 'import-csv';
            if ($critical || isset($item['critical']) || ($versioned && $row['source_fonction_critique'] !== '')) $item['critical'] = ['indicated' => $critical, 'source' => $versioned ? $row['source_fonction_critique'] : ($item['critical']['source'] ?? $row['source_url']), 'verifiedAt' => $versioned ? self::date($row['date_verification_fonction_critique'], $line) : ($item['critical']['verifiedAt'] ?? null)];
            if (array_key_exists('badge', $item)) $item['badge'] = $shortage ? 'Métier en pénurie*' : ($critical ? 'Fonction critique*' : '');
            $kind = $versioned ? $row['type_ligne'] : ($variantId !== '' ? 'variante' : 'formation');
            if (!in_array($kind, ['formation', 'regroupement', 'variante'], true) || (($kind === 'variante') !== ($variantId !== ''))) self::fail("Ligne $line : type de ligne incompatible avec les identifiants.");
            if ($versioned) {
                $status = ['à compléter' => 'pending', 'en cours' => 'partial', 'validée' => 'ready'][mb_strtolower(trim($row['statut_contenu']))] ?? $row['statut_contenu'];
                $item = array_replace($item, ['contentStatus' => $status, 'photo' => $row['photo_url'], 'photoAlt' => $row['photo_alt'], 'photoCredit' => $row['credit_photo']]);
                if (!in_array($row['statut_orientation'], ['', 'pending', 'à calibrer'], true)) self::fail("Ligne $line : statut d’orientation inconnu.");
                // A CSV cannot enable an uncalibrated recommendation by copying an old ID.
                if ($row['statut_orientation'] !== '' || (!$trustedSnapshot && !isset($old[$id]))) $item['orientationStatus'] = 'pending';
                elseif ($trustedSnapshot) unset($item['orientationStatus']);
            }
            $location = ['city' => $row['ville'], 'address' => $row['adresse'], 'accessUrl' => $row['plan_acces_url']];
            unset($item['locations']);
            $identity = ['training' => $item, 'groupId' => $groupId, 'groupTitle' => $row['intitule'], 'kind' => $kind];
            if (isset($byId[$id]) && $byId[$id]['identity'] != $identity) self::fail("Ligne $line : les informations répétées de « $id » sont contradictoires. Seuls les lieux peuvent varier.");
            if (!isset($byId[$id])) $byId[$id] = ['identity' => $identity, 'locations' => [], 'line' => $line];
            if (array_filter($location, fn ($value) => $value !== '') && !in_array($location, $byId[$id]['locations'], true)) $byId[$id]['locations'][] = $location;
        }
        // Historical exports omit parent rows. Recover known parents, or build an
        // explicitly incomplete new group, never erase a known parent silently.
        foreach ($byId as $entry) if ($entry['identity']['kind'] === 'variante') {
            $identity = $entry['identity']; $parentId = $identity['groupId'];
            if (!isset($byId[$parentId])) {
                if ($versioned) self::fail("Regroupement « $parentId » absent du fichier.");
                $parent = $old[$parentId]['training'] ?? Training::blank($parentId, $identity['groupTitle'], $identity['training']['sectorId'], $identity['training']['sourceUrl']);
                $parent['title'] = $identity['groupTitle'];
                $locations = $parent['locations']; unset($parent['locations']);
                $byId[$parentId] = ['identity' => ['training' => $parent, 'groupId' => $parentId, 'groupTitle' => $parent['title'], 'kind' => 'regroupement'], 'locations' => $locations, 'line' => $entry['line']];
            }
        }
        foreach ($byId as $id => $entry) if ($entry['identity']['kind'] === 'variante') {
            $parent = $byId[$entry['identity']['groupId']] ?? null;
            if (!$parent || $parent['identity']['kind'] !== 'regroupement' || $parent['identity']['training']['sectorId'] !== $entry['identity']['training']['sectorId'] || $parent['identity']['training']['title'] !== $entry['identity']['groupTitle']) self::fail("Variante « $id » : regroupement incohérent.");
        }
        $next = $current;
        $next['trainings'] = [];
        $children = [];
        foreach ($byId as $entry) if ($entry['identity']['kind'] === 'variante') $children[$entry['identity']['groupId']][] = [...$entry['identity']['training'], 'locations' => $entry['locations']];
        foreach ($byId as $id => $entry) {
            if ($entry['identity']['kind'] === 'variante') continue;
            $training = [...$entry['identity']['training'], 'locations' => $entry['locations']];
            if ($entry['identity']['kind'] === 'regroupement') {
                $training['variants'] = $children[$id] ?? [];
                if ($mode === 'merge') foreach ($current['trainings'] as $oldGroup) if ($oldGroup['id'] === (string) $id) foreach ($oldGroup['variants'] ?? [] as $oldVariant) if (!isset($byId[$oldVariant['id']])) $training['variants'][] = $oldVariant;
            }
            $next['trainings'][] = $training;
        }
        if ($mode === 'merge') {
            // A flat row cannot silently discard the variants of an existing group.
            foreach ($current['trainings'] as $oldGroup) {
                if (!isset($byId[$oldGroup['id']])) $next['trainings'][] = $oldGroup;
                elseif (isset($oldGroup['variants']) && $byId[$oldGroup['id']]['identity']['kind'] !== 'regroupement') self::fail('Le mode complément ne peut pas transformer un regroupement en formation simple.');
            }
            $positions = array_flip(array_column($current['trainings'], 'id'));
            usort($next['trainings'], fn ($a, $b) => ($positions[$a['id']] ?? PHP_INT_MAX) <=> ($positions[$b['id']] ?? PHP_INT_MAX));
        }
        if (!$trustedSnapshot) Training::invalidateStructuralChanges($current, $next);
        if ($minimal) {
            foreach ($next['trainings'] as &$training) {
                $items = [&$training];
                if (isset($training['variants'])) foreach ($training['variants'] as &$variant) $items[] = &$variant;
                unset($variant);
                foreach ($items as &$item) {
                    if (isset($item['critical']) && trim($item['critical']['source']) === '') $item['critical']['source'] = 'import-csv';
                    if (($item['contentStatus'] ?? '') === 'ready' && (!trim($item['description']) || !$item['foremUrl'] || !$item['locations'] || !$item['verifiedAt'])) {
                        $item['contentStatus'] = 'partial';
                        $warnings[] = $item['title'] . ' : fiche incomplète conservée sans statut de validation.';
                    }
                }
                unset($item, $items);
            }
            unset($training);
        }
        $errors = Validator::errors($next, editorial: !$minimal);
        if ($errors) self::fail('Le fichier contient des données invalides. Le catalogue actif est conservé.', $errors);
        $diff = Training::changes($current, $next);
        $newCount = count($diff['added']);
        if ($newCount) $warnings[] = "$newCount nouvelle(s) fiche(s) : recommandations d’orientation désactivées jusqu’à calibration.";
        $recalibration = count(array_filter($diff['updated'], fn ($entry) => array_filter($entry['changes'], fn ($change) => $change['field'] === 'orientationStatus' && $change['after'] === 'pending')));
        if ($recalibration) $warnings[] = "$recalibration fiche(s) existante(s) changent de structure ou de secteur : leur correspondance d’orientation est à recalibrer.";
        return ['catalog' => $next, 'diff' => $diff, 'warnings' => $warnings, 'recordCount' => count($records)];
    }
}
