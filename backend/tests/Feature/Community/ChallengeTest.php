<?php

declare(strict_types=1);

namespace Tests\Feature\Community;

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use App\Enums\ChallengeStatus;
use App\Enums\Sport;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Challenge;
use App\Models\ChallengeMember;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Défis du club.
 *
 * QUATRE PROPRIÉTÉS, ET TROIS SONT DES PROMESSES FAITES AUX MEMBRES.
 *
 * 1. **La progression compte depuis le DÉBUT du défi**, pas depuis
 *    l'inscription. Repartir de zéro pénaliserait celui qui a ouvert
 *    l'application plus tard alors qu'il roulait déjà.
 * 2. **Un badge obtenu ne se reprend pas.** `completed_at` est figé : une
 *    sortie supprimée ensuite ne doit pas retirer une récompense annoncée.
 * 3. **Une sortie privée ne compte pas**, même pour son auteur — un défi est un
 *    classement, et un classement est une publication.
 * 4. **Créer relève du chef de groupe**, pas du trésorier : un défi est un acte
 *    d'animation sportive.
 */
final class ChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAs_(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader(
                'Authorization',
                'Bearer '.$user->createToken('Test')->plainTextToken,
            );
    }

    private function member(string $prenom, UserRole $role = UserRole::Member): Member
    {
        $user = User::factory()->create(['role' => $role]);

        return Member::factory()->for($user)->create(['first_name' => $prenom]);
    }

    /** Un défi en cours : 100 km ce mois-ci, tous sports. */
    private function challenge(array $overrides = []): Challenge
    {
        return Challenge::create(array_merge([
            'title' => '100 km en septembre',
            'metric' => 'distance',
            'target' => 100_000,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => now()->endOfMonth()->toDateString(),
            'status' => ChallengeStatus::Published,
            'created_by' => User::factory()->create(['role' => UserRole::RideLeader])->id,
        ], $overrides));
    }

    private function sortie(
        Member $membre,
        int $metres,
        ActivityVisibility $visibilite = ActivityVisibility::Club,
        ?string $quand = null,
    ): Activity {
        return Activity::factory()->create([
            'member_id' => $membre->id,
            'sport' => Sport::Cycling,
            'status' => ActivityStatus::Completed,
            'visibility' => $visibilite,
            'distance_m' => $metres,
            'started_at' => $quand ?? now()->startOfMonth()->addHours(8),
        ]);
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function la_progression_compte_depuis_le_debut_du_defi(): void
    {
        /*
         | Un membre qui découvre le défi le 15 et roulait déjà depuis le 1er ne
         | doit pas repartir de zéro : on le pénaliserait d'avoir ouvert
         | l'application plus tard, ce qui n'a rien à voir avec l'effort.
         */
        $membre = $this->member('Awa');
        $defi = $this->challenge();

        // Il roulait AVANT de s'inscrire.
        $this->sortie($membre, 40_000);

        $this->actingAs_($membre->user)
            ->postJson("/api/v1/challenges/{$defi->uuid}/join")
            ->assertOk()
            // Sa barre est remplie dès l'inscription.
            ->assertJsonPath('data.my_progress.value', 40_000)
            ->assertJsonPath('data.my_progress.percent', 40);
    }

    #[Test]
    public function une_sortie_privee_ne_fait_pas_avancer_un_defi(): void
    {
        // Un défi est un classement, et un classement est une publication.
        $membre = $this->member('Awa');
        $defi = $this->challenge();

        $this->sortie($membre, 30_000);
        $this->sortie($membre, 60_000, ActivityVisibility::Private);

        $this->actingAs_($membre->user)
            ->postJson("/api/v1/challenges/{$defi->uuid}/join")
            ->assertOk()
            ->assertJsonPath('data.my_progress.value', 30_000);
    }

    #[Test]
    public function une_sortie_hors_de_la_fenetre_ne_compte_pas(): void
    {
        $membre = $this->member('Awa');
        $defi = $this->challenge();

        $this->sortie($membre, 90_000, quand: now()->subMonths(2)->toDateTimeString());

        $this->actingAs_($membre->user)
            ->postJson("/api/v1/challenges/{$defi->uuid}/join")
            ->assertOk()
            ->assertJsonPath('data.my_progress.value', 0);
    }

    #[Test]
    public function un_defi_par_sport_ignore_les_autres_sports(): void
    {
        $membre = $this->member('Awa');
        $defi = $this->challenge(['sport' => Sport::Walking->value, 'target' => 10_000]);

        // Du vélo, alors que le défi porte sur la marche.
        $this->sortie($membre, 50_000);

        $this->actingAs_($membre->user)
            ->postJson("/api/v1/challenges/{$defi->uuid}/join")
            ->assertOk()
            ->assertJsonPath('data.my_progress.value', 0);
    }

    /* ---------------------------------------------------------------------- */
    /* Les badges                                                             */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_badge_obtenu_ne_se_reprend_pas(): void
    {
        /*
         | Le scénario : un membre atteint l'objectif, le club l'annonce, puis
         | il repasse une sortie en privé — pour une raison qui ne regarde que
         | lui. Sa progression retombe. Son badge, lui, RESTE.
         |
         | Reprendre une récompense déjà annoncée est le plus sûr moyen de
         | faire quitter un club.
         */
        $membre = $this->member('Awa');
        $defi = $this->challenge();

        $sortie = $this->sortie($membre, 120_000);

        $this->actingAs_($membre->user)
            ->postJson("/api/v1/challenges/{$defi->uuid}/join")
            ->assertOk()
            ->assertJsonPath('data.my_progress.percent', 100);

        $inscription = ChallengeMember::firstOrFail();
        $this->assertNotNull($inscription->completed_at);
        $obtenuLe = $inscription->completed_at;

        // La sortie passe en privé.
        $sortie->update(['visibility' => ActivityVisibility::Private]);

        app(\App\Services\Community\ChallengeService::class)
            ->refreshAll($defi->fresh()->load('participants'));

        $apres = ChallengeMember::firstOrFail();

        // La progression retombe — c'est juste, elle n'est plus publiée.
        $this->assertSame(0, $apres->progress);
        // Le badge, lui, est intact.
        $this->assertNotNull($apres->completed_at);
        $this->assertSame($obtenuLe->timestamp, $apres->completed_at->timestamp);
    }

    #[Test]
    public function on_ne_quitte_pas_un_defi_deja_reussi(): void
    {
        $membre = $this->member('Awa');
        $defi = $this->challenge();

        $this->sortie($membre, 150_000);
        $this->actingAs_($membre->user)->postJson("/api/v1/challenges/{$defi->uuid}/join")->assertOk();

        $this->actingAs_($membre->user)
            ->postJson("/api/v1/challenges/{$defi->uuid}/leave")
            ->assertStatus(422)
            ->assertJsonPath('code', 'LEAVE_REFUSED');

        $this->assertSame(1, ChallengeMember::count());
    }

    #[Test]
    public function les_badges_du_membre_listent_ses_defis_reussis(): void
    {
        $membre = $this->member('Awa');
        $defi = $this->challenge();

        $this->sortie($membre, 150_000);
        $this->actingAs_($membre->user)->postJson("/api/v1/challenges/{$defi->uuid}/join")->assertOk();

        $badges = $this->actingAs_($membre->user)
            ->getJson('/api/v1/challenges/badges')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $badges);
        $this->assertSame('100 km en septembre', $badges[0]['challenge']['title']);
        $this->assertNotNull($badges[0]['completed_at']);
    }

    /* ---------------------------------------------------------------------- */
    /* Autorisations                                                          */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_chef_de_groupe_cree_un_defi_un_membre_non(): void
    {
        // Un défi est un acte d'animation sportive : il ne demande aucun accès
        // à l'argent, et il ne demande pas non plus d'être simple membre.
        $corps = [
            'title' => '8 sorties ce mois-ci',
            'metric' => 'activities',
            'target' => 8,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => now()->endOfMonth()->toDateString(),
            'status' => ChallengeStatus::Published->value,
        ];

        $this->actingAs_($this->member('Awa')->user)
            ->postJson('/api/v1/challenges', $corps)
            ->assertForbidden();

        $this->actingAs_($this->member('Ibrahima', UserRole::RideLeader)->user)
            ->postJson('/api/v1/challenges', $corps)
            ->assertCreated()
            ->assertJsonPath('data.target', 8)
            ->assertJsonPath('data.unit', 'sorties');
    }

    #[Test]
    public function un_brouillon_reste_invisible_des_autres(): void
    {
        $auteur = $this->member('Ibrahima', UserRole::RideLeader);
        $defi = $this->challenge([
            'status' => ChallengeStatus::Draft,
            'created_by' => $auteur->user->id,
        ]);

        $this->actingAs_($this->member('Awa')->user)
            ->getJson("/api/v1/challenges/{$defi->uuid}")
            ->assertForbidden();

        $this->actingAs_($auteur->user)
            ->getJson("/api/v1/challenges/{$defi->uuid}")
            ->assertOk();
    }

    #[Test]
    public function on_ne_rejoint_pas_un_defi_termine(): void
    {
        $membre = $this->member('Awa');
        $defi = $this->challenge([
            'starts_on' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'ends_on' => now()->subMonth()->endOfMonth()->toDateString(),
        ]);

        $this->actingAs_($membre->user)
            ->postJson("/api/v1/challenges/{$defi->uuid}/join")
            ->assertStatus(422)
            ->assertJsonPath('code', 'JOIN_REFUSED');
    }

    #[Test]
    public function un_defi_termine_ne_se_modifie_plus(): void
    {
        // Des membres ont gagné des badges sur ces règles-là : en changer
        // l'objectif après coup les invaliderait rétroactivement.
        $auteur = $this->member('Ibrahima', UserRole::RideLeader);
        $defi = $this->challenge([
            'created_by' => $auteur->user->id,
            'starts_on' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'ends_on' => now()->subMonth()->endOfMonth()->toDateString(),
        ]);

        $this->actingAs_($auteur->user)
            ->patchJson("/api/v1/challenges/{$defi->uuid}", ['target' => 1_000])
            ->assertForbidden();
    }

    #[Test]
    public function le_classement_d_un_defi_met_les_finisseurs_devant(): void
    {
        /*
         | Un défi n'est pas un classement à la performance mais un OBJECTIF :
         | celui qui l'a atteint le premier passe devant celui qui l'a atteint
         | après, quel que soit son total.
         */
        $defi = $this->challenge();

        $premier = $this->member('Awa');
        $second = $this->member('Bineta');
        $enCours = $this->member('Cheikh');

        $this->sortie($premier, 110_000);
        $this->actingAs_($premier->user)->postJson("/api/v1/challenges/{$defi->uuid}/join")->assertOk();

        // Plus loin, mais arrivé après.
        $this->sortie($second, 200_000);
        $this->actingAs_($second->user)->postJson("/api/v1/challenges/{$defi->uuid}/join")->assertOk();

        $this->sortie($enCours, 50_000);
        $this->actingAs_($enCours->user)->postJson("/api/v1/challenges/{$defi->uuid}/join")->assertOk();

        $classement = $this->actingAs_($premier->user)
            ->getJson("/api/v1/challenges/{$defi->uuid}/standings")
            ->assertOk()
            ->json('data');

        $this->assertSame('Awa', explode(' ', $classement[0]['member']['full_name'])[0]);
        $this->assertSame('Bineta', explode(' ', $classement[1]['member']['full_name'])[0]);
        $this->assertSame('Cheikh', explode(' ', $classement[2]['member']['full_name'])[0]);
    }
}
