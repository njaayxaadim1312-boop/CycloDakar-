<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recette manuelle : don, sponsoring, vente de maillots.
 *
 * Elle entre DIRECTEMENT au grand livre — il n'y a pas de circuit de
 * validation, contrairement aux dépenses. L'asymétrie est voulue : de l'argent
 * qui entre ne peut pas appauvrir le club, et exiger un double regard pour
 * enregistrer un don ferait perdre la trace du don.
 *
 * Réservée au trésorier et au-dessus. Un collecteur encaisse des cotisations,
 * pas des dons : ces deux gestes n'ont ni le même contrôle ni la même pièce.
 */
final class StoreIncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->canManageFinance() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'exists:transaction_categories,code'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'label' => ['required', 'string', 'max:200'],
            'event' => ['nullable', 'uuid', 'exists:events,uuid'],
            'occurred_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.exists' => "Ce poste de recette n'existe pas.",
            'amount.integer' => 'Le montant doit être un nombre entier de francs CFA, sans décimale.',
            'label.required' => "Indiquez d'où vient cette recette : un don anonyme n'est pas auditable.",
            'occurred_on.before_or_equal' => "Une recette ne s'enregistre pas dans le futur.",
        ];
    }
}
