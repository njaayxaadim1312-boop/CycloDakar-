<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LE JOURNAL D'AUDIT : QUI PEUT LE LIRE, ET CE QU'IL EST INTERDIT D'Y FAIRE.
 *
 * Il existe depuis la phase 3 et personne n'avait jamais pu le lire — il
 * fallait ouvrir la base. Un journal qu'on ne peut pas lire ne protège
 * personne : il donne le sentiment d'être protégé, ce qui est pire, car on
 * renonce alors à d'autres contrôles en croyant celui-là actif.
 *
 * DEUX PROPRIÉTÉS SE JOUENT ICI, ET AUCUNE NE SE VOIT À LA RELECTURE.
 *
 * La première : LE TRÉSORIER N'Y A PAS ACCÈS. Ce n'est pas une omission, c'est
 * la raison d'être du journal — il est la personne qu'on surveille. C'est déjà
 * ce qu'écrit le tableau des droits de `docs/finance.md`, où « voir les
 * journaux d'audit » est la seule ligne où le trésorier a un refus. Une règle
 * écrite dans une documentation et nulle part ailleurs finit toujours par être
 * contredite par le code.
 *
 * La seconde : IL N'EXISTE AUCUNE ROUTE POUR ÉCRIRE. Un journal qu'on peut
 * retoucher ne prouve rien — et la tentation d'ajouter un `DELETE` « pour
 * purger les vieilles lignes » viendra un jour. Le test l'interdit d'avance, en
 * énumérant les routes réellement enregistrées.
 */
final class AuditLogTest extends TestCase
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

    private function compte(UserRole $role): User
    {
        static $rang = 0;
        $rang++;

        $user = User::factory()->create(['role' => $role]);

        // `members.phone` est UNIQUE : deux comptes fabriqués dans le même
        // test se heurteraient, et l'échec de fixture passerait pour un échec
        // de la règle testée.
        Member::factory()->for($user)->create([
            'phone' => '+22178000'.str_pad((string) $rang, 4, '0', STR_PAD_LEFT),
        ]);

        return $user;
    }

    /**
     * Une ligne d'audit écrite par la vraie porte d'entrée.
     *
     * Pas un `insert` fabriqué à la main : ce qui est vérifié ensuite, c'est
     * que ce que l'application ÉCRIT est bien ce que l'écran RELIT.
     */
    private function traceDePromotion(User $cible): void
    {
        app(AuditLogger::class)->logChange(
            action: 'user.role_changed',
            entity: $cible,
            attribute: 'role',
            from: UserRole::Member->value,
            to: UserRole::SuperAdmin->value,
            reason: 'Promotion demandée par le président',
        );
    }

    #[Test]
    public function le_journal_est_lisible_par_l_administration(): void
    {
        $cible = $this->compte(UserRole::Member);
        $this->traceDePromotion($cible);

        $reponse = $this->actingAs_($this->compte(UserRole::Admin))
            ->getJson('/api/v1/audit-logs')
            ->assertOk();

        $ligne = $reponse->json('data.0');

        self::assertSame('user.role_changed', $ligne['action']);
        self::assertSame('User', $ligne['entity']['type']);
        self::assertSame($cible->id, $ligne['entity']['id']);

        // Les valeurs avant/après sont RENDUES, et décodées. C'est ce qui
        // distingue un journal d'une liste d'événements : « le rôle a changé »
        // ne dit rien, « MEMBER → SUPER_ADMIN » dit tout.
        self::assertSame(['role' => 'MEMBER'], $ligne['old_values']);
        self::assertSame(['role' => 'SUPER_ADMIN'], $ligne['new_values']);
        self::assertSame('Promotion demandée par le président', $ligne['reason']);
    }

    #[Test]
    public function le_tresorier_ne_voit_pas_le_journal(): void
    {
        $this->traceDePromotion($this->compte(UserRole::Member));

        // La personne que ce journal surveille. Le refus est la fonction même
        // du journal, pas un oubli de droits.
        $this->actingAs_($this->compte(UserRole::Treasurer))
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();

        $this->actingAs_($this->compte(UserRole::Treasurer))
            ->getJson('/api/v1/audit-logs/actions')
            ->assertForbidden();
    }

    #[Test]
    public function ni_le_collecteur_ni_le_chef_de_groupe_ni_le_membre(): void
    {
        $this->traceDePromotion($this->compte(UserRole::Member));

        foreach ([UserRole::Collector, UserRole::RideLeader, UserRole::Member] as $role) {
            $this->actingAs_($this->compte($role))
                ->getJson('/api/v1/audit-logs')
                ->assertForbidden();
        }
    }

    #[Test]
    public function le_filtre_par_action_est_alimente_par_la_base(): void
    {
        $this->traceDePromotion($this->compte(UserRole::Member));
        $this->traceDePromotion($this->compte(UserRole::Member));

        $reponse = $this->actingAs_($this->compte(UserRole::Admin))
            ->getJson('/api/v1/audit-logs/actions')
            ->assertOk();

        // Lu en base et non écrit en dur : une liste figée oublierait les
        // actions ajoutées par les phases suivantes, et le filtre deviendrait
        // silencieusement incomplet — on croirait qu'il ne s'est rien passé.
        self::assertSame(
            [['action' => 'user.role_changed', 'count' => 2]],
            $reponse->json('data'),
        );
    }

    #[Test]
    public function le_filtre_ecarte_reellement_les_autres_actions(): void
    {
        $cible = $this->compte(UserRole::Member);
        $this->traceDePromotion($cible);

        app(AuditLogger::class)->log(
            action: 'payment.reversed',
            entity: $cible,
            reason: 'Erreur de saisie',
        );

        $reponse = $this->actingAs_($this->compte(UserRole::Admin))
            ->getJson('/api/v1/audit-logs?action=payment')
            ->assertOk();

        // Un filtre qui rendrait tout serait invisible à l'usage : l'écran
        // afficherait des lignes, l'administrateur les croirait filtrées, et
        // conclurait à tort en cherchant une annulation précise.
        self::assertCount(1, $reponse->json('data'));
        self::assertSame('payment.reversed', $reponse->json('data.0.action'));
    }

    #[Test]
    public function le_journal_n_offre_aucune_route_d_ecriture(): void
    {
        $ecritures = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_contains($route->uri(), 'audit-logs')) {
                continue;
            }

            foreach ($route->methods() as $methode) {
                if (! in_array($methode, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    $ecritures[] = $methode.' '.$route->uri();
                }
            }
        }

        // La tentation viendra : un `DELETE` « pour purger les vieilles
        // lignes », un `PATCH` « pour corriger un motif mal saisi ». Un
        // journal qu'on peut retoucher ne prouve plus rien, et c'est
        // exactement quand on en a besoin qu'on s'en apercevrait.
        self::assertSame(
            [],
            $ecritures,
            "Le journal d'audit doit rester en lecture seule.",
        );
    }

    #[Test]
    public function une_ligne_d_audit_survit_a_la_suppression_de_son_auteur(): void
    {
        $auteur = $this->compte(UserRole::Admin);
        $cible = $this->compte(UserRole::Member);

        $this->actingAs($auteur);
        $this->traceDePromotion($cible);

        // Suppression douce : c'est ce que fait `DELETE /me`. Un effacement
        // franc ferait disparaître l'auteur d'opérations financières, et le
        // journal ne dirait plus qui a fait quoi — or c'est précisément le
        // document qu'on consulte quand quelque chose cloche.
        $auteur->delete();

        $reponse = $this->actingAs_($this->compte(UserRole::Admin))
            ->getJson('/api/v1/audit-logs')
            ->assertOk();

        $ligne = collect($reponse->json('data'))
            ->firstWhere('action', 'user.role_changed');

        self::assertNotNull($ligne, "La trace a disparu avec son auteur.");
        self::assertSame($auteur->name, $ligne['author']['name']);
        self::assertSame(
            1,
            DB::table('audit_logs')->where('action', 'user.role_changed')->count(),
        );
    }
}
