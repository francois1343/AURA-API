# AURA-API

Serveur **PHP vanilla** d’AURA : catalogue CSV, connexion protégée, import/export et retour à une seule version précédente. Aucun Laravel, Composer, MariaDB ou Docker nécessaire au fonctionnement courant.

## Démarrer en local

Prérequis : PHP 8.3 à jour avec `mbstring`, sessions et réception de fichiers.

Depuis ce dépôt :

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

L’interface et son dashboard sont dans le dépôt AURA : http://127.0.0.1:5173/admin. Le serveur PHP intégré est réservé au développement.

## Première installation

Utiliser [.env.example](.env.example) comme modèle si une configuration est nécessaire. Par défaut, les données privées sont dans `storage/`, hors de `public/` et exclues de Git.

Depuis le dépôt AURA, placé à côté d’AURA-API :

```bash
node scripts/export-catalog-api.mjs | php ../AURA-API/scripts/catalog-admin.php initialize
```

Créer ensuite un compte avec `php scripts/catalog-admin.php create-user email`, mot de passe de 12 à 72 octets transmis par stdin, jamais dans les arguments. En local uniquement, `create-local-demo` crée un compte avec un mot de passe aléatoire affiché une fois.

Ces étapes ne sont pas à répéter au démarrage. Une initialisation répétée est refusée. Pour copier l’ancienne base ou gérer les comptes, voir la [référence technique](docs/REFERENCE_CSV.md).

## Utiliser le catalogue

Depuis le dashboard : télécharger le CSV, modifier ses lignes, puis importer le fichier complet après confirmation. Les lignes absentes sont retirées. La version remplacée reste disponible pour un retour arrière.

Utiliser de préférence le CSV exporté : 33 colonnes, UTF-8, point-virgule, 1 Mio maximum. Les informations pédagogiques peuvent rester incomplètes ; un fichier vide ou incompatible est refusé sans remplacement.

Les formations sont conservées dans le CSV ; `catalogue.json` contient seulement les références des versions, les secteurs et les métadonnées. Comptes et sessions restent privés.

## Vérifier

```bash
node --test scripts/admin.test.mjs
php scripts/validate.php
```

Les tests API utilisent des fichiers temporaires, sans base. Le second contrôle inclut le seed historique conservé.

## Héberger

Prévoir **une instance PHP et un stockage privé persistant**, HTTPS et des sauvegardes hors machine. Le dossier web doit être `public/`. Configurer `APP_ENV=production`, `SESSION_COOKIE_SECURE=true` et les origines autorisées ; désactiver le compte démo.

AURA reste sur Vercel, avec un relais `/api` vers ce serveur à configurer. Ne pas publier les fichiers de `storage/` ni les secrets. Les anciennes classes SQL sont conservées pour l’archive, pas utilisées par le site.

[Routes, sécurité, stockage et migration : référence complète](docs/REFERENCE_CSV.md).

