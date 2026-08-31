<?php

declare(strict_types=1);

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Models\User;
use App\Services\MatriculeGenerator;
use App\Services\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Génération du matricule club.
 *
 * C'est l'identifiant que le club utilisera pendant des années, sur les
 * listes papier comme dans les collectes. Trois propriétés comptent :
 * il est séquentiel, unique, et jamais réattribué.
 */
final class MatriculeTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MemberService
    {
        return app(MemberService::class);
    }

    #[Test]
    public function le_premier_membre_recoit_cd_000001(): void
    {
        $member = $this->service()->create([
            'first_name' => 'Awa',
            'last_name' => 'Ndiaye',
        ]);

        $this->assertSame('CD-000001', $member->matricule);
    }

    #[Test]
    public function les_matricules_s_incrementent(): void
    {
        $service = $this->service();

        $matricules = collect(range(1, 5))
            ->map(fn (int $i) => $service->create([
                'first_name' => 'Membre',
                'last_name' => "Numero {$i}",
            ])->matricule)
            ->all();

        $this->assertSame(
            ['CD-000001', 'CD-000002', 'CD-000003', 'CD-000004', 'CD-000005'],
            $matricules,
        );
    }

    #[Test]
    public function un_matricule_n_est_jamais_reattribue(): void
    {
        // Un membre part, sa fiche est archivée. Son matricule reste pris :
        // les listes papier et les archives du club y font référence.
        $service = $this->service();

        $service->create(['first_name' => 'Premier', 'last_name' => 'Membre']);
        $second = $service->create(['first_name' => 'Deuxieme', 'last_name' => 'Membre']);

        $this->assertSame('CD-000002', $second->matricule);

        $second->delete(); // suppression douce

        $third = $service->create(['first_name' => 'Troisieme', 'last_name' => 'Membre']);

        $this->assertSame('CD-000003', $third->matricule);
        $this->assertNotSame($second->matricule, $third->matricule);
    }

    #[Test]
    public function le_generateur_tient_compte_des_membres_archives(): void
    {
        Member::factory()->count(3)->create();
        Member::factory()->create(['matricule' => 'CD-000042'])->delete();

        $next = app(MatriculeGenerator::class)->nextInOwnTransaction();

        $this->assertSame('CD-000043', $next);
    }

    #[Test]
    public function le_matricule_est_unique_en_base(): void
    {
        Member::factory()->create(['matricule' => 'CD-000001']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Member::factory()->create(['matricule' => 'CD-000001']);
    }

    #[Test]
    public function chaque_membre_recoit_un_jeton_qr_unique_et_opaque(): void
    {
        $members = Member::factory()->count(10)->create();
        $tokens = $members->pluck('qr_token');

        // Unicité.
        $this->assertCount(10, $tokens->unique());

        foreach ($members as $member) {
            // 32 octets en base64 URL, sans remplissage.
            $this->assertSame(43, strlen($member->qr_token));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $member->qr_token);

            // Le jeton ne doit contenir AUCUNE donnée personnelle : c'est ce
            // qui rend inoffensif un QR photographié par un tiers.
            $this->assertStringNotContainsString($member->matricule, $member->qr_token);
            $this->assertStringNotContainsString((string) $member->phone, $member->qr_token);
            $this->assertStringNotContainsString($member->last_name, $member->qr_token);
        }
    }

    #[Test]
    public function la_rotation_change_le_jeton_qr(): void
    {
        $member = Member::factory()->create();
        $before = $member->qr_token;

        $member->rotateQrToken();

        $this->assertNotSame($before, $member->qr_token);
        $this->assertNotNull($member->qr_rotated_at);
    }

    #[Test]
    public function l_inscription_cree_le_compte_et_la_fiche_ensemble(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Khadim Ndiaye Fall',
            'phone' => '771234567',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
        ])->assertCreated();

        $user = User::firstWhere('phone', '771234567');
        $member = $user->member;

        $this->assertNotNull($member);
        $this->assertSame('CD-000001', $member->matricule);
        // Premier mot = prénom, le reste = nom.
        $this->assertSame('Khadim', $member->first_name);
        $this->assertSame('Ndiaye Fall', $member->last_name);
        $this->assertSame('771234567', $member->phone);
    }

    #[Test]
    public function un_nom_en_un_seul_mot_ne_bloque_pas_l_inscription(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Modou',
            'phone' => '771234567',
            'password' => 'cyclo2026',
            'password_confirmation' => 'cyclo2026',
        ])->assertCreated();

        $member = User::firstWhere('phone', '771234567')->member;

        $this->assertSame('Modou', $member->first_name);
        // On n'invente pas de nom de famille ; le membre corrigera.
        $this->assertSame('—', $member->last_name);
    }
}
