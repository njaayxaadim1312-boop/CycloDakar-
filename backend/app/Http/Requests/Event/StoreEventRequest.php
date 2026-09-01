<?php

declare(strict_types=1);

namespace App\Http\Requests\Event;

use App\Enums\EventDifficulty;
use App\Enums\EventStatus;
use App\Enums\Sport;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'une sortie officielle.
 *
 * L'autorisation est vérifiée AVANT la validation (`authorize()` est appelée
 * en premier par Laravel) : un membre sans droit reçoit 403 sans que le détail
 * des champs attendus ne lui soit décrit.
 */
final class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Event::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sport' => ['required', Rule::in(Sport::values())],

            // Une sortie se prépare : on refuse de créer une annonce dans le
            // passé, qui ne pourrait recueillir aucune inscription.
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],

            'location_name' => ['required', 'string', 'max:160'],
            'start_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'start_lng' => ['nullable', 'numeric', 'between:-180,180'],

            // En MÈTRES, comme partout ailleurs (règle des unités SI).
            // 500 km : au-delà, c'est une saisie en mètres prise pour des km.
            'planned_distance_m' => ['nullable', 'integer', 'min:0', 'max:500000'],
            'route_polyline' => ['nullable', 'string', 'max:200000'],

            'difficulty' => ['nullable', Rule::in(EventDifficulty::values())],

            // Pas de limite = `null`. Un zéro voudrait dire « aucune place ».
            'max_participants' => ['nullable', 'integer', 'min:1', 'max:2000'],

            // Une sortie naît en brouillon par défaut, mais le bureau peut
            // publier directement. Les autres états ne se choisissent pas à
            // la création : ils se traversent.
            'status' => ['nullable', Rule::in([
                EventStatus::Draft->value,
                EventStatus::Published->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.after' => 'La date de départ doit être dans le futur.',
            'ends_at.after' => "L'heure de fin doit suivre l'heure de départ.",
            'max_participants.min' => 'Une sortie limitée compte au moins une place. Laissez le champ vide pour ne pas limiter.',
        ];
    }
}
