<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ParticipationMember;
use App\Models\Payment;
use App\Models\User;

/**
 * Qui encaisse, qui annule, qui lit.
 *
 * DEUX ASYMÉTRIES VOULUES
 *
 * **Encaisser est plus large qu'annuler.** Un collecteur encaisse — c'est son
 * métier, sur la route, souvent hors réseau. Il n'annule pas : une annulation
 * fait ressortir de l'argent de la caisse, et celui qui a encaissé ne peut pas
 * être celui qui efface. C'est le contrôle élémentaire contre le
 * détournement — encaisser 5 000 FCFA, les garder, annuler le paiement.
 * Réservé au trésorier et à l'administration.
 *
 * **Un collecteur ne peut encaisser que SES lignes.** `assigned_collector_id`
 * délimite son terrain. Sans cela, n'importe quel collecteur pourrait saisir
 * des paiements sur toute la base, et le rapport « collectes par collecteur »
 * — le contrôle prévu par `docs/finance.md` §6 — ne voudrait plus rien dire.
 * Le trésorier, lui, passe partout : c'est lui qui rattrape les absences.
 *
 * Un membre voit ses propres paiements, et rien d'autre. Un reçu est une pièce
 * personnelle ; la liste des versements du club n'est pas un annuaire.
 */
final class PaymentPolicy
{
    /** Consulter le journal des encaissements. */
    public function viewAny(User $user): bool
    {
        return $user->role->canCollect();
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($user->role->canManageFinance()) {
            return true;
        }

        // Le collecteur qui a encaissé retrouve son propre reçu — il peut
        // avoir à le représenter à un membre qui conteste.
        if ($payment->collected_by === $user->id) {
            return true;
        }

        return $this->belongsToUser($user, $payment->member_id);
    }

    /**
     * Enregistrer un encaissement sur cette ligne de dette.
     *
     * L'autorisation porte sur la LIGNE, pas sur la collecte : c'est la ligne
     * qui désigne le collecteur responsable.
     */
    public function create(User $user, ParticipationMember $line): bool
    {
        if ($user->role->canManageFinance()) {
            return true;
        }

        if (! $user->role->canCollect()) {
            return false;
        }

        return $line->assigned_collector_id === $user->id;
    }

    /** Annuler. Trésorier et administration seulement — voir l'en-tête. */
    public function cancel(User $user, Payment $payment): bool
    {
        return $user->role->canManageFinance();
    }

    /** Voir le solde de la caisse. */
    public function viewBalance(User $user): bool
    {
        if ($user->role->canManageFinance()) {
            return true;
        }

        // Certains clubs choisissent la transparence : le solde est alors
        // visible de tous les membres. C'est un réglage, pas un défaut.
        return (bool) config('cyclo.finance.public_balance');
    }

    private function belongsToUser(User $user, int $memberId): bool
    {
        return $user->member?->id === $memberId;
    }
}
