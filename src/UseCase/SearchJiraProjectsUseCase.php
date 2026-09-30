<?php

namespace App\UseCase;

use App\Repository\JiraProjectRepositoryInterface;

/**
 * Lance une recherche de projets Jira par texte libre, via le repository injecté.
 * Retourne une liste vide si la requête fait moins de 2 caractères.
 */
class SearchJiraProjectsUseCase
{
    private JiraProjectRepositoryInterface $repository;

    public function __construct(JiraProjectRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Exécute la recherche de projets Jira pour la requête donnée.
     */
    public function execute(string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        return $this->repository->search($query);
    }
}
