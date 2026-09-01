<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use App\Services\QrCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * QR Code personnel.
 *
 * Deux exigences gouvernent ce module, et elles ne se négocient pas.
 *
 * 1. **Le QR ne contient aucune donnée personnelle.** Ni nom, ni téléphone, ni
 *    matricule : un jeton opaque, et rien d'autre. Un QR photographié dans la
 *    rue ne doit rien dire de son porteur.
 * 2. **Le scan n'est pas un annuaire.** Il est réservé aux collecteurs, limité
 *    en débit, et ne renvoie que l'identité minimale — reconnaître quelqu'un,
 *    pas aspirer le fichier des membres un QR à la fois.
 */
final class QrCodeTest extends TestCase
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

    private function user(UserRole $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Member::factory()->for($user)->create();

        return $user;
    }

    /* ------------------------------------------------------------- contenu */

    #[Test]
    public function le_qr_ne_contient_aucune_donnee_personnelle(): void
    {
        $member = Member::factory()->create([
            'first_name' => 'Khadim',
            'last_name' => 'Ndiaye',
            'phone' => '771234567',
            'matricule' => 'CD-000042',
        ]);

        $payload = app(QrCodeGenerator::class)->payload($member);

        // Le contenu encodé, en entier, ne doit rien révéler.
        $this->assertStringNotContainsString('Khadim', $payload);
        $this->assertStringNotContainsString('Ndiaye', $payload);
        $this->assertStringNotContainsString('771234567', $payload);
        $this->assertStringNotContainsString('CD-000042', $payload);
        $this->assertStringNotContainsString($member->uuid, $payload);

        /*
         | L'égalité stricte est la vraie garantie : le contenu est le préfixe
         | du club suivi du jeton, et RIEN d'autre.
         |
         | Chercher l'identifiant interne caractère par caractère n'aurait
         | aucun sens — un « 1 » se trouve dans n'importe quel jeton aléatoire,
         | et l'assertion aurait échoué pour de mauvaises raisons.
         */
        $this->assertSame('CD:'.$member->qr_token, $payload);
    }

    #[Test]
    public function le_jeton_est_opaque_et_assez_long_pour_ne_pas_se_deviner(): void
    {
        $member = Member::factory()->create();

        // 43 caractères base64url = 32 octets d'aléa. Deviner un jeton au
        // hasard est hors de portée, ce qui autorise à distinguer « QR
        // inconnu » de « QR mal formé » sans risque d'énumération.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $member->qr_token);
    }

    #[Test]
    public function deux_membres_n_ont_jamais_le_meme_jeton(): void
    {
        $jetons = Member::factory()->count(30)->create()->pluck('qr_token');

        $this->assertCount(30, $jetons->unique());
    }

    /* --------------------------------------------------------------- image */

    #[Test]
    public function l_image_est_un_svg(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs_($this->user(UserRole::Member))
            ->get("/api/v1/members/{$member->uuid}/qr")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=utf-8');

        $this->assertStringContainsString('<svg', $response->getContent());
    }

    #[Test]
    public function l_image_n_est_jamais_mise_en_cache(): void
    {
        // Un jeton peut être révoqué à tout moment : une image en cache
        // afficherait un QR devenu invalide, et le membre ne comprendrait pas
        // pourquoi il n'est plus reconnu.
        $member = Member::factory()->create();

        $response = $this->actingAs_($this->user(UserRole::Member))
            ->get("/api/v1/members/{$member->uuid}/qr")
            ->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function un_visiteur_n_obtient_pas_d_image(): void
    {
        $member = Member::factory()->create();

        $this->get("/api/v1/members/{$member->uuid}/qr")->assertStatus(401);
    }

    /* ---------------------------------------------------------------- scan */

    #[Test]
    public function un_collecteur_retrouve_le_membre_par_son_qr(): void
    {
        $member = Member::factory()->create([
            'first_name' => 'Aminata',
            'last_name' => 'Cisse',
        ]);

        $this->actingAs_($this->user(UserRole::Collector))
            ->getJson("/api/v1/members/resolve/CD:{$member->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $member->uuid)
            ->assertJsonPath('data.full_name', 'Aminata Cisse')
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function le_prefixe_est_facultatif(): void
    {
        // Un membre peut avoir un vieux QR imprimé : refuser de le lire pour
        // une question de forme serait absurde.
        $member = Member::factory()->create();

        $this->actingAs_($this->user(UserRole::Collector))
            ->getJson("/api/v1/members/resolve/{$member->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $member->uuid);
    }

    #[Test]
    public function le_scan_est_refuse_a_un_membre_ordinaire(): void
    {
        // Scanner, c'est identifier quelqu'un. Ce n'est pas le geste d'un
        // membre, et dès la phase 12 cela permettra d'encaisser en son nom.
        $member = Member::factory()->create();

        $this->actingAs_($this->user(UserRole::Member))
            ->getJson("/api/v1/members/resolve/{$member->qr_token}")
            ->assertStatus(403);
    }

    #[Test]
    public function le_scan_ne_renvoie_ni_telephone_ni_adresse(): void
    {
        // Un scan doit permettre de reconnaître quelqu'un, pas d'aspirer
        // l'annuaire un QR à la fois.
        $member = Member::factory()->create(['phone' => '771234567', 'email' => 'k@cd.sn']);

        $data = $this->actingAs_($this->user(UserRole::Collector))
            ->getJson("/api/v1/members/resolve/{$member->qr_token}")
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('phone', $data);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('birth_date', $data);
        $this->assertArrayNotHasKey('emergency_contact_phone', $data);
    }

    #[Test]
    public function un_code_etranger_est_ecarte_sans_toucher_a_la_base(): void
    {
        // Un membre scanne un paquet de biscuits : le code-barres EAN ne
        // ressemble en rien à un jeton du club, et la forme suffit à
        // conclure sans interroger la base.
        $this->actingAs_($this->user(UserRole::Collector))
            ->getJson('/api/v1/members/resolve/3017620422003')
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_QR');
    }

    #[Test]
    public function un_jeton_revoque_le_dit_clairement(): void
    {
        // Le membre doit savoir que son QR a été régénéré, plutôt que de
        // croire à une panne du scanner.
        $member = Member::factory()->create();
        $ancien = $member->qr_token;

        $member->rotateQrToken();

        $this->actingAs_($this->user(UserRole::Collector))
            ->getJson("/api/v1/members/resolve/{$ancien}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'QR_NOT_FOUND');
    }

    #[Test]
    public function la_rotation_change_bien_le_jeton(): void
    {
        $member = Member::factory()->create();
        $ancien = $member->qr_token;

        $member->rotateQrToken();

        $this->assertNotSame($ancien, $member->fresh()->qr_token);
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]{43}$/',
            $member->fresh()->qr_token,
        );
    }

    #[Test]
    public function un_ancien_membre_est_signale_comme_inactif(): void
    {
        // Un collecteur ne réclame pas de cotisation à quelqu'un qui a quitté
        // le club : il doit le voir immédiatement.
        $member = Member::factory()->former()->create();

        $this->actingAs_($this->user(UserRole::Collector))
            ->getJson("/api/v1/members/resolve/{$member->qr_token}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', MemberStatus::Former->value);
    }

    #[Test]
    public function le_scan_est_limite_en_debit(): void
    {
        /*
         | Sans limite, l'API deviendrait un oracle : on pourrait éprouver des
         | jetons en rafale. Deviner 43 caractères reste hors de portée, mais
         | une porte qu'on peut frapper indéfiniment finit toujours par
         | s'ouvrir sur autre chose.
         */
        $collecteur = $this->user(UserRole::Collector);
        $inconnu = str_repeat('a', 43);

        $limite = false;

        for ($i = 0; $i < 70; $i++) {
            $response = $this->actingAs_($collecteur)
                ->getJson("/api/v1/members/resolve/{$inconnu}");

            if ($response->status() === 429) {
                $limite = true;

                break;
            }
        }

        $this->assertTrue($limite, "Le scan n'est pas limité en débit.");
    }
}
