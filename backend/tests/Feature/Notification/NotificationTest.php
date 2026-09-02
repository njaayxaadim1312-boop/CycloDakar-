<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Enums\ParticipationStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\Participation;
use App\Models\ParticipationMember;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\ExpenseAwaitingApproval;
use App\Notifications\PaymentCancelled;
use App\Notifications\PaymentReceived;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Notifications.
 *
 * TROIS PROPRIÉTÉS, ET DEUX SONT DES QUESTIONS DE CONFIANCE.
 *
 * 1. **Elles sont strictement personnelles.** Une notification porte un
 *    montant, une dette, une décision financière. Aucune route ne prend
 *    d'identifiant d'utilisateur : on ne lit que les siennes.
 * 2. **Elles suivent les actes réels.** Un encaissement notifie le membre ; une
 *    annulation aussi — il a un reçu en main et se croit à jour.
 * 3. **Un approbateur n'est pas invité à approuver sa propre dépense.**
 */
final class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceSeeder::class);
    }

    /* ---------------------------------------------------------------------- */

    private function actingAs_(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader(
                'Authorization',
                'Bearer '.$user->createToken('Test')->plainTextToken,
            );
    }

    private function user(UserRole $role = UserRole::Member): User
    {
        $user = User::factory()->create(['role' => $role]);
        Member::factory()->for($user)->create();

        return $user;
    }

    /** Une collecte ouverte avec un membre qui doit 5 000 FCFA. */
    private function dette(User $collecteur, ?User $debiteur = null): ParticipationMember
    {
        $participation = Participation::factory()->create([
            'status' => ParticipationStatus::Open,
            'expected_amount' => 5_000,
            'created_by' => $collecteur->id,
        ]);

        return ParticipationMember::factory()->create([
            'participation_id' => $participation->id,
            'member_id' => $debiteur === null
                ? Member::factory()->create()->id
                : $debiteur->member->id,
            'expected_amount' => 5_000,
            'assigned_collector_id' => $collecteur->id,
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Elles suivent les actes réels                                          */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_encaissement_notifie_le_membre_avec_son_numero_de_recu(): void
    {
        /*
         | Reportée depuis la phase 12, où le canal n'existait pas. Le numéro
         | de reçu EST l'essentiel : « paiement enregistré » sans référence ne
         | permet rien de vérifier.
         */
        Notification::fake();

        $collecteur = $this->user(UserRole::Collector);
        $debiteur = $this->user();
        $ligne = $this->dette($collecteur, $debiteur);

        $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$ligne->participation->uuid}/payments",
            [
                'member' => $ligne->member->uuid,
                'amount' => 5_000,
                'method' => 'CASH',
                'idempotency_key' => 'notif-test-001',
            ],
        )->assertCreated();

        Notification::assertSentTo($debiteur, PaymentReceived::class, function ($notification) {
            $contenu = $notification->toDatabase($notification);

            return str_contains($contenu['body'], 'RC-')
                && str_contains($contenu['body'], '5')
                && $contenu['code'] === 'payment.received';
        });
    }

    #[Test]
    public function une_annulation_previent_le_membre_avec_le_motif(): void
    {
        /*
         | Celle-ci n'est pas optionnelle. Un membre à qui l'on a remis un reçu
         | se croit à jour ; s'il apprend l'annulation devant un collecteur, en
         | public, la faute retombera sur le club — et il aura raison.
         */
        Notification::fake();

        $collecteur = $this->user(UserRole::Collector);
        $tresorier = $this->user(UserRole::Treasurer);
        $debiteur = $this->user();
        $ligne = $this->dette($collecteur, $debiteur);

        $uuid = $this->actingAs_($collecteur)->postJson(
            "/api/v1/participations/{$ligne->participation->uuid}/payments",
            [
                'member' => $ligne->member->uuid,
                'amount' => 5_000,
                'method' => 'CASH',
                'idempotency_key' => 'notif-test-002',
            ],
        )->json('data.uuid');

        $this->actingAs_($tresorier)->postJson("/api/v1/payments/{$uuid}/cancel", [
            'reason' => 'Somme non retrouvée au pointage du soir.',
        ])->assertOk();

        Notification::assertSentTo($debiteur, PaymentCancelled::class, function ($notification) {
            return str_contains(
                $notification->toDatabase($notification)['body'],
                'non retrouvée au pointage',
            );
        });
    }

    #[Test]
    public function une_depense_a_valider_previent_les_approbateurs_sauf_le_demandeur(): void
    {
        Notification::fake();

        $demandeur = $this->user(UserRole::Treasurer);
        $autre = $this->user(UserRole::Admin);

        $this->actingAs_($demandeur)->postJson('/api/v1/expenses', [
            'category' => 'TRANSPORT',
            // Au-dessus du seuil : elle reste en attente et appelle un second
            // regard.
            'amount' => 80_000,
            'label' => 'Bus Lac Rose',
        ])->assertCreated();

        Notification::assertSentTo($autre, ExpenseAwaitingApproval::class);

        // On n'approuve pas sa propre dépense : l'inviter à le faire serait au
        // mieux inutile, au pire une tentation.
        Notification::assertNotSentTo($demandeur, ExpenseAwaitingApproval::class);
    }

    #[Test]
    public function une_petite_depense_approuvee_seule_ne_se_notifie_pas_a_elle_meme(): void
    {
        // Notifier le trésorier « votre dépense a été approuvée » deux
        // secondes après qu'il l'a saisie serait du bruit pur — et c'est ainsi
        // qu'on apprend aux gens à ignorer les notifications.
        Notification::fake();

        $tresorier = $this->user(UserRole::Treasurer);

        $this->actingAs_($tresorier)->postJson('/api/v1/expenses', [
            'category' => 'RAVITAILLEMENT',
            'amount' => 3_000,
            'label' => 'Eau minérale',
        ])->assertCreated();

        Notification::assertNothingSentTo($tresorier);
    }

    /* ---------------------------------------------------------------------- */
    /* Elles sont strictement personnelles                                    */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_membre_ne_lit_que_ses_propres_notifications(): void
    {
        $moi = $this->user();
        $autre = $this->user();

        $moi->notify(new PaymentReceivedStub);
        $autre->notify(new PaymentReceivedStub);

        $this->actingAs_($moi)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread', 1);
    }

    #[Test]
    public function marquer_lue_une_notification_qui_n_est_pas_la_sienne_donne_404(): void
    {
        /*
         | 404 et NON 403 : une notification qui n'est pas la sienne ne doit pas
         | être distinguable d'une notification inexistante, sinon on pourrait
         | éprouver l'existence d'un identifiant.
         */
        $moi = $this->user();
        $autre = $this->user();

        $autre->notify(new PaymentReceivedStub);
        $id = $autre->notifications()->firstOrFail()->id;

        $this->actingAs_($moi)
            ->postJson("/api/v1/notifications/{$id}/read")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOTIFICATION_NOT_FOUND');

        $this->assertNull($autre->notifications()->firstOrFail()->read_at);
    }

    #[Test]
    public function on_marque_une_notification_puis_toutes(): void
    {
        $moi = $this->user();

        $moi->notify(new PaymentReceivedStub);
        $moi->notify(new PaymentReceivedStub);
        $moi->notify(new PaymentReceivedStub);

        $premiere = $moi->notifications()->firstOrFail()->id;

        $this->actingAs_($moi)
            ->postJson("/api/v1/notifications/{$premiere}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->assertSame(2, $moi->unreadNotifications()->count());

        $this->actingAs_($moi)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 2)
            ->assertJsonPath('data.unread', 0);

        $this->assertSame(0, $moi->fresh()->unreadNotifications()->count());
    }

    #[Test]
    public function le_compteur_de_non_lues_est_une_route_a_part(): void
    {
        // Charger trente notifications pour afficher une pastille serait
        // absurde : l'interface interroge ce compteur en continu.
        $moi = $this->user();
        $moi->notify(new PaymentReceivedStub);

        $this->actingAs_($moi)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 1);
    }

    /* ---------------------------------------------------------------------- */
    /* Les appareils                                                          */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_jeton_change_de_proprietaire_au_lieu_d_etre_duplique(): void
    {
        /*
         | Un téléphone prêté, revendu ou partagé garde son jeton Expo. Si
         | quelqu'un d'autre s'y connecte, l'ancien utilisateur ne doit PLUS
         | recevoir sur un appareil qui n'est plus le sien.
         */
        $premier = $this->user();
        $second = $this->user();

        $jeton = 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]';

        $this->actingAs_($premier)
            ->postJson('/api/v1/devices', ['token' => $jeton, 'device_name' => 'Redmi Note 12'])
            ->assertOk();

        $this->actingAs_($second)
            ->postJson('/api/v1/devices', ['token' => $jeton, 'device_name' => 'Redmi Note 12'])
            ->assertOk();

        $this->assertSame(1, PushToken::count());
        $this->assertSame($second->id, PushToken::firstOrFail()->user_id);
    }

    #[Test]
    public function retirer_son_appareil_coupe_le_push_pas_les_notifications(): void
    {
        // C'est le réglage « ne plus me notifier » : pas de jeton, pas de push.
        // Les notifications en base continuent — elles ne réveillent personne,
        // et un membre doit retrouver ce qu'on lui a dit.
        $moi = $this->user();
        $jeton = 'ExponentPushToken[yyyyyyyyyyyyyyyyyyyyyy]';

        $this->actingAs_($moi)->postJson('/api/v1/devices', ['token' => $jeton])->assertOk();
        $this->actingAs_($moi)
            ->deleteJson('/api/v1/devices', ['token' => $jeton])
            ->assertOk()
            ->assertJsonPath('data.forgotten', true);

        $this->assertSame(0, PushToken::count());

        $moi->notify(new PaymentReceivedStub);

        $this->actingAs_($moi)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}

/**
 * Une notification minimale, pour éprouver le transport sans dépendre d'un
 * paiement réel.
 *
 * Elle vit ici plutôt que dans `app/` : c'est un outil de test, et la placer
 * dans le code de production laisserait croire qu'elle sert à quelque chose.
 */
final class PaymentReceivedStub extends \App\Notifications\ClubNotification
{
    public function code(): string
    {
        return 'test.stub';
    }

    public function title(object $notifiable): string
    {
        return 'Notification de test';
    }

    public function body(object $notifiable): string
    {
        return 'Contenu de test.';
    }

    public function url(object $notifiable): string
    {
        return '/';
    }
}
