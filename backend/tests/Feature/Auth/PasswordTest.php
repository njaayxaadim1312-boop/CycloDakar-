<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------- */
    /* Changement par un utilisateur connecté                                 */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_membre_connecte_peut_changer_son_mot_de_passe(): void
    {
        $user = User::factory()->create(['password' => Hash::make('ancien2026')]);
        $token = $user->createToken('Test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'ancien2026',
                'password' => 'nouveau2026',
                'password_confirmation' => 'nouveau2026',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('nouveau2026', $user->fresh()->password));
    }

    #[Test]
    public function le_mot_de_passe_actuel_est_exige(): void
    {
        // Un téléphone laissé déverrouillé ne doit pas suffire à verrouiller
        // le compte de son propriétaire.
        $user = User::factory()->create(['password' => Hash::make('ancien2026')]);
        $token = $user->createToken('Test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'je-ne-sais-pas',
                'password' => 'nouveau2026',
                'password_confirmation' => 'nouveau2026',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('ancien2026', $user->fresh()->password));
    }

    #[Test]
    public function le_nouveau_mot_de_passe_doit_differer_de_l_ancien(): void
    {
        $user = User::factory()->create(['password' => Hash::make('ancien2026')]);
        $token = $user->createToken('Test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'ancien2026',
                'password' => 'ancien2026',
                'password_confirmation' => 'ancien2026',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function on_peut_deconnecter_les_autres_appareils_en_changeant_de_mot_de_passe(): void
    {
        $user = User::factory()->create(['password' => Hash::make('ancien2026')]);
        $courant = $user->createToken('Navigateur')->plainTextToken;
        $autre = $user->createToken('Telephone')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$courant}")
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'ancien2026',
                'password' => 'nouveau2026',
                'password_confirmation' => 'nouveau2026',
                'logout_other_devices' => true,
            ])
            ->assertOk();

        // La session courante survit : l'utilisateur vient de prouver son
        // identité en donnant son mot de passe actuel.
        $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', "Bearer {$courant}")
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', "Bearer {$autre}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    /* ---------------------------------------------------------------------- */
    /* Mot de passe oublié                                                    */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function une_demande_de_reinitialisation_envoie_un_lien(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'awa@cyclodakar.sn']);

        $this->postJson('/api/v1/auth/forgot-password', ['login' => 'awa@cyclodakar.sn'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    #[Test]
    public function on_peut_demander_une_reinitialisation_avec_son_numero(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'awa@cyclodakar.sn',
            'phone' => '771234567',
        ]);

        $this->postJson('/api/v1/auth/forgot-password', ['login' => '+221 77 123 45 67'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    #[Test]
    public function un_identifiant_inconnu_donne_la_meme_reponse_qu_un_identifiant_connu(): void
    {
        // Sinon l'API deviendrait un moyen de vérifier qui est membre du club.
        Notification::fake();

        User::factory()->create(['email' => 'awa@cyclodakar.sn']);

        $connu = $this->postJson('/api/v1/auth/forgot-password', ['login' => 'awa@cyclodakar.sn']);
        $inconnu = $this->postJson('/api/v1/auth/forgot-password', ['login' => 'inconnu@example.sn']);

        $this->assertSame($connu->status(), $inconnu->status());
        $this->assertSame($connu->json(), $inconnu->json());

        Notification::assertCount(1);
    }

    #[Test]
    public function un_compte_sans_email_ne_peut_pas_se_reinitialiser_seul(): void
    {
        // On le dit franchement plutôt que de faire semblant d'envoyer un mail.
        User::factory()->phoneOnly()->create(['phone' => '771234567']);

        $this->postJson('/api/v1/auth/forgot-password', ['login' => '771234567'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_EMAIL_ON_ACCOUNT');
    }

    /* ---------------------------------------------------------------------- */
    /* Réinitialisation                                                       */
    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_jeton_valide_permet_de_choisir_un_nouveau_mot_de_passe(): void
    {
        $user = User::factory()->create([
            'email' => 'awa@cyclodakar.sn',
            'password' => Hash::make('ancien2026'),
        ]);

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'login' => 'awa@cyclodakar.sn',
            'password' => 'nouveau2026',
            'password_confirmation' => 'nouveau2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('nouveau2026', $user->fresh()->password));
    }

    #[Test]
    public function la_reinitialisation_revoque_toutes_les_sessions(): void
    {
        // Si la demande fait suite à une compromission, l'intrus doit perdre
        // l'accès immédiatement.
        $user = User::factory()->create(['email' => 'awa@cyclodakar.sn']);
        $intrus = $user->createToken('Appareil inconnu')->plainTextToken;

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'login' => 'awa@cyclodakar.sn',
            'password' => 'nouveau2026',
            'password_confirmation' => 'nouveau2026',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->forgetAuthenticatedUser()
            ->withHeader('Authorization', "Bearer {$intrus}")
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    #[Test]
    public function un_jeton_invalide_est_refuse(): void
    {
        $user = User::factory()->create([
            'email' => 'awa@cyclodakar.sn',
            'password' => Hash::make('ancien2026'),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'jeton-invente',
            'login' => 'awa@cyclodakar.sn',
            'password' => 'nouveau2026',
            'password_confirmation' => 'nouveau2026',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_RESET_TOKEN');

        $this->assertTrue(Hash::check('ancien2026', $user->fresh()->password));
    }

    #[Test]
    public function un_jeton_ne_sert_qu_une_fois(): void
    {
        $user = User::factory()->create(['email' => 'awa@cyclodakar.sn']);
        $token = Password::createToken($user);

        $payload = [
            'token' => $token,
            'login' => 'awa@cyclodakar.sn',
            'password' => 'nouveau2026',
            'password_confirmation' => 'nouveau2026',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
    }

    #[Test]
    public function demander_plusieurs_liens_n_empeche_pas_d_utiliser_le_dernier(): void
    {
        // Piège classique : un compteur commun entre « demander un lien » et
        // « utiliser le lien ». Un membre qui redemande cinq fois parce qu'il
        // ne recevait rien se retrouverait alors incapable d'utiliser celui
        // qui finit par arriver. Les deux limiteurs sont donc distincts.
        Notification::fake();

        $user = User::factory()->create(['email' => 'awa@cyclodakar.sn']);

        // On épuise la limite de DEMANDE (5 par heure).
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['login' => 'awa@cyclodakar.sn'])
                ->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['login' => 'awa@cyclodakar.sn'])
            ->assertStatus(429);

        // La réinitialisation, elle, doit rester possible.
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'login' => 'awa@cyclodakar.sn',
            'password' => 'nouveau2026',
            'password_confirmation' => 'nouveau2026',
        ])->assertOk();
    }

    #[Test]
    public function un_refus_pour_exces_de_requetes_est_renvoye_en_francais(): void
    {
        // Le middleware de Laravel repond « Too Many Attempts. » : l'API doit
        // rester en francais et garder son enveloppe habituelle.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['login' => 'inconnu@test.sn']);
        }

        $this->postJson('/api/v1/auth/forgot-password', ['login' => 'inconnu@test.sn'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')
            ->assertJsonPath('message', 'Trop de requêtes. Patientez avant de réessayer.');
    }
}
