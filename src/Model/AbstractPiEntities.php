<?php

namespace App\Model;

use App\Service\JiraDate;
use App\Service\JiraService;

/**
 * AbstractPiEntities
 *
 * Socle commun aux agrégats du « PI en cours » de la page Métriques d'équipe : Releases/Versions
 * ({@see CurrentPiReleases}) et Features ({@see CurrentPiFeatures}). Regroupe toute la logique
 * type-agnostique : filtrage de la fenêtre temporelle [fromDate, toDate], assemblage d'une ligne
 * (métadonnées + métriques cumulées + efficacité du flow + lien Analyse), agrégats d'en-tête et
 * helpers de dates.
 *
 * Chaque sous-classe fournit sa source de données (fetch Jira), son cache et l'URL du deep-link
 * d'analyse propre à son entité (Template Method, comme {@see AbstractIssueCollection}).
 *
 * @phpstan-import-type Breakdown from FeatureBreakdown
 *
 * @phpstan-type PiMetrics array{leadTime: float|null, cycleTime: float|null, waitingTotal: float|null, waitingAvg: float|null, waitingMedian: float|null}
 * @phpstan-type PiRow array{type: string, key: string, name: string, url: string, status: string, startDate: string|null, closeDate: string|null, isDone: bool, leadTime: float|null, cycleTime: float|null, waitingTotal: float|null, waitingAvg: float|null, waitingMedian: float|null, realisationDays: float|null, realisationPct: float|null, efficaciteDays: float|null, efficacitePct: float|null, analyseUrl: string, breakdown: Breakdown|null}
 */
abstract class AbstractPiEntities
{
    protected JiraService $jira;

    protected string $fromDate;
    protected string $toDate;
    protected bool $forceRefresh;

    /**
     * Rafraîchissement CIBLÉ : identifiants des seules entités à recalculer depuis Jira, terminées ou non.
     *
     * Une entité terminée a des métriques de durée immuables, d'où son cache permanent — mais son détail
     * (Story Points, types, étiquettes de bugs) reste éditable dans Jira bien après la clôture. Ce ciblage
     * est le seul moyen de la resynchroniser, sans imposer le coût d'un aller-retour Jira sur TOUTES les
     * entités terminées de la fenêtre.
     *
     * Liste vide = aucun ciblage : c'est alors $forceRefresh qui décide (entités en cours uniquement).
     *
     * @var list<string>
     */
    protected array $refreshKeys = [];

    /**
     * Mode « comptage seul » : les entités de la fenêtre sont listées, mais leurs métriques
     * (Lead/Cycle/Waiting) ne sont PAS calculées.
     *
     * Résoudre les métriques impose de charger les tickets liés de chaque entité avec leur changelog
     * paginé (un aller-retour Jira par entité) : inutile pour les cartes compteur de l'onglet Sprints,
     * qui n'exploitent que `stats['total']`. Ce total étant un simple dénombrement des lignes retenues
     * (cf. PiStatsCalculator::build()), il est identique avec ou sans métriques — le comptage reste
     * donc cohérent avec l'onglet détaillé correspondant.
     */
    protected bool $countOnly = false;

    /**
     * Restriction à un sous-ensemble d'entités : seules ces clés sont calculées.
     *
     * Sert au chargement par lots de l'onglet. Une fenêtre de fin de PI peut concentrer des dizaines
     * d'entités à calculer à froid ; traitées en une seule requête, elles dépassent max_execution_time
     * et TOUT le travail est perdu. Découpées en paquets, chaque requête reste courte et le cache se
     * remplit au fur et à mesure : un échec ne coûte plus que son propre lot.
     *
     * Liste vide = aucune restriction (toute la fenêtre), comportement historique.
     *
     * @var list<string>
     */
    protected array $onlyKeys = [];

    /** @var array<int, PiRow> Lignes calculées (une par entité dans la fenêtre). */
    protected array $items = [];

    /** @var array<string, mixed> Agrégats d'en-tête (compteurs + moyennes/médianes). */
    protected array $stats = [];

    // =========================================================================
    // API publique
    // =========================================================================

    /** @return array<int, PiRow> Une ligne calculée par entité dans la fenêtre. */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Agrégats d'en-tête : nbCreated, nbDone, lead, cycle, waiting, realisation, efficacite.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    // =========================================================================
    // Contrat type-spécifique
    // =========================================================================

    /**
     * URL du deep-link « Analyse » vers la page d'analyse de l'entité (ex: /release, /feature).
     *
     * @param string $key        Identifiant de l'entité (Key ou id)
     * @param string $projectKey Clé du projet porteur (peut être vide selon l'entité)
     */
    abstract protected function analyseUrl(string $key, string $projectKey): string;

    // =========================================================================
    // Construction des lignes
    // =========================================================================

    /**
     * Assemble une ligne complète (métadonnées + métriques + efficacité du flow + lien Analyse).
     *
     * @param string      $type    Type d'entité ('version' | 'release' | 'feature')
     * @param string      $key     Identifiant (id fixVersion, Key Release ou Key Feature)
     * @param string      $name    Nom affiché
     * @param string      $url     Lien Jira
     * @param string      $status  Libellé de statut
     * @param string|null $start   Date de démarrage (Y-m-d)
     * @param string|null $close   Date de clôture (Y-m-d)
     * @param bool            $isDone     Entité en catégorie Done
     * @param PiMetrics       $metrics    Métriques calculées (lead/cycle/waiting*)
     * @param string          $projectKey Clé du projet porteur ; transmis au deep-link d'analyse
     * @param Breakdown|null  $breakdown  Détail des tickets de l'entité (SP, bugs, types) ; null quand
     *                                    l'entité n'en expose pas — seules les Features en fournissent un
     *
     * @return PiRow
     */
    protected function buildRow(string $type, string $key, string $name, string $url, string $status, ?string $start, ?string $close, bool $isDone, array $metrics, string $projectKey = '', ?array $breakdown = null): array
    {
        $lead         = $metrics['leadTime'];
        $cycle        = $metrics['cycleTime'];
        $waitingAvg   = $metrics['waitingAvg'];
        $waitingTotal = $metrics['waitingTotal'];

        // Efficacité du flow, selon qu'on dispose ou non d'un Cycle Time cumulé.
        // Avec Cycle (Releases, et Versions/Features ayant des tickets liés) → Flow Efficiency :
        // Production = Cycle − Waiting total (bornée à 0) ; % = Production / Cycle ;
        // réalisation = Production rapportée au Lead.
        if ($cycle !== null) {
            $production = ($waitingTotal !== null)
                ? round(max(0.0, $cycle - $waitingTotal), 2)
                : null;

            $realisationDays = $production;
            $realisationPct  = ($production !== null && $lead !== null && $lead > 0)
                ? round($production / $lead * 100, 1)
                : null;

            $efficaciteDays = $production;
            $efficacitePct  = ($production !== null && $cycle > 0)
                ? round($production / $cycle * 100, 1)
                : null;
        } else {
            // Sans Cycle Time cumulé : réalisation indéfinie (pas de « in progress » exploitable),
            // efficacité totale basée sur le Lead (Lead − Total Waiting Time).
            // @todo V2 Backend : affiner le repli « sans Cycle » de la réalisation (piste : baser sur le Lead dans le cas des Versions qui n'ont pas de statut donc pas d'En cours
            // + cas des Releases/Features qui bypassent parfois l'état En cours).
            $realisationDays = null;
            $realisationPct  = null;

            $efficaciteDays = ($lead !== null && $waitingTotal !== null) ? round($lead - $waitingTotal, 2) : null;
            // $efficaciteDays non-null implique $lead non-null (cf. condition ci-dessus) → pas de re-test.
            $efficacitePct  = ($efficaciteDays !== null && $lead > 0)
                ? round($efficaciteDays / $lead * 100, 1)
                : null;
        }

        return [
            'type'            => $type,
            'key'             => $key,
            'name'            => $name,
            'url'             => $url,
            'status'          => $status,
            'startDate'       => $start,
            'closeDate'       => $close,
            'isDone'          => $isDone,
            'leadTime'        => $lead,
            'cycleTime'       => $cycle,
            'waitingTotal'    => $waitingTotal,
            'waitingAvg'      => $waitingAvg,
            'waitingMedian'   => $metrics['waitingMedian'],
            'realisationDays' => $realisationDays,
            'realisationPct'  => $realisationPct,
            'efficaciteDays'  => $efficaciteDays,
            'efficacitePct'   => $efficacitePct,
            'analyseUrl'      => $this->analyseUrl($key, $projectKey),
            'breakdown'       => $breakdown,
        ];
    }

    /**
     * Indique s'il faut ignorer le cache et recalculer l'entité depuis Jira.
     *
     * Deux régimes, dans cet ordre :
     * 1. Rafraîchissement ciblé (refreshKeys non vide) : SEULES les entités désignées sont recalculées,
     *    qu'elles soient terminées ou non ; toutes les autres restent servies du cache.
     * 2. Sinon, rafraîchissement global : seules les entités en cours sont recalculées (les terminées
     *    ont des durées immuables, leur cache est permanent).
     *
     * @param string $key    Identifiant de l'entité (Key ou id), tel que passé au cache
     * @param bool   $isDone Entité en catégorie Done
     */
    protected function mustRecompute(string $key, bool $isDone): bool
    {
        if ($this->refreshKeys !== []) {
            return in_array($key, $this->refreshKeys, true);
        }

        return $this->forceRefresh && !$isDone;
    }

    /**
     * Extrait le tableau de métriques (Lead/Cycle/Waiting cumulés) d'une collection, ou des null si
     * elle est absente (Jira inaccessible).
     *
     * @return PiMetrics
     */
    protected function metricsFrom(?AbstractIssueCollection $collection): array
    {
        if ($collection === null) {
            return ['leadTime' => null, 'cycleTime' => null, 'waitingTotal' => null, 'waitingAvg' => null, 'waitingMedian' => null];
        }

        return [
            'leadTime'      => $this->nullIfZero($collection->getTotalLeadTime()),
            'cycleTime'     => $this->nullIfZero($collection->getTotalCycleTime()),
            'waitingTotal'  => $collection->getTotalWaitingTime(),
            'waitingAvg'    => $collection->getAverageWaitingTime(),
            'waitingMedian' => $collection->getMedianWaitingTime(),
        ];
    }

    // =========================================================================
    // Agrégats d'en-tête
    // =========================================================================

    /**
     * Calcule les compteurs et les moyennes/médianes sur l'ensemble des lignes listées.
     *
     * Logique portée par PiStatsCalculator (collaborateur extrait, lot A4 V2).
     */
    protected function computeStats(): void
    {
        $this->stats = (new PiStatsCalculator())->build(array_values($this->items), $this->fromDate, $this->toDate);
    }

    // =========================================================================
    // Helpers fenêtre & dates
    // =========================================================================

    /**
     * Normalise une date Jira (ISO datetime ou Y-m-d) en Y-m-d, ou null si vide/invalide.
     */
    protected function toYmd(?string $raw): ?string
    {
        return JiraDate::toLocalDate($raw);
    }

    /**
     * Indique si une date Y-m-d tombe dans la fenêtre [fromDate, toDate] (bornes incluses).
     */
    protected function isInWindow(?string $ymd): bool
    {
        return $ymd !== null && $ymd >= $this->fromDate && $ymd <= $this->toDate;
    }

    /**
     * Retourne null si la valeur est nulle (0.0), sinon la valeur (pour distinguer « non calculé »).
     */
    protected function nullIfZero(float $value): ?float
    {
        return $value === 0.0 ? null : $value;
    }
}
