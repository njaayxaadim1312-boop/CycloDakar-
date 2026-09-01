<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\Sport;
use App\Models\Activity;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Anneaux d'activité et objectifs hebdomadaires.
 *
 * Les anneaux sont la première chose que voit un membre en ouvrant
 * l'application. Trois exigences en découlent :
 *
 * 1. **Ils portent toujours sur la semaine en cours**, quelle que soit la
 *    période demandée ailleurs sur l'écran. Un objectif hebdomadaire comparé
 *    aux cumuls de l'année ne voudrait rien dire.
 * 2. **Le dépassement n'est pas écrêté.** Une semaine à 150 % mérite de se
 *    voir ; la ramener à 100 % effacerait l'exploit.
 * 3. **Un objectif à zéro n'est pas un objectif atteint.** Il est désactivé,
 *    et le pourcentage vaut `null` — pas 100 %.
 */
final class ActivityRingsTest extends TestCase
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

    /** @return array{User, Member} */
    private function membre(array $goals = []): array
    {
        $user = User::factory()->create();
        $member = Member::factory()->for($user)->create($goals);

        return [$user, $member];
    }

    #[Test]
    public function les_objectifs_par_defaut_sont_modestes_et_atteignables(): void
    {
        // Un objectif inatteignable dès la première semaine décourage, alors
        // qu'un objectif atteint donne envie de le relever.
        [$user] = $this->membre();

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonPath('data.goals.distance_m', 20_000)
            ->assertJsonPath('data.goals.moving_time_s', 7_200)
            ->assertJsonPath('data.goals.activities', 3);
    }

    #[Test]
    public function les_anneaux_comparent_la_semaine_a_l_objectif(): void
    {
        [$user, $member] = $this->membre();

        // 10 km sur un objectif de 20 km : la moitié.
        Activity::factory()->for($member)->withStats(10_000, 3_600)
            ->create(['started_at' => Carbon::now()->startOfWeek()->addHours(8)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonPath('data.rings.metrics.distance_m.value', 10_000)
            ->assertJsonPath('data.rings.metrics.distance_m.goal', 20_000)
            ->assertJsonPath('data.rings.metrics.distance_m.percent', 50)
            ->assertJsonPath('data.rings.metrics.distance_m.completed', false)
            ->assertJsonPath('data.rings.metrics.activities.value', 1)
            ->assertJsonPath('data.rings.metrics.activities.completed', false);
    }

    #[Test]
    public function les_anneaux_ignorent_la_periode_demandee(): void
    {
        // Le membre regarde son année ; ses anneaux restent hebdomadaires.
        [$user, $member] = $this->membre();

        Activity::factory()->for($member)->withStats(200_000, 30_000)
            ->create(['started_at' => Carbon::now()->startOfYear()->addDay()]);
        Activity::factory()->for($member)->withStats(5_000, 1_200)
            ->create(['started_at' => Carbon::now()->startOfWeek()->addHours(8)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=year')
            ->assertOk()
            ->assertJsonPath('data.totals.distance_m', 205_000)
            // Seule la sortie de cette semaine alimente l'anneau.
            ->assertJsonPath('data.rings.metrics.distance_m.value', 5_000);
    }

    #[Test]
    public function un_depassement_n_est_pas_ecrete_a_cent_pour_cent(): void
    {
        // Une semaine à 150 % mérite de se voir.
        [$user, $member] = $this->membre();

        Activity::factory()->for($member)->withStats(30_000, 5_400)
            ->create(['started_at' => Carbon::now()->startOfWeek()->addHours(8)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonPath('data.rings.metrics.distance_m.percent', 150)
            ->assertJsonPath('data.rings.metrics.distance_m.completed', true);
    }

    #[Test]
    public function un_objectif_a_zero_est_desactive_et_non_atteint(): void
    {
        // « 0 / 0 = 100 % » serait absurde : l'anneau n'a pas d'échelle.
        [$user] = $this->membre(['weekly_distance_goal_m' => 0]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonPath('data.rings.metrics.distance_m.percent', null)
            ->assertJsonPath('data.rings.metrics.distance_m.completed', false);
    }

    #[Test]
    public function la_semaine_montre_ses_sept_jours_creux_compris(): void
    {
        // Rouler lundi et dimanche ne se lit pas comme rouler deux jours de
        // suite, et c'est précisément ce que le membre veut voir.
        [$user, $member] = $this->membre();

        $lundi = Carbon::now()->startOfWeek();

        Activity::factory()->for($member)->withStats(12_000, 2_400)
            ->create(['started_at' => $lundi->copy()->addHours(7)]);

        $response = $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonCount(7, 'data.rings.days');

        $days = $response->json('data.rings.days');

        $this->assertSame($lundi->toDateString(), $days[0]['date']);
        $this->assertTrue($days[0]['active']);
        $this->assertSame(12_000, $days[0]['distance_m']);

        $this->assertFalse($days[1]['active']);
        $this->assertSame(0, $days[1]['distance_m']);
    }

    /* ------------------------------------------------------- modification */

    #[Test]
    public function un_membre_ajuste_ses_propres_objectifs(): void
    {
        [$user] = $this->membre();

        $this->actingAs_($user)
            ->patchJson('/api/v1/members/me/goals', ['distance_m' => 50_000])
            ->assertOk()
            ->assertJsonPath('data.distance_m', 50_000)
            // Les champs non fournis ne bougent pas.
            ->assertJsonPath('data.activities', 3);
    }

    #[Test]
    public function un_objectif_manifestement_saisi_en_metres_est_refuse(): void
    {
        // 700 km par semaine n'est pas un objectif, c'est une faute de frappe
        // — des kilomètres saisis là où l'API attend des mètres, ou l'inverse.
        [$user] = $this->membre();

        $this->actingAs_($user)
            ->patchJson('/api/v1/members/me/goals', ['distance_m' => 900_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('distance_m');
    }

    #[Test]
    public function un_compte_sans_fiche_membre_ne_peut_pas_fixer_d_objectif(): void
    {
        $this->actingAs_(User::factory()->create())
            ->patchJson('/api/v1/members/me/goals', ['distance_m' => 30_000])
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------- marche */

    #[Test]
    public function la_marche_est_un_sport_a_part_entiere(): void
    {
        // Le sport le plus pratiqué du club : il doit apparaître partout où
        // les autres apparaissent, y compris à zéro.
        [$user, $member] = $this->membre();

        Activity::factory()->for($member)->sport(Sport::Walking)->withStats(6_000, 4_500)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDay()]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=month')
            ->assertOk()
            ->assertJsonPath('data.by_sport.WALKING.label', 'Marche')
            ->assertJsonPath('data.by_sport.WALKING.activities', 1)
            ->assertJsonPath('data.by_sport.WALKING.distance_m', 6_000);
    }

    #[Test]
    public function la_marche_est_annoncee_par_la_configuration_publique(): void
    {
        // Le mobile filtre les points GPS avec exactement les mêmes seuils
        // que le serveur : ils doivent lui parvenir.
        $response = $this->getJson('/api/v1/config')->assertOk();

        $sports = collect($response->json('data.sports'))->keyBy('code');

        $this->assertTrue($sports->has('WALKING'));
        $this->assertSame('Marche', $sports['WALKING']['label']);
        $this->assertTrue($sports['WALKING']['uses_pace']);
        // 3,5 m/s = 12,6 km/h : au-delà, on ne marche plus, on court.
        $this->assertSame(3.5, $sports['WALKING']['max_speed_mps']);
    }
}
