<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saisie d'un encaissement.
 *
 * `idempotency_key` EST OBLIGATOIRE, et c'est délibéré.
 *
 * La rendre facultative reviendrait à la rendre inexistante : personne ne
 * l'enverrait, et le premier réseau capricieux produirait des doubles
 * paiements. Le client la fabrique (un uuid suffit) et la RÉUTILISE à
 * l'identique sur chaque tentative de la même saisie — c'est ce qui distingue
 * un rejeu d'un second versement volontaire du même montant, lequel est
 * parfaitement légitime et doit passer.
 *
 * Ce qui n'est PAS ici, et ne le sera jamais : `collected_by`, `paid_amount`,
 * `status`, `balance_after`. Le serveur les détermine (règle I3 de
 * `docs/finance.md`). Un client qui les envoie les verra ignorés.
 *
 * Le membre est désigné par son **uuid** et non par sa clé interne : aucune
 * clé primaire ne sort de l'API, ici comme ailleurs.
 */
final class StorePaymentRequest extends FormRequest
{
    /**
     * L'autorisation dépend de la LIGNE de dette (le collecteur assigné), pas
     * seulement de la collecte. Elle est donc vérifiée dans le contrôleur,
     * une fois la ligne retrouvée : la refuser ici obligerait à résoudre le
     * membre deux fois.
     */
    public function authorize(): bool
    {
        return $this->user()?->role->canCollect() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member' => ['required', 'uuid', 'exists:members,uuid'],

            // Entier de FCFA. `integer` refuse « 5000.50 » et « 5 000 » :
            // aucun flottant ne touche l'argent, à aucun étage (règle I5).
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],

            'method' => ['required', Rule::in(PaymentMethod::values())],

            // Identifiant Wave / Orange Money / bordereau. Attendu mais jamais
            // exigé : un collecteur sur la route ne l'a pas toujours sous les
            // yeux, et bloquer l'encaissement ferait perdre la trace du
            // paiement — bien pire que de consigner la référence plus tard.
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],

            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],

            // Date métier : un collecteur ressaisit souvent le lendemain ce
            // qu'il a encaissé la veille. Le futur est refusé — on n'encaisse
            // pas de l'argent qui n'est pas encore arrivé.
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member.exists' => "Ce membre n'existe pas.",
            'amount.integer' => 'Le montant doit être un nombre entier de francs CFA, sans décimale.',
            'amount.min' => 'Un encaissement porte au moins 1 FCFA.',
            'method.in' => "Ce moyen de paiement n'est pas reconnu.",
            'idempotency_key.required' => "La clé d'idempotence est obligatoire : elle protège le membre d'un double débit.",
            'paid_on.before_or_equal' => "La date d'encaissement ne peut pas être dans le futur.",
        ];
    }
}
