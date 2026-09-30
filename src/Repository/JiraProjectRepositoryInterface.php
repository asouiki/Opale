<?php

namespace App\Repository;

/**
 * Abstraction du point d'accès aux projets Jira, découplant le UseCase de
 * l'implémentation HTTP concrète.
 */
interface JiraProjectRepositoryInterface
{
    /**
     * Recherche des projets Jira par texte.
     */
    public function search(string $query): array;
}
