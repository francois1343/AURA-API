<?php

declare(strict_types=1);

use Aura\Api\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? '';
$database = Database::connection();

if ($command === 'migrate') {
    $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/001_create_catalog.sql');

    if ($migration === false) {
        throw new RuntimeException('Migration introuvable.');
    }

    $database->exec($migration);
    fwrite(STDOUT, "Migration du catalogue terminée.\n");
    exit(0);
}

if ($command === 'seed') {
    $seedPath = dirname(__DIR__) . '/database/seeds/catalog.json';
    $seed = json_decode((string) file_get_contents($seedPath), true, flags: JSON_THROW_ON_ERROR);

    $database->beginTransaction();

    try {
        $sectorStatement = $database->prepare(
            'INSERT INTO sectors (id, label, icon, description, color, text_color, sort_order)
             VALUES (:id, :label, :icon, :description, :color, :text_color, :sort_order)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label), icon = VALUES(icon), description = VALUES(description),
                color = VALUES(color), text_color = VALUES(text_color), sort_order = VALUES(sort_order)',
        );

        foreach ($seed['sectors'] as $index => $sector) {
            $sectorStatement->execute([
                ':id' => $sector['id'],
                ':label' => $sector['label'],
                ':icon' => $sector['icon'],
                ':description' => $sector['description'],
                ':color' => $sector['color'],
                ':text_color' => $sector['textColor'],
                ':sort_order' => $index,
            ]);
        }

        $jobStatement = $database->prepare(
            'INSERT INTO jobs (
                id, sector_id, title, search_query, badge, summary, description,
                tasks, environment, schedule, access, qualities, photo_url,
                photo_alt, forem_url, verified_at
             ) VALUES (
                :id, :sector_id, :title, :search_query, :badge, :summary, :description,
                :tasks, :environment, :schedule, :access, :qualities, :photo_url,
                :photo_alt, :forem_url, :verified_at
             ) ON DUPLICATE KEY UPDATE
                sector_id = VALUES(sector_id), title = VALUES(title),
                search_query = VALUES(search_query), badge = VALUES(badge),
                summary = VALUES(summary), description = VALUES(description),
                tasks = VALUES(tasks), environment = VALUES(environment),
                schedule = VALUES(schedule), access = VALUES(access),
                qualities = VALUES(qualities), photo_url = VALUES(photo_url),
                photo_alt = VALUES(photo_alt), forem_url = VALUES(forem_url),
                verified_at = VALUES(verified_at)',
        );

        foreach ($seed['jobs'] as $job) {
            $jobStatement->execute([
                ':id' => $job['id'],
                ':sector_id' => $job['sectorId'],
                ':title' => $job['title'],
                ':search_query' => $job['searchQuery'],
                ':badge' => $job['badge'] ?: null,
                ':summary' => $job['summary'],
                ':description' => $job['description'],
                ':tasks' => json_encode($job['tasks'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':environment' => $job['environment'],
                ':schedule' => $job['schedule'],
                ':access' => $job['access'],
                ':qualities' => json_encode($job['qualities'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':photo_url' => $job['photo'],
                ':photo_alt' => $job['photoAlt'],
                ':forem_url' => $job['foremUrl'],
                ':verified_at' => $job['verifiedAt'] ?? null,
            ]);
        }

        $database->commit();
        fwrite(STDOUT, sprintf(
            "Données initiales importées : %d secteurs et %d métiers.\n",
            count($seed['sectors']),
            count($seed['jobs']),
        ));
    } catch (Throwable $exception) {
        $database->rollBack();
        throw $exception;
    }

    exit(0);
}

fwrite(STDERR, "Usage : php scripts/database.php migrate|seed\n");
exit(1);

