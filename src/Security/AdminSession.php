<?php
declare(strict_types=1);

namespace Aura\Api\Security;

use Aura\Api\Config;
use Aura\Api\Http\ApiException;
use PDO;

final class AdminSession
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        // TLS termination must set this explicitly; never trust arbitrary forwarded headers.
        $secure = Config::bool('SESSION_COOKIE_SECURE', $secure);
        if (Config::string('APP_ENV', 'local') === 'production' && !$secure) throw new ApiException(503, 'secure_cookie_required', 'La session de production exige un accès HTTPS configuré.');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('AURA_ADMIN');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/api/v1/admin', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function user(PDO $db): ?array
    {
        if (!isset($_SESSION['user_id'])) return null;
        if (time() - ($_SESSION['last_seen'] ?? 0) > 1800 || time() - ($_SESSION['started_at'] ?? 0) > 28800) { self::logout(); return null; }
        $query = $db->prepare('SELECT id, email FROM catalog_admin_users WHERE id = ? AND active = 1');
        $query->execute([$_SESSION['user_id']]);
        $user = $query->fetch();
        if (!$user) { self::logout(); return null; }
        $_SESSION['last_seen'] = time();
        return ['id' => (int) $user['id'], 'email' => $user['email']];
    }

    public static function csrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) throw new ApiException(403, 'invalid_csrf', 'La session du formulaire a expiré. Reconnectez-vous avant de réessayer.');
    }

    public static function login(PDO $db, array $request): array
    {
        $email = $request['email'] ?? '';
        $password = $request['password'] ?? '';
        if (!is_string($email) || mb_strlen($email) > 190 || !is_string($password) || strlen($password) > 200) throw new ApiException(401, 'invalid_credentials', 'Adresse e-mail ou mot de passe incorrect.');
        // A shared server-side IP budget also covers unknown and varied email addresses.
        $fingerprint = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $now = time();
        $db->prepare('INSERT INTO catalog_login_attempts (fingerprint, attempts, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts = IF(expires_at < ?, 1, attempts + 1), expires_at = IF(expires_at < ?, ?, expires_at)')->execute([$fingerprint, $now + 600, $now, $now, $now + 600]);
        $budget = $db->prepare('SELECT attempts FROM catalog_login_attempts WHERE fingerprint = ?');
        $budget->execute([$fingerprint]);
        if ((int) $budget->fetchColumn() > 10) throw new ApiException(429, 'login_rate_limited', 'Trop de tentatives. Réessayez dans dix minutes.');
        $query = $db->prepare('SELECT id, email, password_hash, active FROM catalog_admin_users WHERE email = ?');
        $query->execute([strtolower(trim($email))]);
        $user = $query->fetch();
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$valid || !$user || !(bool) $user['active']) throw new ApiException(401, 'invalid_credentials', 'Adresse e-mail ou mot de passe incorrect.');
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) $db->prepare('UPDATE catalog_admin_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        $db->prepare('DELETE FROM catalog_login_attempts WHERE fingerprint = ?')->execute([$fingerprint]);
        session_regenerate_id(true);
        $_SESSION = ['user_id' => (int) $user['id'], 'started_at' => $now, 'last_seen' => $now, 'csrf' => bin2hex(random_bytes(32))];
        return ['id' => (int) $user['id'], 'email' => $user['email']];
    }

    public static function logout(): void
    {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        session_regenerate_id(true);
    }
}
