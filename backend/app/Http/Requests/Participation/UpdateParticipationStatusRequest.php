<?php

declare(strict_types=1);

namespace App\Http\Requests\Participation;

use App\Enums\ParticipationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ouverture, clôture, annulation d'une collecte.
 *
 * Route distincte de la modification : ce sont des actes soumis à des
 * transitions, pas des champs. Ouvrir une collecte, c'est engager le club
 * auprès de ses membres ; la clôturer, c'est arrêter des comptes.
 */
final class UpdateParticipationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `transition` et non `update` : une collecte close se REFUSE
        // d'etre rouverte, mais c'est la table des transitions qui le dit,
        // avec un message clair — pas un 403 muet.
        return $this->user()->can('transition', $this->route('participation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ParticipationStatus::values())],
        ];
    }
}
