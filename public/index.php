<?php

declare(strict_types=1);

use Aura\Api\Config;
use Aura\Api\Database;
use Aura\Api\Http\JsonResponse;
use Aura\Api\Repository\JobRepository;
use Aura\Api\Repository\SectorRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = Config::list('CORS_ALLOWED_ORIGINS');

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    JsonResponse::error('method_not_allowed', 'Cette méthode HTTP n’est pas autorisée.', 405);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/api/v1#', '', $path) ?: '/';

try {
    if ($path === '/health') {
        Database::connection()->query('SELECT 1');
        JsonResponse::send([
            'data' => [
                'status' => 'ok',
                'service' => 'AURA API',
                'database' => 'connected',
                'time' => gmdate(DATE_ATOM),
            ],
        ]);
    }

    if ($path === '/sectors') {
        $items = (new SectorRepository(Database::connection()))->all();
        JsonResponse::send([
            'data' => $items,
            'meta' => ['total' => count($items)],
        ]);
    }

    if ($path === '/jobs') {
        $query = trim((string) ($_GET['q'] ?? ''));
        $sectorId = trim((string) ($_GET['sector'] ?? ''));
        $defaultLimit = Config::int('API_DEFAULT_LIMIT', 20);
        $maxLimit = Config::int('API_MAX_LIMIT', 50);
        $limit = filter_var($_GET['limit'] ?? $defaultLimit, FILTER_VALIDATE_INT);
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > $maxLimit) {
            JsonResponse::error('invalid_limit', sprintf('limit doit être compris entre 1 et %d.', $maxLimit), 400);
        }

        if ($offset === false || $offset < 0) {
            JsonResponse::error('invalid_offset', 'offset doit être supérieur ou égal à 0.', 400);
        }

        $result = (new JobRepository(Database::connection()))->search(
            $query === '' ? null : $query,
            $sectorId === '' ? null : $sectorId,
            $limit,
            $offset,
        );

        JsonResponse::send([
            'data' => $result['items'],
            'meta' => [
                'total' => $result['total'],
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    if (preg_match('#^/jobs/([a-z0-9-]+)$#', $path, $matches) === 1) {
        $job = (new JobRepository(Database::connection()))->find($matches[1]);

        if ($job === null) {
            JsonResponse::error('job_not_found', 'Ce métier n’existe pas.', 404);
        }

        JsonResponse::send(['data' => $job]);
    }

    JsonResponse::error('route_not_found', 'Cette route API n’existe pas.', 404);
} catch (Throwable $exception) {
    $payload = [
        'error' => [
            'code' => 'internal_error',
            'message' => 'Une erreur interne empêche de traiter la requête.',
        ],
    ];

    if (Config::bool('APP_DEBUG')) {
        $payload['error']['debug'] = $exception->getMessage();
    }

    JsonResponse::send($payload, 500);
}

