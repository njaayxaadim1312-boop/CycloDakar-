<?php

declare(strict_types=1);

namespace App\Http\Requests\Event;

use App\Enums\EventDifficulty;
use App\Enums\Sport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modification d'une sortie.
 *
 * Deux différences assumées avec la création :
 *
 *  - `starts_at` n'exige plus d'être dans le futur. Corriger l'heure d'une
 *    sortie en cours doit rester possible ; l'interdire obligerait le bureau à
 *    laisser une donnée fausse.
 *  - le statut ne se change pas ici. Il a sa propre route, avec ses propres
 *    règles de transition — publier ou annuler n'est pas « modifier un champ ».
 */
final class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sport' => ['sometimes', 'required', Rule::in(Sport::values())],

            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],

            'location_name' => ['sometimes', 'required', 'string', 'max:160'],
            'start_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'start_lng' => ['nullable', 'numeric', 'between:-180,180'],

            'planned_distance_m' => ['nullable', 'integer', 'min:0', 'max:500000'],
            'route_polyline' => ['nullable', 'string', 'max:200000'],
            'difficulty' => ['nullable', Rule::in(EventDifficulty::values())],
            'max_participants' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ];
    }
}
