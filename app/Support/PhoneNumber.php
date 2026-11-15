<?php

declare(strict_types=1);

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Numéros de téléphone au format international E.164 (+22670123456).
 *
 * Un même numéro peut être saisi « 70 12 34 56 », « 0022670123456 » ou
 * « +226 70 12 34 56 » : sans normalisation, ce seraient trois comptes.
 * Un numéro sans indicatif est lu dans le pays par défaut (Burkina Faso).
 */
final class PhoneNumber
{
    /** Retourne le numéro E.164, ou null s'il est vide ou invalide. */
    public static function normalize(?string $raw, ?string $region = null): ?string
    {
        $raw = $raw === null ? '' : trim($raw);

        if ($raw === '') {
            return null;
        }

        // « 00226… » est la forme internationale composée depuis un fixe.
        if (str_starts_with($raw, '00')) {
            $raw = '+'.substr($raw, 2);
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($raw, $region ?? self::defaultRegion());
        } catch (NumberParseException) {
            return null;
        }

        if (! $util->isValidNumber($number)) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }

    public static function isValid(?string $raw, ?string $region = null): bool
    {
        return self::normalize($raw, $region) !== null;
    }

    /** Forme lisible : « +226 70 12 34 56 ». */
    public static function display(string $e164): string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            return $util->format($util->parse($e164), PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException) {
            return $e164;
        }
    }

    /** Forme masquée pour les écrans publics : « +226 •• •• 34 56 ». */
    public static function mask(string $e164): string
    {
        $display = self::display($e164);
        $digits = 0;
        $out = '';

        // On garde l'indicatif et les quatre derniers chiffres.
        for ($i = strlen($display) - 1; $i >= 0; $i--) {
            $char = $display[$i];
            if (ctype_digit($char)) {
                $digits++;
            }
            $out = ($digits > 4 && ctype_digit($char) && ! self::inPrefix($display, $i) ? '•' : $char).$out;
        }

        return $out;
    }

    /**
     * Ancien format conservé en base avant la normalisation : chiffres et « + »
     * seulement. Sert de repli à la connexion tant que la reprise n'est pas faite.
     */
    public static function legacy(?string $raw): ?string
    {
        $raw = (string) preg_replace('/[^0-9+]/', '', (string) $raw);

        return $raw === '' ? null : $raw;
    }

    public static function defaultRegion(): string
    {
        return config()->string('koudi.phone_region', 'BF');
    }

    private static function inPrefix(string $display, int $index): bool
    {
        $space = strpos($display, ' ');

        return $space !== false && $index < $space;
    }
}
