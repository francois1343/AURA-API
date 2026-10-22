<?php
declare(strict_types=1);

namespace Aura\Api\Http;

use Aura\Api\Config;
use Aura\Api\Repository\FileCatalogRepository;
use Aura\Api\Security\CsvAdminSession;

final class TrainingAdmin
{
    public static function body(): array
    {
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') throw new ApiException(415, 'json_required', 'Un formulaire JSON est attendu.');
        $raw = file_get_contents('php://input', length: 8193);
        if ($raw === false || strlen($raw) > 8192) throw new ApiException(413, 'payload_too_large', 'Le formulaire est trop volumineux.');
        try { $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiException(400, 'invalid_json', 'Le formulaire est invalide.'); }
        if (!is_array($body) || array_is_list($body)) throw new ApiException(400, 'invalid_json', 'Un objet JSON est attendu.');
        return $body;
    }

    public static function handle(string $path, string $method, string $origin): never
    {
        if ($origin !== '' && !in_array($origin, Config::list('CORS_ALLOWED_ORIGINS'), true)) throw new ApiException(403, 'origin_denied', 'Cette origine n’est pas autorisée.');
        CsvAdminSession::start();
        if ($path === '/admin/session' && $method === 'GET') JsonResponse::send(['user' => CsvAdminSession::user(), 'csrfToken' => $_SESSION['csrf']]);
        if ($path === '/admin/login' && $method === 'POST') {
            CsvAdminSession::csrf();
            JsonResponse::send(['user' => CsvAdminSession::login(self::body()), 'csrfToken' => $_SESSION['csrf']]);
        }
        if ($path === '/admin/logout' && $method === 'POST') {
            CsvAdminSession::csrf(); CsvAdminSession::logout();
            JsonResponse::send(['user' => null, 'csrfToken' => $_SESSION['csrf']]);
        }
        $user = CsvAdminSession::user();
        if (!$user) throw new ApiException(401, 'authentication_required', 'Connectez-vous pour gérer le catalogue CSV.');
        $repository = new FileCatalogRepository();
        if ($path === '/admin/catalog' && $method === 'GET') JsonResponse::send($repository->status());
        if ($path === '/admin/catalog/export' && $method === 'GET') JsonResponse::send($repository->export());
        if ($path === '/admin/catalog/import' && $method === 'POST') {
            CsvAdminSession::csrf();
            if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data;')) throw new ApiException(415, 'file_required', 'Un fichier CSV est attendu.');
            $file = $_FILES['catalogue'] ?? null;
            if (!$file || is_array($file['error'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new ApiException(422, 'invalid_upload', 'Le fichier n’a pas été reçu correctement. Choisissez un CSV de 1 Mo maximum.');
            if (($_POST['confirmation'] ?? '') !== 'REMPLACER') throw new ApiException(422, 'confirmation_required', 'Confirmez le remplacement du catalogue complet.');
            $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
            if ($revision === false || $revision < 1) throw new ApiException(422, 'invalid_revision', 'Rechargez le dashboard avant l’import.');
            JsonResponse::send($repository->import($file['tmp_name'], $file['name'], $revision, $user['email']));
        }
        if ($path === '/admin/catalog/restore' && $method === 'POST') {
            CsvAdminSession::csrf();
            JsonResponse::send($repository->restore(self::body(), $user['email']));
        }
        throw new ApiException(404, 'route_not_found', 'Cette route n’existe plus dans la gestion CSV simplifiée.');
    }
}
