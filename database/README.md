# database/

Ce dossier ne contient PAS le schema : le schema vit dans les migrations Laravel
(`backend/database/migrations/`) et nulle part ailleurs.

| Dossier | Contenu |
|---|---|
| `dumps/` | Sauvegardes `mysqldump` locales (ignorees par Git) |
| `seeds/` | Jeux de donnees de demonstration et fixtures partagees |

## Creer la base

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS cyclo_dakar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## Appliquer le schema

```powershell
cd backend
php artisan migrate
```

## Sauvegarder

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root cyclo_dakar > database\dumps\cyclo_dakar_2026-08-31.sql
```

## Restaurer

```powershell
C:\xampp\mysql\bin\mysql.exe -u root cyclo_dakar < database\dumps\cyclo_dakar_2026-08-31.sql
```

## Regle absolue

Ne jamais modifier la structure de la base a la main (phpMyAdmin, client SQL).
Toute evolution passe par une migration :

```powershell
php artisan make:migration ajoute_colonne_x_a_table_y
```

Sans cela, la base du poste diverge de celle des autres developpeurs et de la
production. Le detail du schema cible est documente dans docs/database.md.
