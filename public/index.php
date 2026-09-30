<?php

declare(strict_types=1);

use App\Service\JiraDate;
use App\Support\AppErrorHandler;
use App\Support\CsrfGuard;
use App\Support\FatalErrorGuard;
use DI\Container;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;

/**
 * Bootstrapping the app
 */

require __DIR__ . '/../vendor/autoload.php';

/**
 * Fuseau horaire de l'application (équipe basée à Paris) : sans cela, les dates
 * formatées via date() s'affichent en UTC (2 h de décalage l'été). La valeur fait
 * référence à JiraDate::APP_TIMEZONE (source de vérité unique du fuseau projet).
 */
date_default_timezone_set(JiraDate::APP_TIMEZONE);

/**
 * Load environment variables FIRST
 */
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

/**
 * Normalize env variables
 */
$_ENV['IS_DEMO'] = filter_var(
    $_ENV['IS_DEMO'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);

/**
 * Create container & app
 */
$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();

/**
 * Register routes
 */
(require __DIR__ . '/../src/routes.php')($app);

/**
 * Garde anti-CSRF : refuse les écritures (POST/PUT/PATCH/DELETE) provenant d'un autre site.
 * Ajoutée AVANT le ErrorMiddleware pour que celui-ci reste la couche la plus externe (une erreur
 * survenant dans la garde donne donc bien une réponse propre).
 */
$app->add(new CsrfGuard($app->getResponseFactory()));

/**
 * Error handling : toute exception non capturée devient une réponse propre
 * (page HTML d'erreur ou JSON pour /api/) au lieu d'un fatal PHP (page blanche / « 500 » brut).
 * Le détail technique n'est jamais exposé à l'utilisateur (pas de fuite du .env).
 */
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(new AppErrorHandler($app->getResponseFactory()));

/**
 * Filet pour les erreurs FATALES (dépassement de max_execution_time / mémoire) : invisibles du
 * ErrorMiddleware Slim car ce ne sont pas des Throwable. Rend une page d'erreur propre plutôt qu'une
 * page blanche brute — cas typique : /team-metrics avec trop de sprints sélectionnés.
 */
$fatalGuard = new FatalErrorGuard(__DIR__ . '/../views/pages/error.phtml');
$fatalGuard->register();

/**
 * Run app
 */
$app->run();
$fatalGuard->markCompleted();
