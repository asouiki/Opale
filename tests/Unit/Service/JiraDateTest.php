<?php

namespace Tests\Unit\Service;

use App\Service\JiraDate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la conversion des dates Jira vers le fuseau applicatif (Europe/Paris).
 *
 * Cas nominal : un datetime UTC à minuit local (encodage Jira) doit ressortir sur le bon jour calendaire.
 * Cas limites : offset explicite, date seule (sans heure), placeholder de formatage.
 * Cas d'erreur : entrée vide ou non parsable → null / placeholder (jamais d'exception).
 */
class JiraDateTest extends TestCase
{
    #[Test]
    public function test_toLocalDate_datetimeUtcMinuit_rameneAuJourLocal(): void
    {
        // 24/06 comme 07/08 : minuit Europe/Paris est encodé la veille 22h UTC par Jira.
        self::assertSame('2026-08-07', JiraDate::toLocalDate('2026-08-06T22:00:00.000Z'));
    }

    #[Test]
    public function test_toLocalDate_offsetPositif_conserveLeJour(): void
    {
        self::assertSame('2026-08-07', JiraDate::toLocalDate('2026-08-07T00:00:00.000+02:00'));
    }

    #[Test]
    public function test_toLocalDate_dateSeule_inchangee(): void
    {
        self::assertSame('2026-08-07', JiraDate::toLocalDate('2026-08-07'));
    }

    #[Test]
    public function test_toLocalDate_videOuNull_renvoieNull(): void
    {
        self::assertNull(JiraDate::toLocalDate(null));
        self::assertNull(JiraDate::toLocalDate(''));
    }

    #[Test]
    public function test_toLocalDate_invalide_renvoieNull(): void
    {
        self::assertNull(JiraDate::toLocalDate('pas-une-date'));
    }

    #[Test]
    public function test_formatFr_datetimeUtc_formatFrancaisSurLeBonJour(): void
    {
        self::assertSame('07/08/2026', JiraDate::formatFr('2026-08-06T22:00:00.000Z'));
    }

    #[Test]
    public function test_formatFr_vide_renvoiePlaceholderParDefaut(): void
    {
        self::assertSame('—', JiraDate::formatFr(null));
    }

    #[Test]
    public function test_formatFr_invalide_renvoiePlaceholderPersonnalise(): void
    {
        self::assertSame('?', JiraDate::formatFr('pas-une-date', '?'));
    }

    #[Test]
    public function test_toLocal_datetimeUtc_objetAuFuseauParis(): void
    {
        $local = JiraDate::toLocal('2026-08-06T22:00:00.000Z');
        self::assertNotNull($local);
        self::assertSame('Europe/Paris', $local->getTimezone()->getName());
        self::assertSame('2026-08-07 00:00:00', $local->format('Y-m-d H:i:s'));
    }
}
