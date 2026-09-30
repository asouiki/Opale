<?php

/**
 * Bootstrap PHPUnit.
 *
 * Charge l'autoloader Composer puis enregistre le namespace de test `Tests\`
 * (mappé sur le dossier tests/). Cela rend les utilitaires de test
 * (Tests\Utils\*) disponibles même sans `composer dump-autoload`, tout en
 * restant cohérent avec la section "autoload-dev" de composer.json.
 */

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('Tests\\', __DIR__);

// Même fuseau applicatif que le bootstrap de prod (public/index.php) : les fixtures « naïves »
// (sans offset) des tests expriment des heures locales d'équipe, comme en réalité.
date_default_timezone_set(\App\Service\JiraDate::APP_TIMEZONE);

return $loader;
