<?php

declare(strict_types=1);

namespace App\Http\Requests\Event;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pointage d'une présence.
 *
 * Le corps ne porte QUE le membre et son état. Ni `checked_in_by`, ni
 * `checked_in_at` : ils viennent de la session et de l'horloge du serveur.
 * Laisser le client signer le pointage viderait la liste de sa valeur.
 */
final class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAttendance', $this->route('event'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member' => ['required', 'uuid', 'exists:members,uuid'],
            'status' => ['required', Rule::in(AttendanceStatus::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member.exists' => "Ce membre n'existe pas.",
        ];
    }
}
