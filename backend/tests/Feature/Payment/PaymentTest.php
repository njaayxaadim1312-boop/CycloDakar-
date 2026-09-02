<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\ParticipationMemberStatus;
use App\Enums\ParticipationStatus;
use App\Enums\TransactionDirection;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Encaissements.
 *
 * Ce qui est éprouvé ici n'est pas du confort d'affichage : c'est l'argent du
 * club. Cinq propriétés doivent tenir, et chacune correspond à une manière
 * concrète de perdre de l'argent ou la confiance des membres.
 *
 * 1. **L'idempotence** — un réseau capricieux ne doit pas débiter deux fois.
 * 2. **Le plafond du reste dû** — une faute de frappe ne doit pas encaisser
 *    50 000 FCFA pour une dette de 5 000.
 * 3. **L'autorisation par ligne** — un collecteur n'encaisse que sur son
 *    terrain, et n'annule jamais ce qu'il a encaissé.
 * 4. **La dérivation** — `paid_amount`, `status` et le solde ne viennent
 *    jamais du client.
 * 5. **L'immuabilité** — on corrige par contre-passation, jamais en effaçant.
 */
final class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sans caisse par défaut, aucun encaissement n'est possible : c'est le
        // socle, pas une donnée de confort.
        $this->seed(FinanceSeeder::class);
    }

    /* ---------------------------------------------------------------------- */
    /* Outillage                                                              */
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
     * Une collecte ouverte, un membre qui doit 5 000 FCFA, un collecteur
     * assigné à cette ligne.
     *
     * @return array{0: Participation, 1: ParticipationMember, 2: User}
     */
    private function scenario(int $expected = 5_000): array
    {
        $tresorier = $this->user(UserRole::Treasurer);
        $collecteur = $this->user(UserRole::Collector);

        $participation = Participation::factory()->create([
            'status' => ParticipationStatus::Open,
            'expected_amount' => $expected,
            'created_by' => $tresorier->id,
        ]);

        $ligne = ParticipationMember::factory()->create([
            'participation_id' => $participation->id,
            'member_id' => Member::factory()->create()->id,
            'expected_amount' => $expected,
            'assigned_collector_id' => $collecteur->id,
        ]);

        return [$participation, $ligne, $collecteur];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(ParticipationMember $ligne, array $overrides = []): array
    {
        return array_merge([
            'member' => $ligne->member->uuid,
            'amount' => 2_000,
            'method' => 'CASH',
            'idempotency_key' => 'cle-'.uniqid(),
        ], $overrides);
    }

    private function balance(): int
    {
        return CashAccount::default()->current_balance;
    }

    /* ---------------------------------------------------------------------- */
    /* Le chemin normal                                                       */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_collecteur_assigne_encaisse_et_la_caisse_bouge(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $reponse = $this->actingAs_($collecteur)
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", $this->payload($ligne));

        $reponse->assertCreated()
            ->assertJsonPath('data.amount', 2_000)
            ->assertJsonPath('data.method', 'CASH')
            ->assertJsonPath('meta.replayed', false)
            // Le collecteur doit voir immédiatement ce qu'il reste à percevoir.
            ->assertJsonPath('meta.line.remaining_amount', 3_000)
            ->assertJsonPath('meta.line.status', ParticipationMemberStatus::Partial->value);

        // Le numéro de reçu est réel, pas un identifiant technique.
        $this->assertMatchesRegularExpression(
            '/^RC-\d{4}-\d{6}$/',
            $reponse->json('data.receipt_number'),
        );

        $this->assertSame(2_000, $this->balance());

        // Une écriture au grand livre, du bon sens, avec le solde figé.
        $ecriture = FinancialTransaction::query()->latest('id')->firstOrFail();
        $this->assertSame(TransactionDirection::In, $ecriture->direction);
        $this->assertSame(2_000, $ecriture->amount);
        $this->assertSame(2_000, $ecriture->balance_after);

        // La dette est recalculée, jamais reçue du client.
        $this->assertSame(2_000, $ligne->fresh()->paid_amount);
        $this->assertNotNull($ligne->fresh()->last_payment_at);
    }

    #[Test]
    public function le_solde_complet_fait_passer_la_dette_en_paye(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $this->actingAs_($collecteur)
            ->postJson(
                "/api/v1/participations/{$participation->uuid}/payments",
                $this->payload($ligne, ['amount' => 5_000]),
            )
            ->assertCreated();

        $this->assertSame(
            ParticipationMemberStatus::Paid,
            $ligne->fresh()->status,
        );
    }

    #[Test]
    public function un_membre_deja_a_jour_ne_peut_plus_rien_verser(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            $this->payload($ligne, ['amount' => 5_000]),
        )->assertCreated();

        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            $this->payload($ligne, ['amount' => 1_000]),
        )->assertStatus(422)->assertJsonPath('code', 'PAYMENT_REFUSED');

        $this->assertSame(5_000, $this->balance());
    }

    #[Test]
    public function les_numeros_de_recu_se_suivent(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $premier = $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            $this->payload($ligne, ['amount' => 1_000]),
        )->json('data.receipt_number');

        $second = $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            $this->payload($ligne, ['amount' => 1_000]),
        )->json('data.receipt_number');

        $annee = now()->year;
        $this->assertSame("RC-{$annee}-000001", $premier);
        $this->assertSame("RC-{$annee}-000002", $second);
    }

    /* ---------------------------------------------------------------------- */
    /* 1. Idempotence — la protection la plus importante                      */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function rejouer_la_meme_cle_ne_debite_pas_deux_fois(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $corps = $this->payload($ligne, ['idempotency_key' => 'reseau-capricieux-001']);
        $url = "/api/v1/participations/{$participation->uuid}/payments";

        $premier = $this->actingAs_($collecteur)->postJson($url, $corps)->assertCreated();

        // Le téléphone n'a pas reçu la réponse et réessaie.
        $second = $this->actingAs_($collecteur)->postJson($url, $corps)
            ->assertOk()
            ->assertJsonPath('meta.replayed', true);

        // Le MÊME reçu, et un seul paiement en base.
        $this->assertSame(
            $premier->json('data.uuid'),
            $second->json('data.uuid'),
        );
        $this->assertSame(1, Payment::count());
        $this->assertSame(2_000, $this->balance());
        $this->assertSame(1, FinancialTransaction::count());
    }

    #[Test]
    public function deux_versements_distincts_du_meme_montant_passent_tous_les_deux(): void
    {
        // Un membre qui paie deux fois 1 000 FCFA le même jour est parfaitement
        // légitime. Ce qui distingue ce cas d'un rejeu, c'est la CLÉ, et rien
        // d'autre : confondre les deux refuserait un paiement réel.
        [$participation, $ligne, $collecteur] = $this->scenario();

        foreach (['premiere-cle-xx', 'seconde-cle-xxx'] as $cle) {
            $this->actingAs_($collecteur)->postJson(
                "/api/v1/participations/{$participation->uuid}/payments",
                $this->payload($ligne, ['amount' => 1_000, 'idempotency_key' => $cle]),
            )->assertCreated();
        }

        $this->assertSame(2, Payment::count());
        $this->assertSame(2_000, $this->balance());
    }

    #[Test]
    public function la_cle_d_idempotence_est_obligatoire(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $corps = $this->payload($ligne);
        unset($corps['idempotency_key']);

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", $corps)
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    }

    /* ---------------------------------------------------------------------- */
    /* 2. Le plafond du reste dû                                              */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_montant_superieur_au_reste_du_est_refuse_et_rien_n_est_ecrit(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $this->actingAs_($collecteur)
            ->postJson(
                "/api/v1/participations/{$participation->uuid}/payments",
                $this->payload($ligne, ['amount' => 50_000]),
            )
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_REFUSED');

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, FinancialTransaction::count());
        $this->assertSame(0, $this->balance());
    }

    #[Test]
    public function un_montant_decimal_est_refuse_et_non_arrondi(): void
    {
        // Règle I5 : aucun flottant ne touche l'argent, à aucun étage.
        [$participation, $ligne, $collecteur] = $this->scenario();

        $this->actingAs_($collecteur)
            ->postJson(
                "/api/v1/participations/{$participation->uuid}/payments",
                $this->payload($ligne, ['amount' => 2_000.75]),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    #[Test]
    public function une_date_d_encaissement_dans_le_futur_est_refusee(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $this->actingAs_($collecteur)
            ->postJson(
                "/api/v1/participations/{$participation->uuid}/payments",
                $this->payload($ligne, ['paid_on' => now()->addDay()->toDateString()]),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('paid_on');
    }

    /* ---------------------------------------------------------------------- */
    /* 3. Autorisations                                                       */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_collecteur_non_assigne_ne_peut_pas_encaisser(): void
    {
        [$participation, $ligne] = $this->scenario();

        $etranger = $this->user(UserRole::Collector);

        $this->actingAs_($etranger)
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", $this->payload($ligne))
            ->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function le_tresorier_encaisse_partout(): void
    {
        [$participation, $ligne] = $this->scenario();

        $tresorier = $this->user(UserRole::Treasurer);

        $this->actingAs_($tresorier)
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", $this->payload($ligne))
            ->assertCreated();
    }

    #[Test]
    public function un_simple_membre_ne_peut_pas_encaisser(): void
    {
        [$participation, $ligne] = $this->scenario();

        $this->actingAs_($this->user(UserRole::Member))
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", $this->payload($ligne))
            ->assertForbidden();
    }

    #[Test]
    public function le_collecteur_est_pris_dans_la_session_pas_dans_la_requete(): void
    {
        // Règle I3. Un client qui désigne quelqu'un d'autre comme collecteur
        // doit être ignoré, sans quoi le rapport « collectes par collecteur »
        // — le contrôle contre le détournement — ne vaudrait rien.
        [$participation, $ligne, $collecteur] = $this->scenario();

        $autre = $this->user(UserRole::Collector);

        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            $this->payload($ligne, ['collected_by' => $autre->id]),
        )->assertCreated();

        $this->assertSame($collecteur->id, Payment::firstOrFail()->collected_by);
    }

    #[Test]
    public function le_statut_et_le_montant_paye_ne_sont_jamais_recus_du_client(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();

        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            $this->payload($ligne, [
                'amount' => 1_000,
                'paid_amount' => 5_000,
                'status' => ParticipationMemberStatus::Paid->value,
            ]),
        )->assertCreated();

        $fraiche = $ligne->fresh();
        $this->assertSame(1_000, $fraiche->paid_amount);
        $this->assertSame(ParticipationMemberStatus::Partial, $fraiche->status);
    }

    /* ---------------------------------------------------------------------- */
    /* L'état de la collecte                                                  */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function on_n_encaisse_pas_sur_une_collecte_en_brouillon(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();
        $participation->update(['status' => ParticipationStatus::Draft]);

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", $this->payload($ligne))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_REFUSED');
    }

    #[Test]
    public function on_n_encaisse_pas_sur_une_collecte_cloturee(): void
    {
        // Les comptes ont été arrêtés : une recette ajoutée après coup
        // fausserait un rapport peut-être déjà présenté en assemblée.
        [$participation, $ligne, $collecteur] = $this->scenario();
        $participation->update(['status' => ParticipationStatus::Closed]);

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", $this->payload($ligne))
            ->assertStatus(422);
    }

    #[Test]
    public function un_membre_dispense_ne_doit_plus_rien(): void
    {
        [$participation, $ligne, $collecteur] = $this->scenario();
        $ligne->update(['status' => ParticipationMemberStatus::Cancelled]);

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", $this->payload($ligne))
            ->assertStatus(422);
    }

    #[Test]
    public function un_membre_etranger_a_la_collecte_donne_404(): void
    {
        [$participation, , $collecteur] = $this->scenario();

        $etranger = Member::factory()->create();

        $this->actingAs_($collecteur)
            ->postJson("/api/v1/participations/{$participation->uuid}/payments", [
                'member' => $etranger->uuid,
                'amount' => 1_000,
                'method' => 'CASH',
                'idempotency_key' => 'cle-etrangere-1',
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'LINE_NOT_FOUND');
    }

    /* ---------------------------------------------------------------------- */
    /* Ce qu'un membre doit, vu par un collecteur — le sens du scan QR        */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function le_collecteur_voit_ce_que_le_membre_doit_apres_un_scan(): void
    {
        [, $ligne, $collecteur] = $this->scenario();

        $reponse = $this->actingAs_($collecteur)
            ->getJson("/api/v1/members/{$ligne->member->uuid}/dues")
            ->assertOk()
            ->assertJsonPath('meta.remaining_amount', 5_000)
            ->assertJsonPath('data.0.can_pay', true);

        $this->assertCount(1, $reponse->json('data'));
    }

    #[Test]
    public function une_dette_confiee_a_un_autre_collecteur_est_visible_mais_pas_encaissable(): void
    {
        // Le droit vient du SERVEUR, ligne par ligne. La ligne reste visible —
        // un collecteur peut avoir besoin de renseigner un membre — mais
        // `can_pay` dit clairement qu'il n'a pas a encaisser celle-ci.
        [, $ligne] = $this->scenario();

        $etranger = $this->user(UserRole::Collector);

        $this->actingAs_($etranger)
            ->getJson("/api/v1/members/{$ligne->member->uuid}/dues")
            ->assertOk()
            ->assertJsonPath('data.0.can_pay', false);
    }

    #[Test]
    public function une_collecte_non_ouverte_n_apparait_pas_dans_les_dettes(): void
    {
        // Un collecteur n'a que faire de l'historique : il a besoin de savoir
        // quoi demander MAINTENANT.
        [$participation, $ligne, $collecteur] = $this->scenario();
        $participation->update(['status' => ParticipationStatus::Closed]);

        $this->actingAs_($collecteur)
            ->getJson("/api/v1/members/{$ligne->member->uuid}/dues")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.remaining_amount', 0);
    }

    #[Test]
    public function un_simple_membre_ne_consulte_pas_les_dettes_des_autres(): void
    {
        [, $ligne] = $this->scenario();

        $this->actingAs_($this->user(UserRole::Member))
            ->getJson("/api/v1/members/{$ligne->member->uuid}/dues")
            ->assertForbidden();
    }
}
