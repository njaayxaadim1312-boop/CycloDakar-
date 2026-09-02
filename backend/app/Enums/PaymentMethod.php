<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Comment l'argent est arrivé.
 *
 * Au Sénégal, l'essentiel des paiements ne passe pas par une banque : Wave et
 * Orange Money pèsent plus lourd que le virement. Les distinguer n'est pas
 * cosmétique — c'est ce qui permet au trésorier de rapprocher la caisse
 * physique de ce que dit l'application, poste par poste.
 *
 * L'espèce est le seul moyen qui alimente réellement la caisse en billets ;
 * les autres arrivent sur un compte. Le club ne tient pour l'instant qu'une
 * seule caisse — la distinction est portée par le moyen de paiement, et
 * `movesPhysicalCash()` existe pour le jour où deux comptes seront tenus
 * séparément (PHASE 13).
 */
enum PaymentMethod: string
{
    case Cash = 'CASH';
    case Wave = 'WAVE';
    case OrangeMoney = 'ORANGE_MONEY';
    case FreeMoney = 'FREE_MONEY';
    case Transfer = 'TRANSFER';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::Wave => 'Wave',
            self::OrangeMoney => 'Orange Money',
            self::FreeMoney => 'Free Money',
            self::Transfer => 'Virement',
            self::Other => 'Autre',
        };
    }

    /**
     * Une référence de transaction est-elle attendue ?
     *
     * Elle n'est pas EXIGÉE : un collecteur sur la route du Lac Rose n'a pas
     * toujours l'identifiant Wave sous les yeux, et bloquer l'encaissement
     * pour cela ferait perdre la trace du paiement — bien pire que de la
     * consigner plus tard. L'interface la réclame, le serveur ne l'impose pas.
     */
    public function expectsReference(): bool
    {
        return $this !== self::Cash;
    }

    /** Alimente-t-il la caisse en billets ? */
    public function movesPhysicalCash(): bool
    {
        return $this === self::Cash;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
