<?php

use App\Controller\CapacityController;
use App\Controller\CapacityImportController;
use App\Controller\ConfigController;
use App\Controller\DocsController;
use App\Controller\FeatureController;
use App\Controller\HomeController;
use App\Controller\JiraBoardController;
use App\Controller\JiraIssueTypeController;
use App\Controller\JiraProjectController;
use App\Controller\ManDaysController;
use App\Controller\PlanificationController;
use App\Controller\ProgramIncrementController;
use App\Controller\ProjectFeaturesController;
use App\Controller\ProjectReleasesController;
use App\Controller\ProjectVersionsController;
use App\Controller\PromptExportController;
use App\Controller\RebootController;
use App\Controller\SprintController;
use App\Controller\TeamConfigController;
use App\Controller\TeamMetricsController;
use App\Controller\VersionController;
use App\Controller\WorkflowConfigController;
use Slim\App;

return function (App $app) {
    /**
     * Front Routes
     */
    $app->get('/', [HomeController::class, 'index']);
    $app->get('/release', [VersionController::class, 'index']);
    $app->post('/release', [VersionController::class, 'process']);
    $app->get('/feature', [FeatureController::class, 'index']);
    $app->post('/feature', [FeatureController::class, 'process']);
    $app->get('/pi', [ProgramIncrementController::class, 'index']);
    $app->post('/pi', [ProgramIncrementController::class, 'process']);
    $app->get('/sprint', [SprintController::class, 'index']);
    $app->post('/sprint', [SprintController::class, 'process']);
    $app->get('/capacity', [CapacityController::class, 'index']);
    $app->get('/planification', [PlanificationController::class, 'index']);
    $app->post('/planification', [PlanificationController::class, 'process']);
    $app->get('/team-metrics', [TeamMetricsController::class, 'index']);
    $app->post('/team-metrics', [TeamMetricsController::class, 'process']);
    $app->get('/config', [ConfigController::class, 'index']);
    $app->get('/docs', [DocsController::class, 'index']);
    $app->get('/reboot', [RebootController::class, 'index']);
    $app->post('/reboot', [RebootController::class, 'reboot']);
    /**
     * API Routes
     */
    // Statut de cache des snapshots de sprint : partagé par les sélecteurs des pages Sprint et Métriques d'équipe.
    $app->get('/api/internal/sprints/cache-status', [SprintController::class, 'getCacheStatus']);
    $app->get('/api/internal/projects/{projectKey}/pi-releases', [TeamMetricsController::class, 'getPiReleases']);
    $app->get('/api/internal/projects/{projectKey}/pi-features', [TeamMetricsController::class, 'getPiFeatures']);
    $app->get('/api/internal/projects/{projectKey}/pi-releases/count', [TeamMetricsController::class, 'getPiReleasesCount']);
    $app->get('/api/internal/projects/{projectKey}/pi-features/count', [TeamMetricsController::class, 'getPiFeaturesCount']);
    // Listes de clés de la fenêtre : première étape du chargement par lots des deux onglets.
    $app->get('/api/internal/projects/{projectKey}/pi-features/keys', [TeamMetricsController::class, 'getPiFeaturesKeys']);
    $app->get('/api/internal/projects/{projectKey}/pi-releases/keys', [TeamMetricsController::class, 'getPiReleasesKeys']);
    $app->post('/api/internal/projects/{projectKey}/team-metrics/analysis', [TeamMetricsController::class, 'analyze']);
    $app->get('/api/internal/projects/search', [JiraProjectController::class, 'search']);
    $app->get('/api/internal/projects/{projectKey}/versions', [ProjectVersionsController::class, 'versionsListByProject']);
    $app->get('/api/internal/projects/{projectKey}/releases', [ProjectReleasesController::class, 'releasesListByProject']);
    $app->get('/api/internal/projects/{projectKey}/features', [ProjectFeaturesController::class, 'featuresListByProject']);
    $app->get('/api/internal/projects/{projectKey}/boards', [JiraBoardController::class, 'boardsListByProject']);
    $app->get('/api/internal/boards/{boardId}/sprints', [JiraBoardController::class, 'sprintsListByBoard']);
    $app->post('/api/internal/issues/{issueKey}/planning', [PlanificationController::class, 'updateIssue']);
    $app->post('/api/internal/issues/assign-sprint', [PlanificationController::class, 'assignToSprint']);
    $app->post('/api/internal/projects/{projectKey}/planning-margin', [PlanificationController::class, 'savePlanningMargin']);
    $app->get('/api/internal/projects/{projectKey}/workflow', [WorkflowConfigController::class, 'get']);
    $app->post('/api/internal/projects/{projectKey}/workflow', [WorkflowConfigController::class, 'save']);
    $app->post('/api/internal/projects/{projectKey}/sprints/{sprintId}/man-days', [ManDaysController::class, 'save']);
    $app->post('/api/internal/projects/{projectKey}/sprints/{sprintId}/capacity-points', [ManDaysController::class, 'saveCapacityPoints']);
    $app->get('/api/internal/projects/{projectKey}/sprints/{sprintId}/capacity', [CapacityController::class, 'get']);
    $app->post('/api/internal/projects/{projectKey}/sprints/{sprintId}/capacity', [CapacityController::class, 'save']);
    $app->post('/api/internal/projects/{projectKey}/sprints/{sprintId}/capacity/day-events/propagate', [CapacityController::class, 'propagateDayEvents']);
    $app->post('/api/internal/projects/{projectKey}/capacity/import', [CapacityImportController::class, 'import']);
    $app->get('/api/internal/absence-import/example', [CapacityImportController::class, 'example']);
    $app->post('/api/internal/projects/{projectKey}/sprints/{sprintId}/analysis', [SprintController::class, 'analyze']);
    $app->get('/api/internal/projects/{projectKey}/sprints/{sprintId}/export-prompt', [PromptExportController::class, 'sprint']);
    $app->get('/api/internal/projects/{projectKey}/team-metrics/export-prompt', [PromptExportController::class, 'teamMetrics']);
    $app->get('/api/internal/projects/{projectKey}/team-config', [TeamConfigController::class, 'get']);
    $app->post('/api/internal/projects/{projectKey}/team-config', [TeamConfigController::class, 'save']);
    $app->get('/api/internal/projects/{projectKey}/issue-types', [JiraIssueTypeController::class, 'listByProject']);
};
