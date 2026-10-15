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

if (count($seed['jobs'] ?? []) !== 28) {
    $errors[] = 'Le catalogue doit contenir exactement 28 métiers.';
}

$sectorIds = array_column($seed['sectors'], 'id');

foreach ($seed['jobs'] as $job) {
    if (!in_array($job['sectorId'] ?? null, $sectorIds, true)) {
        $errors[] = sprintf('Secteur inconnu pour le métier %s.', $job['id'] ?? '?');
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "Validation réussie : syntaxe PHP valide, 28 secteurs et 28 métiers.\n");

