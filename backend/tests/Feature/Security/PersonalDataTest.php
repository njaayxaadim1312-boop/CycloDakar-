<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\ActivityStatus;
use App\Enums\ParticipationStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\CashAccount;
use App\Models\Member;
use App\Models\Participation;
use App\Models\ParticipationMember;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DONNÉES PERSONNELLES : EMPORTER LES SIENNES, FAIRE EFFACER LES SIENNES.
 *
 * LA LIGNE DE PARTAGE EST CE QUI SE JOUE ICI.
 *
 * Un membre peut faire effacer ce qui ne concerne que lui — ses traces GPS, qui
 * révèlent son domicile et ses horaires ; sa photo ; son téléphone.
 *
 * Il ne peut pas faire effacer les écritures comptables auxquelles il a
 * participé. Un encaissement de 5 000 FCFA engage la caisse du club et figure
 * dans un rapport peut-être déjà présenté en assemblée : le supprimer rendrait
 * ce rapport faux, et la règle I2 l'interdit de toute façon.
 *
 * Ces lignes sont donc ANONYMISÉES. Le montant reste, le nom disparaît. La
 * comptabilité demeure juste, et le membre n'y est plus identifiable.
 */
final class PersonalDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceSeeder::class);
    }

    private function actingAs_(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader(
                'Authorization',
                'Bearer '.$user->createToken('Test')->plainTextToken,
            );
    }

    /**
     * Un membre avec une trace GPS, une dette et un encaissement.
     *
     * @return array{0: User, 1: Member}
     */
    private function membreComplet(): array
    {
        // Un numéro distinct à chaque appel : `members.phone` est UNIQUE, et
        // deux membres fabriqués dans le même test se heurteraient — un
        // échec de fixture qu'on prendrait pour un échec de la règle testée.
        static $rang = 0;
        $rang++;

        $user = User::factory()->create([
            'role' => UserRole::Member,
            'name' => 'Khadim Ndiaye',
            'password' => Hash::make('cyclo2026'),
        ]);

        $member = Member::factory()->for($user)->create([
            'first_name' => 'Khadim',
            'last_name' => 'Ndiaye',
            'phone' => '+22177000'.str_pad((string) $rang, 4, '0', STR_PAD_LEFT),
            'emergency_contact_name' => 'Aminata Ndiaye',
        ]);

        $activite = Activity::factory()->create([
            'member_id' => $member->id,
            'status' => ActivityStatus::Completed,
            'distance_m' => 24_000,
        ]);

        // Deux points bruts : ce sont eux qui portent la position.
        DB::table('activity_points')->insert([
            [
                'activity_id' => $activite->id, 'seq' => 0,
                'lat' => 14.6928, 'lng' => -17.4467,
                'recorded_at' => now()->subHour(),
            ],
            [
                'activity_id' => $activite->id, 'seq' => 1,
                'lat' => 14.6930, 'lng' => -17.4470,
                'recorded_at' => now()->subHour()->addMinute(),
            ],
        ]);

        $collecteur = User::factory()->create(['role' => UserRole::Collector]);
        Member::factory()->for($collecteur)->create();

        $participation = Participation::factory()->create([
            'status' => ParticipationStatus::Open,
            'expected_amount' => 5_000,
            'created_by' => $collecteur->id,
        ]);

        $ligne = ParticipationMember::factory()->create([
            'participation_id' => $participation->id,
            'member_id' => $member->id,
            'expected_amount' => 5_000,
            'assigned_collector_id' => $collecteur->id,
        ]);

        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$participation->uuid}/payments",
            [
                'member' => $member->uuid,
                'amount' => 5_000,
                'method' => 'CASH',
                'idempotency_key' => 'rgpd-test-'.$rang,
                'note' => 'Remis en main propre devant chez lui',
            ],
        )->assertCreated();

        return [$user, $member->fresh()];
    }

    /* ---------------------------------------------------------------------- */
    /* L'export                                                               */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_membre_emporte_toutes_ses_donnees(): void
    {
        [$user] = $this->membreComplet();

        $reponse = $this->actingAs_($user)
            ->get('/api/v1/me/export')
            ->assertOk();

        $this->assertStringContainsString(
            'attachment;',
            (string) $reponse->headers->get('content-disposition'),
        );

        /** @var array<string, mixed> $contenu */
        $contenu = json_decode($reponse->streamedContent(), true);

        $this->assertSame('Khadim Ndiaye', $contenu['compte']['nom']);
        $this->assertSame('Khadim', $contenu['fiche_club']['prenom']);
        $this->assertSame('Aminata Ndiaye', $contenu['fiche_club']['contact_urgence']['nom']);

        // Ses sorties, avec la trace : ce sont SES données, et un export qui
        // n'en donnerait que le résumé ne lui permettrait pas de les reprendre
        // ailleurs.
        $this->assertCount(1, $contenu['sorties']);
        $this->assertSame(24_000, $contenu['sorties'][0]['distance_m']);

        $this->assertCount(1, $contenu['paiements']);
        $this->assertSame(5_000, $contenu['paiements'][0]['montant_fcfa']);

        // L'export DIT ce qu'il ne peut pas effacer, plutôt que de le taire.
        $this->assertStringContainsString('comptables', $contenu['export']['a_propos']);
    }

    #[Test]
    public function l_export_ne_montre_que_soi(): void
    {
        // La route ne prend aucun identifiant : c'est structurel, mais on
        // vérifie le comportement — un export RGPD qui fuiterait l'annuaire
        // serait la pire fuite possible, tout y est.
        [$user] = $this->membreComplet();
        [$autre] = $this->membreComplet();

        $contenu = json_decode(
            $this->actingAs_($user)->get('/api/v1/me/export')->streamedContent(),
            true,
        );

        $this->assertSame($user->email, $contenu['compte']['email']);
        $this->assertNotSame($autre->email, $contenu['compte']['email']);
        $this->assertCount(1, $contenu['sorties']);
    }

    /* ---------------------------------------------------------------------- */
    /* L'effacement                                                           */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function la_suppression_efface_les_traces_gps_et_anonymise_la_comptabilite(): void
    {
        [$user, $member] = $this->membreComplet();

        $soldeAvant = CashAccount::default()->current_balance;

        $this->actingAs_($user)
            ->deleteJson('/api/v1/me', [
                'password' => 'cyclo2026',
                'confirmation' => 'SUPPRIMER',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.details.sorties_supprimees', 1);

        // LES TRACES SONT PARTIES. Elles révèlent le domicile et les horaires :
        // c'est la donnée la plus sensible que le club détienne.
        $this->assertSame(0, Activity::query()->where('member_id', $member->id)->count());
        $this->assertSame(0, DB::table('activity_points')->count());

        // LA COMPTABILITÉ EST INTACTE. Le paiement existe toujours, avec son
        // montant : il engage la caisse du club.
        $this->assertSame(1, Payment::count());
        $this->assertSame(5_000, Payment::firstOrFail()->amount);
        $this->assertSame($soldeAvant, CashAccount::default()->current_balance);

        // Mais elle ne dit plus qui. La note libre, qui pouvait porter un
        // détail personnel, a disparu.
        $this->assertNull(Payment::firstOrFail()->note);

        $fiche = $member->fresh();
        $this->assertSame('Membre', $fiche->first_name);
        $this->assertSame('supprimé', $fiche->last_name);
        $this->assertNull($fiche->phone);
        $this->assertNull($fiche->emergency_contact_name);

        // Le matricule reste : il relie une écriture à une ligne, et ne dit
        // rien de la personne.
        $this->assertNotNull($fiche->matricule);

        // Le compte n'ouvre plus rien.
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotNull($user->fresh()->deleted_at);
    }

    #[Test]
    public function le_qr_code_est_revoque_a_la_suppression(): void
    {
        // Une carte imprimée ne doit plus rien ouvrir : sinon, un QR retrouvé
        // dans un tiroir désignerait encore une fiche.
        [$user, $member] = $this->membreComplet();

        $ancienJeton = $member->qr_token;

        $this->actingAs_($user)->deleteJson('/api/v1/me', [
            'password' => 'cyclo2026',
            'confirmation' => 'SUPPRIMER',
        ])->assertOk();

        $this->assertNotSame($ancienJeton, $member->fresh()->qr_token);
    }

    #[Test]
    public function la_suppression_exige_le_mot_de_passe(): void
    {
        /*
         | C'est irréversible, et c'est le seul endroit de l'application où un
         | téléphone laissé déverrouillé sur une table permettrait de détruire
         | un compte en deux appuis. Le mot de passe est la seule chose qu'un
         | passant n'a pas.
         */
        [$user, $member] = $this->membreComplet();

        $this->actingAs_($user)
            ->deleteJson('/api/v1/me', [
                'password' => 'mauvais',
                'confirmation' => 'SUPPRIMER',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_PASSWORD');

        $this->assertNull($user->fresh()->deleted_at);
        $this->assertSame(1, Activity::query()->where('member_id', $member->id)->count());
    }

    #[Test]
    public function la_suppression_exige_une_confirmation_ecrite(): void
    {
        // Un simple bouton se clique par erreur ; « SUPPRIMER » se tape
        // volontairement.
        [$user] = $this->membreComplet();

        $this->actingAs_($user)
            ->deleteJson('/api/v1/me', ['password' => 'cyclo2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation');

        $this->actingAs_($user)
            ->deleteJson('/api/v1/me', [
                'password' => 'cyclo2026',
                'confirmation' => 'supprimer',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation');
    }

    #[Test]
    public function le_journal_d_audit_garde_la_trace_des_operations_du_compte_supprime(): void
    {
        /*
         | Le compte part en suppression douce, pas en effacement franc.
         |
         | `audit_logs.user_id` référence les utilisateurs : un effacement
         | complet ferait disparaître l'auteur d'opérations financières, et le
         | journal ne dirait plus qui a fait quoi. Or c'est précisément le
         | document qu'on consulte quand quelque chose cloche.
         |
         | L'identité, elle, est bien effacée : ne reste qu'une adresse
         | neutralisée.
         */
        [$user] = $this->membreComplet();

        $tracesAvant = DB::table('audit_logs')->count();

        $this->actingAs_($user)->deleteJson('/api/v1/me', [
            'password' => 'cyclo2026',
            'confirmation' => 'SUPPRIMER',
        ])->assertOk();

        $this->assertSame($tracesAvant, DB::table('audit_logs')->count());

        $supprime = User::withTrashed()->findOrFail($user->id);
        $this->assertSame('Compte supprimé', $supprime->name);
        $this->assertStringEndsWith('@cyclodakar.invalid', (string) $supprime->email);
    }
}
