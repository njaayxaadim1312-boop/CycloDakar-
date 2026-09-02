# Module financier — règles d'intégrité

> Implémentation : phase 12 livrée (encaissements, grand livre, caisse) ;
> phases 13 et 14 à venir (dépenses, journal complet, rapports).
> Ce document a valeur de **contrat**. Toute évolution du module financier doit s'y conformer
> ou modifier ce document en premier.

## 1. Les cinq invariants

Ces cinq règles sont vérifiées par des tests automatisés (phase 18). Si l'une casse,
le module est considéré comme hors service.

### I1 — Le solde est dérivé, jamais saisi

```
solde = opening_balance + Σ(transactions IN) − Σ(transactions OUT)
```

`cash_accounts.current_balance` est un **cache de lecture**. La commande
`php artisan finance:recompute-balance` recalcule depuis zéro et échoue bruyamment en cas
d'écart. Elle tourne chaque nuit via le scheduler.

Aucune route de l'API n'accepte un solde en entrée. Il n'existe pas de
`PUT /finance/balance`. Toute tentative doit renvoyer `404`.

### I2 — Les écritures sont immuables

`financial_transactions` et `payments` sont **append-only** :
pas de `deleted_at`, pas d'`UPDATE` du montant, pas de route `DELETE`.

La règle est **exécutable**, pas seulement écrite ici : les modèles
`FinancialTransaction` et `Payment` lèvent une exception sur toute tentative de
mise à jour du montant ou de suppression. Un jour, un import ou un correctif
pressé appellera `->update()` sur ces tables ; il vaut mieux qu'il s'arrête net
plutôt que de fausser un solde en silence.

Une contre-passation garde la **catégorie** de l'écriture qu'elle annule, bien
qu'elle soit de sens inverse. C'est indispensable : une annulation
d'encaissement doit venir se retrancher du poste « Participations », faute de
quoi le rapport annuel afficherait toujours des recettes dont une partie est
ressortie de la caisse.

Une erreur se corrige par **contre-passation** : une nouvelle écriture de sens inverse,
même montant, avec `reverses_transaction_id` renseigné et un `reason` obligatoire.
Le journal montre les deux lignes. Le solde redevient juste. L'historique reste vrai.

### I3 — Le client n'est jamais la source de vérité

Sont **toujours** déterminés par le serveur, jamais lus dans la requête :
`collected_by`, `created_by`, `balance_after`, `occurred_at`/`created_at`,
le statut de `participation_members`, et le solde.

### I4 — Une dépense en attente n'affecte pas le solde

Une `expense` en `PENDING` n'a **aucune** ligne dans `financial_transactions`.
La transaction est créée dans la même transaction SQL que le passage à `APPROVED`.

Le tableau de bord distingue explicitement :

```
Solde disponible      845 000 FCFA   ← transactions réelles
Engagé (en attente)    60 000 FCFA   ← dépenses PENDING, informatif
Solde après engagements 785 000 FCFA
```

### I5 — L'argent est en entiers

Tous les montants sont des `BIGINT` de **francs CFA**. Le XOF n'a pas de subdivision en usage.
Aucun `float`, aucun `decimal`, à aucun étage (base, PHP, JSON, TypeScript).
En TypeScript, les montants sont typés `type Fcfa = number & { __brand: 'FCFA' }`.

## 2. Le grand livre

`financial_transactions` est la seule table qui bouge le solde. Chaque ligne porte :

| Champ | Rôle |
|---|---|
| `direction` | `IN` ou `OUT` — le signe n'est jamais dans `amount` |
| `amount` | toujours positif |
| `balance_after` | solde après cette écriture, figé à l'écriture — c'est la colonne « Solde » du journal de caisse |
| `source_type` / `source_id` | `payment` · `expense` · `manual` · `reversal` — remonte à l'origine |
| `occurred_on` | date **métier** (le jour de la sortie), distincte de `created_at` (le jour de la saisie) |
| `created_by` | qui a saisi |

`balance_after` est calculé sous verrou (`SELECT ... FOR UPDATE` sur `cash_accounts`) pour que
deux encaissements simultanés ne produisent pas deux fois le même solde.

**`balance_after` suit l'ordre d'ENREGISTREMENT, pas la date métier.** C'est le solde de la
caisse au moment où l'écriture a été passée ; il ne se recompose donc que dans l'ordre des
`id`. Un encaissement saisi le lundi pour une sortie du samedi précédent — cas courant, un
collecteur ressaisit rarement le soir même — s'insère avant lui par `occurred_on` mais après
lui par `id`. Conséquence pour le journal de caisse : trié par date métier, la colonne
« Solde » n'est pas monotone dès qu'une saisie a été antidatée. C'est la réalité d'une caisse
tenue à la main, pas un défaut à masquer, et `finance:recompute-balance` vérifie la suite dans
l'ordre d'enregistrement.

## 3. Les trois chemins vers la caisse

### 3.1 Encaissement d'une participation

```text
POST /api/v1/participations/{uuid}/payments
{ member, amount, method, reference?, note?, idempotency_key, paid_on? }

  `member` est l'UUID du membre, et non sa clé interne : aucune clé primaire
  ne sort de l'API. Cette convention prime sur le `member_id` esquissé ici
  avant qu'elle ne soit fixée.

DB::transaction:
  0. idempotency_key déjà vue ? → renvoyer le paiement existant, 200, FIN
  1. authorize('pay', $participation)     — COLLECTOR assigné | TREASURER | ADMIN
  2. $pm = participation_members WHERE participation + member   (sinon 404)
  3. $reste = $pm->expected_amount − $pm->paid_amount
     amount ≤ 0 ou amount > $reste                              → 422
  4. INSERT payments (collected_by = auth()->id())
  5. INSERT financial_transactions (IN, source=payment, catégorie PARTICIPATION)
  6. UPDATE participation_members.paid_amount, status, last_payment_at
  7. UPDATE cash_accounts.current_balance   (cache)
  8. INSERT audit_logs ('payment.created')
  9. Numéro de reçu `RC-2026-000042`, attribué sous le même verrou que le solde
     — sans quoi deux encaissements simultanés liraient le même maximum et l'un
     des deux échouerait sur l'index unique
 10. PHASE 17 — notification « Paiement de 5 000 FCFA enregistré ». Le canal
     (push, SMS) n'existe pas encore ; le reçu, lui, est réel et consultable par
     le membre dans « Mes cotisations ».
```

Statut recalculé, jamais reçu du client :

| Condition | Statut |
|---|---|
| `paid_amount = 0` | `NON_PAYE` |
| `0 < paid_amount < expected_amount` | `PARTIELLEMENT_PAYE` |
| `paid_amount ≥ expected_amount` | `PAYE` |
| annulé par un responsable | `ANNULE` |

### 3.2 Dépense

```text
POST /api/v1/expenses          → status PENDING (aucune écriture)
   si amount < seuil_configurable ET l'auteur est TREASURER/ADMIN
      → auto-approbation immédiate

POST /api/v1/expenses/{id}/approve
DB::transaction:
  1. authorize('approve', $expense)    — TREASURER | ADMIN, et ≠ requested_by
  2. status PENDING sinon 409
  3. INSERT financial_transactions (OUT, source=expense)
  4. UPDATE expenses.status=APPROVED, approved_by, approved_at, financial_transaction_id
  5. audit_logs ('expense.approved')

POST /api/v1/expenses/{id}/reject   { reason }   → REJECTED, aucune écriture
```

Le seuil vit dans `settings.expense_approval_threshold` (défaut : 25 000 FCFA).
Un approbateur ne peut pas approuver sa propre dépense.

### 3.3 Recette manuelle (don, sponsoring, vente)

```text
POST /api/v1/finance/income   { amount, income_category_id, label, occurred_on, event_id? }
→ TREASURER | ADMIN uniquement
→ INSERT financial_transactions (IN, source=manual)
```

## 4. Journal de caisse

`GET /api/v1/finance/transactions?from=&to=&direction=&category_id=&event_id=`

Rendu :

| Date | Opération | Entrée | Sortie | Solde | Auteur |
|---|---|---|---|---|---|
| 01/09 | Solde initial | 100 000 | | 100 000 | — |
| 02/09 | Participation — K. Ndiaye | +50 000 | | 150 000 | A. Sow |
| 03/09 | Participation — M. Fall | +75 000 | | 225 000 | A. Sow |
| 04/09 | Transport Lac Rose | | −40 000 | 185 000 | F. Diop |

Tri par `(occurred_on, id)`. `balance_after` est lu, jamais recalculé à l'affichage —
c'est ce qui garantit que le journal imprimé en AG est reproductible à l'identique.

## 5. Rapports

`GET /api/v1/finance/reports?period=day|week|month|year|custom&from=&to=&format=json|pdf|xlsx|csv`

Contenu : total recettes, total dépenses, solde d'ouverture et de clôture, ventilation par
catégorie (recettes et dépenses), participations attendues / encaissées / restant dû,
courbe d'évolution du solde, liste des opérations.

Génération PDF : `barryvdh/laravel-dompdf`. Excel : `maatwebsite/excel`.
Les exports lourds passent par un job en file d'attente et une notification de disponibilité.

## 6. Audit

Chaque opération financière écrit dans `audit_logs` :
`payment.created`, `payment.reversed`, `expense.created`, `expense.approved`,
`expense.rejected`, `income.created`, `transaction.reversed`, `participation.closed`.

Avec : `user_id`, `entity_type`/`entity_id`, `old_values`, `new_values`, `reason`,
`ip_address`, `user_agent`, `created_at`.

Un rapport « collectes par collecteur » (montant encaissé, nombre d'opérations, écarts)
est consultable par le trésorier et l'administrateur — c'est le contrôle contre F7.

## 7. Permissions

| Action | MEMBER | COLLECTOR | TREASURER | ADMIN | SUPER_ADMIN |
|---|:--:|:--:|:--:|:--:|:--:|
| Voir ses propres participations | ✅ | ✅ | ✅ | ✅ | ✅ |
| Voir le solde de la caisse | ⚙️ | ⚙️ | ✅ | ✅ | ✅ |
| Enregistrer un paiement | ❌ | ✅¹ | ✅ | ✅ | ✅ |
| Annuler un paiement | ❌ | ❌ | ✅ | ✅ | ✅ |
| Créer une participation | ❌ | ❌ | ✅ | ✅ | ✅ |
| Saisir une dépense | ❌ | ❌ | ✅ | ✅ | ✅ |
| Approuver une dépense | ❌ | ❌ | ✅² | ✅ | ✅ |
| Saisir une recette manuelle | ❌ | ❌ | ✅ | ✅ | ✅ |
| Exporter les rapports | ❌ | ❌ | ✅ | ✅ | ✅ |
| Voir les journaux d'audit | ❌ | ❌ | ❌ | ✅ | ✅ |

¹ uniquement sur les participations où il est `assigned_collector_id`.
² sauf ses propres dépenses.
⚙️ dépend de `settings.public_balance` (transparence configurable par le club).

## 8. Exemple complet — Sortie Lac Rose

```
Participation « Sortie Lac Rose »   40 membres × 5 000 FCFA
   Attendu                                            200 000 FCFA
   Encaissé (33 paiements)                            165 000 FCFA
   Reste à collecter                                   35 000 FCFA

Dépenses approuvées de l'événement
   Transport                          80 000
   Eau et ravitaillement              45 000
   Assistance médicale                20 000
                                     ────────         145 000 FCFA

Résultat de l'événement                               + 20 000 FCFA
```

Toutes ces valeurs sont **calculées** depuis `financial_transactions` filtrées sur
`event_id`. Aucune n'est stockée en dur.
