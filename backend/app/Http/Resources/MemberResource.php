<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation d'un membre.
 *
 * Deux niveaux de détail, décidés par le SERVEUR :
 *
 *  - tout membre connecté voit l'annuaire (nom, matricule, photo, statut) ;
 *  - les coordonnées, la date de naissance, les notes et le contact d'urgence
 *    ne sont exposés qu'à l'intéressé et à l'administration.
 *
 * Le filtrage est fait ici et non côté client : un client modifié ne doit
 * jamais pouvoir obtenir plus que ce à quoi il a droit.
 *
 * @mixin Member
 */
final class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isSelf = $viewer !== null && $this->user_id === $viewer->id;
        $isPrivileged = $viewer !== null && $viewer->role->isAdmin();
        $canSeeContact = $isSelf || $isPrivileged || ($viewer !== null && $viewer->role->canCollect());
        $canSeePrivate = $isSelf || $isPrivileged;

        return [
            'uuid' => $this->uuid,
            'matricule' => $this->matricule,

            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'initials' => $this->initials(),

            'photo_url' => $this->photoUrl(),

            /*
             | L'image de fond du compte.
             |
             | Exposée à TOUS les lecteurs, comme la photo : c'est un décor, pas
             | une donnée personnelle. Un membre qui choisit la corniche au
             | lever du jour ne révèle rien de lui en la montrant.
             */
            'cover_url' => $this->coverUrl(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'joined_at' => $this->joined_at?->toDateString(),
            'seniority_years' => $this->seniorityYears(),

            // Le collecteur a besoin du téléphone pour retrouver quelqu'un sur
            // le terrain ; un membre lambda n'en a pas besoin.
            'phone' => $this->when($canSeeContact, $this->phone),
            'phone_formatted' => $this->when($canSeeContact, $this->formattedPhone()),
            'email' => $this->when($canSeeContact, $this->email),

            'birth_date' => $this->when($canSeePrivate, $this->birth_date?->toDateString()),
            'gender' => $this->when($canSeePrivate, $this->gender),
            'emergency_contact_name' => $this->when($canSeePrivate, $this->emergency_contact_name),
            'emergency_contact_phone' => $this->when($canSeePrivate, $this->emergency_contact_phone),
            'notes' => $this->when($isPrivileged, $this->notes),

            // Le jeton du QR n'est jamais envoyé dans une liste : il ne sort
            // que sur la fiche de l'intéressé ou pour un administrateur.
            'qr_token' => $this->when(
                $canSeePrivate && $request->routeIs('api.v1.members.show'),
                $this->qr_token,
            ),

            // Compte de connexion associé, s'il existe. Un membre sans compte
            // est parfaitement normal (pas de smartphone).
            'account' => $this->when(
                $canSeeContact,
                fn () => $this->user === null ? null : [
                    'uuid' => $this->user->uuid,
                    'role' => $this->user->role->value,
                    'role_label' => $this->user->role->label(),
                    'is_active' => $this->user->is_active,
                    'last_login_at' => $this->user->last_login_at?->toIso8601String(),
                ],
            ),
            'has_account' => $this->user_id !== null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Ce que le VISITEUR peut faire sur cette fiche. Sert à masquer
            // les boutons inutiles ; l'autorisation réelle est refaite à
            // chaque requête par la Policy.
            'permissions' => $this->when($viewer !== null, fn () => [
                'update' => $viewer->can('update', $this->resource),
                'update_status' => $viewer->can('updateStatus', $this->resource),
                'update_role' => $viewer->can('updateRole', $this->resource),
                'manage_qr' => $viewer->can('manageQrCode', $this->resource),
                'delete' => $viewer->can('delete', $this->resource),
            ]),
        ];
    }
}
