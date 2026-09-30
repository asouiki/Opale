<?php

namespace App\View;

use App\Model\Config;
use App\Model\JiraUrl;
use App\Model\Sprint;
use App\Model\StatusCategory;
use App\Model\Timeline;
use App\Service\JiraDate;
use DateTime;

/**
 * SprintView
 *
 * Expose et formate les données d'un Sprint pour le template /sprint.phtml.
 *
 * La logique métier reste dans le modèle Sprint : cette vue ne fait que de la
 * présentation (formatage des dates, libellés d'état, classes de badge).
 */
class SprintView extends AbstractCollectionView
{
    use ViewFormatterTrait;

    /**
     * Libellés d'entrée de sprint réputés « non démarrés », exprimés sous leur forme normalisée
     * (cf. Timeline::NATIVE_STATUS_MAP : « To Do » se normalise en « À faire »).
     * Consommés par isNotStartedStatus() pour distinguer « À faire » de « En cours », que la catégorie
     * `sprint` du workflow regroupe indistinctement.
     *
     * @var list<string>
     */
    private const NOT_STARTED_STATUSES = ['À faire'];

    private Sprint $sprint;

    public function __construct(Sprint $sprint)
    {
        parent::__construct($sprint);
        $this->sprint = $sprint;
    }

    // ==================== Temps d'attente : pendants « cycle de vie complet » ====================

    /**
     * Les getters hérités d'AbstractCollectionView renvoient l'attente BORNÉE au sprint. Ceux-ci
     * exposent son pendant sur tout le cycle de vie du ticket, affiché en second plan : l'écart
     * distingue un blocage survenu dans le sprint d'un ticket arrivé déjà empêché.
     */
    public function getTotalTimeSpentInWaitingFull(): float
    {
        return $this->sprint->getTotalTimeSpentInWaitingFull();
    }

    public function getAverageTimeSpentInWaitingFull(): float
    {
        return $this->sprint->getAverageTimeSpentInWaitingFull();
    }

    /** @return array<string, float> */
    public function getAggregatedWaitingTimesFull(): array
    {
        return $this->sprint->getAggregatedWaitingTimesFull();
    }

    // ==================== Identité du sprint ====================

    public function getSprintId(): string
    {
        return $this->sprint->getSprintId();
    }

    public function getSprintName(): string
    {
        return $this->sprint->getName();
    }

    /**
     * URL vers le rapport de fin de sprint natif Jira.
     * Retourne une chaîne vide si projectKey ou boardId sont absents.
     */
    public function getSprintUrl(): string
    {
        $projectKey = $this->sprint->getProjectKey();
        $boardId    = $this->sprint->getBoardId();

        if ($projectKey === '' || $boardId === '') {
            return '';
        }

        $base = rtrim(Config::jiraBaseUrl(), '/');

        return $base
            . '/jira/software/c/projects/' . rawurlencode($projectKey)
            . '/boards/' . rawurlencode($boardId)
            . '/reports/sprint-retrospective?sprint=' . rawurlencode($this->sprint->getSprintId());
    }

    /**
     * URL « browse » Jira d'un ticket à partir de sa clé.
     * Retourne '#' si la clé est vide.
     */
    public function getBrowseUrl(string $key): string
    {
        if ($key === '') {
            return '#';
        }

        return JiraUrl::browse($key);
    }

    public function getGoal(): string
    {
        return $this->sprint->getGoal();
    }

    /**
     * Clé du projet Jira — utilisée par le formulaire « Rafraîchir » du cache.
     */
    public function getProjectKey(): string
    {
        return $this->sprint->getProjectKey();
    }

    /**
     * ID du board Jira — utilisé par le formulaire « Rafraîchir » du cache.
     */
    public function getBoardId(): string
    {
        return $this->sprint->getBoardId();
    }

    // ==================== État ====================

    public function isActive(): bool
    {
        return $this->sprint->getState() === Sprint::STATE_ACTIVE;
    }

    public function isClosed(): bool
    {
        return $this->sprint->getState() === Sprint::STATE_CLOSED;
    }

    public function toDateLabel(): string
    {
        $asOf = $this->sprint->getAsOfDate();
        if ($asOf !== null) {
            return 'au ' . $asOf->format('d/m/Y H:i');
        }

        return $this->isActive() ? 'à aujourd\'hui' : 'à la clôture';
    }

    /**
     * Le sprint affiché est-il une reconstruction à une date choisie (et non son état nominal) ?
     */
    public function isAsOfPhoto(): bool
    {
        return $this->sprint->getAsOfDate() !== null;
    }

    /**
     * Date d'observation formatée (« 27/08/2026 à 17:30 »), ou null hors mode « photo à date ».
     */
    public function getAsOfLabel(): ?string
    {
        return $this->sprint->getAsOfDate()?->format('d/m/Y \à H:i');
    }

    /**
     * Date d'observation au format du champ `datetime-local`, pour réafficher la valeur saisie dans le formulaire.
     */
    public function getAsOfInputValue(): ?string
    {
        return $this->sprint->getAsOfDate()?->format('Y-m-d\TH:i');
    }

    /**
     * Nombre de tickets réintégrés au périmètre par la reconstruction (sortis du sprint après la date observée).
     */
    public function getReintegratedCount(): int
    {
        return $this->sprint->getReintegratedCount();
    }

    /**
     * Écart entre la date d'observation et la clôture du sprint, en clair (« 2 heures avant la clôture »).
     *
     * Repère de contrôle : plus la photo s'éloigne de la clôture, plus elle ignore de travail terminé entre les deux.
     * Retourne null si le sprint n'est pas clôturé ou hors mode photo.
     */
    public function getAsOfGapLabel(): ?string
    {
        $asOf  = $this->sprint->getAsOfDate();
        $close = $this->sprint->getCompleteDate() ?: $this->sprint->getEndDate();

        if ($asOf === null || $close === '') {
            return null;
        }

        $closeDate = JiraDate::toLocal($close);
        if ($closeDate === null) {
            return null;
        }

        $diff  = $closeDate->diff($asOf);
        $after = $diff->invert === 0;
        $days  = (int) $diff->days;
        $hours = $diff->h;

        $amount = $days > 0
            ? $days . ' jour' . ($days > 1 ? 's' : '')
            : max($hours, 1) . ' heure' . ($hours > 1 ? 's' : '');

        return $amount . ($after ? ' après la clôture' : ' avant la clôture');
    }

    /**
     * Libellé lisible de l'état du sprint.
     */
    public function getStateLabel(): string
    {
        return match ($this->sprint->getState()) {
            Sprint::STATE_ACTIVE => '🟢 En cours',
            Sprint::STATE_CLOSED => '⚪ Clôturé',
            Sprint::STATE_FUTURE => '🔵 À venir',
            default              => $this->sprint->getState(),
        };
    }

    /**
     * Classe CSS de badge associée à l'état du sprint.
     */
    public function getStateBadgeClass(): string
    {
        return match ($this->sprint->getState()) {
            Sprint::STATE_ACTIVE => 'orange',
            Sprint::STATE_CLOSED => 'green',
            Sprint::STATE_FUTURE => 'blue',
            default              => 'grey',
        };
    }

    // ==================== Dates ====================

    public function getStartDate(): string
    {
        return $this->formatDate($this->sprint->getStartDate());
    }

    public function getEndDate(): string
    {
        return $this->formatDate($this->sprint->getEndDate());
    }

    public function getCompleteDate(): string
    {
        return $this->formatDate($this->sprint->getCompleteDate());
    }

    /**
     * Titre de la section « Atterrissage » :
     * - photo à date     → atterrissage à la date d'observation
     * - sprint en cours  → atterrissage à date du jour
     * - sprint clôturé   → atterrissage à la date de clôture
     */
    public function getLandingTitle(): string
    {
        $asOf = $this->sprint->getAsOfDate();
        if ($asOf !== null) {
            return 'Atterrissage reconstruit (au ' . $asOf->format('d/m/Y H:i') . ')';
        }

        if ($this->isActive()) {
            return 'Atterrissage à date (au ' . (new DateTime())->format('d/m/Y') . ')';
        }

        $reference = $this->sprint->getCompleteDate() ?: $this->sprint->getEndDate();

        return 'Atterrissage à la clôture (' . $this->formatDate($reference) . ')';
    }

    // ==================== Données métier (pass-through) ====================

    /**
     * Nombre de jours ouvrés restants jusqu'à la fin du sprint actif, ou null.
     */
    public function getRemainingBusinessDays(): ?int
    {
        return $this->sprint->getRemainingBusinessDays();
    }

    /**
     * Durée du sprint en jours ouvrés : du démarrage à la clôture (à défaut, à la
     * date de fin théorique). Sert au calcul des vélocités « par jour ouvré » de la
     * carte Vélocité (iso à la page Métriques d'équipe).
     *
     * @return float 0.0 si les dates sont absentes ou invalides
     */
    public function getBusinessDays(): float
    {
        return $this->sprint->getBusinessDays();
    }

    public function getLandingByStatus(): array
    {
        return $this->sprint->getLandingByStatus();
    }

    public function getObjectiveGroups(): array
    {
        return $this->sprint->getObjectiveGroups();
    }

    public function getFeatures(): array
    {
        return $this->sprint->getFeatures();
    }

    public function getStats(): array
    {
        return $this->sprint->getStats();
    }

    public function getPredictability(): array
    {
        return $this->sprint->getPredictability();
    }

    public function getTicketAgeStats(): array
    {
        return $this->sprint->getTicketAgeStats();
    }

    /**
     * Export d'un prompt d'analyse à coller dans une IA Web : indépendant de la synthèse IA
     * locale, donc affiché même quand LLM_ENABLED est à false.
     */
    public function getExportPromptUrl(): string
    {
        return '/api/internal/projects/' . rawurlencode($this->getProjectKey())
            . '/sprints/' . rawurlencode($this->getSprintId()) . '/export-prompt'
            . '?boardId=' . urlencode($this->getBoardId());
    }

    // ==================== Helpers de formatage ====================

    /**
     * Couleur (hex) associée à une catégorie de statut, pour les graphiques.
     *
     * Contrairement à getCategoryBadgeClass() (qui renvoie une classe de badge
     * sur fond clair), on renvoie ici une teinte saturée lisible en aplat de
     * camembert. Les teintes sont alignées sur la palette d'accent de l'app
     * (vert/rouge déjà utilisés par les jauges de prédictibilité).
     *
     * @param  string $statusCategory 'done'|'sprint'|'refinement'|'waiting'|'other'
     * @return string Couleur hexadécimale (ex: '#10b981')
     */
    public function getCategoryChartColor(string $statusCategory): string
    {
        return match ($statusCategory) {
            StatusCategory::DONE       => '#10b981', // emerald-500
            StatusCategory::SPRINT     => '#f59e0b', // amber-500
            StatusCategory::REFINEMENT => '#0ea5e9', // sky-500 (accent principal)
            StatusCategory::WAITING    => '#ef4444', // red-500
            default      => '#94a3b8', // slate-400
        };
    }

    /**
     * Libellé du déclencheur d'un tableau de tickets replié : annonce le nombre de tickets qu'on va ouvrir.
     *
     * À zéro, on n'invite pas à déplier un tableau vide : le libellé annonce directement qu'il n'y a rien,
     * ce qui évite un clic pour ne rien trouver.
     *
     * @param int $count Nombre de tickets du tableau
     */
    public function collapseTicketsLabel(int $count): string
    {
        if ($count === 0) {
            return 'Aucun ticket à afficher';
        }

        return $count === 1 ? 'Afficher le ticket' : 'Afficher les ' . $count . ' tickets';
    }

    /**
     * Libellé lisible d'une catégorie de statut, tel qu'affiché dans les jauges d'avancement.
     *
     * @param  string $statusCategory 'done'|'sprint'|'refinement'|'waiting'|'other'
     * @return string Ex: 'Terminé'
     */
    public function getCategoryLabel(string $statusCategory): string
    {
        return match ($statusCategory) {
            StatusCategory::DONE       => 'Terminé',
            StatusCategory::SPRINT     => 'En cours',
            StatusCategory::REFINEMENT => 'Non démarré',
            StatusCategory::WAITING    => 'Bloqué',
            default                    => 'Autre',
        };
    }

    /**
     * Répartition d'une liste de tickets par catégorie de statut, prête à alimenter une jauge de
     * progression empilée (composant views/components/sprint/categoryBar.phtml).
     *
     * Les catégories sont ordonnées du plus avancé au moins avancé (terminé → en cours → non démarré →
     * bloqué → autre) pour que la jauge se lise de gauche à droite comme une progression. Les catégories
     * sans aucun ticket sont écartées : elles n'auraient aucun segment à afficher.
     *
     * @param  list<array<string, mixed>> $issues Tickets au format SprintTicketDto::toArray()
     * @return list<array{key: string, label: string, color: string, count: int, percent: float}>
     */
    public function getCategoryBreakdown(array $issues): array
    {
        $order  = [
            StatusCategory::DONE,
            StatusCategory::SPRINT,
            StatusCategory::REFINEMENT,
            StatusCategory::WAITING,
            StatusCategory::OTHER,
        ];
        $counts = array_fill_keys($order, 0);

        foreach ($issues as $issue) {
            $category = (string) ($issue['statusCategory'] ?? StatusCategory::OTHER);
            // Une catégorie inconnue (workflow mal configuré) est rattachée à « Autre » plutôt qu'ignorée :
            // le total des segments doit toujours faire 100 % des tickets.
            $counts[isset($counts[$category]) ? $category : StatusCategory::OTHER]++;
        }

        $total = count($issues);
        if ($total === 0) {
            return [];
        }

        $breakdown = [];
        foreach ($order as $category) {
            if ($counts[$category] === 0) {
                continue;
            }
            $breakdown[] = [
                'key'     => $category,
                'label'   => $this->getCategoryLabel($category),
                'color'   => $this->getCategoryChartColor($category),
                'count'   => $counts[$category],
                'percent' => round($counts[$category] / $total * 100, 1),
            ];
        }

        return $breakdown;
    }

    /**
     * Badge d'avancement simplifié d'un ticket, en 3 états seulement (À faire / En cours / Terminé).
     *
     * Projection volontairement plus grossière que getCategoryBadgeClass(), qui distingue les 5
     * catégories du workflow : sur une simple liste de tickets, la nuance « bloqué » ou « à affiner »
     * n'apporte rien, seul l'avancement compte.
     *
     * La catégorie de statut ne suffit pas à trancher : le statut d'entrée du sprint (« À faire »,
     * « To Do »…) appartient à `sprint_statuses`, il serait donc annoncé « En cours » alors que rien n'a
     * démarré. On applique la convention déjà en vigueur dans Timeline::buildStatusTimeline : le PREMIER
     * statut de `sprint_statuses` est le statut « non démarré » (c'est celui qui, justement, ne date pas
     * la prise en charge). Un ticket en attente reste « En cours » : il a démarré, il est suspendu.
     *
     * @param  array<string, mixed> $ticket Ticket au format SprintTicketDto::toArray() (clés lues :
     *                                      statusCategory, status)
     * @return array{label: string, class: string}
     */
    public function getProgressBadge(array $ticket): array
    {
        $todo = ['label' => 'À faire', 'class' => 'grey'];

        $category = (string) ($ticket['statusCategory'] ?? '');
        if ($category === StatusCategory::DONE) {
            return ['label' => 'Terminé', 'class' => 'green'];
        }
        if ($category !== StatusCategory::SPRINT && $category !== StatusCategory::WAITING) {
            return $todo;
        }

        return $this->isNotStartedStatus((string) ($ticket['status'] ?? ''))
            ? $todo
            : ['label' => 'En cours', 'class' => 'orange'];
    }

    /**
     * Indique si un statut de la catégorie « sprint » correspond en réalité à un ticket pas encore démarré.
     *
     * Deux règles cumulées, la seconde couvrant les workflows au libellé d'entrée atypique :
     * 1. le statut est un libellé d'entrée standard (self::NOT_STARTED_STATUSES) ;
     * 2. ou c'est le PREMIER statut de `sprint_statuses`, que Timeline::buildStatusTimeline traite déjà
     *    comme le statut « non démarré » (celui qui ne date pas la prise en charge).
     *
     * La comparaison se fait sur les libellés normalisés via Timeline::NATIVE_STATUS_MAP, car « To Do » et
     * « À faire » désignent le même statut alors qu'un workflow projet ne déclare que l'un des deux.
     */
    private function isNotStartedStatus(string $status): bool
    {
        $normalize = static fn (string $value): string => Timeline::NATIVE_STATUS_MAP[$value] ?? $value;

        $status = $normalize($status);
        if ($status === '') {
            return false;
        }

        if (in_array($status, self::NOT_STARTED_STATUSES, true)) {
            return true;
        }

        $sprintStatuses = $this->config->getJiraWorkflow()['sprint_statuses'] ?? [];

        return isset($sprintStatuses[0]) && $status === $normalize((string) $sprintStatuses[0]);
    }

    /**
     * Retourne le libellé et la classe de badge à afficher dans la colonne « Origine »
     * d'un tableau de tickets. Trois catégories mutuellement exclusives :
     *
     * - « 🆕 nouvelle_prio » : ticket ajouté en cours de sprint ET porteur de
     *   l'étiquette `nouvelle_prio` (priorité commerciale interrompant le périmètre).
     * - « Ajouté »           : ticket entré dans le sprint après son démarrage.
     * - « Planifié »         : ticket présent dès le démarrage (périmètre initial).
     *
     * La règle de priorité (nouvelle_prio > ajouté > planifié) est alignée sur le
     * filtre `computeStats()`/`newPrio` qui exige aussi `addedDuringSprint`.
     *
     * Le rang sert au tri client (script `sortableTableScript`) : planifié → ajouté
     * → nouvelle_prio, du plus « calme » au plus « bruyant » pour le périmètre.
     *
     * @param  array $ticket Ticket issu de Sprint (clés utilisées : addedDuringSprint, labels)
     * @return array{label:string, class:string, rank:int}
     */
    public function getOriginBadge(array $ticket): array
    {
        $isAdded = !empty($ticket['addedDuringSprint']);
        $labels  = $ticket['labels'] ?? [];

        if ($isAdded && in_array(Sprint::LABEL_NEW_PRIORITY, $labels, true)) {
            return ['label' => '🆕 nouvelle_prio', 'class' => 'red', 'rank' => 2];
        }
        if ($isAdded) {
            return ['label' => 'Ajouté', 'class' => 'orange', 'rank' => 1];
        }
        return ['label' => 'Planifié', 'class' => 'blue', 'rank' => 0];
    }

    /**
     * Liste des sprints affectés (déployée au clic sur le chevron), du plus
     * ancien au sprint courant. Le nom du sprint courant est remplacé par
     * « courant ».
     *
     * Retourne une chaîne vide lorsqu'il n'y a aucun détail pertinent (0 ou 1
     * sprint) : la cellule n'affiche alors pas de chevron ni de ligne déployable.
     *
     * @param string[] $sprintNames Noms des sprints affectés, déjà triés en ordre naturel (sprint
     *                              courant en dernier) et déjà filtrés des sprints ignorés en amont
     *                              (SprintTicketBuilder::sprintAssignmentNames).
     */
    public function formatSprintNamesList(array $sprintNames): string
    {
        if (count($sprintNames) <= 1) {
            return '';
        }

        // Le sprint analysé est affiché « courant » plutôt que par son nom réel.
        $currentName = $this->getSprintName();
        $labels = array_map(
            static fn (string $name): string => $name === $currentName ? 'courant' : $name,
            $sprintNames
        );

        return implode(', ', $labels);
    }

    /**
     * Formate un écart (delta) signé pour l'affichage.
     *
     * - Écart positif  → préfixe « + » (ex: « +3 »)
     * - Écart négatif  → signe « - » porté par formatSp() (ex: « -2.5 »)
     * - Écart nul      → « 0 » sans signe
     *
     * @param float|int $delta Écart en tickets ou en Story Points
     */
    public function formatDelta(float|int $delta): string
    {
        if ((float) $delta === 0.0) {
            return '+0';
        }

        return ($delta > 0 ? '+' : '') . $this->formatSp($delta);
    }
}
