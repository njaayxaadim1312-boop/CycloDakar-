<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\ParticipationMemberStatus;
use App\Enums\ParticipationStatus;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\UserRole;
use App\Models\CashAccount;
use App\Models\FinancialTransaction;
use App\Models\Member;
use App\Models\Participation;
use App\Models\ParticipationMember;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Intégrité comptable : les invariants de `docs/finance.md`.
 *
 * Ce fichier n'éprouve pas des fonctionnalités mais des IMPOSSIBILITÉS. Chaque
 * test décrit une manière de falsifier la comptabilité du club, et vérifie
 * qu'elle est fermée :
 *
 *  - I1 — le solde est dérivé : aucune route ne l'accepte, et un écart du
 *    cache est détecté et signalé ;
 *  - I2 — les écritures sont immuables : ni mise à jour, ni suppression. Une
 *    erreur se corrige par contre-passation, et le journal garde les deux
 *    lignes ;
 *  - I3 — le client n'est pas la source de vérité ;
 *  - I5 — l'argent est en entiers.
 *
 * Un module financier se juge à ce qu'il refuse, pas à ce qu'il permet.
 */
final class CashIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceSeeder::class);
    }

    /* ---------------------------------------------------------------------- */

    private function actingAs_(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader(
                'Authorization',
                'Bearer '.$user->createToken('Test')->plainTextToken,
            );
    }

    private function user(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Member::factory()->for($user)->create();

        return $user;
    }

    /**
     * Une collecte ouverte avec un paiement de 2 000 FCFA déjà encaissé.
     *
     * @return array{0: Payment, 1: ParticipationMember, 2: User, 3: Participation}
     */
    private function encaissement(int $montant = 2_000): array
    {
        $collecteur = $this->user(UserRole::Collector);

        $participation = Participation::factory()->create([
            'status' => ParticipationStatus::Open,
            'expected_amount' => 5_000,
            'created_by' => $this->user(UserRole::Treasurer)->id,
        ]);

        $ligne = ParticipationMember::factory()->create([
            'participation_id' => $participation->id,
            'member_id' => Member::factory()->create()->id,
            'expected_amount' => 5_000,
            'assigned_collector_id' => $collecteur->id,
        ]);

        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            [
                'member' => $ligne->member->uuid,
                'amount' => $montant,
                'method' => 'WAVE',
                'reference' => 'WV-123456',
                'idempotency_key' => 'cle-'.uniqid(),
            ],
        )->assertCreated();

        return [Payment::firstOrFail(), $ligne->fresh(), $collecteur, $participation];
    }

    /* ---------------------------------------------------------------------- */
    /* I2 — l'annulation passe par une contre-passation                       */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function annuler_ecrit_une_contre_passation_et_ne_supprime_rien(): void
    {
        [$paiement, $ligne] = $this->encaissement();

        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", [
                'reason' => 'Saisi deux fois lors de la sortie du 14 septembre.',
            ])
            ->assertOk()
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('meta.line.paid_amount', 0)
            ->assertJsonPath('meta.line.status', ParticipationMemberStatus::Unpaid->value);

        // Le paiement EXISTE toujours : un membre qui se présente avec son
        // reçu doit le retrouver, marqué annulé.
        $this->assertSame(1, Payment::count());
        $this->assertNotNull($paiement->fresh()->cancelled_at);
        $this->assertSame(2_000, $paiement->fresh()->amount);

        // Le journal montre les DEUX lignes.
        $this->assertSame(2, FinancialTransaction::count());

        $contrepassation = FinancialTransaction::query()
            ->where('source_type', TransactionSource::Reversal)
            ->firstOrFail();

        $this->assertSame(TransactionDirection::Out, $contrepassation->direction);
        $this->assertSame(2_000, $contrepassation->amount);
        $this->assertSame(0, $contrepassation->balance_after);
        $this->assertNotNull($contrepassation->reverses_transaction_id);

        // Et le solde est revenu à zéro, sans qu'aucune ligne ait disparu.
        $this->assertSame(0, CashAccount::default()->current_balance);
        $this->assertSame(0, CashAccount::default()->derivedBalance());
        $this->assertSame(0, $ligne->fresh()->paid_amount);
        $this->assertNull($ligne->fresh()->last_payment_at);
    }

    #[Test]
    public function un_collecteur_ne_peut_pas_annuler_ce_qu_il_a_encaisse(): void
    {
        // Le contrôle élémentaire contre le détournement : encaisser 5 000
        // FCFA, les garder, annuler le paiement.
        [$paiement, , $collecteur] = $this->encaissement();

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", [
                'reason' => 'Je préfère effacer cette opération.',
            ])
            ->assertForbidden();

        $this->assertNull($paiement->fresh()->cancelled_at);
        $this->assertSame(2_000, CashAccount::default()->current_balance);
    }

    #[Test]
    public function le_motif_d_annulation_est_obligatoire_et_consistant(): void
    {
        [$paiement] = $this->encaissement();
        $tresorier = $this->user(UserRole::Treasurer);

        $this->actingAs_($tresorier)
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // « erreur » n'explique rien et ne se vérifie pas en assemblée.
        $this->actingAs_($tresorier)
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", ['reason' => 'erreur'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function une_annulation_ne_se_repete_pas(): void
    {
        // Sans ce garde-fou, la seconde annulation enverrait le solde à
        // l'envers d'un montant complet.
        [$paiement] = $this->encaissement();
        $tresorier = $this->user(UserRole::Treasurer);
        $corps = ['reason' => 'Doublon constaté au pointage du soir.'];

        $this->actingAs_($tresorier)
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", $corps)->assertOk();

        $this->actingAs_($tresorier)
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", $corps)
            ->assertStatus(422)
            ->assertJsonPath('code', 'CANCEL_REFUSED');

        $this->assertSame(2, FinancialTransaction::count());
        $this->assertSame(0, CashAccount::default()->current_balance);
    }

    #[Test]
    public function apres_annulation_le_membre_peut_de_nouveau_payer(): void
    {
        [$paiement, $ligne, $collecteur, $participation] = $this->encaissement(5_000);

        $this->assertSame(ParticipationMemberStatus::Paid, $ligne->fresh()->status);

        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", [
                'reason' => 'Chèque revenu impayé, la somme ressort de la caisse.',
            ])->assertOk();

        // La dette est de nouveau ouverte : c'est bien le montant DÉRIVÉ des
        // paiements valides qui commande, pas un compteur incrémenté.
        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            [
                'member' => $ligne->member->uuid,
                'amount' => 5_000,
                'method' => 'CASH',
                'idempotency_key' => 'apres-annulation-1',
            ],
        )->assertCreated();

        $this->assertSame(5_000, CashAccount::default()->current_balance);
        $this->assertSame(5_000, $ligne->fresh()->paid_amount);
    }

    /* ---------------------------------------------------------------------- */
    /* I2 — l'immuabilité est exécutable, pas seulement documentée            */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_ecriture_du_grand_livre_ne_peut_pas_etre_modifiee(): void
    {
        [$paiement] = $this->encaissement();

        $this->expectException(LogicException::class);

        $paiement->transaction->update(['amount' => 999_999]);
    }

    #[Test]
    public function une_ecriture_du_grand_livre_ne_peut_pas_etre_supprimee(): void
    {
        [$paiement] = $this->encaissement();

        $this->expectException(LogicException::class);

        $paiement->transaction->delete();
    }

    #[Test]
    public function le_montant_d_un_encaissement_ne_peut_pas_etre_reecrit(): void
    {
        [$paiement] = $this->encaissement();

        $this->expectException(LogicException::class);

        $paiement->update(['amount' => 100]);
    }

    #[Test]
    public function un_encaissement_ne_peut_pas_etre_supprime(): void
    {
        [$paiement] = $this->encaissement();

        $this->expectException(LogicException::class);

        $paiement->delete();
    }

    /* ---------------------------------------------------------------------- */
    /* I1 — le solde est dérivé                                               */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function aucune_route_n_accepte_un_solde(): void
    {
        $tresorier = $this->user(UserRole::Treasurer);

        foreach ([['put', '/api/v1/finance/balance'], ['post', '/api/v1/finance/balance']] as [$verbe, $url]) {
            $this->actingAs_($tresorier)->json($verbe, $url, ['balance' => 999_999])
                ->assertNotFound();
        }
    }

    #[Test]
    public function la_commande_de_verification_detecte_un_cache_fausse(): void
    {
        $this->encaissement();

        // On simule ce que la commande existe pour attraper : une écriture
        // passée hors de CashLedger, ou un cache trafiqué à la main.
        DB::table('cash_accounts')->update(['current_balance' => 999_999]);

        $this->artisan('finance:recompute-balance')
            ->assertExitCode(1);

        // Et elle répare quand on le lui demande explicitement.
        $this->artisan('finance:recompute-balance', ['--fix' => true])
            ->assertExitCode(1);

        $this->assertSame(2_000, CashAccount::default()->current_balance);

        // Une fois réparée, elle se tait.
        $this->artisan('finance:recompute-balance')->assertExitCode(0);
    }

    #[Test]
    public function la_suite_des_soldes_figes_se_recompose(): void
    {
        // C'est la colonne « Solde » du journal imprimé en assemblée : si elle
        // ne se recompose pas, le journal est faux même quand le total final
        // est bon.
        [$paiement] = $this->encaissement();

        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", [
                'reason' => 'Régularisation après pointage de la caisse.',
            ])->assertOk();

        $soldes = FinancialTransaction::query()->orderBy('id')->pluck('balance_after')->all();

        $this->assertSame([2_000, 0], $soldes);
        $this->artisan('finance:recompute-balance')->assertExitCode(0);
    }

    #[Test]
    public function le_solde_de_caisse_n_est_pas_visible_d_un_simple_membre(): void
    {
        config(['cyclo.finance.public_balance' => false]);

        $this->actingAs_($this->user(UserRole::Member))
            ->getJson('/api/v1/finance/cash')
            ->assertForbidden();

        $this->actingAs_($this->user(UserRole::Treasurer))
            ->getJson('/api/v1/finance/cash')
            ->assertOk()
            /*
             | Depuis la PHASE 13, le solde est COMPLET : recettes et dépenses
             | passent toutes par le grand livre.
             |
             | Ce champ n'est pas décoratif. En phase 12, il valait `false` et
             | portait la raison : les dépenses n'étaient pas encore saisies, et
             | présenter ce montant comme le solde réel du club aurait trompé le
             | bureau. Il reste exposé pour que ce genre de demi-vérité soit
             | toujours dicible plutôt que tue.
             */
            ->assertJsonPath('data.complete', true)
            ->assertJsonPath('data.incomplete_reason', null);
    }

    /* ---------------------------------------------------------------------- */
    /* Le contrôle contre le détournement                                     */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function le_rapport_par_collecteur_isole_les_annulations(): void
    {
        [$paiement, , $collecteur] = $this->encaissement();

        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", [
                'reason' => 'Somme non retrouvée au pointage du soir.',
            ])->assertOk();

        $reponse = $this->actingAs_($this->user(UserRole::Treasurer))
            ->getJson('/api/v1/finance/collections')
            ->assertOk();

        $ligne = collect($reponse->json('data'))
            ->firstWhere('collector.uuid', $collecteur->uuid);

        // L'annulation ne se cache pas dans le montant encaissé : c'est
        // exactement l'écart qu'on cherche à voir.
        $this->assertSame(0, $ligne['collected_amount']);
        $this->assertSame(0, $ligne['collected_count']);
        $this->assertSame(2_000, $ligne['cancelled_amount']);
        $this->assertSame(1, $ligne['cancelled_count']);
    }

    #[Test]
    public function un_membre_voit_sa_dette_et_ses_recus_et_rien_d_autre(): void
    {
        [, $ligne] = $this->encaissement();

        $proprietaire = User::factory()->create(['role' => UserRole::Member]);
        $ligne->member->forceFill(['user_id' => $proprietaire->id])->save();

        $reponse = $this->actingAs_($proprietaire)
            ->getJson('/api/v1/payments/mine')
            ->assertOk()
            ->assertJsonPath('meta.remaining_amount', 3_000)
            ->assertJsonPath('meta.paid_amount', 2_000);

        $this->assertCount(1, $reponse->json('data.payments'));

        // Un autre membre ne voit rien de tout cela.
        $this->actingAs_($this->user(UserRole::Member))
            ->getJson('/api/v1/payments/mine')
            ->assertOk()
            ->assertJsonPath('meta.remaining_amount', 0);
    }

    #[Test]
    public function chaque_encaissement_laisse_une_trace_d_audit(): void
    {
        [$paiement] = $this->encaissement();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.created',
            'entity_type' => 'Payment',
            'entity_id' => $paiement->id,
        ]);

        $this->actingAs_($this->user(UserRole::Treasurer))
            ->postJson("/api/v1/payments/{$paiement->uuid}/cancel", [
                'reason' => 'Erreur de saisie constatée le lendemain.',
            ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.reversed',
            'entity_id' => $paiement->id,
            'reason' => 'Erreur de saisie constatée le lendemain.',
        ]);
    }
}
