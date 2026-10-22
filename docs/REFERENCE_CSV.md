# Référence technique — API et catalogue CSV

Détails conservés lors de la simplification du 22 octobre 2026. Pour le démarrage rapide, voir le [README](../README.md).

MVP AURA : **un CSV actif, une seule version précédente**, connexion protégée et dashboard sur http://127.0.0.1:5173/admin.

Aucun Laravel, Composer ou accès MariaDB nécessaire au runtime. Les anciennes classes SQL, migrations et données sont conservées pour l’archive et la copie ponctuelle. Les anciennes routes métiers répondent 410.

## Démarrer en local

Prérequis : PHP 8.3 à jour avec mbstring, sessions et upload de fichiers.

Depuis AURA-API :

~~~bash
php -S 127.0.0.1:8000 -t public public/index.php
~~~

Depuis AURA/frontend, dans un autre terminal :

~~~bash
npm run dev -- --host 127.0.0.1
~~~

Docker n’est plus requis. Vite relaie /api vers PHP. Le serveur PHP intégré est réservé au développement.

## Première installation

Si .env n’existe pas, prendre .env.example comme modèle. AURA_STORAGE_DIR peut désigner un dossier absolu privé et persistant ; par défaut, storage/ dans ce dépôt. Ne jamais le placer dans public/ ni le commiter.

Depuis AURA :

~~~bash
node scripts/export-catalog-api.mjs | php ../AURA-API/scripts/catalog-admin.php initialize
~~~

L’initialisation refuse tout remplacement d’un catalogue existant. Les secteurs sont copiés dans la configuration privée ; les formations sont uniquement dans le CSV.

Créer un compte avec php scripts/catalog-admin.php create-user email : mot de passe de 12 à 72 octets transmis par stdin, pas dans les arguments ni le dépôt. create-local-demo est réservé à APP_ENV=local et affiche son mot de passe aléatoire une seule fois. disable-user email invalide les sessions existantes.

php scripts/catalog-admin.php status affiche les métadonnées des fichiers, jamais de mot de passe.

### Copier l’ancienne base une seule fois

~~~bash
php scripts/catalog-admin.php migrate-from-database
~~~

Cette commande utilise les anciennes variables DB_* du .env pour **lire seulement** le catalogue et les comptes : copie du CSV et des hachages, aucun effacement de la base. Elle refuse si le stockage fichiers est déjà initialisé. Les notes privées et anciennes versions restent dans la base archivée et ne sont jamais exportées dans le CSV public.

La migration locale du 22 octobre a conservé les 32 entrées, 35 formations individuelles et le compte existant sans différence de données. Reconnexion nécessaire : le cookie des sessions fichiers est distinct.

## Dashboard et CSV

1. Télécharger le CSV actuel.
2. Modifier ses lignes dans un tableur sans renommer les colonnes ni les identifiants pour corriger un titre.
3. Choisir le fichier complet et confirmer « Importer et remplacer ».
4. Si besoin, utiliser « Revenir à cette version ».

Les formations absentes du nouveau fichier disparaissent du catalogue. Aucun mode fusion, éditeur de fiches ou aperçu détaillé. Un fichier inutilisable ne remplace rien ; un fichier identique ne consomme pas la sauvegarde. Le retour échange actif/précédent et incrémente la révision.

Format exporté : 33 colonnes, UTF-8 avec BOM, point-virgule, CRLF, parents/variantes, lieux multiples, photos/crédits. Ancien CSV à 22 colonnes accepté avec reprise des métadonnées connues. Listes JSON ou un élément par ligne dans la cellule. Maximum 1 Mio et 5 000 lignes de données.

Validation technique seulement : CSV lisible, colonnes, identifiants/secteurs/groupes, types et liens compatibles. Descriptions, horaires, lieux et prérequis peuvent rester vides. Les fiches incomplètes marquées validées sont conservées sans statut de validation. Les nouvelles formations et changements structurels restent hors recommandations jusqu’à calibration ; exploration par secteur conservée.

## Stockage

- Un CSV actif et, après remplacement, le CSV immédiatement précédent.
- catalogue.json : références des fichiers, empreintes SHA-256, secteurs, révision, dates ; aucune liste de formations.
- accounts.json : comptes et mots de passe hachés.
- sessions/ et fichiers de limitation des connexions.
- *.lock : verrouillage partagé en lecture, exclusif en publication.

Des noms internes aléatoires permettent d’écrire un CSV complet puis de changer atomiquement son pointeur. Le téléchargement se nomme aura-catalogue-rN.csv. Ne pas éditer les fichiers de stockage pendant le fonctionnement. Aucun fichier uploadé n’est exécuté.

Ni comptes ni métadonnées du personnel ne sont exposés par le catalogue public. Le runtime ne contacte jamais SQL.

## Routes

| Méthode | Route | Accès |
| --- | --- | --- |
| GET | /api/v1/catalog | Catalogue JSON public lu depuis le CSV actif. |
| GET | /health ou /api/v1/health | État du stockage CSV, sans SQL. |
| GET | /api/v1/admin/session | Session et jeton CSRF. |
| POST | /api/v1/admin/login | JSON email/password + CSRF. |
| POST | /api/v1/admin/logout | CSRF. |
| GET | /api/v1/admin/catalog | Métadonnées actif/précédent et révision. |
| GET | /api/v1/admin/catalog/export | JSON filename/csv/revision pour téléchargement. |
| POST | /api/v1/admin/catalog/import | multipart : catalogue, revision, confirmation=REMPLACER + CSRF. |
| POST | /api/v1/admin/catalog/restore | JSON revision, confirmation=REVENIR + CSRF. |

HTTP 401/403 : accès refusé ; 409 : révision périmée ou retour impossible ; 422 : fichier/formulaire refusé. Les anciennes mutations fiche par fiche et import preview/commit sont retirées.

## Production

Une instance PHP et un **disque persistant privé** sont requis. Le stockage doit survivre aux redémarrages/déploiements et être sauvegardé hors machine. Plusieurs instances à disques indépendants ne sont pas prises en charge. Sur Render, prévoir le volume persistant : un disque éphémère ne convient pas.

Configurer HTTPS, APP_ENV=production, APP_DEBUG=false, SESSION_COOKIE_SECURE=true, origines autorisées, upload_max_filesize au moins à 1M et post_max_size supérieur pour l’enveloppe multipart. Le dossier web est public/, jamais la racine du dépôt.

Connexion : sessions HttpOnly/SameSite=Lax, rotation à la connexion, expiration après 30 minutes d’inactivité/8 heures maximum, compte actif contrôlé, CSRF et limitation persistante des tentatives. Désactiver le compte démo avant exposition publique. Ces protections ne valent pas audit de production.

AURA reste sur Vercel : configurer le relais /api vers PHP avant la réécriture SPA, sans cache des réponses de gestion. Secrets et fichiers restent côté PHP. Aucune URL de production n’est configurée ici.

## Vérifier

~~~bash
node --test scripts/admin.test.mjs
php scripts/validate.php
~~~

22 tests HTTP/PHP utilisent un dossier temporaire isolé, sans Docker ni base. La seconde commande contrôle la syntaxe PHP et le seed métiers historique conservé. Aucun test d’import ou retour arrière ne remplace le catalogue de travail.
