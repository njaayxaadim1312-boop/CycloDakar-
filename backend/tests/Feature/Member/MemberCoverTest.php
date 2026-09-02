<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Enums\UserRole;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * L'image de fond du compte — le « fond d'écran » de chaque membre.
 *
 * Ce qui est vérifié ici tient en une phrase : **chacun choisit le sien, et
 * personne ne choisit celui d'un autre.**
 *
 * C'est déjà la règle de la photo de profil, et il n'y aurait aucune raison
 * qu'un décor soit plus verrouillé qu'un portrait — ni moins.
 */
final class MemberCoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function actingAs_(User $user): static
    {
        return $this->forgetAuthenticatedUser()
            ->withHeader(
                'Authorization',
                'Bearer '.$user->createToken('Test')->plainTextToken,
            );
    }

    private function member(UserRole $role = UserRole::Member): Member
    {
        $user = User::factory()->create(['role' => $role]);

        return Member::factory()->for($user)->create();
    }

    /* ---------------------------------------------------------------------- */

    #[Test]
    public function un_membre_choisit_son_propre_fond_d_ecran(): void
    {
        $membre = $this->member();

        $this->actingAs_($membre->user)
            ->post("/api/v1/members/{$membre->uuid}/cover", [
                'cover' => UploadedFile::fake()->image('corniche.jpg', 1600, 900),
            ])
            ->assertOk()
            // L'URL revient tout de suite : l'écran peut afficher le nouveau
            // fond sans second appel.
            ->assertJsonPath('data.cover_url', fn ($url) => is_string($url) && $url !== '');

        $chemin = $membre->fresh()->cover_path;

        $this->assertNotNull($chemin);
        Storage::disk('public')->assertExists($chemin);

        // Le nom est REGÉNÉRÉ : celui du client peut contenir n'importe quoi.
        $this->assertStringNotContainsString('corniche', $chemin);
    }

    #[Test]
    public function un_membre_ne_change_pas_le_fond_d_un_autre(): void
    {
        $moi = $this->member();
        $autre = $this->member();

        $this->actingAs_($moi->user)
            ->post("/api/v1/members/{$autre->uuid}/cover", [
                'cover' => UploadedFile::fake()->image('paysage.jpg'),
            ])
            ->assertForbidden();

        $this->assertNull($autre->fresh()->cover_path);
    }

    #[Test]
    public function l_administration_peut_changer_celui_d_un_membre(): void
    {
        // Même règle que la photo : l'administration gère les fiches, y compris
        // pour un adhérent qui n'a pas de smartphone.
        $admin = $this->member(UserRole::Admin);
        $membre = $this->member();

        $this->actingAs_($admin->user)
            ->post("/api/v1/members/{$membre->uuid}/cover", [
                'cover' => UploadedFile::fake()->image('lac-rose.jpg'),
            ])
            ->assertOk();

        $this->assertNotNull($membre->fresh()->cover_path);
    }

    #[Test]
    public function changer_de_fond_supprime_l_ancien_fichier(): void
    {
        // Sans cela, chaque changement laisserait un fichier orphelin, et le
        // disque grossirait d'images que plus rien ne désigne.
        $membre = $this->member();

        $this->actingAs_($membre->user)->post("/api/v1/members/{$membre->uuid}/cover", [
            'cover' => UploadedFile::fake()->image('premier.jpg'),
        ])->assertOk();

        $premier = $membre->fresh()->cover_path;

        $this->actingAs_($membre->user)->post("/api/v1/members/{$membre->uuid}/cover", [
            'cover' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        $second = $membre->fresh()->cover_path;

        $this->assertNotSame($premier, $second);
        Storage::disk('public')->assertMissing($premier);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function retirer_le_fond_ramene_au_decor_par_defaut(): void
    {
        $membre = $this->member();

        $this->actingAs_($membre->user)->post("/api/v1/members/{$membre->uuid}/cover", [
            'cover' => UploadedFile::fake()->image('fond.jpg'),
        ])->assertOk();

        $chemin = $membre->fresh()->cover_path;

        $this->actingAs_($membre->user)
            ->deleteJson("/api/v1/members/{$membre->uuid}/cover")
            ->assertOk()
            // `null` et non une image par défaut : c'est au client de décider
            // quoi afficher, et le serveur ne doit pas confondre « rien
            // choisi » avec « a choisi ceci ».
            ->assertJsonPath('data.cover_url', null);

        Storage::disk('public')->assertMissing($chemin);
    }

    #[Test]
    public function un_fichier_qui_n_est_pas_une_image_est_refuse(): void
    {
        $membre = $this->member();

        $this->actingAs_($membre->user)
            ->post("/api/v1/members/{$membre->uuid}/cover", [
                'cover' => UploadedFile::fake()->create('script.php', 8, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cover');

        $this->assertNull($membre->fresh()->cover_path);
    }
}
