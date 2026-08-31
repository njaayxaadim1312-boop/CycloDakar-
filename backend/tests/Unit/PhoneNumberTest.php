<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La normalisation du téléphone est critique : c'est elle qui empêche un même
 * membre de créer plusieurs comptes en saisissant son numéro différemment,
 * et qui garantit qu'il retrouve son compte à la connexion.
 */
final class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function numbers(): array
    {
        return [
            'brut' => ['771234567', '771234567'],
            'avec espaces' => ['77 123 45 67', '771234567'],
            'avec tirets' => ['77-123-45-67', '771234567'],
            'avec points' => ['77.123.45.67', '771234567'],
            'indicatif +221' => ['+221771234567', '771234567'],
            'indicatif +221 espace' => ['+221 77 123 45 67', '771234567'],
            'indicatif 00221' => ['00221771234567', '771234567'],
            'indicatif 221 sans plus' => ['221771234567', '771234567'],
            'parentheses' => ['(+221) 77 123 45 67', '771234567'],
            'trop court' => ['7712345', null],
            'trop long' => ['77123456789', null],
            'vide' => ['', null],
            'lettres seules' => ['abc', null],
        ];
    }

    #[Test]
    #[DataProvider('numbers')]
    public function il_ramene_les_numeros_a_une_forme_canonique(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    #[Test]
    public function toutes_les_ecritures_d_un_meme_numero_donnent_le_meme_resultat(): void
    {
        // C'est LA propriété qui empêche les comptes en double.
        $variants = [
            '771234567',
            '77 123 45 67',
            '+221771234567',
            '00221 77 123 45 67',
            '(221) 77-123-45-67',
        ];

        $normalized = array_map(PhoneNumber::normalize(...), $variants);

        $this->assertCount(1, array_unique($normalized));
        $this->assertSame('771234567', $normalized[0]);
    }

    #[Test]
    public function il_accepte_les_mobiles_et_refuse_le_reste(): void
    {
        $this->assertTrue(PhoneNumber::isValid('77 123 45 67'));
        $this->assertTrue(PhoneNumber::isValid('+221 70 987 65 43'));

        // Un fixe (33...) n'est pas un mobile : on ne peut pas y envoyer de SMS.
        $this->assertFalse(PhoneNumber::isValid('338001234'));
        $this->assertFalse(PhoneNumber::isValid('123'));
        $this->assertFalse(PhoneNumber::isValid(null));
    }

    #[Test]
    public function il_met_en_forme_pour_l_affichage(): void
    {
        $this->assertSame('77 123 45 67', PhoneNumber::format('+221771234567'));
        $this->assertSame('+221771234567', PhoneNumber::toInternational('77 123 45 67'));
    }

    #[Test]
    public function il_renvoie_l_entree_telle_quelle_si_elle_est_inexploitable(): void
    {
        // Mieux vaut afficher ce que la personne a saisi qu'un « null » opaque.
        $this->assertSame('inconnu', PhoneNumber::format('inconnu'));
    }
}
