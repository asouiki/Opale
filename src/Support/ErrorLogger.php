<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * ErrorLogger
 *
 * Journal fichier des erreurs BLOQUANTES uniquement — celles qui ont fait échouer une action de
 * l'utilisateur (exception non rattrapée, écriture de cache impossible, réponse Jira inexploitable).
 * Les erreurs de saisie et les dégradations silencieuses (best-effort) n'y ont pas leur place : le
 * journal doit rester lisible et exploitable en support.
 *
 * Il recueille le détail technique que l'IHM n'affiche plus (chemins de fichiers, réponse brute d'API),
 * afin que le diagnostic reste possible sans exposer ces informations à l'utilisateur.
 *
 * La CHAÎNE DES CAUSES (`getPrevious()`) est journalisée avec l'entrée : une exception métier porte un
 * message volontairement générique (ex. JiraCredentialsException parle d'identifiants « manquants ou
 * invalides »), qui masque la cause réelle — code HTTP 401, 429 de rate-limit, timeout réseau ou proxy SSL.
 * Sans ces lignes, ces situations sont indistinguables dans le journal.
 *
 * Trois garde-fous contre la saturation disque :
 *  - un fichier par jour (`logs/error-YYYY-MM-DD.log`), jamais un fichier unique qui enfle ;
 *  - le détail joint est tronqué à MAX_DETAIL_LENGTH (une réponse Jira peut peser plusieurs Mo) ;
 *  - purge automatique des journaux de plus de RETENTION_DAYS jours, déclenchée à la première écriture de chaque jour
 *    (donc au plus une fois par jour, pas à chaque appel).
 *
 * L'écriture est « best-effort » : un journal indisponible ne doit JAMAIS casser la requête en cours.
 */
final class ErrorLogger
{
    /** Répertoire des journaux (créé au besoin, ignoré par git). */
    public const LOG_DIR = __DIR__ . '/../../logs';

    /** Durée de conservation des journaux, en jours. */
    private const RETENTION_DAYS = 21;

    /** Taille max du détail technique joint à une entrée, en caractères. */
    private const MAX_DETAIL_LENGTH = 2000;

    /** Nombre max de causes remontées par entrée : une chaîne anormalement longue ne doit pas noyer le journal. */
    private const MAX_CAUSE_DEPTH = 5;

    /**
     * Journalise une erreur bloquante, horodatée, suivie de sa chaîne de causes puis du détail fourni.
     *
     * @param Throwable $exception Exception à tracer (type, message, fichier, ligne) ; ses causes
     *                             successives (`getPrevious()`) sont ajoutées sous l'entrée
     * @param string    $detail    Contexte technique volumineux retiré du message utilisateur
     *                             (réponse brute d'API, chemin visé...) ; tronqué si trop long
     */
    public static function critical(Throwable $exception, string $detail = ''): void
    {
        try {
            $file    = self::LOG_DIR . '/error-' . date('Y-m-d') . '.log';
            $isNewDay = !is_file($file);

            if (!is_dir(self::LOG_DIR) && !mkdir(self::LOG_DIR, 0775, true) && !is_dir(self::LOG_DIR)) {
                return;
            }

            $entry = sprintf(
                "[%s] %s: %s (%s:%d)\n",
                date('Y-m-d H:i:s'),
                $exception::class,
                self::oneLine($exception->getMessage()),
                $exception->getFile(),
                $exception->getLine()
            );

            $entry .= self::formatCauses($exception);

            if ($detail !== '') {
                $entry .= '    detail: ' . self::truncate(self::oneLine($detail)) . "\n";
            }

            file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);

            // Première écriture du jour : c'est le seul moment où de nouveaux journaux deviennent périmés.
            if ($isNewDay) {
                self::purgeExpired();
            }
        } catch (Throwable) {
            // Journalisation best-effort : on n'aggrave jamais une erreur en cours.
        }
    }

    /**
     * Formate la chaîne des causes d'une exception, une par ligne, de la plus proche à la plus lointaine.
     *
     * Chaque cause porte son type, son message et sa position : c'est là que se trouve l'information
     * réellement exploitable quand l'exception de tête ne porte qu'un message destiné à l'utilisateur.
     * La profondeur est bornée par MAX_CAUSE_DEPTH, ce qui protège aussi d'une chaîne cyclique.
     *
     * @return string Lignes prêtes à concaténer (chaîne vide si l'exception n'a aucune cause)
     */
    private static function formatCauses(Throwable $exception): string
    {
        $lines = '';
        $cause = $exception->getPrevious();
        $depth = 0;

        while ($cause !== null && $depth < self::MAX_CAUSE_DEPTH) {
            $lines .= sprintf(
                "    cause: %s: %s (%s:%d)\n",
                $cause::class,
                self::truncate(self::oneLine($cause->getMessage())),
                $cause->getFile(),
                $cause->getLine()
            );

            $cause = $cause->getPrevious();
            $depth++;
        }

        return $lines;
    }

    /**
     * Écrase les retours à la ligne : une entrée = une ligne, sinon le journal devient illisible
     * (les messages d'API et les var_dump sont multilignes).
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Tronque un détail trop volumineux, en signalant explicitement la coupure.
     */
    private static function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_DETAIL_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_DETAIL_LENGTH) . ' […tronqué]';
    }

    /**
     * Supprime les journaux dont la date dépasse la durée de rétention.
     */
    private static function purgeExpired(): void
    {
        $limit = time() - (self::RETENTION_DAYS * 86400);

        foreach (glob(self::LOG_DIR . '/error-*.log') ?: [] as $path) {
            if (is_file($path) && (int) filemtime($path) < $limit) {
                @unlink($path);
            }
        }
    }
}
