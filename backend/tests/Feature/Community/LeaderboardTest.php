<?php

declare(strict_types=1);

namespace Tests\Feature\Community;

use App\Enums\ActivityStatus;
use App\Enums\ActivityVisibility;
use App\Enums\ChallengeMetric;
use App\Enums\Sport;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\LeaderboardSnapshot;
use App\Models\Member;
use App\Models\User;
use App\Services\Community\LeaderboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Classements du club.
 *
 * DEUX PROPRIÉTÉS, ET LA PREMIÈRE EST UNE QUESTION DE PAROLE DONNÉE.
 *
 * 1. **Une sortie privée ne classe jamais son auteur.** Un membre qui marque
 *    une sortie « privée » a demandé qu'elle ne soit pas vue ; la faire
 *    apparaître dans un classement — même sous forme d'un total — trahirait
 *    exactement cette demande. Un classement est une publication.
 *
 * 2. **Une période close est figée.** Les sorties bougent après coup
 *    (synchronisation en différé, passage en privé, correction de trace).
 *    Recalculé, le classement de septembre changerait en octobre, après que le
 *    club a félicité quelqu'un.
 */
final class LeaderboardTest extends TestCase
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

    /** Une sortie terminée, ce mois-ci. */
    private function sortie(
        Member $membre,
        int $metres,
        ActivityVisibility $visibilite = ActivityVisibility::Club,
        ?Sport $sport = null,
        ?string $quand = null,
    ): Activity {
        return Activity::factory()->create([
            'member_id' => $membre->id,
            'sport' => $sport ?? Sport::Cycling,
            'status' => ActivityStatus::Completed,
            'visibility' => $visibilite,
            'distance_m' => $metres,
            'moving_time_s' => (int) ($metres / 6),
            'elevation_gain_m' => (int) ($metres / 100),
            'started_at' => $quand ?? now()->startOfMonth()->addHours(8),
        ]);
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_sortie_privee_ne_classe_jamais_son_auteur(): void
    {
        /*
         | LA règle de ce module. Un membre qui a tout mis en privé n'apparaît
         | nulle part, et c'est normal : mieux vaut un classement incomplet
         | qu'un classement qui publie ce qu'on lui a confié.
         */
        $public = $this->member('Awa');
        $discret = $this->member('Khadim');

        $this->sortie($public, 30_000);
        // Deux fois plus loin — et invisible, parce qu'il l'a demandé.
        $this->sortie($discret, 60_000, ActivityVisibility::Private);

        $classement = $this->actingAs_($public->user)
            ->getJson('/api/v1/leaderboard?period=month&metric=distance')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $classement);
        $this->assertSame('Awa', explode(' ', $classement[0]['member']['full_name'])[0]);
    }

    #[Test]
    public function une_sortie_en_cours_ou_abandonnee_ne_compte_pas(): void
    {
        $membre = $this->member('Awa');

        $this->sortie($membre, 10_000);

        Activity::factory()->create([
            'member_id' => $membre->id,
            'status' => ActivityStatus::Recording,
            'visibility' => ActivityVisibility::Club,
            'distance_m' => 90_000,
            'started_at' => now()->startOfMonth()->addHours(9),
        ]);

        $this->actingAs_($membre->user)
            ->getJson('/api/v1/leaderboard?period=month&metric=distance')
            ->assertOk()
            // 10 km, pas 100 : une sortie en cours n'a pas de statistiques
            // fiables.
            ->assertJsonPath('data.0.value', 10_000);
    }

    #[Test]
    public function le_classement_se_trie_et_gere_les_ex_aequo(): void
    {
        /*
         | Deux membres à 40 km partagent la 1re place, et le suivant est 3e —
         | la convention du sport. Les numéroter 1, 2, 3 donnerait une deuxième
         | place à quelqu'un qui a fait exactement autant que le premier.
         */
        $a = $this->member('Awa');
        $b = $this->member('Bineta');
        $c = $this->member('Cheikh');

        $this->sortie($a, 40_000);
        $this->sortie($b, 40_000);
        $this->sortie($c, 10_000);

        $classement = $this->actingAs_($a->user)
            ->getJson('/api/v1/leaderboard?period=month&metric=distance')
            ->assertOk()
            ->json('data');

        $this->assertSame([1, 1, 3], array_column($classement, 'rank'));
    }

    #[Test]
    public function le_lecteur_voit_son_rang_meme_hors_du_classement(): void
    {
        // Un classement qui ne montre que les premiers dit à tous les autres
        // qu'ils ne comptent pas.
        $vedette = $this->member('Awa');
        $absent = $this->member('Khadim');

        $this->sortie($vedette, 40_000);

        $meta = $this->actingAs_($absent->user)
            ->getJson('/api/v1/leaderboard?period=month&metric=distance')
            ->assertOk()
            ->json('meta');

        // Il n'a rien fait : rang `null`, et on le DIT plutôt que de renvoyer
        // `null` tout court, qui se confondrait avec « pas encore chargé ».
        $this->assertNull($meta['me']['rank']);
        $this->assertSame(0, $meta['me']['value']);
        $this->assertSame(1, $meta['me']['total']);
    }

    #[Test]
    public function les_mesures_et_les_sports_se_filtrent(): void
    {
        $velo = $this->member('Awa');
        $marche = $this->member('Bineta');

        $this->sortie($velo, 40_000, sport: Sport::Cycling);
        $this->sortie($marche, 5_000, sport: Sport::Walking);
        $this->sortie($marche, 5_000, sport: Sport::Walking);

        // À la distance, le cycliste passe devant.
        $this->actingAs_($velo->user)
            ->getJson('/api/v1/leaderboard?period=month&metric=distance')
            ->assertOk()
            ->assertJsonPath('data.0.value', 40_000);

        // Au NOMBRE DE SORTIES, la régularité l'emporte : c'est tout
        // l'intérêt d'avoir plusieurs mesures.
        $this->actingAs_($velo->user)
            ->getJson('/api/v1/leaderboard?period=month&metric=activities')
            ->assertOk()
            ->assertJsonPath('data.0.value', 2);

        // Filtré par sport, un marcheur n'est plus comparé à un cycliste.
        $marcheurs = $this->actingAs_($velo->user)
            ->getJson('/api/v1/leaderboard?period=month&metric=distance&sport=WALKING')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $marcheurs);
        $this->assertSame(10_000, $marcheurs[0]['value']);
    }

    /* ---------------------------------------------------------------------- */
    /* Le figeage                                                             */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_periode_close_une_fois_figee_ne_bouge_plus(): void
    {
        /*
         | Le scénario réel : le club félicite le vainqueur du mois dernier,
         | puis une sortie arrive en retard — le mobile a synchronisé une
         | semaine après. Sans figeage, le classement changerait APRÈS
         | l'annonce, et il faudrait reprendre une première place.
         */
        $gagnant = $this->member('Awa');
        $retardataire = $this->member('Khadim');

        $moisDernier = now()->subMonth()->startOfMonth()->addDays(5);
        $this->sortie($gagnant, 50_000, quand: $moisDernier->toDateTimeString());

        $cle = LeaderboardService::periodKey('month', now()->subMonth());

        // Le club fige et annonce.
        $this->artisan('cyclo:snapshot-leaderboards', ['--period' => ['month']])
            ->assertExitCode(0);

        $this->assertTrue(
            LeaderboardSnapshot::query()->where('period_key', $cle)->exists(),
        );

        // La synchronisation tardive arrive.
        $this->sortie($retardataire, 90_000, quand: $moisDernier->toDateTimeString());

        $reponse = $this->actingAs_($gagnant->user)
            ->getJson("/api/v1/leaderboard?period=month&metric=distance&key={$cle}")
            ->assertOk();

        // Le classement figé fait foi : le vainqueur annoncé le reste.
        $reponse->assertJsonPath('meta.frozen', true);
        $this->assertCount(1, $reponse->json('data'));
        $this->assertSame(50_000, $reponse->json('data.0.value'));
    }

    #[Test]
    public function la_periode_en_cours_n_est_jamais_figee(): void
    {
        // Elle n'est pas finie : la figer n'aurait aucun sens.
        $membre = $this->member('Awa');
        $this->sortie($membre, 20_000);

        $cle = LeaderboardService::periodKey('month', now());

        $fige = app(LeaderboardService::class)
            ->freeze('month', $cle, ChallengeMetric::Distance, null);

        $this->assertSame(0, $fige);
        $this->assertSame(0, LeaderboardSnapshot::count());

        $this->actingAs_($membre->user)
            ->getJson('/api/v1/leaderboard?period=month&metric=distance')
            ->assertOk()
            ->assertJsonPath('meta.frozen', false)
            // Et elle se calcule en direct, donc elle reflète la sortie.
            ->assertJsonPath('data.0.value', 20_000);
    }

    #[Test]
    public function un_classement_fige_n_expose_pas_les_sorties_privees(): void
    {
        // Le figeage ne doit pas devenir une porte dérobée : ce qui est exclu
        // du calcul en direct l'est aussi de l'instantané.
        $public = $this->member('Awa');
        $discret = $this->member('Khadim');

        $moisDernier = now()->subMonth()->startOfMonth()->addDays(3);
        $this->sortie($public, 20_000, quand: $moisDernier->toDateTimeString());
        $this->sortie($discret, 80_000, ActivityVisibility::Private, quand: $moisDernier->toDateTimeString());

        $this->artisan('cyclo:snapshot-leaderboards', ['--period' => ['month']])->assertExitCode(0);

        $cle = LeaderboardService::periodKey('month', now()->subMonth());

        $lignes = LeaderboardSnapshot::query()
            ->where('period_key', $cle)
            ->where('metric', 'distance')
            ->whereNull('sport')
            ->get();

        $this->assertCount(1, $lignes);
        $this->assertSame($public->id, $lignes->first()->member_id);
    }
}
