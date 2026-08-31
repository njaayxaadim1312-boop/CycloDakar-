# Cyclo Dakar — conventions du projet

Plateforme sportive et de gestion du club Cyclo Dakar.
Lire `docs/architecture.md` avant toute modification structurante.

## Environnement

- **PHP 8.3** est dans `C:\php83` (en tete du PATH). Le PHP de XAMPP (8.1) ne
  convient pas a Laravel 13.
- **MySQL** vient de XAMPP (MariaDB 10.4), base `cyclo_dakar`.
- Diagnostic complet : `cd backend && php artisan cyclo:doctor`

## Regles non negociables

1. **Laravel est la source de verite.** Node.js ne touche jamais la base ; il
   rappelle Laravel par webhook signe en HMAC.
2. **Le web et le mobile consomment la meme API.** Pas de route « web » ni de
   route « mobile ». Cf. `docs/api.md`.
3. **Les montants sont des ENTIERS de FCFA.** Aucun flottant ne touche l'argent,
   a aucun etage. Cf. `docs/finance.md`.
4. **Le solde de caisse est derive**, jamais ecrit. Aucune route n'accepte un
   solde. Une erreur se corrige par contre-passation, jamais par suppression.
5. **Le client n'est jamais cru.** Les statistiques GPS sont recalculees serveur ;
   `collected_by` et `created_by` viennent de la session.
6. **Unites SI en base et en API** : metres, secondes, m/s. La conversion en km,
   km/h et min/km se fait a l'affichage (`lib/format.ts`).
7. **Jamais de modification manuelle de la base.** Toute evolution passe par une
   migration Laravel.
8. **Aucune couleur en dur.** Les jetons de design vivent dans
   `web/src/styles/tokens.css` et `mobile/src/theme/tokens.ts`.
   Cf. `docs/design-system.md`.

## Developpement par phases

Le projet avance phase par phase (`docs/roadmap.md`). On ne demarre pas une phase
tant que la precedente n'est pas terminee, testee et documentee.
Le code d'une phase en cours doit etre reel et fonctionnel — pas de `TODO` a la
place d'une fonctionnalite. Ce qui releve d'une phase ulterieure est marque
explicitement « PHASE X » et son architecture est preparee.

## Fichiers miroirs

Ces paires doivent rester synchronisees a la main (ADR-006, pas de paquet partage) :

| Web | Mobile |
|---|---|
| `web/src/lib/api.ts` | `mobile/src/lib/api.ts` |
| `web/src/lib/format.ts` | `mobile/src/lib/format.ts` |
| `web/src/types/api.ts` | `mobile/src/types/api.ts` |
| `web/src/styles/tokens.css` | `mobile/src/theme/tokens.ts` |

## Langue

L'interface, les messages d'erreur et les commentaires du code sont **en francais**.
Les identifiants du code (variables, classes, routes) restent en anglais.
