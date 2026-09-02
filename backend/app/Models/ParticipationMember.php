<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParticipationMemberStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une dette : ce qu'un membre doit sur une collecte.
 *
 * Deux invariants gouvernent cette table.
 *
 * **`expected_amount` est figé à l'affectation.** Relever le tarif d'une
 * collecte en cours ne réécrit pas les lignes existantes : sinon un
 * encaissement de 5 000 FCFA apparaîtrait comme partiel sur une dette
 * rétroactivement portée à 7 500.
 *
 * **`paid_amount` et `status` sont DÉRIVÉS.** Ils ne sont jamais reçus du
 * client. `recalculate()` est le seul chemin qui les modifie, et il part
 * toujours de la somme réelle des paiements. Accepter ces champs en entrée
 * reviendrait à laisser quiconque se déclarer à jour de cotisation.
 *
 * @property int $expected_amount
 * @property int $paid_amount
 * @property ParticipationMemberStatus $status
 */
final class ParticipationMember extends Model
{
    /** @use HasFactory<\Database\Factories\ParticipationMemberFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ParticipationMemberStatus::class,
            'expected_amount' => 'integer',
            'paid_amount' => 'integer',
            'last_payment_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------- */

    /** @return BelongsTo<Participation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_collector_id');
    }

    /**
     * Les encaissements portés par cette dette, annulés compris.
     *
     * La relation ne filtre PAS les paiements annulés : un membre qui se
     * présente avec un reçu doit le retrouver, même annulé. C'est
     * `paidToDate()` qui écarte les annulations pour le calcul du montant.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'participation_member_id');
    }

    /* ---------------------------------------------------------------------- */

    /** Ce qu'il reste à percevoir. Jamais négatif. */
    public function remaining(): int
    {
        return max(0, $this->expected_amount - $this->paid_amount);
    }

    /**
     * Recalcule le montant encaissé et le statut depuis les paiements réels.
     *
     * **Seul chemin d'écriture** de `paid_amount` et de `status`. La somme
     * repart toujours de la table des paiements : jamais d'incrément sur
     * l'ancienne valeur. Un incrément se trompe dès qu'une opération est
     * rejouée ou qu'une annulation passe entre-temps, et l'erreur se fige
     * ensuite pour toujours ; un recalcul complet, lui, se corrige tout seul
     * au mouvement suivant.
     */
    public function recalculate(): void
    {
        // Une ligne annulée le reste : un membre dispensé ne redevient pas
        // débiteur parce qu'un paiement a été saisi ailleurs.
        if ($this->status === ParticipationMemberStatus::Cancelled) {
            return;
        }

        $paid = $this->paidToDate();

        $this->update([
            'paid_amount' => $paid,
            'status' => ParticipationMemberStatus::derive($this->expected_amount, $paid),
            // Dérivée elle aussi, et non « estampillée à maintenant » :
            // annuler le dernier paiement doit faire reculer cette date, pas
            // laisser croire à un versement qui n'existe plus.
            'last_payment_at' => $this->payments()
                ->whereNull('cancelled_at')
                ->max('created_at'),
        ]);
    }

    /**
     * Somme réellement encaissée sur cette ligne.
     *
     * Les paiements annulés en sont exclus — ils restent en base, avec leur
     * contre-passation au grand livre, mais ils ne comptent plus dans ce que
     * le membre a versé.
     */
    private function paidToDate(): int
    {
        return (int) $this->payments()->whereNull('cancelled_at')->sum('amount');
    }
}
