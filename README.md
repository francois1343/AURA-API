# AURA API

API indépendante du frontend AURA. Cette première version expose le catalogue
des 28 secteurs et des 112 métiers depuis MariaDB, avec quatre métiers par
secteur.

## Prérequis

- PHP 8.3 avec `pdo_mysql` ;
- Docker Desktop pour la base MariaDB.

Aucune dépendance Composer n'est nécessaire dans cette première version.

## Installation locale

```bash
cp .env.example .env
docker compose up -d database
php scripts/database.php migrate
php scripts/database.php seed
php -S 127.0.0.1:8000 -t public public/index.php
```

L'API répond ensuite sur `http://127.0.0.1:8000`.

## Routes disponibles

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

