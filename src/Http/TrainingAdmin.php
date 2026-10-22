<?php
declare(strict_types=1);

namespace Aura\Api\Http;

use Aura\Api\Config;
use Aura\Api\Database;
use Aura\Api\Repository\TrainingCatalogRepository;
use Aura\Api\Security\AdminSession;

final class TrainingAdmin
{
    public static function body(): array
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') throw new ApiException(415, 'json_required', 'Le formulaire doit être envoyé en JSON.');
        $raw = file_get_contents('php://input', length: 2097153);
        if ($raw === false || strlen($raw) > 2097152) throw new ApiException(413, 'payload_too_large', 'La fiche est trop volumineuse.');
        try { $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiException(400, 'invalid_json', 'Le JSON est invalide.'); }
        if (!is_array($body) || array_is_list($body)) throw new ApiException(400, 'invalid_json', 'Un objet JSON est attendu.');
        return $body;
    }

    public static function handle(string $path, string $method, string $origin): never
    {
        header('Cache-Control: no-store');
        if ($origin !== '' && !in_array($origin, Config::list('CORS_ALLOWED_ORIGINS'), true)) throw new ApiException(403, 'origin_denied', 'Cette origine n’est pas autorisée.');
        AdminSession::start();
        // Session bootstrap remains available when the database is down.
        if ($path === '/admin/session' && $method === 'GET') {
            $user = isset($_SESSION['user_id']) ? AdminSession::user(Database::connection()) : null;
            JsonResponse::send(['user' => $user, 'csrfToken' => $_SESSION['csrf']]);
        }
        if ($path === '/admin/login' && $method === 'POST') {
            AdminSession::csrf();
            $user = AdminSession::login(Database::connection(), self::body());
            JsonResponse::send(['user' => $user, 'csrfToken' => $_SESSION['csrf']]);
        }
        if ($path === '/admin/logout' && $method === 'POST') {
            AdminSession::csrf();
            AdminSession::logout();
            JsonResponse::send(['user' => null, 'csrfToken' => $_SESSION['csrf']]);
        }
        if (!isset($_SESSION['user_id'])) throw new ApiException(401, 'authentication_required', 'Connectez-vous pour accéder à la gestion du catalogue.');
        $db = Database::connection();
        $user = AdminSession::user($db);
        if (!$user) throw new ApiException(401, 'authentication_required', 'Votre session a expiré. Reconnectez-vous.');
        $repository = new TrainingCatalogRepository($db);
        if ($path === '/admin/catalog' && $method === 'GET') JsonResponse::send($repository->current());
        if ($path === '/admin/catalog/export' && $method === 'GET') JsonResponse::send($repository->exportCsv());
        if ($path === '/admin/trainings' && $method === 'POST') {
            AdminSession::csrf();
            JsonResponse::send($repository->create(self::body(), $user['email']), 201);
        }
        if ($path === '/admin/catalog/import/preview' && $method === 'POST') {
            AdminSession::csrf();
            JsonResponse::send($repository->importPreview(self::body()));
        }
        if ($path === '/admin/catalog/import/commit' && $method === 'POST') {
            AdminSession::csrf();
            JsonResponse::send($repository->importCommit(self::body(), $user['email']));
        }
        if (preg_match('#^/admin/trainings/([a-z0-9-]{1,100})/delete-preview$#', $path, $matches) === 1 && $method === 'POST') {
            AdminSession::csrf();
            JsonResponse::send($repository->deletionPreview($matches[1], self::body()));
        }
        if (preg_match('#^/admin/trainings/([a-z0-9-]{1,100})$#', $path, $matches) === 1 && $method === 'DELETE') {
            AdminSession::csrf();
            JsonResponse::send($repository->remove($matches[1], self::body(), $user['email']));
        }
        if (preg_match('#^/admin/trainings/([a-z0-9-]{1,100})$#', $path, $matches) === 1 && $method === 'PATCH') {
            AdminSession::csrf();
            JsonResponse::send($repository->save($matches[1], self::body(), $user['email']));
        }
        throw new ApiException(404, 'route_not_found', 'Cette route de gestion n’existe pas.');
    }
}
