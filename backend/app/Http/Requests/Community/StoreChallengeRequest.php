<?php

declare(strict_types=1);

namespace App\Http\Requests\Community;

use App\Enums\ChallengeMetric;
use App\Enums\ChallengeStatus;
use App\Enums\Sport;
use App\Models\Challenge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création ou modification d'un défi.
 *
 * `target` est en **unité SI** : mètres pour une distance ou un dénivelé,
 * secondes pour une durée, nombre entier pour des sorties. C'est l'interface
 * qui convertit les kilomètres saisis par un chef de groupe — la même règle
 * que partout ailleurs dans le projet (`docs/gps.md`).
 *
 * `sport` reste facultatif : « 500 km, à pied ou à vélo » est un défi
 * parfaitement légitime, et le cas le plus fréquent.
 */
final class StoreChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $challenge = $this->route('challenge');

        return $challenge instanceof Challenge
            ? $this->user()->can('update', $challenge)
            : $this->user()->can('create', Challenge::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $modification = $this->route('challenge') instanceof Challenge;
        $requis = $modification ? 'sometimes' : 'required';

        return [
            'title' => [$requis, 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],

            'metric' => [$requis, Rule::in(ChallengeMetric::values())],
            // Un objectif de zéro serait atteint par tout le monde avant
            // d'avoir commencé.
            'target' => [$requis, 'integer', 'min:1', 'max:100000000'],

            'sport' => ['nullable', Rule::in(Sport::values())],

            'starts_on' => [$requis, 'date'],
            // Un défi qui finirait avant de commencer n'aurait aucun
            // participant, et personne ne comprendrait pourquoi.
            'ends_on' => [$requis, 'date', 'after_or_equal:starts_on'],

            'icon' => ['nullable', 'string', 'max:40'],

            'status' => ['nullable', Rule::in(ChallengeStatus::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target.integer' => "L'objectif se saisit en unité SI : des mètres, des secondes ou un nombre de sorties.",
            'target.min' => "Un objectif de zéro serait atteint avant même de commencer.",
            'ends_on.after_or_equal' => 'Un défi ne peut pas finir avant de commencer.',
            'metric.in' => 'Cette mesure ne fait pas partie de celles que le club sait suivre.',
        ];
    }
}
