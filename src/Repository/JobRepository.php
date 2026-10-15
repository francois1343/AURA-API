<?php

declare(strict_types=1);

namespace Aura\Api\Repository;

use PDO;

final class JobRepository
{
    private const SELECT_FIELDS = <<<'SQL'
        SELECT
            jobs.id,
            jobs.sector_id AS sectorId,
            sectors.label AS sectorLabel,
            sectors.color AS sectorColor,
            sectors.text_color AS sectorTextColor,
            jobs.title,
            jobs.search_query AS searchQuery,
            jobs.badge,
            jobs.summary,
            jobs.description,
            jobs.tasks,
            jobs.environment,
            jobs.schedule,
            jobs.access,
            jobs.qualities,
            jobs.photo_url AS photo,
            jobs.photo_alt AS photoAlt,
            jobs.forem_url AS foremUrl,
            jobs.verified_at AS verifiedAt
        FROM jobs
        INNER JOIN sectors ON sectors.id = jobs.sector_id
        SQL;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(?string $query, ?string $sectorId, int $limit, int $offset): array
    {
        [$where, $parameters] = $this->buildFilters($query, $sectorId);
        $countStatement = $this->database->prepare('SELECT COUNT(*) FROM jobs' . $where);
        $countStatement->execute($parameters);

        $statement = $this->database->prepare(
            self::SELECT_FIELDS . $where . ' ORDER BY jobs.title LIMIT :limit OFFSET :offset',
        );

        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map([$this, 'normalize'], $statement->fetchAll()),
            'total' => (int) $countStatement->fetchColumn(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->database->prepare(self::SELECT_FIELDS . ' WHERE jobs.id = :id');
        $statement->execute([':id' => $id]);
        $job = $statement->fetch();

        return $job === false ? null : $this->normalize($job);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function buildFilters(?string $query, ?string $sectorId): array
    {
        $conditions = [];
        $parameters = [];

        if ($query !== null && $query !== '') {
            $conditions[] = '(jobs.title LIKE :query OR jobs.search_query LIKE :query OR jobs.summary LIKE :query)';
            $parameters[':query'] = '%' . $query . '%';
        }

        if ($sectorId !== null && $sectorId !== '') {
            $conditions[] = 'jobs.sector_id = :sector';
            $parameters[':sector'] = $sectorId;
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $parameters,
        ];
    }

    /** @param array<string, mixed> $job
     *  @return array<string, mixed>
     */
    private function normalize(array $job): array
    {
        $job['tasks'] = json_decode((string) $job['tasks'], true, flags: JSON_THROW_ON_ERROR);
        $job['qualities'] = json_decode((string) $job['qualities'], true, flags: JSON_THROW_ON_ERROR);
        $job['badge'] = $job['badge'] ?: null;
        $job['verifiedAt'] = $job['verifiedAt'] ?: null;

        return $job;
    }
}

