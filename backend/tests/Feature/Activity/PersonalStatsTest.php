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
 * Cumuls, tendance et records personnels — `GET /stats/me`.
 *
 * Trois exigences se vérifient ici, et chacune correspond à une erreur qu'il
 * serait facile de commettre :
 *
 * 1. Les cumuls suivent la période demandée, les records **non** : un record
 *    du mois n'est pas un record.
 * 2. Un membre sans sortie n'a pas « 0 km de record », il n'a **pas** de
 *    record — la réponse renvoie `null`, pas un zéro.
 * 3. Les statistiques d'un membre ne contiennent jamais celles d'un autre.
 */
final class PersonalStatsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAs_(User $user): static
    {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$user->createToken('Test')->plainTextToken,
        );
    }

    /**
     * Un membre relié à un compte, prêt à enregistrer des sorties.
     *
     * @return array{User, Member}
     */
    private function membre(): array
    {
        $user = User::factory()->create();
        $member = Member::factory()->for($user)->create();

        return [$user, $member];
    }

    #[Test]
    public function un_visiteur_n_a_pas_acces_a_ses_statistiques(): void
    {
        $this->getJson('/api/v1/stats/me')->assertStatus(401);
    }

    #[Test]
    public function un_compte_sans_fiche_membre_recoit_une_erreur_explicite(): void
    {
        // Cas réel : un compte administrateur créé en console, sans adhésion.
        // Renvoyer des cumuls à zéro laisserait croire à une absence de
        // sorties alors que le problème est ailleurs.
        $this->actingAs_(User::factory()->create())
            ->getJson('/api/v1/stats/me')
            ->assertStatus(404)
            ->assertJsonPath('code', 'NO_MEMBER_PROFILE');
    }

    #[Test]
    public function une_periode_inconnue_est_refusee(): void
    {
        [$user] = $this->membre();

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=decennie')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function les_cumuls_additionnent_les_sorties_de_la_periode(): void
    {
        [$user, $member] = $this->membre();

        Activity::factory()->for($member)->withStats(30_000, 5_400)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDay()]);
        Activity::factory()->for($member)->withStats(20_000, 3_600)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDays(2)]);

        // Hors période : le mois précédent.
        Activity::factory()->for($member)->withStats(100_000, 12_000)
            ->create(['started_at' => Carbon::now()->startOfMonth()->subDays(3)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=month')
            ->assertOk()
            ->assertJsonPath('data.period', 'month')
            ->assertJsonPath('data.totals.activities', 2)
            ->assertJsonPath('data.totals.distance_m', 50_000)
            ->assertJsonPath('data.totals.moving_time_s', 9_000);
    }

    #[Test]
    public function la_periode_all_englobe_toute_la_carriere(): void
    {
        [$user, $member] = $this->membre();

        Activity::factory()->for($member)->withStats(30_000, 5_400)
            ->create(['started_at' => Carbon::now()->subDay()]);
        Activity::factory()->for($member)->withStats(100_000, 12_000)
            ->create(['started_at' => Carbon::now()->subYears(2)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=all')
            ->assertOk()
            ->assertJsonPath('data.period_from', null)
            ->assertJsonPath('data.totals.activities', 2)
            ->assertJsonPath('data.totals.distance_m', 130_000);
    }

    #[Test]
    public function la_vitesse_moyenne_pondere_les_sorties_par_leur_duree(): void
    {
        [$user, $member] = $this->membre();

        // 2 km en 1 000 s (2 m/s) et 40 km en 4 000 s (10 m/s).
        // Une moyenne des moyennes donnerait 6 m/s ; la bonne réponse est
        // 42 000 / 5 000 = 8,4 m/s. Une petite sortie ne doit pas peser autant
        // qu'une longue.
        Activity::factory()->for($member)->withStats(2_000, 1_000)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDay()]);
        Activity::factory()->for($member)->withStats(40_000, 4_000)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDays(2)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=month')
            ->assertOk()
            ->assertJsonPath('data.totals.avg_speed_mps', 8.4);
    }

    #[Test]
    public function les_sorties_en_cours_ne_sont_pas_comptees(): void
    {
        [$user, $member] = $this->membre();

        Activity::factory()->for($member)->withStats(30_000, 5_400)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDay()]);

        // Une sortie non finalisée porte des statistiques partielles : les
        // additionner afficherait un cumul qui régresserait à la finalisation.
        Activity::factory()->for($member)->recording()->withStats(9_000, 1_800)
            ->create(['started_at' => Carbon::now()]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=month')
            ->assertOk()
            ->assertJsonPath('data.totals.activities', 1)
            ->assertJsonPath('data.totals.distance_m', 30_000);
    }

    #[Test]
    public function les_sorties_des_autres_membres_sont_exclues(): void
    {
        [$user, $member] = $this->membre();
        $autre = Member::factory()->create();

        Activity::factory()->for($member)->withStats(10_000, 2_000)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDay()]);
        Activity::factory()->for($autre)->withStats(90_000, 9_000)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDay()]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=month')
            ->assertOk()
            ->assertJsonPath('data.totals.distance_m', 10_000);
    }

    #[Test]
    public function tous_les_sports_sont_presents_meme_a_zero(): void
    {
        [$user, $member] = $this->membre();

        Activity::factory()->for($member)->sport(Sport::Cycling)->withStats(30_000, 5_400)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDay()]);

        $response = $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=month')
            ->assertOk();

        // Un sport absent de la réponse disparaîtrait de l'affichage et
        // semblerait ne pas exister dans le club.
        foreach (Sport::cases() as $sport) {
            $response->assertJsonPath("data.by_sport.{$sport->value}.label", $sport->label());
        }

        $response
            ->assertJsonPath('data.by_sport.CYCLING.activities', 1)
            ->assertJsonPath('data.by_sport.CYCLING.distance_m', 30_000)
            ->assertJsonPath('data.by_sport.RUNNING.activities', 0)
            ->assertJsonPath('data.by_sport.RUNNING.distance_m', 0);
    }

    #[Test]
    public function un_membre_sans_sortie_n_a_pas_de_record_plutot_que_des_zeros(): void
    {
        [$user] = $this->membre();

        $response = $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonPath('data.totals.activities', 0);

        foreach (['longest_distance', 'longest_duration', 'max_speed', 'most_elevation', 'best_pace'] as $record) {
            $response->assertJsonPath("data.records.{$record}", null);
        }
    }

    #[Test]
    public function les_records_portent_sur_toute_la_carriere_pas_sur_la_periode(): void
    {
        [$user, $member] = $this->membre();

        // La plus longue sortie date d'il y a deux ans. Elle reste le record,
        // même quand on regarde les cumuls du mois.
        $exploit = Activity::factory()->for($member)->withStats(180_000, 21_600)
            ->create(['started_at' => Carbon::now()->subYears(2), 'title' => 'Dakar — Thiès']);

        Activity::factory()->for($member)->withStats(25_000, 4_000)
            ->create(['started_at' => Carbon::now()->startOfMonth()->addDay()]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=month')
            ->assertOk()
            ->assertJsonPath('data.totals.distance_m', 25_000)
            ->assertJsonPath('data.records.longest_distance.value', 180_000)
            ->assertJsonPath('data.records.longest_distance.activity_uuid', $exploit->uuid)
            ->assertJsonPath('data.records.longest_distance.activity_title', 'Dakar — Thiès');
    }

    #[Test]
    public function le_record_d_allure_retient_la_plus_basse(): void
    {
        [$user, $member] = $this->membre();

        // L'allure se lit à l'envers : 4'30/km est MEILLEUR que 6'00/km.
        // Un tri décroissant, correct pour tous les autres records, donnerait
        // ici la pire sortie comme record.
        Activity::factory()->for($member)->sport(Sport::Running)
            ->withStats(10_000, 3_600)->paces(360)
            ->create(['started_at' => Carbon::now()->subMonths(2)]);

        $rapide = Activity::factory()->for($member)->sport(Sport::Running)
            ->withStats(5_000, 1_350)->paces(270)
            ->create(['started_at' => Carbon::now()->subDays(3)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonPath('data.records.best_pace.value', 270)
            ->assertJsonPath('data.records.best_pace.activity_uuid', $rapide->uuid);
    }

    #[Test]
    public function une_sortie_sans_allure_mesuree_ne_devient_pas_le_record(): void
    {
        [$user, $member] = $this->membre();

        // Une sortie vélo laisse `best_pace_s_per_km` à NULL. Sans la clause
        // qui écarte les valeurs nulles ou nulles-en-valeur, un tri croissant
        // la placerait en tête et afficherait « meilleure allure : — ».
        Activity::factory()->for($member)->sport(Sport::Cycling)
            ->withStats(40_000, 5_000)
            ->create(['started_at' => Carbon::now()->subDays(5)]);

        $course = Activity::factory()->for($member)->sport(Sport::Running)
            ->withStats(10_000, 3_000)->paces(300)
            ->create(['started_at' => Carbon::now()->subDays(2)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonPath('data.records.best_pace.activity_uuid', $course->uuid);
    }

    #[Test]
    public function un_denivele_nul_ne_devient_pas_un_record_de_denivele(): void
    {
        [$user, $member] = $this->membre();

        // Dakar est plate : beaucoup de sorties finissent à 0 m de dénivelé.
        // « Record : 0 m » serait absurde ; on veut « — ».
        Activity::factory()->for($member)->withStats(30_000, 5_400)
            ->create(['started_at' => Carbon::now()->subDays(4), 'elevation_gain_m' => 0]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonPath('data.records.most_elevation', null)
            // Les autres records, eux, existent bien.
            ->assertJsonPath('data.records.longest_distance.value', 30_000);
    }

    #[Test]
    public function la_tendance_couvre_douze_semaines_creux_compris(): void
    {
        [$user, $member] = $this->membre();

        $cetteSemaine = Carbon::now()->startOfWeek()->addHours(8);

        Activity::factory()->for($member)->withStats(30_000, 5_400)
            ->create(['started_at' => $cetteSemaine]);

        $response = $this->actingAs_($user)
            ->getJson('/api/v1/stats/me')
            ->assertOk()
            ->assertJsonCount(12, 'data.trend');

        $trend = $response->json('data.trend');

        // La dernière colonne est la semaine en cours.
        $this->assertSame($cetteSemaine->copy()->startOfWeek()->toDateString(), $trend[11]['week']);
        $this->assertSame(30_000, $trend[11]['distance_m']);
        $this->assertSame(1, $trend[11]['activities']);

        // Une semaine sans sortie vaut zéro et n'est pas omise : une courbe
        // qui sauterait les semaines creuses donnerait une fausse impression
        // de régularité.
        $this->assertSame(0, $trend[0]['distance_m']);
        $this->assertSame(0, $trend[0]['activities']);
    }

    #[Test]
    public function la_periode_semaine_commence_le_lundi(): void
    {
        [$user, $member] = $this->membre();

        $lundi = Carbon::now()->startOfWeek();

        Activity::factory()->for($member)->withStats(15_000, 3_000)
            ->create(['started_at' => $lundi->copy()->addHours(6)]);

        // Le dimanche précédent appartient à la semaine d'avant.
        Activity::factory()->for($member)->withStats(80_000, 9_000)
            ->create(['started_at' => $lundi->copy()->subHours(3)]);

        $this->actingAs_($user)
            ->getJson('/api/v1/stats/me?period=week')
            ->assertOk()
            ->assertJsonPath('data.period_label', 'Cette semaine')
            ->assertJsonPath('data.period_from', $lundi->toDateString())
            ->assertJsonPath('data.totals.distance_m', 15_000);
    }
}
