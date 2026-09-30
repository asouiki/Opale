<?php

declare(strict_types=1);

namespace App\Model;

use App\Dto\SprintTicketDto;
use DateTime;

/**
 * Constructeur des tickets de sprint — collaborateur extrait de Sprint (lot A2 du chantier V2).
 *
 * Reconstruit l'état de chaque Issue À LA DATE DE RÉFÉRENCE du sprint (clôture ou aujourd'hui) en
 * rejouant le changelog Jira (statut, Story Points, étiquettes, affectations de sprint), puis
 * assemble les SprintTicketDto — source unique de vérité de tous les calculs de Sprint.
 *
 * Les méthodes de reconstruction « à date » sont publiques : elles constituent l'API de rejeu du
 * changelog, caractérisée unitairement par SprintReconstructionTest.
 */
final class SprintTicketBuilder
{
    /**
     * @param Config        $config        Config projet (workflow, sprints ignorés)
     * @param Timeline      $timeline      Rejeu du changelog (getChangeHistory, normalisation des statuts)
     * @param string        $sprintId      ID Agile du sprint analysé
     * @param string        $sprintName    Nom canonique actuel du sprint (métadonnées Jira)
     * @param DateTime|null $startDateTime Date de démarrage du sprint (null : sprint futur)
     */
    public function __construct(
        private readonly Config $config,
        private readonly Timeline $timeline,
        private readonly string $sprintId,
        private readonly string $sprintName,
        private readonly ?DateTime $startDateTime,
    ) {
    }

    /**
     * Construit la liste des tickets enrichis à partir des objets Issue.
     *
     * Chaque ticket porte les données nécessaires à TOUS les calculs du sprint,
     * ce qui évite de re-parcourir les Issue dans chaque méthode de calcul.
     *
     * @param  Issue[]  $issues        Issues du sprint (changelog chargé)
     * @param  DateTime $referenceDate Date de référence (clôture ou aujourd'hui, cf. Sprint::resolveSprintEnd())
     * @return list<SprintTicketDto>
     */
    public function build(array $issues, DateTime $referenceDate): array
    {
        $workflow     = $this->config->getJiraWorkflow();
        $doneStatuses = $workflow['done_statuses'] ?? [];

        // Rang du statut dans l'ordre du workflow (refinement → sprint → done) :
        // sert de clé de tri numérique pour la colonne « Statut » côté front.
        $statusRanks  = array_flip($this->config->getWorkflowStatusOrder());

        $spField          = JiraFields::STORY_POINTS;
        $spRemainingField = JiraFields::STORY_POINTS_REMAINING;
        $spFinalField     = JiraFields::STORY_POINTS_FINAL;

        $tickets = [];

        foreach ($issues as $issue) {
            // Attente sur TOUT le cycle de vie du ticket, telle que posée à la construction de l'Issue :
            // capturée AVANT le bornage, qui l'écrase. Affichée en second plan à côté de la valeur du
            // sprint, elle distingue un blocage ponctuel d'un ticket chroniquement empêché.
            $waitingTimeFull  = $issue->getTimeSpentInWaiting();
            $waitingTimesFull = $issue->getWaitingTimes();

            // Temps d'attente RESTREINTS à la fenêtre du sprint : une attente débutée avant le sprint
            // (ticket déjà bloqué à son arrivée) ou courant après la date de référence ne compte que
            // pour sa part réellement écoulée PENDANT ce sprint. Recalculé depuis le changelog déjà
            // chargé — aucun appel Jira supplémentaire.
            $this->timeline->buildWaitingTimes($issue, $this->startDateTime, $referenceDate);

            // Statut, Story Points et étiquettes RECONSTRUITS à la date de référence
            // (clôture ou aujourd'hui) : la page représente l'état du sprint à cette
            // date, et non l'état live actuel des tickets. Toutes les métriques en
            // aval (atterrissage, reliquat, prédictibilité, stats) en héritent.
            // Le statut reconstruit reflète le nom tel qu'il était dans le changelog
            // (ex: « Done ») : on le normalise vers le libellé d'affichage réel
            // (« Terminé(e) »), cohérent avec le reste de l'app. La config workflow
            // contenant les deux formes, isDone/catégorie/rang restent corrects.
            $status     = $this->timeline->normalizeNativeStatusName(
                $this->statusAtDate($issue, $referenceDate)
            );
            $labels     = $this->labelsAtDate($issue, $referenceDate);
            $membership = $this->analyzeSprintMembership($issue, $referenceDate);

            $tickets[] = new SprintTicketDto(
                key: $issue->getKey(),
                summary: $issue->getSummary(),
                url: $issue->getIssueUrl(),
                // Type Jira (Story, Tâche, Bug...) : sert au graphe « points
                // d'effort vs temps de cycle » de la page Métriques d'équipe,
                // segmenté par type. Embarqué dans le snapshot via doneTickets.
                issueType: $issue->getIssueTypeName(),
                // Icône du type Jira (affichée en colonne « Type » des tableaux de
                // tickets, à côté du nom). Absente des anciens snapshots en cache
                // → la vue la gère avec un fallback (?? '').
                issueTypeIcon: $issue->getIssueTypeIcon(),
                status: $status,
                statusCategory: StatusCategory::resolveFromWorkflow($status, $workflow),
                statusRank: $statusRanks[$status] ?? PHP_INT_MAX,
                storyPoints: $this->storyPointsAtDate($issue, $spField, $referenceDate),
                spFinal: $this->storyPointsAtDate($issue, $spFinalField, $referenceDate),
                spRemaining: $this->storyPointsAtDate($issue, $spRemainingField, $referenceDate),
                parentKey: $issue->getParentKey(),
                parentSummary: $issue->getParentSummary(),
                priority: [
                    'name' => $issue->getPriorityName(),
                    'icon' => $issue->getPriorityIcon(),
                    'id'   => $issue->getPriorityId(),
                ],
                // Temps de traversée du ticket, en jours ouvrés (cf. Issue) :
                // - cycleTime : premier passage « en cours » → terminé
                // - leadTime  : création → terminé
                // Consommés par la page Métriques d'équipe (moyennes + distribution
                // du cycle time par estimation). Embarqués dans le snapshot via
                // stats['doneTickets'], donc disponibles aussi depuis le cache.
                cycleTime: $issue->getCycleTime(),
                leadTime: $issue->getLeadTime(),
                waitingTime: $issue->getTimeSpentInWaiting(),
                // Ventilation du temps d'attente par cause (statuts, labels, flag) : alimente
                // la ligne dépliable « Détail de l'attente » du tableau des tickets.
                waitingTimes: $issue->getWaitingTimes(),
                waitingTimeFull: $waitingTimeFull,
                waitingTimesFull: $waitingTimesFull,
                labels: array_values($labels),
                // Liste ordonnée (chronologique) des noms de sprints auxquels le
                // ticket a été affecté jusqu'à la date de référence, sprint courant
                // inclus. Alimente la colonne « Nombre de sprints affectés ».
                sprintNames: $this->sprintAssignmentNames($issue, $referenceDate),
                isDone: in_array($status, $doneStatuses, true),
                addedDuringSprint: $membership['added'],
                removedDuringSprint: $membership['removed'],
                // Vrai si le ticket était non estimé à son entrée dans CE sprint
                // puis a reçu une estimation par la suite (jusqu'à la date de référence).
                estimatedAfterAdd: $this->wasEstimatedAfterAdd($issue, $referenceDate),
            );
        }

        return $tickets;
    }

    /**
     * Analyse l'historique du champ « Sprint » d'une issue pour détecter son entrée/sortie du sprint courant
     * entre la date de démarrage et la date de référence.
     *
     * Algorithme :
     * - Parcourt le changelog (champ "Sprint") trié chronologiquement.
     * - Un événement où l'ID du sprint apparaît dans `to` mais pas dans `from` = ajout. L'inverse = retrait.
     * - Seuls les événements de la fenêtre ]date de démarrage, $at] sont retenus : un mouvement postérieur à la date
     *   de référence n'avait pas encore eu lieu à l'instant observé, il ne peut donc pas compter comme un changement
     *   de périmètre (cas typique : tickets déplacés APRÈS la clôture d'un sprint clos).
     *
     * Limite connue : un ticket retiré puis JAMAIS réintégré n'est plus rattaché
     * au sprint et n'est donc pas récupérable via l'API publique Jira. Seuls les
     * retraits de tickets encore visibles dans le sprint sont détectables ici.
     * Limite mitigée en best-effort par buildPuntedTickets() (cf. Sprint::fetchPuntedTickets()),
     * qui va chercher ces tickets via l'endpoint interne "Sprint Report" de Jira.
     *
     * @param DateTime $at Date de référence du sprint (clôture, aujourd'hui, ou date d'observation choisie)
     *
     * @return array{added:bool, removed:bool}
     */
    public function analyzeSprintMembership(Issue $issue, DateTime $at): array
    {
        $result = ['added' => false, 'removed' => false];

        // Pas de date de démarrage (sprint futur) : aucun changement de périmètre calculable
        if ($this->startDateTime === null) {
            return $result;
        }

        foreach ($this->timeline->getChangeHistory($issue, 'Sprint') as $change) {
            // Historique trié : au-delà de la date de référence, plus rien ne concerne l'état observé.
            if ($change['date'] > $at) {
                break;
            }
            if ($change['date'] <= $this->startDateTime) {
                continue;
            }

            $fromIds = $this->parseSprintIds($change['item']['from'] ?? '');
            $toIds   = $this->parseSprintIds($change['item']['to'] ?? '');

            $wasIn = in_array($this->sprintId, $fromIds, true);
            $isIn  = in_array($this->sprintId, $toIds, true);

            if (!$wasIn && $isIn) {
                $result['added'] = true;
            }
            if ($wasIn && !$isIn) {
                $result['removed'] = true;
            }
        }

        return $result;
    }

    /**
     * Le ticket appartenait-il au sprint courant à la date donnée ?
     *
     * Reconstruit l'appartenance en rejouant le changelog du champ « Sprint » (même principe que statusAtDate() /
     * labelsAtDate()) : état initial = présence de l'ID du sprint dans le `from` de la 1re transition, puis on suit
     * les `to` tant que la transition précède $at.
     *
     * Sert à reconstruire le PÉRIMÈTRE du sprint à une date passée, dans les deux sens : réintégrer un ticket retiré
     * après cette date (invisible de l'API Agile aujourd'hui) et exclure un ticket ajouté après elle.
     *
     * @param DateTime $at             Date d'observation
     * @param bool     $isInSprintNow  Appartenance ACTUELLE au sprint, utilisée comme repli quand le changelog ne
     *                                 porte aucune transition « Sprint » (l'objet Issue ne connaît pas ce champ :
     *                                 seul l'appelant sait d'où provient le ticket). Vrai pour une issue renvoyée par
     *                                 l'API Agile du sprint, faux pour un ticket retiré retrouvé via le Sprint Report.
     */
    public function wasInSprintAt(Issue $issue, DateTime $at, bool $isInSprintNow): bool
    {
        $inSprint    = $isInSprintNow;
        $foundChange = false;

        foreach ($this->timeline->getChangeHistory($issue, 'Sprint') as $change) {
            // Amorçage : état AVANT la 1re réaffectation tracée.
            if (!$foundChange) {
                $inSprint    = in_array($this->sprintId, $this->parseSprintIds($change['item']['from'] ?? ''), true);
                $foundChange = true;
            }

            // Historique trié du plus ancien au plus récent : on s'arrête à la 1re transition postérieure à $at.
            if ($change['date'] > $at) {
                return $inSprint;
            }

            $inSprint = in_array($this->sprintId, $this->parseSprintIds($change['item']['to'] ?? ''), true);
        }

        return $inSprint;
    }

    /**
     * Découpe une valeur brute `from`/`to` du changelog Sprint en IDs de sprint.
     *
     * @param  mixed $raw Valeur brute (ex: "123, 124" ou null)
     * @return string[]
     */
    private function parseSprintIds(mixed $raw): array
    {
        $raw = (string) ($raw ?? '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Construit les tickets « pontés » (retirés du sprint puis jamais réintégrés) à partir de
     * `contents.puntedIssues` du "Sprint Report" interne de Jira (cf. JiraService::getSprintReport()).
     *
     * Ces tickets ne sont plus rattachés au sprint : ils sont absents de l'API Agile publique
     * `/sprint/{id}/issue` et donc invisibles pour analyzeSprintMembership(). Le payload de ce
     * rapport ne portant pas de changelog, plusieurs champs du DTO restent volontairement neutres
     * (non reconstructibles à la date de référence) :
     * - spFinal / spRemaining : non fournis par cet endpoint → null
     * - cycleTime / leadTime / waitingTime / waitingTimes : nécessitent l'historique complet → 0 / []
     * - sprintNames : non nécessaire aux colonnes affichées par le tableau des tickets retirés → []
     * - estimatedAfterAdd : non calculable sans changelog → false
     * - parentSummary : seul le rattachement à une Epic est fourni (`epicField.summary`) ; un
     *   parent Story/Subtask classique n'est pas présent dans ce payload → null dans ce cas
     *
     * @param  array<int, array<string, mixed>> $puntedIssuesRaw Entrées brutes de `contents.puntedIssues`
     * @param  list<string>                     $existingKeys    Clés déjà couvertes par analyzeSprintMembership()
     *                                                            (retrait détecté via changelog) — évite un double
     *                                                            comptage pour un ticket retiré PUIS réintégré.
     * @return list<SprintTicketDto>
     */
    public function buildPuntedTickets(array $puntedIssuesRaw, array $existingKeys): array
    {
        $workflow    = $this->config->getJiraWorkflow();
        $statusRanks = array_flip($this->config->getWorkflowStatusOrder());

        $tickets = [];

        foreach ($puntedIssuesRaw as $raw) {
            $key = (string) ($raw['key'] ?? '');
            if ($key === '' || in_array($key, $existingKeys, true)) {
                continue;
            }

            $status = (string) ($raw['statusName'] ?? '');

            $tickets[] = new SprintTicketDto(
                key: $key,
                summary: (string) ($raw['summary'] ?? ''),
                url: JiraUrl::browse($key),
                issueType: (string) ($raw['typeName'] ?? ''),
                issueTypeIcon: (string) ($raw['typeUrl'] ?? ''),
                status: $status,
                statusCategory: StatusCategory::resolveFromWorkflow($status, $workflow),
                statusRank: $statusRanks[$status] ?? PHP_INT_MAX,
                storyPoints: $this->puntedStoryPoints($raw),
                spFinal: null,
                spRemaining: null,
                parentKey: isset($raw['parentKey']) ? (string) $raw['parentKey'] : null,
                parentSummary: isset($raw['epicField']['summary']) ? (string) $raw['epicField']['summary'] : null,
                priority: [
                    'name' => (string) ($raw['priorityName'] ?? ''),
                    'icon' => (string) ($raw['priorityUrl'] ?? ''),
                    'id'   => (string) ($raw['priorityId'] ?? ''),
                ],
                cycleTime: 0.0,
                leadTime: 0.0,
                waitingTime: 0.0,
                waitingTimes: [],
                waitingTimeFull: 0.0,
                waitingTimesFull: [],
                labels: array_values($raw['labels'] ?? []),
                sprintNames: [],
                isDone: (bool) ($raw['done'] ?? false),
                addedDuringSprint: false,
                removedDuringSprint: true,
                estimatedAfterAdd: false,
            );
        }

        return $tickets;
    }

    /**
     * Extrait les Story Points d'une entrée `puntedIssues` (valeur numérique du champ
     * courant d'estimation, absente si le ticket n'était pas estimé).
     *
     * @param  array<string, mixed> $raw Entrée brute de `contents.puntedIssues`
     */
    private function puntedStoryPoints(array $raw): ?float
    {
        $value = $raw['currentEstimateStatistic']['statFieldValue']['value'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Reconstruit la liste ordonnée des sprints auxquels le ticket a été affecté,
     * du plus ancien jusqu'au sprint courant, en rejouant le changelog du champ
     * « Sprint ».
     *
     * Même principe que Timeline::buildStatusTimeline() (colonne « Date de 1er
     * ajout à un sprint » de la page Feature) : les NOMS lisibles des sprints
     * sont portés par `fromString`/`toString` (listes séparées par des virgules).
     * Ici on ne se limite pas au premier ajout : on collecte toute la séquence.
     *
     * Algorithme (changelog trié du plus ancien au plus récent) :
     * - 1re transition « Sprint » : on amorce avec le côté `fromString` (sprints
     *   présents avant la première réaffectation tracée).
     * - Pour chaque transition jusqu'à $at inclus, on ajoute les noms du côté
     *   `toString` non encore vus → l'ordre d'insertion reflète l'ordre
     *   chronologique d'affectation.
     * - Le sprint courant est toujours ajouté en dernier s'il manque (ticket créé
     *   directement dans le sprint, sans entrée de changelog « Sprint »).
     *
     * @param DateTime $at Date de référence du sprint (clôture ou aujourd'hui)
     *
     * @return list<string> Noms des sprints affectés, ordre chronologique
     */
    public function sprintAssignmentNames(Issue $issue, DateTime $at): array
    {
        $names       = []; // [nom => true], ordre d'insertion = ordre chronologique
        $foundChange = false;

        foreach ($this->timeline->getChangeHistory($issue, 'Sprint') as $change) {
            $item = $change['item'];

            // Amorçage : sprints présents avant la 1re réaffectation tracée.
            if (!$foundChange) {
                $names = $this->collectSprintNamesExcludingCurrent($names, $item['from'] ?? '', $item['fromString'] ?? '');
                $foundChange = true;
            }

            // Transition postérieure à la date de référence : on s'arrête
            // (historique trié) après avoir conservé l'état antérieur.
            if ($change['date'] > $at) {
                break;
            }

            $names = $this->collectSprintNamesExcludingCurrent($names, $item['to'] ?? '', $item['toString'] ?? '');
        }

        // Le sprint courant est toujours compté : le ticket appartient à son périmètre.
        $currentName = $this->sprintName;

        $names = $this->removeIgnoredSprintsFromConfig($names, $currentName);

        // Tri « naturel » (strnatcmp) des sprints affectés, puis sprint courant
        // ajouté EN DERNIER. Le tri naturel rétablit l'ordre logique « du plus
        // ancien au plus récent » quel que soit l'ordre dans lequel le ticket a
        // été baladé entre sprints : « PI6 ... » < « PI7 ... », et « ité 4 » <
        // « ité IP » (les chiffres précèdent les lettres, donc l'itération « IP »
        // se range après les itérations numérotées).
        //
        // Heuristique assumée (choix KISS, aucun appel Jira) : suppose des noms
        // homogènes au sein d'un même projet. Elle peut mal classer l'itération
        // « IP » quand les itérations numérotées portent un préfixe lettre
        // (« IT4 », « n°3 »), 'I' s'intercalant alors avant. Le sprint courant est
        // toujours forcé en dernier (exigence d'affichage), même s'il n'est pas le
        // plus récent au sens du tri.
        unset($names[$currentName]);
        // strval : une clé de tableau numérique (sprint nommé « 12 ») serait sinon typée int.
        $ordered = array_map('strval', array_keys($names));
        usort($ordered, 'strnatcmp');
        $ordered[] = $currentName;

        return $ordered;
    }

    /**
     * Ajoute à l'accumulateur $names les noms de sprint d'un côté du changelog
     * « Sprint » (`from`/`fromString` ou `to`/`toString`), en EXCLUANT le sprint
     * courant identifié par son ID.
     *
     * Pourquoi exclure le courant ici : son nom canonique (issu des métadonnées
     * Jira actuelles) est ajouté séparément en fin de sprintAssignmentNames().
     * Un renommage de sprint ne génère aucune entrée de changelog : le changelog
     * conserve donc l'ancien nom. Sans cette exclusion, un renommage du sprint
     * courant ferait apparaître un doublon (ancien nom figé + nouveau nom).
     *
     * Les listes `from`/`to` (IDs) et `fromString`/`toString` (noms) sont
     * alignées positionnellement : on saute le nom dont l'ID vaut $this->sprintId.
     * Si l'ID aligné est absent (cas anormal pour ce champ), le nom est conservé
     * (aucune régression).
     *
     * @param  array<string, bool> $names    Accumulateur [nom => true] (ordre = chrono)
     * @param  mixed               $rawIds   Côté `from`/`to` : IDs (ex: "100, 101")
     * @param  mixed               $rawNames Côté `fromString`/`toString` : noms alignés
     * @return array<string, bool>
     */
    private function collectSprintNamesExcludingCurrent(array $names, mixed $rawIds, mixed $rawNames): array
    {
        $ids   = $this->parseSprintIds($rawIds);
        $sprintNames = $this->splitSprintNames($rawNames);

        foreach ($sprintNames as $i => $name) {
            if (($ids[$i] ?? null) === $this->sprintId) {
                continue;
            }
            $names[$name] = true;
        }

        return $names;
    }

    /**
     * Retire les sprints « fictifs » configurés (config_files/teams/<projectKey>_team_config.json) :
     * ils ne représentent pas une itération réelle (ex. backlog de priorisation PO, IP...).
     * Comparaison insensible à la casse.
     * Le sprint courant n'est jamais filtré (c'est celui qu'on analyse).
     *
     * NB : le filtrage opère sur les CLÉS (forme accumulateur [nom => true] de
     * sprintAssignmentNames). Également appelé par SprintView (via Sprint) avec une liste
     * indexée — chemin sur lequel il est alors sans effet, les noms ayant déjà été filtrés
     * en amont par sprintAssignmentNames.
     *
     * @param  array<int|string, mixed> $sprintNames       Noms de sprints (clés ou valeurs selon l'appelant)
     * @param  string                   $currentSprintName Nom du sprint courant (jamais filtré)
     * @return array<int|string, mixed>
     */
    public function removeIgnoredSprintsFromConfig(array $sprintNames, string $currentSprintName): array
    {
        $ignored = array_map('mb_strtolower', $this->config->getIgnoredSprintNames());
        if ($ignored !== []) {
            foreach (array_keys($sprintNames) as $name) {
                if ($name !== $currentSprintName && in_array(mb_strtolower((string) $name), $ignored, true)) {
                    unset($sprintNames[$name]);
                }
            }
        }
        return $sprintNames;
    }

    /**
     * Découpe une valeur `fromString`/`toString` du changelog « Sprint » (noms de
     * sprints séparés par des virgules) en tableau de noms, vides exclus.
     *
     * @param  mixed $raw Ex: "Sprint A, Sprint B"
     * @return string[]
     */
    private function splitSprintNames(mixed $raw): array
    {
        return $this->parseSprintIds($raw); // même découpage générique : virgule + trim
    }

    /**
     * Reconstruit la valeur du champ « Story Points » telle qu'elle était à une
     * date donnée, en rejouant le changelog Jira.
     *
     * Algorithme (changelog trié du plus ancien au plus récent) :
     * - On repère les transitions du champ (match sur `fieldId`).
     * - La valeur AVANT la première transition = le `from` de cette transition.
     * - On avance dans les transitions : tant que la date de changement est
     *   antérieure ou égale à $at, on adopte le `to` de la transition.
     * - Dès qu'une transition dépasse $at, on s'arrête (historique trié) et on
     *   retourne la dernière valeur retenue.
     * - Si aucune transition du champ n'existe, la valeur n'a jamais changé :
     *   on retourne donc la valeur actuelle.
     *
     * Pour un champ personnalisé numérique, Jira renseigne la valeur lisible dans
     * `fromString`/`toString` et laisse souvent `from`/`to` à null : on lit donc
     * en priorité la forme « String » (cf. storyPointValueFromChange()).
     *
     * @param  Issue     $issue   Issue dont on rejoue l'historique
     * @param  string    $fieldId ID du customfield Story Points (ex: "customfield_10024")
     * @param  DateTime  $at      Date à laquelle on veut connaître la valeur
     * @return float|null         Valeur SP à cette date, ou null si non estimé (champ vide)
     */
    public function storyPointsAtDate(Issue $issue, string $fieldId, DateTime $at): ?float
    {
        $value       = null;  // Valeur courante au fil du rejeu
        $foundChange = false; // A-t-on rencontré au moins une transition du champ ?

        foreach ($this->timeline->getChangeHistory($issue, $fieldId, 'fieldId') as $change) {
            // Initialise la valeur avec l'état précédant la 1re transition connue.
            if (!$foundChange) {
                $value       = $this->storyPointValueFromChange($change['item'], 'from');
                $foundChange = true;
            }

            // Transition postérieure à $at : la valeur à $at est déjà figée.
            if ($change['date'] > $at) {
                return $value;
            }

            $value = $this->storyPointValueFromChange($change['item'], 'to');
        }

        // Aucune transition journalisée : la valeur actuelle vaut depuis la création.
        if (!$foundChange) {
            return $issue->getStoryPoints($fieldId);
        }

        return $value;
    }

    /**
     * Extrait la valeur de Story Points d'une transition du changelog, pour le
     * côté demandé (`from` ou `to`).
     *
     * Jira renseigne la valeur lisible dans `<côté>String` ; la clé brute
     * `<côté>` peut être null pour les champs personnalisés numériques. On
     * privilégie donc la forme lisible, avec repli sur la valeur brute.
     *
     * @param  array<string, string|null> $item Élément de changelog (une transition)
     * @param  string                     $side 'from' ou 'to'
     * @return float|null                       Valeur SP, ou null si le champ était vide
     */
    private function storyPointValueFromChange(array $item, string $side): ?float
    {
        $raw = $item[$side . 'String'] ?? null;
        if ($raw === null || $raw === '') {
            $raw = $item[$side] ?? null;
        }

        return $this->parseStoryPointValue($raw);
    }

    /**
     * Convertit une valeur brute du changelog en Story Points.
     *
     * Une valeur vide (null ou chaîne vide) signifie « champ non renseigné »
     * et est donc retournée comme null (= non estimé). La valeur 0 est conservée
     * telle quelle car c'est une estimation valide. La virgule décimale (locale
     * française) est tolérée (« 2,5 » → 2.5).
     *
     * @param  mixed      $raw Valeur brute du changelog
     * @return float|null      Valeur numérique, ou null si le champ était vide
     */
    private function parseStoryPointValue(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            $raw = str_replace(',', '.', trim($raw));
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    /**
     * Reconstruit le statut Jira de l'issue tel qu'il était à une date donnée,
     * en rejouant les transitions de statut du changelog.
     *
     * Algorithme (changelog trié du plus ancien au plus récent) :
     * - La valeur AVANT la première transition = le `fromString` de celle-ci.
     * - Tant que la date de transition est antérieure ou égale à $at, on adopte
     *   son `toString`.
     * - Dès qu'une transition dépasse $at, on s'arrête (historique trié).
     * - Si aucune transition de statut n'existe, on retourne le statut actuel.
     *
     * @param DateTime $at Date à laquelle on veut connaître le statut
     *
     * @return string Nom du statut à cette date
     */
    public function statusAtDate(Issue $issue, DateTime $at): string
    {
        $status      = $issue->getStatusName(); // fallback si aucune transition
        $foundChange = false;

        foreach ($this->timeline->getChangeHistory($issue, 'status') as $change) {
            if (!$foundChange) {
                $status      = $change['item']['fromString'] ?? $status;
                $foundChange = true;
            }

            if ($change['date'] > $at) {
                return $status;
            }

            $status = $change['item']['toString'] ?? $status;
        }

        return $status;
    }

    /**
     * Reconstruit les étiquettes (labels) Jira de l'issue telles qu'elles étaient
     * à une date donnée, en rejouant les changements du champ « labels ».
     *
     * Même algorithme que statusAtDate() : valeur avant la 1re modification =
     * `fromString` de celle-ci, puis on suit les `toString` jusqu'à $at.
     * Si aucune modification de labels n'existe, on retourne les labels actuels.
     *
     * @param DateTime $at Date à laquelle on veut connaître les étiquettes
     *
     * @return string[] Étiquettes à cette date
     */
    public function labelsAtDate(Issue $issue, DateTime $at): array
    {
        $labels      = $issue->getLabels(); // fallback si aucune modification
        $foundChange = false;

        foreach ($this->timeline->getChangeHistory($issue, 'labels') as $change) {
            if (!$foundChange) {
                $labels      = $this->splitLabels($change['item']['fromString'] ?? '');
                $foundChange = true;
            }

            if ($change['date'] > $at) {
                return $labels;
            }

            $labels = $this->splitLabels($change['item']['toString'] ?? '');
        }

        return $labels;
    }

    /**
     * Découpe une chaîne d'étiquettes Jira (séparées par des espaces) en tableau,
     * en préservant la casse d'origine (pour un affichage fidèle).
     *
     * @param  string   $raw Chaîne brute du changelog (`fromString`/`toString`)
     * @return string[]
     */
    private function splitLabels(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $raw), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Détermine la date à laquelle l'issue est entrée dans CE sprint.
     *
     * Parcourt le changelog (champ « Sprint ») trié chronologiquement et retourne
     * la date du premier événement où l'ID du sprint courant apparaît dans `to`
     * sans être dans `from` (= ajout au sprint).
     *
     * @return DateTime|null Date d'entrée, ou null si aucun ajout n'est tracé
     *                       (ticket présent dès la planification / création)
     */
    private function sprintEntryDate(Issue $issue): ?DateTime
    {
        foreach ($this->timeline->getChangeHistory($issue, 'Sprint') as $change) {
            $fromIds = $this->parseSprintIds($change['item']['from'] ?? '');
            $toIds   = $this->parseSprintIds($change['item']['to'] ?? '');

            if (!in_array($this->sprintId, $fromIds, true)
                && in_array($this->sprintId, $toIds, true)
            ) {
                return $change['date'];
            }
        }

        return null;
    }

    /**
     * Indique si le ticket a été estimé APRÈS son entrée dans le sprint.
     *
     * Définition métier : le champ Story Points était vide (non renseigné) au
     * moment de l'entrée du ticket dans CE sprint, puis a reçu une valeur
     * (y compris 0, qui est une estimation valide) au plus tard à la date de
     * référence du sprint.
     *
     * Date d'entrée retenue : date d'ajout au sprint si elle est tracée, sinon
     * la date de démarrage du sprint (ticket planifié dès le départ), sinon la
     * date de création du ticket.
     *
     * @param DateTime $referenceDate État du sprint (clôture ou aujourd'hui)
     */
    public function wasEstimatedAfterAdd(Issue $issue, DateTime $referenceDate): bool
    {
        $entryDate = $this->sprintEntryDate($issue)
            ?? $this->startDateTime
            ?? $issue->getCreatedDate(false);

        if (!$entryDate instanceof DateTime) {
            return false;
        }

        $field     = JiraFields::STORY_POINTS;
        $spAtEntry = $this->storyPointsAtDate($issue, $field, $entryDate);
        $spAtRef   = $this->storyPointsAtDate($issue, $field, $referenceDate);

        return $spAtEntry === null && $spAtRef !== null;
    }
}
