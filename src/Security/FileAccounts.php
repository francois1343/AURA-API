<?php
declare(strict_types=1);

namespace Aura\Api\Security;

use Aura\Api\Http\ApiException;
use Aura\Api\Storage\PrivateFiles;

final class FileAccounts
{
    public function __construct(private readonly PrivateFiles $files = new PrivateFiles()) {}

    public function user(string $email): ?array
    {
        return $this->files->locked('accounts', false, fn () => $this->files->json('accounts.json', [])[$email] ?? null);
    }

    public function create(string $email, string $password): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) < 12 || strlen($password) > 72) throw new \RuntimeException('E-mail valide et mot de passe de 12 à 72 octets requis.');
        $this->files->locked('accounts', true, function () use ($email, $password) {
            $users = $this->files->json('accounts.json', []);
            if (isset($users[$email])) throw new ApiException(409, 'account_exists', 'Ce compte existe déjà ; aucun mot de passe n’a été remplacé.');
            $users[$email] = ['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'active' => true, 'version' => bin2hex(random_bytes(16))];
            $this->files->writeJson('accounts.json', $users);
        });
    }

    /** One-time copy of hashes, not passwords. The old database is never changed. */
    public function import(array $rows): void
    {
        $this->files->locked('accounts', true, function () use ($rows) {
            if (is_file($this->files->path('accounts.json'))) throw new ApiException(409, 'accounts_exist', 'Les comptes fichiers existent déjà ; aucun remplacement.');
            $users = [];
            foreach ($rows as $row) {
                $email = strtolower($row['email']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !password_get_info($row['password_hash'])['algo']) throw new \RuntimeException('Compte source invalide.');
                $users[$email] = ['email' => $email, 'password_hash' => $row['password_hash'], 'active' => (bool) $row['active'], 'version' => bin2hex(random_bytes(16))];
            }
            $this->files->writeJson('accounts.json', $users);
        });
    }

    public function disable(string $email): void
    {
        $this->files->locked('accounts', true, function () use ($email) {
            $users = $this->files->json('accounts.json', []);
            $email = strtolower(trim($email));
            if (!isset($users[$email])) throw new \RuntimeException('Compte introuvable.');
            $users[$email]['active'] = false;
            $users[$email]['version'] = bin2hex(random_bytes(16));
            $this->files->writeJson('accounts.json', $users);
        });
    }

    public function authenticate(array $request, string $ip): array
    {
        $email = $request['email'] ?? '';
        $password = $request['password'] ?? '';
        if (!is_string($email) || strlen($email) > 190 || !is_string($password) || strlen($password) > 200) throw new ApiException(401, 'invalid_credentials', 'Adresse e-mail ou mot de passe incorrect.');
        $fingerprint = hash('sha256', $ip);
        $this->files->locked('login-attempts', true, function () use ($fingerprint) {
            $now = time();
            $attempts = array_filter($this->files->json('login-attempts.json', []), fn ($entry) => $entry['expires'] >= $now);
            if (!isset($attempts[$fingerprint]) && count($attempts) >= 5000) throw new ApiException(429, 'login_rate_limited', 'Réessayez la connexion dans dix minutes.');
            $entry = $attempts[$fingerprint] ?? ['count' => 0, 'expires' => $now + 600];
            $entry['count']++;
            $attempts[$fingerprint] = $entry;
            $this->files->writeJson('login-attempts.json', $attempts);
            if ($entry['count'] > 10) throw new ApiException(429, 'login_rate_limited', 'Trop de tentatives. Réessayez dans dix minutes.');
        });
        $user = $this->user(strtolower(trim($email)));
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$valid || !$user || !$user['active']) throw new ApiException(401, 'invalid_credentials', 'Adresse e-mail ou mot de passe incorrect.');
        $this->files->locked('login-attempts', true, function () use ($fingerprint) {
            $attempts = $this->files->json('login-attempts.json', []);
            unset($attempts[$fingerprint]);
            $this->files->writeJson('login-attempts.json', $attempts);
        });
        return $user;
    }
}
