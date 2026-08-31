<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalisation des numéros de téléphone sénégalais.
 *
 * Le téléphone est l'identifiant principal des membres du club : c'est par lui
 * qu'on se connecte et qu'on retrouve quelqu'un. Or les gens le saisissent de
 * dix façons différentes :
 *
 *     77 123 45 67   ·   77-123-45-67   ·   +221 77 123 45 67
 *     00221771234567 ·   771234567
 *
 * Sans normalisation, la même personne pourrait créer plusieurs comptes et ne
 * plus retrouver le sien à la connexion. On ramène donc TOUT à une forme
 * canonique de 9 chiffres, la seule stockée en base.
 */
final class PhoneNumber
{
    /** Indicatif du Sénégal. */
    private const COUNTRY_CODE = '221';

    /** Longueur d'un numéro sénégalais sans indicatif. */
    private const NATIONAL_LENGTH = 9;

    /**
     * Ramène un numéro à sa forme canonique : 9 chiffres, sans indicatif.
     * Renvoie `null` si l'entrée ne peut pas être interprétée.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        // On ne garde que les chiffres : espaces, points, tirets, parenthèses
        // et le « + » disparaissent.
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        // 00221XXXXXXXXX → 221XXXXXXXXX
        if (str_starts_with($digits, '00'.self::COUNTRY_CODE)) {
            $digits = substr($digits, 2);
        }

        // 221XXXXXXXXX → XXXXXXXXX
        if (strlen($digits) === strlen(self::COUNTRY_CODE) + self::NATIONAL_LENGTH
            && str_starts_with($digits, self::COUNTRY_CODE)) {
            $digits = substr($digits, strlen(self::COUNTRY_CODE));
        }

        return strlen($digits) === self::NATIONAL_LENGTH ? $digits : null;
    }

    /**
     * Le numéro est-il un mobile sénégalais plausible ?
     *
     * Les mobiles commencent par 7 et le chiffre suivant identifie l'opérateur :
     * 70 Expresso · 75 Promobile · 76 Free (ex-Tigo) · 77 Orange · 78 Orange.
     * On reste volontairement permissif sur le 7X : les plans de numérotation
     * évoluent, et refuser un numéro valide est bien pire que d'en accepter un
     * douteux (le membre existe, il est devant le collecteur).
     */
    public static function isValid(?string $input): bool
    {
        $normalized = self::normalize($input);

        return $normalized !== null && str_starts_with($normalized, '7');
    }

    /** Format lisible : « 77 123 45 67 ». */
    public static function format(?string $input): ?string
    {
        $normalized = self::normalize($input);

        if ($normalized === null) {
            return $input;
        }

        return implode(' ', [
            substr($normalized, 0, 2),
            substr($normalized, 2, 3),
            substr($normalized, 5, 2),
            substr($normalized, 7, 2),
        ]);
    }

    /** Format international : « +221771234567 ». Utile pour les SMS. */
    public static function toInternational(?string $input): ?string
    {
        $normalized = self::normalize($input);

        return $normalized === null ? null : '+'.self::COUNTRY_CODE.$normalized;
    }
}
