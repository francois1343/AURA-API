<?php
declare(strict_types=1);

namespace Aura\Api\Catalog;

use Aura\Api\Http\ApiException;

final class Validator
{
    public const EDITABLE = ['title', 'sectorId', 'occupationDescription', 'description', 'topics', 'prerequisites', 'duration', 'schedule', 'extraInfo', 'locations', 'foremUrl', 'videoUrl', 'photo', 'photoAlt', 'photoCredit', 'sourceUrl', 'contentStatus', 'verifiedAt'];

    public static function url(mixed $value, string $kind = 'https'): bool
    {
        if (!is_string($value)) return false;
        if ($value === '') return true;
        $url = parse_url($value);
        if (!filter_var($value, FILTER_VALIDATE_URL) || !$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['port'])) return false;
        $host = strtolower($url['host']);
        if ($kind === 'forem') return $host === 'leforem.be' || str_ends_with($host, '.leforem.be');
        if ($kind === 'youtube') {
            $path = $url['path'] ?? '/';
            parse_str($url['query'] ?? '', $query);
            if ($host === 'youtu.be') return preg_match('#^/[\w-]{11}/?$#', $path) === 1;
            return in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true) &&
                (($path === '/watch' && is_string($query['v'] ?? null) && preg_match('/^[\w-]{11}$/', $query['v']) === 1) || preg_match('#^/shorts/[\w-]{11}/?$#', $path) === 1);
        }
        return true;
    }

    public static function date(mixed $value): bool
    {
        if ($value === null) return true;
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) return false;
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public static function errors(array $catalog, bool $editorial = true): array
    {
        $errors = [];
        if (($catalog['kind'] ?? null) !== 'forem-training-catalog' || ($catalog['schemaVersion'] ?? null) !== 2) return ['Format de catalogue incompatible.'];
        if (!is_array($catalog['sectors'] ?? null) || !array_is_list($catalog['sectors']) || !$catalog['sectors'] || !is_array($catalog['trainings'] ?? null) || !array_is_list($catalog['trainings']) || !$catalog['trainings']) return ['Le catalogue et les secteurs ne peuvent pas être vides.'];
        $sectors = [];
        foreach ($catalog['sectors'] as $sector) {
            if (!is_array($sector)) { $errors[] = 'Secteur invalide.'; continue; }
            $id = $sector['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-z0-9-]{1,100}$/', $id) || isset($sectors[$id]) || !is_string($sector['label'] ?? null) || !trim($sector['label']) || !is_string($sector['icon'] ?? null) || !is_string($sector['color'] ?? null) || !preg_match('/^#[a-f0-9]{6}$/i', $sector['color'])) $errors[] = 'Secteur invalide ou dupliqué.';
            if (is_string($id)) $sectors[$id] = true;
        }
        $ids = [];
        $photos = [];
        $check = function (mixed $item, ?string $parentSector = null) use (&$check, &$errors, &$ids, &$photos, $sectors, $editorial): void {
            if (!is_array($item) || array_is_list($item)) { $errors[] = 'Fiche invalide.'; return; }
            $id = $item['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-z0-9-]{1,100}$/', $id) || isset($ids[$id])) { $errors[] = 'Identifiant invalide ou dupliqué.'; return; }
            $ids[$id] = true;
            $sector = $item['sectorId'] ?? null;
            if (!is_string($sector) || !isset($sectors[$sector]) || ($parentSector !== null && $sector !== $parentSector)) $errors[] = "$id : secteur invalide.";
            foreach (['title', 'description', 'extraInfo', 'duration', 'schedule', 'foremUrl', 'photo', 'photoAlt', 'photoCredit', 'sourceUrl'] as $field) {
                if (!is_string($item[$field] ?? null) || mb_strlen($item[$field]) > 30000) $errors[] = "$id : champ $field invalide ou trop long.";
            }
            foreach (['occupationDescription', 'videoUrl'] as $field) if (isset($item[$field]) && (!is_string($item[$field]) || mb_strlen($item[$field]) > 30000)) $errors[] = "$id : champ $field invalide.";
            if (!is_string($item['title'] ?? null) || !trim($item['title']) || mb_strlen($item['title']) > 200) $errors[] = "$id : titre requis (200 caractères maximum).";
            foreach (['topics', 'prerequisites'] as $field) {
                $lines = $item[$field] ?? ($field === 'prerequisites' ? [] : null);
                if (!is_array($lines) || !array_is_list($lines) || count($lines) > 100 || array_filter($lines, fn ($line) => !is_string($line) || mb_strlen($line) > 4000)) $errors[] = "$id : liste $field invalide.";
            }
            $locations = $item['locations'] ?? null;
            if (!is_array($locations) || !array_is_list($locations) || count($locations) > 50) $errors[] = "$id : lieux invalides.";
            else foreach ($locations as $location) {
                if (!is_array($location) || array_diff(array_keys($location), ['city', 'address', 'accessUrl'])) { $errors[] = "$id : lieu invalide."; continue; }
                foreach (['city', 'address', 'accessUrl'] as $key) if (!is_string($location[$key] ?? null) || mb_strlen($location[$key]) > 2000) $errors[] = "$id : $key invalide.";
                if (!self::url($location['accessUrl'] ?? null)) $errors[] = "$id : lien d’accès invalide.";
            }
            foreach (['foremUrl' => 'forem', 'photo' => 'https', 'sourceUrl' => 'https', 'videoUrl' => 'youtube'] as $field => $kind) {
                if (!self::url($item[$field] ?? ($field === 'videoUrl' ? '' : null), $kind) || ($editorial && $field === 'sourceUrl' && empty($item[$field]))) $errors[] = "$id : $field doit être une URL HTTPS autorisée.";
            }
            if (!array_key_exists('verifiedAt', $item) || !self::date($item['verifiedAt'])) $errors[] = "$id : date de validation invalide.";
            if (!in_array($item['contentStatus'] ?? '', ['pending', 'partial', 'ready'], true)) $errors[] = "$id : état invalide.";
            if (isset($item['orientationStatus']) && $item['orientationStatus'] !== 'pending') $errors[] = "$id : statut d’orientation invalide.";
            if (!is_bool($item['shortage']['indicated'] ?? null)) $errors[] = "$id : indication de pénurie invalide.";
            if (!is_string($item['shortage']['source'] ?? null) || !trim($item['shortage']['source']) || !array_key_exists('verifiedAt', $item['shortage']) || !self::date($item['shortage']['verifiedAt'])) $errors[] = "$id : source ou date de pénurie invalide.";
            if (isset($item['critical']) && (!is_bool($item['critical']['indicated'] ?? null) || !is_string($item['critical']['source'] ?? null) || !trim($item['critical']['source']) || !array_key_exists('verifiedAt', $item['critical']) || !self::date($item['critical']['verifiedAt']))) $errors[] = "$id : fonction critique invalide.";
            if ($editorial && ($item['contentStatus'] ?? '') === 'ready') {
                if (!is_string($item['description'] ?? null) || !trim($item['description']) || empty($item['foremUrl']) || !$locations || empty($item['verifiedAt'])) $errors[] = "$id : une fiche validée exige description, lien Forem, lieu et date de validation.";
                if (is_array($locations)) foreach ($locations as $location) if (!is_array($location) || !is_string($location['city'] ?? null) || !trim($location['city']) || !is_string($location['address'] ?? null) || !trim($location['address'])) $errors[] = "$id : renseignez la ville et l’adresse de chaque lieu avant validation.";
            }
            if (!empty($item['photo']) && self::url($item['photo'])) {
                if ($editorial && (!is_string($item['photoAlt'] ?? null) || !trim($item['photoAlt']) || !is_string($item['photoCredit'] ?? null) || !trim($item['photoCredit']))) $errors[] = "$id : renseignez la description et le crédit de l’illustration.";
                $url = parse_url($item['photo']);
                $identity = strtolower($url['host']) . ($url['path'] ?? '/') . (in_array(strtolower($url['host']), ['images.unsplash.com', 'plus.unsplash.com', 'images.pexels.com'], true) ? '' : '?' . ($url['query'] ?? ''));
                if (isset($photos[$identity])) $errors[] = "$id : photo déjà attribuée à {$photos[$identity]}.";
                $photos[$identity] = $id;
            }
            if (array_key_exists('variants', $item)) {
                if ($parentSector !== null || !is_array($item['variants']) || !array_is_list($item['variants']) || count($item['variants']) < 2) $errors[] = "$id : un regroupement exige au moins deux variantes, sans imbrication.";
                elseif (is_string($sector)) foreach ($item['variants'] as $variant) $check($variant, $sector);
            }
        };
        foreach ($catalog['trainings'] as $training) $check($training);
        return array_values(array_unique($errors));
    }

    public static function assert(array $catalog): void
    {
        $errors = self::errors($catalog);
        if ($errors) throw new ApiException(422, 'invalid_catalog', 'La fiche n’a pas été enregistrée. Corrigez les champs signalés.', $errors);
    }
}
