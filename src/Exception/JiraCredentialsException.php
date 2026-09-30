<?php

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Levée lorsque les identifiants Jira issus du fichier .env sont absents ou refusés par Jira.
 *
 * Distingue explicitement un problème de configuration/authentification (403/401) d'une erreur
 * technique quelconque, afin que le gestionnaire d'erreurs global affiche un message IHM dédié.
 */
class JiraCredentialsException extends RuntimeException
{
    /**
     * Message affichable en IHM, indépendant du détail technique (jamais le contenu du .env).
     */
    public const USER_MESSAGE = 'Connexion à Jira impossible : les identifiants configurés '
        . '(URL, e-mail ou token API) sont manquants ou invalides. Vérifiez le fichier .env de l\'application.';

    public function __construct(string $message = self::USER_MESSAGE, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
