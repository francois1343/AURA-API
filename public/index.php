<?php
declare(strict_types=1);

use Aura\Api\Config;
use Aura\Api\Http\ApiException;
use Aura\Api\Http\JsonResponse;
use Aura\Api\Http\AdminPage;
use Aura\Api\Repository\FileCatalogRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestPath === '/admin' || str_starts_with($requestPath, '/admin/')) AdminPage::handle($requestPath, $method);
if ($origin !== '' && in_array($origin, Config::list('CORS_ALLOWED_ORIGINS'), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
$path = $requestPath;
$path = preg_replace('#^/api/v1(?=/|$)#', '', $path) ?: '/';

try {
    if ($path === '/admin' || str_starts_with($path, '/admin/')) throw new ApiException(410, 'admin_api_retired', 'La gestion utilise maintenant les pages PHP à /admin.');
    if ($method !== 'GET') throw new ApiException(405, 'method_not_allowed', 'Cette méthode HTTP n’est pas autorisée.');
    if ($path === '/catalog') JsonResponse::send((new FileCatalogRepository())->current());
    if ($path === '/health') {
        $status = (new FileCatalogRepository())->status();
        JsonResponse::send(['data' => ['status' => 'ok', 'service' => 'AURA API', 'storage' => 'csv', 'revision' => $status['revision']]]);
    }
    if ($path === '/jobs' || $path === '/sectors' || str_starts_with($path, '/jobs/')) throw new ApiException(410, 'legacy_api_retired', 'L’ancien catalogue métiers est archivé. Utilisez /api/v1/catalog.');
    throw new ApiException(404, 'route_not_found', 'Cette route API n’existe pas.');
} catch (ApiException $error) {
    JsonResponse::send(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage(), 'details' => $error->details]], $error->status);
} catch (Throwable $error) {
    error_log('AURA API : ' . $error->getMessage());
    JsonResponse::send(['error' => ['code' => 'internal_error', 'message' => 'Le service est indisponible. Aucun catalogue incomplet n’a été publié.']], 500);
}

