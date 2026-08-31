<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Qui peut modifier quoi.
 *
 * Le point le plus sensible du module : attribuer un rôle donne accès à la
 * caisse du club.
 */
final class MemberPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAs_(User $user): static
    {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$user->createToken('Test')->plainTextToken,
        );
    }

    private function memberFor(UserRole $role = UserRole::Member): Member
    {
        $user = User::factory()->role($role)->create();

        return Member::factory()->forUser($user)->create();
    }

    /* ---------------------------------------------------------------------- */
    /* Modification de sa propre fiche                                        */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_membre_peut_modifier_sa_propre_fiche(): void
    {
        $member = $this->memberFor();

        $this->actingAs_($member->user)
            ->postJson("/api/v1/members/{$member->uuid}", [
                'first_name' => 'Awa',
                'last_name' => 'Ndiaye Fall',
            ])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Awa Ndiaye Fall');
    }

    #[Test]
    public function un_membre_ne_peut_pas_modifier_la_fiche_d_un_autre(): void
    {
        $member = $this->memberFor();
        $autre = $this->memberFor();

        $this->actingAs_($member->user)
            ->postJson("/api/v1/members/{$autre->uuid}", ['first_name' => 'Pirate'])
            ->assertStatus(403);
    }

    #[Test]
    public function un_membre_ne_peut_pas_changer_son_propre_statut(): void
    {
        // Sinon un membre suspendu se réactiverait lui-même.
        $member = $this->memberFor();
        $member->forceFill(['status' => MemberStatus::Suspended])->save();

        $this->actingAs_($member->user)
            ->postJson("/api/v1/members/{$member->uuid}", [
                'status' => MemberStatus::Active->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(MemberStatus::Suspended, $member->fresh()->status);
    }

    #[Test]
    public function un_administrateur_peut_changer_le_statut(): void
    {
        $admin = User::factory()->admin()->create();
        Member::factory()->forUser($admin)->create();
        $member = $this->memberFor();

        $this->actingAs_($admin)
            ->postJson("/api/v1/members/{$member->uuid}", [
                'status' => MemberStatus::Suspended->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'SUSPENDED');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member.status_changed',
            'entity_id' => $member->id,
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Création                                                               */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_collecteur_peut_inscrire_quelqu_un_sur_le_terrain(): void
    {
        // Cas réel : un nouveau se présente au départ d'une sortie.
        $collector = User::factory()->collector()->create();

        $this->actingAs_($collector)
            ->postJson('/api/v1/members', [
                'first_name' => 'Ibrahima',
                'last_name' => 'Ba',
                'phone' => '77 987 65 43',
            ])
            ->assertCreated()
            ->assertJsonPath('data.matricule', 'CD-000001')
            ->assertJsonPath('data.has_account', false);

        $this->assertDatabaseHas('members', ['phone' => '779876543']);
    }

    #[Test]
    public function un_simple_membre_ne_peut_pas_creer_de_fiche(): void
    {
        $member = $this->memberFor();

        $this->actingAs_($member->user)
            ->postJson('/api/v1/members', [
                'first_name' => 'Ibrahima',
                'last_name' => 'Ba',
            ])
            ->assertStatus(403);
    }

    /* ---------------------------------------------------------------------- */
    /* Attribution de rôle                                                    */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_administrateur_peut_nommer_un_tresorier(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->memberFor();

        $this->actingAs_($admin)
            ->postJson("/api/v1/members/{$member->uuid}/role", [
                'role' => UserRole::Treasurer->value,
                'reason' => 'Élu trésorier en assemblée générale',
            ])
            ->assertOk()
            ->assertJsonPath('data.account.role', 'TREASURER');

        $this->assertSame(UserRole::Treasurer, $member->user->fresh()->role);
    }

    #[Test]
    public function le_changement_de_role_est_trace_avec_son_motif(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->memberFor();

        $this->actingAs_($admin)
            ->postJson("/api/v1/members/{$member->uuid}/role", [
                'role' => UserRole::Collector->value,
                'reason' => 'Renfort pour la sortie du Lac Rose',
            ])
            ->assertOk();

        $log = DB::table('audit_logs')->where('action', 'member.role_changed')->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Renfort pour la sortie du Lac Rose', $log->reason);
        $this->assertSame('{"role":"MEMBER"}', $log->old_values);
        $this->assertSame('{"role":"COLLECTOR"}', $log->new_values);
    }

    #[Test]
    public function un_changement_de_role_revoque_les_sessions_existantes(): void
    {
        // Les jetons émis portent les capacités de l'ANCIEN rôle. En cas de
        // rétrogradation, l'accès doit tomber tout de suite.
        $admin = User::factory()->admin()->create();
        $member = $this->memberFor(UserRole::Treasurer);
        $ancienJeton = $member->user->createToken('Telephone')->plainTextToken;

        $this->actingAs_($admin)
            ->postJson("/api/v1/members/{$member->uuid}/role", [
                'role' => UserRole::Member->value,
            ])
            ->assertOk();

        $this->assertSame(0, $member->user->fresh()->tokens()->count());

        $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', "Bearer {$ancienJeton}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    #[Test]
    public function un_tresorier_ne_peut_pas_distribuer_les_roles(): void
    {
        $treasurer = User::factory()->treasurer()->create();
        $member = $this->memberFor();

        $this->actingAs_($treasurer)
            ->postJson("/api/v1/members/{$member->uuid}/role", [
                'role' => UserRole::Treasurer->value,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function on_ne_peut_pas_modifier_son_propre_role(): void
    {
        // Ni se promouvoir, ni se dégrader par erreur.
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->forUser($admin)->create();

        // On vise un role AU NIVEAU de l'acteur : sinon c'est la regle
        // « pas plus haut que soi » qui repondrait, et on ne testerait pas
        // l'interdiction de se modifier soi-meme.
        $this->actingAs_($admin)
            ->postJson("/api/v1/members/{$member->uuid}/role", [
                'role' => UserRole::Member->value,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function un_administrateur_ne_peut_pas_nommer_un_super_administrateur(): void
    {
        // On ne nomme pas plus haut que soi : sinon un ADMIN se créerait un
        // complice SUPER_ADMIN, qui le promouvrait ensuite.
        $admin = User::factory()->admin()->create();
        $member = $this->memberFor();

        $this->actingAs_($admin)
            ->postJson("/api/v1/members/{$member->uuid}/role", [
                'role' => UserRole::SuperAdmin->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    #[Test]
    public function un_administrateur_ne_peut_pas_ecarter_un_autre_administrateur(): void
    {
        $admin = User::factory()->admin()->create();
        $autreAdmin = $this->memberFor(UserRole::Admin);

        $this->actingAs_($admin)
            ->postJson("/api/v1/members/{$autreAdmin->uuid}/role", [
                'role' => UserRole::Member->value,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function un_super_administrateur_peut_retrograder_un_administrateur(): void
    {
        $superAdmin = User::factory()->role(UserRole::SuperAdmin)->create();
        $admin = $this->memberFor(UserRole::Admin);

        $this->actingAs_($superAdmin)
            ->postJson("/api/v1/members/{$admin->uuid}/role", [
                'role' => UserRole::Member->value,
                'reason' => 'Fin de mandat',
            ])
            ->assertOk()
            ->assertJsonPath('data.account.role', 'MEMBER');
    }

    #[Test]
    public function un_membre_sans_compte_ne_peut_pas_recevoir_de_role(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->withoutAccount()->create();

        $this->actingAs_($admin)
            ->postJson("/api/v1/members/{$member->uuid}/role", [
                'role' => UserRole::Collector->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MEMBER_HAS_NO_ACCOUNT');
    }

    /* ---------------------------------------------------------------------- */
    /* QR Code et archivage                                                   */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_membre_peut_faire_tourner_son_propre_qr_code(): void
    {
        $member = $this->memberFor();
        $avant = $member->qr_token;

        $this->actingAs_($member->user)
            ->postJson("/api/v1/members/{$member->uuid}/rotate-qr")
            ->assertOk();

        $this->assertNotSame($avant, $member->fresh()->qr_token);
    }

    #[Test]
    public function un_membre_ne_peut_pas_faire_tourner_le_qr_d_un_autre(): void
    {
        $member = $this->memberFor();
        $autre = $this->memberFor();

        $this->actingAs_($member->user)
            ->postJson("/api/v1/members/{$autre->uuid}/rotate-qr")
            ->assertStatus(403);
    }

    #[Test]
    public function l_archivage_desactive_le_compte_sans_le_supprimer(): void
    {
        // Les paiements et activités doivent rester rattachés à quelqu'un.
        $admin = User::factory()->admin()->create();
        $member = $this->memberFor();
        $member->user->createToken('Telephone');

        $this->actingAs_($admin)
            ->deleteJson("/api/v1/members/{$member->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('members', ['id' => $member->id]);
        $this->assertDatabaseHas('users', ['id' => $member->user_id, 'is_active' => false]);
        $this->assertSame(0, $member->user->fresh()->tokens()->count());
    }

    #[Test]
    public function un_administrateur_ne_peut_pas_s_archiver_lui_meme(): void
    {
        $admin = User::factory()->admin()->create();
        $member = Member::factory()->forUser($admin)->create();

        $this->actingAs_($admin)
            ->deleteJson("/api/v1/members/{$member->uuid}")
            ->assertStatus(403);
    }
}
