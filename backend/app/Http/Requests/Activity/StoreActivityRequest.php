<?php

declare(strict_types=1);

namespace App\Http\Requests\Activity;

use App\Enums\ActivityVisibility;
use App\Enums\Sport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ouverture d'une activité.
 *
 * L'`uuid` est **fourni par le client**, contrairement à toutes les autres
 * entités du projet. C'est délibéré : le téléphone le génère au démarrage de
 * la sortie, hors ligne, et peut ensuite rejouer la création autant de fois
 * qu'il le faut sans jamais créer de doublon.
 *
 * Aucune statistique n'est acceptée ici : distance, durée et vitesses sont
 * recalculées par le serveur à la finalisation.
 */
final class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'sport' => ['required', Rule::in(Sport::values())],

            // Une sortie peut être déclarée après coup (synchronisation
            // tardive), mais jamais dans le futur.
            'started_at' => ['required', 'date', 'before_or_equal:now'],

            'title' => ['nullable', 'string', 'max:140'],
            'visibility' => ['nullable', Rule::in(ActivityVisibility::values())],

            // Modèle, système, version de l'application : indispensable pour
            // comprendre un problème GPS propre à un appareil.
            'device_info' => ['nullable', 'array'],
            'device_info.model' => ['nullable', 'string', 'max:120'],
            'device_info.os' => ['nullable', 'string', 'max:60'],
            'device_info.app_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uuid.required' => "L'identifiant de l'activité est obligatoire.",
            'uuid.uuid' => "L'identifiant de l'activité est invalide.",
            'sport.required' => 'Le sport est obligatoire.',
            'started_at.before_or_equal' => 'Une sortie ne peut pas démarrer dans le futur.',
        ];
    }
}
