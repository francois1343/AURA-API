<?php
declare(strict_types=1);

namespace Aura\Api\Security;

use Aura\Api\Config;
use Aura\Api\Http\ApiException;
use Aura\Api\Storage\PrivateFiles;

final class CsvAdminSession
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $files = new PrivateFiles();
        $sessions = $files->directory . '/sessions';
        if (is_link($sessions) || (!is_dir($sessions) && !mkdir($sessions, 0700) && !is_dir($sessions))) throw new \RuntimeException('Stockage des sessions indisponible.');
        session_save_path($sessions);
        $secure = Config::bool('SESSION_COOKIE_SECURE', false);
        if (Config::string('APP_ENV', 'local') === 'production' && !$secure) throw new ApiException(503, 'secure_cookie_required', 'HTTPS doit être configuré pour la connexion en production.');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('AURA_CSV_ADMIN');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/api/v1/admin', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start()) throw new \RuntimeException('Impossible de démarrer la session.');
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function user(): ?array
    {
        if (!isset($_SESSION['email'])) return null;
        $user = (new FileAccounts())->user($_SESSION['email']);
        if (!$user || !$user['active'] || !hash_equals($user['version'], $_SESSION['account_version'] ?? '') || time() - ($_SESSION['last_seen'] ?? 0) > 1800 || time() - ($_SESSION['started_at'] ?? 0) > 28800) { self::logout(); return null; }
        $_SESSION['last_seen'] = time();
        return ['email' => $user['email']];
    }

    public static function csrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) throw new ApiException(403, 'invalid_csrf', 'Le formulaire a expiré. Rechargez la page avant de réessayer.');
    }

    public static function login(array $request): array
    {
        $user = (new FileAccounts())->authenticate($request, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        session_regenerate_id(true);
        $_SESSION = ['email' => $user['email'], 'account_version' => $user['version'], 'last_seen' => time(), 'started_at' => time(), 'csrf' => bin2hex(random_bytes(32))];
        return ['email' => $user['email']];
    }

    public static function logout(): void
    {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        session_regenerate_id(true);
    }
}
