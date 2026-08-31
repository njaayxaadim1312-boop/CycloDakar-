<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function membre(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Awa Ndiaye',
            'email' => 'awa@cyclodakar.sn',
            'phone' => '771234567',
            'password' => Hash::make('cyclo2026'),
        ], $attributes));
    }

    /** @return array<string, array{string}> */
    public static function identifiants(): array
    {
        return [
            'email' => ['awa@cyclodakar.sn'],
            'email en majuscules' => ['AWA@CYCLODAKAR.SN'],
            'telephone brut' => ['771234567'],
            'telephone espace' => ['77 123 45 67'],
            'telephone international' => ['+221771234567'],
        ];
    }

    #[Test]
    #[DataProvider('identifiants')]
    public function on_peut_se_connecter_par_email_ou_par_telephone(string $login): void
    {
        $this->membre();

        $this->postJson('/api/v1/auth/login', [
            'login' => $login,
            'password' => 'cyclo2026',
            'device_name' => 'Test',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user']])
            ->assertJsonPath('data.user.name', 'Awa Ndiaye');
    }

    #[Test]
    public function un_mauvais_mot_de_passe_est_refuse(): void
    {
        $this->membre();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'awa@cyclodakar.sn',
            'password' => 'mauvais',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    #[Test]
    public function un_compte_inexistant_donne_exactement_la_meme_reponse_qu_un_mauvais_mot_de_passe(): void
    {
        // Sans cela, l'API permettrait de savoir qui est membre du club en
        // comparant les messages d'erreur.
        $this->membre();

        $inexistant = $this->postJson('/api/v1/auth/login', [
            'login' => 'inconnu@example.sn',
            'password' => 'cyclo2026',
        ]);

        $mauvaisMotDePasse = $this->postJson('/api/v1/auth/login', [
            'login' => 'awa@cyclodakar.sn',
            'password' => 'mauvais',
        ]);

        $this->assertSame($inexistant->status(), $mauvaisMotDePasse->status());
        $this->assertSame($inexistant->json(), $mauvaisMotDePasse->json());
    }

    #[Test]
    public function un_compte_desactive_ne_peut_pas_se_connecter(): void
    {
        $this->membre(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'awa@cyclodakar.sn',
            'password' => 'cyclo2026',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    #[Test]
    public function la_connexion_enregistre_la_date_et_l_ip(): void
    {
        $user = $this->membre(['last_login_at' => null]);

        $this->postJson('/api/v1/auth/login', [
            'login' => '77 123 45 67',
            'password' => 'cyclo2026',
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);
    }

    #[Test]
    public function les_tentatives_repetees_sont_bloquees(): void
    {
        $this->membre();

        // Le limiteur est réglé à 5 par minute et par identifiant.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'login' => 'awa@cyclodakar.sn',
                'password' => 'mauvais',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'login' => 'awa@cyclodakar.sn',
            'password' => 'mauvais',
        ])->assertStatus(429);
    }

    #[Test]
    public function une_connexion_reussie_libere_le_compteur_de_tentatives(): void
    {
        // Sinon un membre qui se trompe quatre fois puis réussit resterait
        // à une tentative du blocage pour rien.
        $this->membre();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'login' => 'awa@cyclodakar.sn',
                'password' => 'mauvais',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'login' => 'awa@cyclodakar.sn',
            'password' => 'cyclo2026',
        ])->assertOk();

        // Le compteur est remis à zéro : on peut à nouveau se tromper 5 fois.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'login' => 'awa@cyclodakar.sn',
                'password' => 'mauvais',
            ])->assertStatus(422);
        }
    }

    #[Test]
    public function la_reponse_ne_contient_jamais_le_mot_de_passe(): void
    {
        $this->membre();

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'awa@cyclodakar.sn',
            'password' => 'cyclo2026',
        ])->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }

    #[Test]
    public function le_jeton_porte_les_capacites_du_role(): void
    {
        $tresorier = User::factory()->treasurer()->create([
            'email' => 'tresorier@cyclodakar.sn',
            'password' => Hash::make('cyclo2026'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'tresorier@cyclodakar.sn',
            'password' => 'cyclo2026',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.abilities.manage_finance', true)
            ->assertJsonPath('data.user.abilities.administer', false);

        $this->assertSame(
            ['finance:*', 'collect:*', 'member:*'],
            $tresorier->tokens()->first()->abilities,
        );
    }
}
