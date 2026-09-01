<?php

declare(strict_types=1);

namespace App\Http\Requests\Event;

use App\Enums\EventStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changement d'état d'une sortie.
 *
 * Route distincte de la modification ordinaire, parce que ce n'est pas la même
 * chose : publier, démarrer ou annuler sont des ACTES, pas des champs. Ils
 * obéissent à des transitions précises (`EventStatus::allowedTransitions`) et
 * déclencheront des notifications en phase 17.
 */
final class UpdateEventStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(EventStatus::values())],
        ];
    }
}
