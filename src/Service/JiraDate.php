<?php

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * JiraDate
 *
 * Source de vérité unique du fuseau horaire applicatif (équipe basée à Paris) et de la conversion des
 * dates renvoyées par Jira.
 *
 * Jira encode les datetime en UTC (suffixe `Z`). `new DateTime($iso)` conserve ce fuseau : formater
 * directement ressortirait le jour UTC — la veille pour une fin de sprint à minuit local (ex.
 * `2026-08-06T22:00:00Z` = 07/08 00h00 Europe/Paris). Toute date Jira destinée à l'affichage ou à un
 * raisonnement « au jour » doit passer par ce service pour être ramenée au fuseau de l'équipe.
 *
 * `APP_TIMEZONE` est la constante de référence : `public/index.php` s'en sert pour le
 * `date_default_timezone_set()` du bootstrap, et ce service pour ses conversions.
 *
 * Périmètre couvert : sites « feuilles » (Views, Repository, normalisation d'affichage), ET le cœur
 * de calcul métrique (lot A9 V2) : `Timeline::calculateBusinessDays` — goulot unique de tout le
 * comptage en jours ouvrés — normalise ses deux bornes vers APP_TIMEZONE avant tout raisonnement
 * « au jour » (jour calendaire, week-end, férié). Les instants comparés ailleurs (rejeu de changelog)
 * sont insensibles au fuseau et restent tels quels.
 */
final class JiraDate
{
    /** Fuseau horaire de l'application. Source de vérité unique du projet. */
    public const APP_TIMEZONE = 'Europe/Paris';

    /**
     * Parse une date Jira (datetime ISO 8601 UTC ou date `Y-m-d`) et la ramène au fuseau applicatif.
     * Renvoie null si l'entrée est vide ou non parsable.
     */
    public static function toLocal(?string $isoDate): ?DateTimeImmutable
    {
        if ($isoDate === null || $isoDate === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($isoDate))->setTimezone(new DateTimeZone(self::APP_TIMEZONE));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Date locale `Y-m-d` (jour calendaire vu par l'équipe), ou null si l'entrée est vide/invalide.
     */
    public static function toLocalDate(?string $isoDate): ?string
    {
        return self::toLocal($isoDate)?->format('Y-m-d');
    }

    /**
     * Date locale au format français `d/m/Y`, ou $placeholder si l'entrée est vide/invalide.
     */
    public static function formatFr(?string $isoDate, string $placeholder = '—'): string
    {
        return self::toLocal($isoDate)?->format('d/m/Y') ?? $placeholder;
    }
}
