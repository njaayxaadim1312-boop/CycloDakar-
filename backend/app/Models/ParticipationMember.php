<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParticipationMemberStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /* ---------------------------------------------------------------------- */

    /** Ce qu'il reste à percevoir. Jamais négatif. */
    public function remaining(): int
    {
        return max(0, $this->expected_amount - $this->paid_amount);
    }

    /**
     * Recalcule le montant encaissé et le statut depuis les paiements réels.
     *
     * PHASE 12 — la somme viendra de la table `payments`, filtrée sur les
     * paiements non annulés. D'ici là il n'existe aucun paiement, donc le
     * total vaut zéro, et c'est la vérité : le module d'encaissement n'est pas
     * livré. On ne simule pas de montants pour faire joli.
     *
     * La méthode existe dès maintenant pour que le statut n'ait, dès le
     * premier jour, qu'UN SEUL chemin d'écriture.
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
        ]);
    }

    /**
     * Somme réellement encaissée sur cette ligne.
     *
     * PHASE 12 : `$this->payments()->whereNull('cancelled_at')->sum('amount')`.
     */
    private function paidToDate(): int
    {
        return 0;
    }
}
