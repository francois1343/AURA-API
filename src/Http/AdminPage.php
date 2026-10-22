<?php
declare(strict_types=1);

namespace Aura\Api\Http;

use Aura\Api\Config;
use Aura\Api\Repository\FileCatalogRepository;
use Aura\Api\Security\CsvAdminSession;

/** Server-rendered administration: ordinary HTML forms, no browser API client. */
final class AdminPage
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function date(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('d/m/Y à H:i');
    }

    private static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    private static function render(string $page, array $data = [], int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $csrf = $_SESSION['csrf'] ?? '';
        require dirname(__DIR__) . '/Views/admin.php';
        exit;
    }

    private static function form(string $kind = 'application/x-www-form-urlencoded'): void
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($type !== $kind) throw new ApiException(415, 'form_required', 'Utilisez le formulaire de cette page.');
        // Do not trust forwarded headers: HTTPS termination is configured explicitly.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $scheme = Config::bool('SESSION_COOKIE_SECURE', false) ? 'https' : 'http';
        $expected = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        if (($origin !== '' && $origin !== $expected) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') throw new ApiException(403, 'origin_denied', 'Ce formulaire doit être envoyé depuis cette administration.');
        CsvAdminSession::csrf();
    }

    private static function revision(): int
    {
        $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($revision === false || $revision < 1) throw new ApiException(422, 'invalid_revision', 'Rechargez le dashboard avant de réessayer.');
        return $revision;
    }

    private static function flash(string $message, array $warnings = []): void
    {
        $_SESSION['flash'] = ['message' => $message, 'warnings' => $warnings];
    }

    public static function handle(string $path, string $method): never
    {
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        if ($path === '/admin/assets/style.css' && $method === 'GET') {
            header('Content-Type: text/css; charset=utf-8');
            readfile(dirname(__DIR__) . '/Views/admin.css');
            exit;
        }
        $user = null;
        try {
            if (!in_array($method, ['GET', 'POST'], true)) throw new ApiException(405, 'method_not_allowed', 'Utilisez les liens et formulaires de cette administration.');
            CsvAdminSession::start();
            $user = CsvAdminSession::user();
            $flash = $_SESSION['flash'] ?? [];
            unset($_SESSION['flash']);

            if ($path === '/admin/' && $method === 'GET') self::redirect('/admin');
            if ($path === '/admin/login') {
                if ($method === 'GET') {
                    if ($user) self::redirect('/admin');
                    self::render('login', ['flash' => $flash]);
                }
                self::form();
                CsvAdminSession::login(['email' => $_POST['email'] ?? null, 'password' => $_POST['password'] ?? null]);
                self::redirect('/admin');
            }

            if (!in_array($path, ['/admin', '/admin/export', '/admin/import', '/admin/restore', '/admin/logout'], true)) throw new ApiException(404, 'route_not_found', 'Cette page d’administration n’existe pas.');
            if (!$user) {
                self::flash('Connectez-vous pour gérer le catalogue. Si votre session a expiré, renvoyez ensuite votre formulaire.');
                self::redirect('/admin/login');
            }
            $repository = new FileCatalogRepository();
            if ($path === '/admin' && $method === 'GET') self::render('dashboard', ['user' => $user, 'catalog' => $repository->status(), 'flash' => $flash]);
            if ($path === '/admin/export' && $method === 'GET') {
                $file = $repository->export();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
                echo $file['csv'];
                exit;
            }
            if ($path === '/admin/restore' && $method === 'GET') {
                $catalog = $repository->status();
                if (!$catalog['previous']) throw new ApiException(409, 'no_previous_catalog', 'Aucune version précédente n’est encore disponible.');
                self::render('restore', ['user' => $user, 'catalog' => $catalog]);
            }
            if ($method !== 'POST' || $path === '/admin' || $path === '/admin/export') throw new ApiException(405, 'method_not_allowed', 'Cette action nécessite son formulaire de confirmation.');
            self::form($path === '/admin/import' ? 'multipart/form-data' : 'application/x-www-form-urlencoded');
            if ($path === '/admin/logout') {
                CsvAdminSession::logout();
                self::flash('Vous êtes déconnecté.');
                self::redirect('/admin/login');
            }
            if ($path === '/admin/restore') {
                $repository->restore(['revision' => self::revision(), 'confirmation' => $_POST['confirmation'] ?? ''], $user['email']);
                self::flash('La version précédente est maintenant utilisée par AURA. Le catalogue remplacé reste disponible pour annuler ce retour.');
                self::redirect('/admin');
            }
            $file = $_FILES['catalogue'] ?? null;
            if (!$file || is_array($file['error'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new ApiException(422, 'invalid_upload', 'Choisissez un CSV non vide de 1 Mo maximum. Le fichier n’a pas été reçu correctement.');
            if (($_POST['confirmation'] ?? '') !== 'REMPLACER') throw new ApiException(422, 'confirmation_required', 'Confirmez le remplacement du catalogue complet.');
            $saved = $repository->import($file['tmp_name'], $file['name'], self::revision(), $user['email']);
            self::flash($saved['unchanged'] ? 'Ce fichier correspond déjà au catalogue actif. La version précédente est inchangée.' : 'Catalogue remplacé. La version précédente a été conservée.', $saved['warnings']);
            self::redirect('/admin');
        } catch (ApiException $error) {
            $failure = ['message' => $error->getMessage(), 'code' => $error->errorCode, 'details' => $error->details];
            if ($path === '/admin/login') self::render('login', ['error' => $failure, 'email' => is_string($_POST['email'] ?? null) ? mb_substr($_POST['email'], 0, 190) : ''], $error->status);
            if ($user && in_array($path, ['/admin/import', '/admin/restore'], true)) {
                try { $catalog = (new FileCatalogRepository())->status(); }
                catch (\Throwable) { self::render('error', ['error' => $failure, 'user' => $user], $error->status); }
                self::render('dashboard', ['error' => $failure, 'user' => $user, 'catalog' => $catalog], $error->status);
            }
            self::render('error', ['error' => $failure, 'user' => $user], $error->status);
        } catch (\Throwable $error) {
            error_log('AURA administration : ' . $error->getMessage());
            self::render('error', ['user' => $user, 'error' => ['message' => 'Le service est momentanément indisponible. Aucun remplacement incomplet n’a été confirmé.', 'code' => 'internal_error', 'details' => []]], 500);
        }
    }
}
