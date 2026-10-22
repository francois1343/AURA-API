<?php
declare(strict_types=1);

use Aura\Api\Database;
use Aura\Api\Repository\TrainingCatalogRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $command = $argv[1] ?? '';
    $db = Database::connection();
    if ($command === 'migrate') {
        $db->exec(file_get_contents(dirname(__DIR__) . '/database/migrations/003_training_admin.sql'));
        fwrite(STDOUT, "Tables de gestion formations ajoutées. Anciennes tables conservées.\n");
    } elseif ($command === 'initialize') {
        $catalog = json_decode(stream_get_contents(STDIN, 2097153), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($catalog)) throw new RuntimeException('Un catalogue JSON est attendu sur stdin.');
        (new TrainingCatalogRepository($db))->initialize($catalog);
        fwrite(STDOUT, "Catalogue initialisé en révision 1. Une seconde initialisation sera refusée.\n");
    } elseif ($command === 'create-user') {
        $email = strtolower(trim($argv[2] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('Adresse e-mail invalide.');
        if (function_exists('stream_isatty') && stream_isatty(STDIN)) throw new RuntimeException('Transmettez le mot de passe par stdin (sans le placer dans les arguments ou le dépôt).');
        $password = rtrim(stream_get_contents(STDIN, 201), "\r\n");
        if (strlen($password) < 12 || strlen($password) > 72) throw new RuntimeException('Le mot de passe doit compter de 12 à 72 octets.');
        $db->prepare('INSERT INTO catalog_admin_users (email, password_hash) VALUES (?, ?)')->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        fwrite(STDOUT, "Compte créé. Aucun mot de passe n’est conservé en clair.\n");
    } elseif ($command === 'create-local-demo') {
        if (Aura\Api\Config::string('APP_ENV', '') !== 'local') throw new RuntimeException('Le compte de démonstration est réservé à APP_ENV=local.');
        $email = 'demo@aura.local';
        $password = bin2hex(random_bytes(12));
        $db->prepare('INSERT INTO catalog_admin_users (email, password_hash) VALUES (?, ?)')->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        fwrite(STDOUT, "Compte de démonstration local : $email\nMot de passe affiché une seule fois : $password\nDésactivez ce compte avant toute exposition publique.\n");
    } elseif ($command === 'disable-user') {
        $db->prepare('UPDATE catalog_admin_users SET active = 0 WHERE email = ?')->execute([strtolower(trim($argv[2] ?? ''))]);
        fwrite(STDOUT, "Compte désactivé, ses sessions seront refusées à la prochaine requête.\n");
    } else throw new RuntimeException('Usage : php scripts/catalog-admin.php migrate|initialize|create-user email|create-local-demo|disable-user email');
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Opération refusée par la base : vérifiez la connexion ou l’existence du compte.\n" : $error->getMessage() . "\n");
    exit(1);
}
