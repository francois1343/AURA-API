<?php
declare(strict_types=1);

use Aura\Api\Repository\FileCatalogRepository;
use Aura\Api\Security\FileAccounts;
use Aura\Api\Storage\PrivateFiles;

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $command = $argv[1] ?? '';
    if ($command === 'initialize') {
        $catalog = json_decode(stream_get_contents(STDIN, 2097153), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($catalog)) throw new RuntimeException('Un catalogue JSON est attendu sur stdin pour la première installation.');
        (new FileCatalogRepository())->initialize($catalog);
        fwrite(STDOUT, "Catalogue CSV initialisé. Une seconde initialisation ne remplace rien.\n");
    } elseif ($command === 'migrate-from-database') {
        // Deliberately read-only: neither the catalogue nor accounts in SQL are changed.
        $db = Aura\Api\Database::connection();
        $catalog = (new Aura\Api\Repository\TrainingCatalogRepository($db))->current()['catalog'];
        $users = $db->query('SELECT email, password_hash, active FROM catalog_admin_users')->fetchAll();
        $files = new PrivateFiles();
        if (is_file($files->path('catalogue.json')) || is_file($files->path('accounts.json'))) throw new RuntimeException('Migration refusée : un stockage fichiers existe déjà.');
        (new FileCatalogRepository($files))->initialize($catalog, 'migration-depuis-base');
        (new FileAccounts($files))->import($users);
        fwrite(STDOUT, "Catalogue et comptes copiés vers les fichiers privés. Base d’origine inchangée. Mots de passe conservés sous forme de hachages.\n");
    } elseif ($command === 'create-user' || $command === 'create-local-demo') {
        $email = strtolower(trim($argv[2] ?? ''));
        if ($command === 'create-local-demo') {
            if (Aura\Api\Config::string('APP_ENV', '') !== 'local') throw new RuntimeException('Compte démo réservé à APP_ENV=local.');
            $email = 'demo@aura.local';
            $password = bin2hex(random_bytes(12));
        } else {
            if (function_exists('stream_isatty') && stream_isatty(STDIN)) throw new RuntimeException('Fournissez le mot de passe par stdin, pas dans les arguments.');
            $password = rtrim(stream_get_contents(STDIN, 201), "\r\n");
        }
        (new FileAccounts())->create($email, $password);
        fwrite(STDOUT, $command === 'create-local-demo' ? "Compte local : $email\nMot de passe affiché une fois : $password\n" : "Compte créé dans le stockage privé, mot de passe haché.\n");
    } elseif ($command === 'disable-user') {
        (new FileAccounts())->disable($argv[2] ?? '');
        fwrite(STDOUT, "Compte désactivé ; ses sessions ne permettent plus d’accéder au dashboard.\n");
    } elseif ($command === 'status') {
        fwrite(STDOUT, json_encode((new FileCatalogRepository())->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    } else throw new RuntimeException('Usage : php scripts/catalog-admin.php initialize|migrate-from-database|create-user email|create-local-demo|disable-user email|status');
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof PDOException ? "Lecture de l’ancienne base impossible. Aucun remplacement effectué.\n" : $error->getMessage() . "\n");
    exit(1);
}
