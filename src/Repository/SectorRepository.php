<?php

declare(strict_types=1);

namespace Aura\Api\Repository;

use PDO;

final class SectorRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $statement = $this->database->query(
            'SELECT id, label, icon, description, color, text_color AS textColor
             FROM sectors
             ORDER BY sort_order, label',
        );

        return $statement->fetchAll();
    }
}

