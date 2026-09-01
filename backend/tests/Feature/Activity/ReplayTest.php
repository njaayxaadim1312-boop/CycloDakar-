<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\ActivityVisibility;
use App\Models\Activity;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trace horodatée pour le rejeu animé.
 *
 * Ce qui est protégé ici : **le temps**. Une animation qui parcourrait la
 * polyligne à vitesse constante effacerait les pauses et ferait monter une
 * côte aussi vite qu'une descente — or c'est exactement ce qu'un membre veut
 * revoir. Chaque point porte donc sa seconde depuis le départ.
 */
final class ReplayTest extends TestCase
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

    /**
     * Trace synthétique : une ligne droite vers le nord, avec une PAUSE.
     *
     * La pause est le cœur du test : elle doit se voir dans les temps.
     */
    private function trace(Activity $activity, int $count = 10, int $pauseAfter = 5): void
    {
        $start = Carbon::parse('2026-09-01 06:00:00');
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            // Une longue pause de 5 minutes au milieu de la sortie.
            $offset = $i < $pauseAfter ? $i * 10 : $i * 10 + 300;

            $rows[] = [
                'activity_id' => $activity->id,
                'seq' => $i,
                // 1 degré de latitude ≈ 111 320 m : 20 m entre deux points.
                'lat' => 14.6928 + ($i * 20 / 111_320),
                'lng' => -17.4467,
                'altitude_m' => 10 + $i,
                'recorded_at' => $start->copy()->addSeconds($offset),
                // Pas de `created_at` : la table des points n'en a pas. Sur
                // des millions de lignes, deux horodatages de plus par point
                // coûteraient sans rien apprendre — `recorded_at` suffit.
            ];
        }

        DB::table('activity_points')->insert($rows);
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_visiteur_ne_rejoue_pas_une_sortie(): void
    {
        $activity = Activity::factory()->create();

        $this->getJson("/api/v1/activities/{$activity->uuid}/replay")->assertStatus(401);
    }

    #[Test]
    public function une_sortie_privee_reste_privee_au_rejeu(): void
    {
        // Le rejeu n'est qu'une autre présentation de la trace : il ne doit
        // pas devenir une porte dérobée vers une sortie privée.
        $activity = Activity::factory()->visibility(ActivityVisibility::Private)->create();
        $this->trace($activity);

        $user = User::factory()->create();
        Member::factory()->for($user)->create();

        $this->actingAs_($user)
            ->getJson("/api/v1/activities/{$activity->uuid}/replay")
            ->assertStatus(403);
    }

    #[Test]
    public function une_sortie_sans_trace_le_dit_au_lieu_de_renvoyer_du_vide(): void
    {
        // Un tableau vide laisserait le client afficher une animation figée
        // sans expliquer pourquoi.
        $activity = Activity::factory()->create();

        $user = User::factory()->create();
        Member::factory()->for($user)->create();

        $this->actingAs_($user)
            ->getJson("/api/v1/activities/{$activity->uuid}/replay")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'NO_TRACE');
    }

    #[Test]
    public function chaque_point_porte_son_temps_sa_distance_et_sa_vitesse(): void
    {
        $activity = Activity::factory()->create();
        $this->trace($activity, count: 6, pauseAfter: 6);

        $user = User::factory()->create();
        Member::factory()->for($user)->create();

        $response = $this->actingAs_($user)
            ->getJson("/api/v1/activities/{$activity->uuid}/replay")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonCount(6, 'data.points');

        $points = $response->json('data.points');

        // Le premier point est l'origine du temps et de la distance.
        $this->assertSame(0, $points[0]['t']);
        $this->assertSame(0, $points[0]['d']);

        // 20 m toutes les 10 s : 2 m/s, et 100 m au sixième point.
        $this->assertSame(50, $points[5]['t']);
        $this->assertEqualsWithDelta(100, $points[5]['d'], 1.0);
        $this->assertEqualsWithDelta(2.0, $points[5]['v'], 0.05);
    }

    #[Test]
    public function une_pause_se_voit_dans_les_temps(): void
    {
        // Le point de tout ce module : rejouer une sortie doit montrer QUAND
        // on s'est arrêté. Une animation à vitesse constante l'effacerait.
        $activity = Activity::factory()->create();
        $this->trace($activity, count: 10, pauseAfter: 5);

        $user = User::factory()->create();
        Member::factory()->for($user)->create();

        $points = $this->actingAs_($user)
            ->getJson("/api/v1/activities/{$activity->uuid}/replay")
            ->assertOk()
            ->json('data.points');

        // Entre le 5e et le 6e point : 10 s de trajet + 300 s d'arrêt.
        $ecart = $points[5]['t'] - $points[4]['t'];

        $this->assertSame(310, $ecart);

        // Et la vitesse du segment s'effondre en conséquence : 20 m en 310 s.
        $this->assertLessThan(0.1, $points[5]['v']);
    }

    #[Test]
    public function la_trace_est_decimee_sans_perdre_l_arrivee(): void
    {
        // Une sortie de trois heures compte plus de dix mille points : les
        // envoyer tous ferait plusieurs mégaoctets pour une animation qui
        // n'affiche pas deux points par pixel.
        $activity = Activity::factory()->create();
        $this->trace($activity, count: 2_000, pauseAfter: 2_000);

        $user = User::factory()->create();
        Member::factory()->for($user)->create();

        $response = $this->actingAs_($user)
            ->getJson("/api/v1/activities/{$activity->uuid}/replay")
            ->assertOk();

        $points = $response->json('data.points');

        $this->assertLessThanOrEqual(601, count($points));
        $this->assertGreaterThan(400, count($points));

        // Le dernier point de la trace est conservé : sinon l'animation
        // paraîtrait s'arrêter avant l'arrivée.
        $dernier = end($points);
        $this->assertSame(19_990, $dernier['t']);
    }

    #[Test]
    public function les_bornes_encadrent_toute_la_trace(): void
    {
        $activity = Activity::factory()->create();
        $this->trace($activity, count: 10, pauseAfter: 10);

        $user = User::factory()->create();
        Member::factory()->for($user)->create();

        $bounds = $this->actingAs_($user)
            ->getJson("/api/v1/activities/{$activity->uuid}/replay")
            ->assertOk()
            ->json('data.bounds');

        $this->assertEqualsWithDelta(14.6928, $bounds['min_lat'], 0.0001);
        $this->assertGreaterThan($bounds['min_lat'], $bounds['max_lat']);
    }
}
