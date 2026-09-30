<?php

namespace Tests\Utils;

use App\Model\AbstractIssueCollection;

/**
 * FakeIssueCollection
 *
 * Sous-classe concrète minimale d'AbstractIssueCollection, destinée à tester
 * les méthodes protégées de l'abstraite (ex. calculateMedian) sans dépendre
 * d'un agrégat réel (Sprint, Version…) ni déclencher d'appel Jira.
 *
 * À instancier via Reflection::newInstanceWithoutConstructor() : le constructeur
 * parent crée les services Jira et charge les issues, ce qu'on veut éviter ici.
 */
final class FakeIssueCollection extends AbstractIssueCollection
{
    protected function loadCollectionData(string $id): void
    {
    }

    protected function loadIssues(string $id): void
    {
    }
}
