# AURA API

API indépendante du frontend AURA, en PHP/MariaDB. Depuis le 22 octobre, elle sert
le catalogue formations Forem v2 et une première édition authentifiée des fiches.
L'ancien catalogue de 28 secteurs / 112 métiers reste dans ses tables et routes
historiques, sans être remplacé ni utilisé comme catalogue formations.

## Gestion du catalogue formations — 22 octobre 2026

L'interface React est dans le dépôt AURA, route `http://127.0.0.1:5173/admin`.
Vite relaie `/api` vers ce service PHP. Les personnels disposent de comptes AURA
autorisés ; aucune intégration SSO Forem n'est revendiquée.

```bash
docker compose up -d database
php scripts/catalog-admin.php migrate
php -S 127.0.0.1:8000 -t public public/index.php
```

La migration ajoute quatre tables dédiées. Initialiser une seule fois depuis le
dépôt AURA voisin :

```bash
node scripts/export-catalog-api.mjs | php ../AURA-API/scripts/catalog-admin.php initialize
```

Une initialisation répétée est refusée sans écrasement. Pour un compte local de
démonstration : `php scripts/catalog-admin.php create-local-demo` (mot de passe
aléatoire affiché une fois, haché dans la base, commande refusée hors APP_ENV=local).
Ne pas commiter ce mot de passe. Désactiver le compte avant exposition publique :
`php scripts/catalog-admin.php disable-user demo@aura.local`.

Créer un compte autorisé : `php scripts/catalog-admin.php create-user email`, en
fournissant un mot de passe de 12 à 72 octets sur stdin, pas dans les arguments.
`disable-user email` coupe l'accès aux prochaines requêtes des sessions existantes.
La gestion des comptes est pour l'instant réservée à la ligne de commande.

| Méthode | Route | Accès |
|---|---|---|
| GET | `/api/v1/catalog` | Catalogue v2 public uniquement ; aucune note interne ni identité du personnel. |
| GET | `/api/v1/admin/session` | Amorçage session/CSRF et utilisateur courant ou null. |
| POST | `/api/v1/admin/login` | JSON email/password + jeton CSRF. |
| POST | `/api/v1/admin/logout` | Jeton CSRF. |
| GET | `/api/v1/admin/catalog` | Session autorisée ; catalogue, notes, révision et auteur. |
| PATCH | `/api/v1/admin/trainings/{id}` | Session + CSRF ; JSON revision, changes, note. |
| POST | `/api/v1/admin/trainings` | Création ; revision, id, parentId éventuel, changes, note. |
| POST | `/api/v1/admin/trainings/{id}/delete-preview` | Aperçu sans écriture des suppressions et de leur impact. |
| DELETE | `/api/v1/admin/trainings/{id}` | Révision, confirmation d'identifiant et nombre exact de fiches retirées. |
| GET | `/api/v1/admin/catalog/export` | JSON filename/csv/revision, sans notes ni identités ; téléchargement par le frontend. |
| POST | `/api/v1/admin/catalog/import/preview` | revision, mode merge/replace, csv ou csvBase64 ; candidat conservé en session. |
| POST | `/api/v1/admin/catalog/import/commit` | revision, token d'aperçu, confirmation des suppressions éventuelles. |

Ajout, édition et suppression des formations et variantes ; identifiants immuables
après création, secteurs existants modifiables. Supprimer un regroupement retire
ses variantes ; si une seule variante subsiste après une suppression individuelle,
elle devient une fiche indépendante. La dernière entrée ne peut pas être retirée.

CSV complet aura-catalogue-v1 à 33 colonnes (parents de regroupement, lieux multiples,
photos et métadonnées inclus) et import de l'ancien format à 22 colonnes. Fusion par
défaut ou remplacement explicite. L'aperçu expose les avant/après sans écriture ;
son jeton de session expire après 15 minutes et n'est utilisable qu'une fois. Les
suppressions exigent la confirmation « SUPPRIMER N ». Fichier vide ou invalide :
refus intégral. Limites : 1 Mio, 5 000 lignes de données.

Chaque publication est transactionnelle et versionnée ; HTTP 409 si la révision
a changé, HTTP 422 si les données sont invalides. Aucun passage automatique à ready.
Les nouvelles fiches et changements structurels restent hors recommandations tant
que leur matrice n'est pas calibrée. Les notes des fiches conservées restent privées.
Les versions techniques restent en base : aucun bouton de restauration ou de remise
à zéro n'est prévu, et aucun import ne passe par un catalogue vide.

Sessions HttpOnly/SameSite, rotation à la connexion, expiration 30 min d'inactivité
et huit heures maximum, comptes actifs contrôlés à chaque requête, jeton CSRF,
liste d'origines et limitation d'essais par IP côté serveur. Aucun mot de passe en
clair ni token d'administration dans le stockage du navigateur.

Avant production : héberger PHP/MariaDB derrière HTTPS et un relais de même
origine pour `/api/v1/admin`, fixer APP_ENV=production, APP_DEBUG=false,
SESSION_COOKIE_SECURE=true, restreindre CORS, configurer sessions et sauvegardes,
remplacer les identifiants Docker locaux et organiser les comptes/récupérations.
Le serveur PHP intégré et le compte de démonstration ne sont pas destinés au
déploiement. Vercel seul ne fournit pas ce backend PHP/MariaDB.

Principes de référence : [sessions PHP](https://www.php.net/manual/en/features.session.security.management.php)
et [CSRF OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).
Ces protections ne valent pas audit de sécurité de production.

Tests : `node --test scripts/admin.test.mjs` avec Docker actif. Trente-quatre contrôles
HTTP/PHP/MariaDB créent et suppriment seulement une base aléatoire aura_admin_test_*,
sans toucher au catalogue local. `php scripts/validate.php` conserve la validation
de l'ancien catalogue. PHP doit disposer de pdo_mysql, session et mbstring.

## Prérequis

- PHP 8.3 avec `pdo_mysql` ;
- Docker Desktop pour la base MariaDB.

Aucune dépendance Composer n'est nécessaire dans cette première version.

## Installation du catalogue historique (facultative)

```bash
cp .env.example .env
docker compose up -d database
php scripts/database.php migrate
php scripts/database.php seed
php -S 127.0.0.1:8000 -t public public/index.php
```

L'API répond ensuite sur `http://127.0.0.1:8000`.

## Routes historiques conservées

| Méthode | Route | Description |
|---|---|---|
| GET | `/health` | Vérifie le service et la connexion à la base. |
| GET | `/api/v1/sectors` | Retourne les 28 secteurs. |
| GET | `/api/v1/jobs` | Retourne les métiers avec pagination. |
| GET | `/api/v1/jobs/{id}` | Retourne une fiche métier précise. |

La liste des métiers accepte les paramètres suivants :

- `q` : recherche dans le titre, les mots-clés et le résumé ;
- `sector` : identifiant exact d'un secteur ;
- `limit` : de 1 à 50 ;
- `offset` : entier positif ou nul.

Exemples :

```bash
curl http://127.0.0.1:8000/health
curl 'http://127.0.0.1:8000/api/v1/jobs?sector=informatique'
curl http://127.0.0.1:8000/api/v1/jobs/developpeur-logiciels
```

## Format des réponses

Succès :

```json
{
  "data": [],
  "meta": { "total": 112, "limit": 20, "offset": 0 }
}
```

Erreur :

```json
{
  "error": {
    "code": "job_not_found",
    "message": "Ce métier n’existe pas."
  }
}
```

## Vérification

```bash
php scripts/validate.php
```

La validation contrôle également la répartition de quatre métiers par secteur,
les liens officiels Forem, les dates de vérification et les statuts du marché du
travail. Chaque fiche expose `labourMarket` avec le statut, le périmètre,
l’année et la source utilisée.

## Connexion depuis AURA

Le frontend devra définir :

```env
VITE_API_URL=http://127.0.0.1:8000/api/v1
```

Les origines locales de Vite et le domaine Vercel actuel sont autorisés dans
`.env.example`. En production, cette liste doit être limitée au domaine publié.

