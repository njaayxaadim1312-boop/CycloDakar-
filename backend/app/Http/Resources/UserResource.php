<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation publique d'un compte.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Jamais l'auto-incrément : il divulguerait le nombre de comptes
            // et permettrait de les énumérer.
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_formatted' => PhoneNumber::format($this->phone),

            'role' => $this->role->value,
            'role_label' => $this->role->label(),

            // Capacités calculées côté serveur. Le client s'en sert pour
            // MASQUER ce qui est inaccessible — jamais pour autoriser :
            // l'autorisation réelle est refaite à chaque requête par les
            // Policies. Un client modifié ne gagne donc aucun droit.
            'abilities' => [
                'collect' => $this->role->canCollect(),
                'manage_finance' => $this->role->canManageFinance(),
                'administer' => $this->role->isAdmin(),
            ],

            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
