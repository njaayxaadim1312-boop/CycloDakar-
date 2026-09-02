<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LA SONDE DE SANTÉ, ET SES DEUX NIVEAUX DE PANNE.
 *
 * Une sonde qui dit « tout va bien » alors qu'un rouage est mort est pire
 * qu'une absence de sonde : on cesse de regarder. C'est la même leçon que le
 * journal d'audit de la phase 19, appliquée à la production.
 *
 * CE QUI SE JOUE ICI EST LA DISTINCTION ENTRE DEUX PANNES.
 *
 * La base injoignable, c'est une application qui ne peut rien servir : 503, on
 * réveille quelqu'un. La file d'attente à l'arrêt, c'est autre chose — les
 * écrans marchent, les encaissements passent, mais les rappels de cotisation
 * ne partent plus. Répondre 503 ferait redémarrer un serveur qui va bien ; se
 * taire laisserait la panne courir des semaines, jusqu'à ce qu'on s'étonne que
 * plus personne ne paie.
 *
 * D'où 200 avec `status: "degraded"` — et un déploiement qui refuse de
 * s'annoncer tant que ce champ ne vaut pas `healthy`.
 */
final class HealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un système en ordre de marche : le planificateur vient de battre.
     */
    private function planificateurVivant(): void
    {
        Cache::put(HealthController::HEARTBEAT, now()->toIso8601String(), now()->addDay());
    }

    #[Test]
    public function une_installation_saine_repond_healthy(): void
    {
        $this->planificateurVivant();

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.checks.database.ok', true)
            ->assertJsonPath('data.checks.storage.ok', true)
            ->assertJsonPath('data.checks.queue.ok', true)
            ->assertJsonPath('data.checks.scheduler.ok', true);
    }

    #[Test]
    public function un_planificateur_muet_degrade_sans_faire_tomber_le_site(): void
    {
        // Rien en cache : `schedule:run` n'a jamais tourné, ou plus.
        Cache::forget(HealthController::HEARTBEAT);

        $reponse = $this->getJson('/api/v1/health')
            // 200, et c'est le point : les écrans fonctionnent. Un 503 ferait
            // redémarrer par erreur un serveur en parfait état.
            ->assertOk()
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.scheduler.ok', false);

        self::assertStringContainsString(
            'schedule:run',
            $reponse->json('data.checks.scheduler.message'),
            'Le message doit nommer le rouage à relancer.',
        );
    }

    #[Test]
    public function un_battement_trop_vieux_vaut_un_planificateur_arrete(): void
    {
        // Il a battu… il y a une heure. Le processus est mort entre-temps.
        Cache::put(
            HealthController::HEARTBEAT,
            now()->subHour()->toIso8601String(),
            now()->addDay(),
        );

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.scheduler.ok', false);
    }

    #[Test]
    public function une_file_qui_stagne_denonce_l_ouvrier_mort(): void
    {
        $this->planificateurVivant();

        // Un travail exigible depuis une heure et que personne n'a pris :
        // c'est la signature d'un `queue:work` arrêté.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHour()->getTimestamp(),
            'created_at' => now()->subHour()->getTimestamp(),
        ]);

        $reponse = $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.queue.ok', false)
            ->assertJsonPath('data.checks.queue.pending', 1);

        self::assertStringContainsString(
            'queue:work',
            $reponse->json('data.checks.queue.message'),
        );
    }

    #[Test]
    public function une_file_pleine_mais_qui_avance_ne_declenche_rien(): void
    {
        $this->planificateurVivant();

        // Trente travaux déposés à l'instant. Une file pleine va très bien
        // tant qu'elle se vide : alerter ici crierait au loup à chaque envoi
        // groupé de notifications, et on finirait par ignorer la sonde.
        for ($i = 0; $i < 30; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->getTimestamp(),
                'created_at' => now()->getTimestamp(),
            ]);
        }

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.checks.queue.ok', true)
            ->assertJsonPath('data.checks.queue.pending', 30);
    }

    #[Test]
    public function un_travail_differe_n_est_pas_un_travail_en_retard(): void
    {
        $this->planificateurVivant();

        // Un travail programmé pour dans dix minutes. Aucun ouvrier ne doit
        // l'avoir pris : le confondre avec un retard rendrait la sonde
        // ininterprétable dès qu'on utilise `delay()`.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->addMinutes(10)->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy');
    }

    #[Test]
    public function les_travaux_en_echec_sont_signales_sans_crier_a_la_panne(): void
    {
        $this->planificateurVivant();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Expo a répondu 500',
            'failed_at' => now(),
        ]);

        // Un travail échoué demande un examen humain, pas un redémarrage : la
        // file, elle, continue d'avancer.
        $reponse = $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.checks.queue.failed', 1);

        self::assertStringContainsString(
            'échec',
            $reponse->json('data.checks.queue.message'),
        );
    }

    #[Test]
    public function la_sonde_ne_publie_pas_le_numero_de_correctif_php(): void
    {
        $this->planificateurVivant();

        // En production, le correctif exact désigne au visiteur la liste
        // précise des failles connues qui s'appliquent. Le majeur.mineur
        // suffit à diagnostiquer.
        app()['env'] = 'production';

        $php = $this->getJson('/api/v1/health')->json('data.php');

        self::assertSame(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, $php);
        self::assertNotSame(PHP_VERSION, $php);
    }
}
