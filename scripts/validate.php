<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
);
$errors = [];

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $command = sprintf('php -l %s 2>&1', escapeshellarg($file->getPathname()));
    exec($command, $output, $status);

    if ($status !== 0) {
        $errors[] = implode("\n", $output);
    }

    $output = [];
}

$seed = json_decode(
    (string) file_get_contents($root . '/database/seeds/catalog.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

if (count($seed['sectors'] ?? []) !== 28) {
    $errors[] = 'Le catalogue doit contenir exactement 28 secteurs.';
}

if (count($seed['jobs'] ?? []) !== 112) {
    $errors[] = 'Le catalogue doit contenir exactement 112 métiers.';
}

$sectorIds = array_column($seed['sectors'], 'id');
$jobIds = [];
$jobsPerSector = array_fill_keys($sectorIds, 0);

foreach ($seed['jobs'] as $job) {
    $jobId = $job['id'] ?? '?';
    $sectorId = $job['sectorId'] ?? null;

    if (!in_array($sectorId, $sectorIds, true)) {
        $errors[] = sprintf('Secteur inconnu pour le métier %s.', $job['id'] ?? '?');
    } else {
        $jobsPerSector[$sectorId]++;
    }

    if (in_array($jobId, $jobIds, true)) {
        $errors[] = sprintf('Identifiant métier dupliqué : %s.', $jobId);
    }
    $jobIds[] = $jobId;

    if (!str_starts_with((string) ($job['foremUrl'] ?? ''), 'https://www.leforem.be/infos-metiers/metiers/')) {
        $errors[] = sprintf('Lien Forem imprécis ou manquant pour %s.', $jobId);
    }

    if (($job['verifiedAt'] ?? null) === null) {
        $errors[] = sprintf('Date de vérification manquante pour %s.', $jobId);
    }

    if (!in_array($job['labourMarket']['status'] ?? null, ['standard', 'shortage', 'opportunity'], true)) {
        $errors[] = sprintf('Statut du marché du travail invalide pour %s.', $jobId);
    }

    if (count($job['tasks'] ?? []) < 3 || count($job['qualities'] ?? []) < 3) {
        $errors[] = sprintf('Contenu métier insuffisant pour %s.', $jobId);
    }
}

foreach ($jobsPerSector as $sectorId => $count) {
    if ($count !== 4) {
        $errors[] = sprintf('Le secteur %s doit contenir exactement 4 métiers, %d trouvé(s).', $sectorId, $count);
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "Validation réussie : syntaxe PHP valide, 28 secteurs et 112 métiers équilibrés.\n");

