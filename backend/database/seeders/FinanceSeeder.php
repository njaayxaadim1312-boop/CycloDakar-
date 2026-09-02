<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TransactionDirection;
use App\Models\CashAccount;
use App\Models\TransactionCategory;
use Illuminate\Database\Seeder;

/**
 * La caisse du club et les postes du grand livre.
 *
 * Ce seeder est **idempotent** : il se relance sans rien casser ni dupliquer.
 * C'est indispensable — il tourne en test avant chaque scénario financier, et
 * il tournera en production à chaque déploiement qui ajoute un poste.
 *
 * Les catégories créées ici sont marquées `is_system` : elles peuvent être
 * renommées (« Participations » deviendra peut-être « Cotisations »), jamais
 * supprimées. `PARTICIPATION` est utilisé en dur par le service
 * d'encaissement ; le faire disparaître laisserait les recettes sans poste.
 *
 * Le solde d'ouverture reste à zéro. Il n'appartient pas au développeur de
 * décider ce que contenait la caisse du club : c'est au trésorier de le
 * saisir, et un chiffre inventé ici serait faux dans tous les rapports à
 * venir.
 */
final class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        CashAccount::query()->firstOrCreate(
            ['is_default' => true],
            [
                'name' => 'Caisse du club',
                'description' => 'Caisse principale : cotisations, participations, dépenses courantes.',
                'opening_balance' => 0,
                'current_balance' => 0,
                'is_active' => true,
            ],
        );

        foreach ($this->categories() as $position => [$code, $name, $direction]) {
            // `firstOrCreate` et non `updateOrCreate` : le club a le droit de
            // renommer ses postes, et un seeder qui rétablirait le libellé
            // d'origine à chaque déploiement serait insupportable. Seuls le
            // code et le sens sont structurants, et ils ne changent pas.
            TransactionCategory::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'direction' => $direction,
                    'is_system' => true,
                    'position' => $position,
                ],
            );
        }
    }

    /**
     * Les postes de départ.
     *
     * Volontairement peu nombreux. Une nomenclature trop fine au premier jour
     * ne se remplit jamais correctement : le collecteur pressé range tout dans
     * « Autre », et le rapport devient illisible. Le club en ajoutera à mesure
     * que le besoin se présentera (PHASE 13).
     *
     * @return list<array{0: string, 1: string, 2: TransactionDirection}>
     */
    private function categories(): array
    {
        return [
            [TransactionCategory::PARTICIPATION, 'Participations', TransactionDirection::In],
            ['COTISATION', 'Cotisations', TransactionDirection::In],
            ['DON', 'Dons', TransactionDirection::In],
            ['SPONSORING', 'Sponsoring', TransactionDirection::In],
            ['VENTE', 'Ventes (maillots, équipement)', TransactionDirection::In],
            ['AUTRE_RECETTE', 'Autre recette', TransactionDirection::In],

            ['TRANSPORT', 'Transport', TransactionDirection::Out],
            ['RAVITAILLEMENT', 'Eau et ravitaillement', TransactionDirection::Out],
            ['MEDICAL', 'Assistance médicale', TransactionDirection::Out],
            ['MATERIEL', 'Matériel et entretien', TransactionDirection::Out],
            ['ADMINISTRATIF', 'Frais administratifs', TransactionDirection::Out],
            ['AUTRE_DEPENSE', 'Autre dépense', TransactionDirection::Out],
        ];
    }
}
