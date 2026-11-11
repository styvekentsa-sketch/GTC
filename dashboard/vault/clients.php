<?php
/**
 * GTC-Vault — Répertoire des clients KYC
 *
 *   clients.php?q=mballa&type=particulier&page=2
 *   - q    : recherche dans le nom, le téléphone et le N° d'identifiant (plusieurs mots : tous doivent être trouvés) ;
 *   - type : '' (tous), 'particulier' ou 'entreprise' ;
 *   - page : 20 dossiers par page, du plus récent au plus ancien ;
 *   - etat : '' (dossiers actifs) ou 'archives' (dossiers archivés, restaurables).
 * Actions par dossier (menu « ⋮ ») : archiver / restaurer, supprimer (administrateurs) → vault/client-action.php.
 *
 * Recherche fluide : le script de la page interroge cette même adresse avec « partial=1 » et reçoit en JSON le HTML
 * des résultats, rendu ici par PHP (un seul gabarit). Sans JavaScript, le formulaire s'envoie normalement (GET).
 * Sécurité : session obligatoire (401 en JSON pour la recherche), requêtes préparées, LIKE échappé.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/vault.php';

const CLIENTS_PER_PAGE = 20;
const CLIENTS_TYPES    = ['particulier' => 'Particuliers', 'entreprise' => 'Entreprises'];

$partial = ($_GET['partial'] ?? '') === '1';
vault_guard($partial ? 'json' : false);   // connexion du Dashboard + rôle VAULT_ROLES
if ($partial) {
    session_write_close();   // recherche en direct : lecture seule, la session n'est pas bloquée
}

// ---------- Paramètres ----------
$q     = trim((string) preg_replace('/\s+/u', ' ', mb_substr((string) ($_GET['q'] ?? ''), 0, 100)));
$type  = isset(CLIENTS_TYPES[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';
$etat  = in_array($_GET['etat'] ?? '', ['archives', 'provisoires'], true) ? (string) $_GET['etat'] : '';
if ($etat !== '') {
    $type = '';   // vues « Archivés » et « À vérifier » : tous les types
}
$page  = max(1, (int) ($_GET['page'] ?? 1));
$words = $q === '' ? [] : array_slice(array_unique(search_words($q)), 0, 5);

/**
 * Mots recherchés. Les groupes de chiffres qui se suivent forment un seul nombre :
 * « +237 6 70 00 » → « +23767000 », « mballa 6 70 » → « mballa », « 670 ».
 */
function search_words(string $q): array
{
    $words = [];
    $number = '';
    foreach (explode(' ', $q) as $token) {
        if (preg_match('/^\+?[\d().\-]+$/', $token)) {
            $number .= $token;
            continue;
        }
        if ($number !== '') {
            $words[] = $number;
            $number = '';
        }
        $words[] = $token;
    }
    if ($number !== '') {
        $words[] = $number;
    }
    return $words;
}

/** Adresse de la liste avec les filtres courants ($change : paramètres à remplacer). */
function clients_url(array $change = []): string
{
    global $q, $type, $page, $etat;
    $params = array_filter(array_merge(['q' => $q, 'type' => $type, 'etat' => $etat, 'page' => $page > 1 ? $page : null], $change),
        fn ($v) => $v !== null && $v !== '' && $v !== 1);
    return 'vault/clients.php' . ($params ? '?' . http_build_query($params) : '');
}

/**
 * Conditions de recherche : chaque mot doit apparaître dans le nom, le N° d'identifiant ou le téléphone
 * (pour le téléphone, les chiffres seuls sont aussi cherchés : « 670 00 » trouve « +237670000000 »).
 */
function clients_where(array $words, string $type, ?string $etat = ''): array
{
    $sql = [];
    $params = [];
    if ($etat !== null) {   // null : actifs et archivés confondus
        $sql[] = $etat === 'archives' ? 'c.archived_at IS NOT NULL' : 'c.archived_at IS NULL';
    }
    if ($etat === 'provisoires') {   // dossiers créés depuis la caisse, à vérifier par la conformité
        $sql[] = "c.review_status = 'pending'";
    }
    if ($type !== '') {
        $sql[] = 'c.type_client = ?';
        $params[] = $type;
    }
    foreach ($words as $word) {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';   // « ! » : caractère d'échappement
        $or = ["c.nom_complet LIKE ? ESCAPE '!'", "c.numero_identifiant LIKE ? ESCAPE '!'", "c.telephone LIKE ? ESCAPE '!'"];
        array_push($params, $like, $like, $like);
        $digits = preg_replace('/\D/', '', $word);
        if (strlen($digits) >= 3 && $digits !== $word) {
            $or[] = 'c.telephone LIKE ?';
            $params[] = '%' . $digits . '%';
        }
        $sql[] = '(' . implode(' OR ', $or) . ')';
    }
    return [$sql ? 'WHERE ' . implode(' AND ', $sql) : '', $params];
}

/** Texte échappé avec les mots recherchés surlignés (chiffres trouvés même séparés par des espaces). */
function mark(string $text, array $words): string
{
    $patterns = [];
    foreach ($words as $w) {
        // Nombre : chiffres trouvés même séparés par des espaces dans le texte affiché (« 67000 » → « 6 70 00 »)
        $digits = preg_replace('/\D/', '', $w);
        $patterns[] = preg_match('/^\+?[\d().\-]+$/', $w) && strlen($digits) >= 2
            ? implode('\s?', str_split($digits))
            : preg_quote($w, '/');
    }
    if (!$patterns) {
        return h($text);
    }
    $parts = preg_split('/(' . implode('|', $patterns) . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $out = '';
    foreach ($parts as $i => $part) {
        $out .= $i % 2 ? '<mark class="vault-mark">' . h($part) . '</mark>' : h($part);
    }
    return $out;
}

// ---------- Requêtes ----------
$error  = null;
$rows   = [];
$total  = 0;
$counts = ['' => 0, 'particulier' => 0, 'entreprise' => 0];
try {
    $pdo = vault_db();

    // Nombre de dossiers par type pour la recherche en cours (pastilles de filtre)
    [$whereAll, $paramsAll] = clients_where($words, '', '');   // pastilles : dossiers actifs
    $st = $pdo->prepare("SELECT c.type_client, COUNT(*) AS n FROM clients c {$whereAll} GROUP BY c.type_client");
    $st->execute($paramsAll);
    foreach ($st as $r) {
        $counts[$r['type_client']] = (int) $r['n'];
        $counts[''] += (int) $r['n'];
    }
    // Dossiers archivés correspondant à la recherche (onglet « Archivés »)
    [$whereArch, $paramsArch] = clients_where($words, '', 'archives');
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients c {$whereArch}");
    $st->execute($paramsArch);
    $archivedCount = (int) $st->fetchColumn();
    [$wherePend, $paramsPend] = clients_where($words, '', 'provisoires');
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients c {$wherePend}");
    $st->execute($paramsPend);
    $pendingCount = (int) $st->fetchColumn();
    $total = $etat === 'archives' ? $archivedCount : ($etat === 'provisoires' ? $pendingCount : $counts[$type]);
    $pages = max(1, (int) ceil($total / CLIENTS_PER_PAGE));
    $page  = min($page, $pages);

    [$where, $params] = clients_where($words, $type, $etat);
    $offset = ($page - 1) * CLIENTS_PER_PAGE;
    $st = $pdo->prepare("SELECT c.uuid, c.type_client, c.nom_complet, c.telephone, c.numero_identifiant, c.created_at, c.avatar_path, c.archived_at, c.review_status,
                                (SELECT COUNT(*) FROM client_documents d WHERE d.client_id = c.id) AS nb_docs
                         FROM clients c {$where}
                         ORDER BY c.created_at DESC, c.id DESC
                         LIMIT " . CLIENTS_PER_PAGE . " OFFSET {$offset}");
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    error_log('[GTC-Vault clients] ' . $e->getMessage());
    $error = 'La liste des clients est momentanément indisponible. Réessayez dans un instant.';
    $pages = 1;
}
$archivedCount ??= 0;
$pendingCount ??= 0;
$isEmptyBase = !$error && $counts[''] === 0 && !$words && $etat === '' && $archivedCount === 0;

// ---------- Gabarits ----------
function type_badge(string $type): string
{
    return $type === 'entreprise'
        ? '<span class="badge badge--info">Entreprise</span>'
        : '<span class="badge badge--success">Particulier</span>';
}

/** Initiales de l'avatar : chiffres, symboles et formes juridiques ignorés (« GLOBAL IMPORT SARL » → « GI »). */
function kyc_initials(string $name): string
{
    $legal = ['SARL', 'SARLU', 'SA', 'SAS', 'SASU', 'SUARL', 'GIE', 'ETS', 'STE', 'SCI', 'LTD', 'PLC', 'INC', 'ET', 'DE', 'DU', 'DES', 'LA', 'LE', 'LES'];
    $parts = array_values(array_filter(preg_split('/[\s&\-]+/u', trim($name)) ?: [],
        fn ($w) => preg_match('/^\p{L}/u', $w) && !in_array(mb_strtoupper($w), $legal, true))) ?: [$name];
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function docs_label(int $n): string
{
    return $n === 0 ? '<span class="vault-warn">Aucune pièce</span>' : $n . ' pièce' . ($n > 1 ? 's' : '');
}

/**
 * Menu « ⋮ » d'un dossier : archiver ou restaurer (rôles GTC-Vault), supprimer définitivement (administrateurs).
 * Formulaires POST vers vault/client-action.php (jeton CSRF, confirmation avant l'envoi).
 */
function client_actions_menu(array $r, string $back): string
{
    $base = ['uuid' => $r['uuid'], 'back' => $back];
    $name = $r['nom_complet'];
    $items = [$r['archived_at']
        ? ['post', 'Restaurer le dossier', 'refresh', ['op' => 'restore'] + $base, 'action' => 'vault/client-action.php']
        : ['post', 'Archiver le dossier', 'archive', ['op' => 'archive'] + $base,
           "Archiver le dossier de {$name} ?\n\nIl sera retiré du répertoire et de la recherche de la facturation, mais conservé intact : vous pourrez le restaurer à tout moment depuis l'onglet « Archivés ».",
           'action' => 'vault/client-action.php']];
    if (is_admin()) {
        $n = (int) ($r['nb_docs'] ?? 0);
        $items[] = ['sep'];
        $items[] = ['post', 'Supprimer définitivement', 'trash', ['op' => 'delete'] + $base,
            "Supprimer DÉFINITIVEMENT le dossier de {$name}" . ($n ? " et ses {$n} pièce" . ($n > 1 ? 's' : '') . " d'identité" : '') . " ?\n\n"
            . "Cette action est irréversible. Pour retirer simplement le dossier du répertoire, préférez « Archiver ».",
            'danger' => true, 'action' => 'vault/client-action.php'];
    }
    return action_menu($items, 'Actions du dossier de ' . $name);
}

/** Pastilles de filtre, résultats, pagination : renvoyés tels quels à la recherche fluide. */
function render_results(): string
{
    global $rows, $total, $page, $pages, $q, $type, $words, $counts, $error, $isEmptyBase, $etat, $archivedCount, $pendingCount;
    ob_start(); ?>
    <div class="vault-list-head">
        <nav class="chips vault-chips" aria-label="Filtrer par type de client">
            <?php foreach (['' => 'Tous'] + CLIENTS_TYPES as $key => $label):
                $active = $key === $type && $etat === ''; ?>
                <a href="<?= h(clients_url(['type' => $key, 'etat' => null, 'page' => null])) ?>" data-nav data-type="<?= h($key) ?>"
                   class="chip<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="true"' : '' ?>>
                    <?= h($label) ?> <span class="chip__count"><?= $counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
            <!-- Dossiers provisoires créés depuis la caisse : à compléter et valider par la conformité -->
            <a href="<?= h(clients_url(['etat' => $etat === 'provisoires' ? null : 'provisoires', 'type' => null, 'page' => null])) ?>" data-nav data-etat
               class="chip vault-chip--pending<?= $etat === 'provisoires' ? ' is-active' : '' ?><?= $pendingCount ? ' has-items' : '' ?>"<?= $etat === 'provisoires' ? ' aria-current="true"' : '' ?>>
                <?= icon('clock') ?> À vérifier <span class="chip__count"><?= $pendingCount ?></span>
            </a>
            <!-- Dossiers archivés : conservés, restaurables, hors du répertoire et de la facturation -->
            <a href="<?= h(clients_url(['etat' => $etat === 'archives' ? null : 'archives', 'type' => null, 'page' => null])) ?>" data-nav data-etat
               class="chip vault-chip--archive<?= $etat === 'archives' ? ' is-active' : '' ?>"<?= $etat === 'archives' ? ' aria-current="true"' : '' ?>>
                <?= icon('archive') ?> Archivés <span class="chip__count"><?= $archivedCount ?></span>
            </a>
        </nav>
        <p class="vault-summary" data-summary>
            <?php if ($total > 0): ?>
                <?= $total === 1 ? '1 dossier' : number_format($total, 0, ',', "\u{202F}") . ' dossiers' ?><?= $etat === 'archives' ? ($total === 1 ? ' archivé' : ' archivés') : ($etat === 'provisoires' ? ' à vérifier' : '') ?><?= $q !== '' ? ' pour « ' . h($q) . ' »' : '' ?>
                <?php if ($total > CLIENTS_PER_PAGE): ?>
                    · <?= ($page - 1) * CLIENTS_PER_PAGE + 1 ?>–<?= min($total, $page * CLIENTS_PER_PAGE) ?>
                <?php endif; ?>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert--error" role="alert"><?= icon('alert') ?><p><?= h($error) ?></p></div>

    <?php elseif ($isEmptyBase): ?>
        <div class="empty vault-empty">
            <span class="vault-empty__icon"><?= icon('users') ?></span>
            <h2>Aucun dossier KYC pour l'instant</h2>
            <p>Enregistrez votre premier client et ses pièces justificatives.</p>
            <div class="toolbar__actions"><a href="vault/clients-nouveau.php" class="btn btn--primary"><?= icon('plus') ?> Nouveau client</a></div>
        </div>

    <?php elseif (!$rows): ?>
        <div class="empty vault-empty" role="status">
            <span class="vault-empty__icon"><?= icon('search') ?></span>
            <h2><?= $etat === 'archives' && $q === '' ? 'Aucun dossier archivé' : ($etat === 'provisoires' && $q === '' ? 'Aucun dossier à vérifier' : 'Aucun client ne correspond' . ($q !== '' ? ' à « ' . h($q) . ' »' : '')) ?></h2>
            <p>Vérifiez l'orthographe, cherchez une partie du nom, du téléphone ou du N° d'identifiant<?= $type ? ', ou élargissez à tous les types' : '' ?>.</p>
            <div class="toolbar__actions">
                <a href="vault/clients.php" data-nav data-reset class="btn btn--ghost">Effacer la recherche</a>
                <a href="vault/clients-nouveau.php" class="btn btn--primary"><?= icon('plus') ?> Nouveau client</a>
            </div>
        </div>

    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--hover table--stack">
                <thead>
                    <tr>
                        <th scope="col">Type</th>
                        <th scope="col">Nom complet</th>
                        <th scope="col">Téléphone</th>
                        <th scope="col">N° identifiant</th>
                        <th scope="col">Enregistré le</th>
                        <th scope="col" class="actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $url = 'vault/client.php?uuid=' . rawurlencode($r['uuid']);
                        [$day, $time] = vault_date($r['created_at']); ?>
                        <tr data-href="<?= h($url) ?>"<?= $r['archived_at'] ? ' class="is-archived"' : '' ?>>
                            <td data-label="Type"><span class="cell-stack"><?= type_badge($r['type_client']) ?><?= $r['review_status'] === 'pending' ? '<span class="badge badge--info vault-pending" title="Créé depuis la caisse, à vérifier par la conformité">' . icon('clock') . ' Provisoire</span>' : '' ?></span></td>
                            <td data-label="Client">
                                <span class="vault-client">
                                    <?= avatar_html($r, kyc_initials($r['nom_complet']), 'sm') ?>
                                    <span class="cell-stack">
                                        <a href="<?= h($url) ?>" class="link-strong"><?= mark($r['nom_complet'], $words) ?></a>
                                        <small class="cell-sub"><?= docs_label((int) $r['nb_docs']) ?><?= $r['archived_at'] ? ' · <span class="vault-archived">Archivé le ' . h(vault_date($r['archived_at'])[0]) . '</span>' : '' ?></small>
                                    </span>
                                </span>
                            </td>
                            <td data-label="Téléphone" class="nowrap"><a href="tel:<?= h($r['telephone']) ?>"><?= mark(vault_phone($r['telephone']), $words) ?></a></td>
                            <td data-label="N° identifiant"><span class="vault-mono"><?= $r['numero_identifiant'] !== null ? mark($r['numero_identifiant'], $words) : '—' ?></span></td>
                            <td data-label="Enregistré le" class="nowrap"><span><?= h($day) ?> <small class="vault-muted"><?= h($time) ?></small></span></td>
                            <td class="actions">
                                <span class="vault-row-actions">
                                    <a href="<?= h($url) ?>" class="icon-btn" title="Ouvrir le dossier" aria-label="Ouvrir le dossier de <?= h($r['nom_complet']) ?>"><?= icon('eye') ?></a>
                                    <?= client_actions_menu($r, clients_url()) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1):
            // Pages affichées : 1, …, page-1, page, page+1, …, dernière
            $show = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], fn ($p) => $p >= 1 && $p <= $pages));
            sort($show); ?>
            <nav class="vault-pager" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= h(clients_url(['page' => $page - 1])) ?>" data-nav data-page class="btn btn--ghost btn--sm" rel="prev"><?= vault_icon('chev-l') ?><span>Précédent</span></a>
                <?php else: ?>
                    <span class="btn btn--ghost btn--sm" aria-disabled="true"><?= vault_icon('chev-l') ?><span>Précédent</span></span>
                <?php endif; ?>
                <div class="pagination">
                    <?php $prev = 0; foreach ($show as $p): ?>
                        <?php if ($p - $prev > 1): ?><span class="pagination__item" aria-hidden="true">…</span><?php endif; ?>
                        <?php if ($p === $page): ?>
                            <span class="pagination__item is-active" aria-current="page"><?= $p ?></span>
                        <?php else: ?>
                            <a class="pagination__item" href="<?= h(clients_url(['page' => $p])) ?>" data-nav data-page aria-label="Page <?= $p ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php $prev = $p; endforeach; ?>
                </div>
                <?php if ($page < $pages): ?>
                    <a href="<?= h(clients_url(['page' => $page + 1])) ?>" data-nav data-page class="btn btn--ghost btn--sm" rel="next"><span>Suivant</span><?= vault_icon('chev-r') ?></a>
                <?php else: ?>
                    <span class="btn btn--ghost btn--sm" aria-disabled="true"><span>Suivant</span><?= vault_icon('chev-r') ?></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif;
    return (string) ob_get_clean();
}

// ---------- Recherche fluide : résultats seuls, en JSON ----------
if ($partial) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['html' => render_results(), 'url' => clients_url(), 'total' => $total, 'type' => $type, 'etat' => $etat, 'q' => $q],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Chiffres du répertoire (toute la base, indépendants de la recherche)
$overview = ['total' => 0, 'particulier' => 0, 'entreprise' => 0, 'incomplete' => 0];
if (!$error) {
    try {
        $o = vault_db()->query("SELECT COUNT(*) AS total,
                                       SUM(CASE WHEN type_client = 'particulier' THEN 1 ELSE 0 END) AS particulier,
                                       SUM(CASE WHEN type_client = 'entreprise' THEN 1 ELSE 0 END) AS entreprise,
                                       SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM client_documents d WHERE d.client_id = c.id) THEN 1 ELSE 0 END) AS incomplete
                                FROM clients c WHERE c.archived_at IS NULL")->fetch();
        $overview = array_map('intval', $o ?: $overview);
    } catch (Throwable $e) {
        error_log('[GTC-Vault clients] chiffres : ' . $e->getMessage());
    }
}

vault_header('GTC-Vault · Répertoire KYC');
session_write_close();   // en-tête affiché (messages flash consommés) : la session est libérée
?>

<!-- ==================== CHIFFRES ==================== -->
<section class="stats stats--summary" aria-label="Répertoire KYC en chiffres">
    <?php foreach ([
        ['Dossiers KYC', $overview['total'], 'users', 'blue', 'Clients identifiés'],
        ['Particuliers', $overview['particulier'], 'shield', 'green', 'CNI ou passeport'],
        ['Entreprises', $overview['entreprise'], 'store', 'slate', 'RCCM et représentant légal'],
        ['Sans pièce', $overview['incomplete'], 'alert', 'gold', $overview['incomplete'] ? 'Dossiers à compléter' : 'Tous les dossiers ont des pièces'],
    ] as [$label, $value, $ic, $tone, $note]): ?>
        <div class="stat">
            <span class="stat__icon stat__icon--<?= $tone ?>"><?= icon($ic) ?></span>
            <div>
                <p class="stat__label"><?= h($label) ?></p>
                <p class="stat__value"><?= number_format($value, 0, ',', "\u{202F}") ?></p>
                <p class="stat__note"><?= h($note) ?></p>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<!-- ==================== RECHERCHE ==================== -->
<div class="toolbar vault-toolbar">
    <form method="get" action="vault/clients.php" role="search" id="client-search" class="filters">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <input type="hidden" name="etat" value="<?= h($etat) ?>">
        <div class="search vault-search">
            <?= icon('search') ?>
            <label for="q" class="visually-hidden">Rechercher un client</label>
            <input type="search" id="q" name="q" value="<?= h($q) ?>" maxlength="100" autocomplete="off" spellcheck="false" enterkeyhint="search"
                   placeholder="Nom, téléphone, N° CNI, passeport ou RCCM…" title="Rechercher par nom, téléphone ou N° CNI / passeport / RCCM">
            <button type="button" class="vault-search__clear" data-clear <?= $q === '' ? 'hidden' : '' ?> aria-label="Effacer la recherche"><?= vault_icon('x') ?></button>
            <kbd class="vault-search__kbd" data-kbd <?= $q !== '' ? 'hidden' : '' ?> title="Raccourci : touche /">/</kbd>
        </div>
        <noscript><button type="submit" class="btn btn--ghost btn--sm">Rechercher</button></noscript>
    </form>
    <div class="toolbar__actions">
        <a href="vault/clients-nouveau.php" class="btn btn--primary"><?= icon('plus') ?> Nouveau client</a>
    </div>
</div>

<!-- ==================== RÉSULTATS ==================== -->
<section class="panel vault-results" id="results" aria-live="polite" aria-busy="false">
    <?= render_results() ?>
</section>

<script>
(function () {
  'use strict';

  var form = document.getElementById('client-search');
  var input = document.getElementById('q');
  var typeInput = form.elements.type;
  var etatInput = form.elements.etat;
  var results = document.getElementById('results');
  var search = form.querySelector('.vault-search');
  var clearBtn = form.querySelector('[data-clear]'), kbd = form.querySelector('[data-kbd]');
  var controller = null, timer = null, lastQuery = null;

  function busy(on) {
    results.setAttribute('aria-busy', on ? 'true' : 'false');
    results.classList.toggle('is-loading', on);
    search.classList.toggle('is-loading', on);
  }
  function syncClear() { clearBtn.hidden = !input.value; kbd.hidden = !!input.value; }

  /** Charge les résultats d'une adresse de la liste, sans recharger la page. */
  function load(url, mode) {
    if (controller) controller.abort();                       // une frappe plus récente annule la requête en cours
    controller = new AbortController();
    var u = new URL(url, document.baseURI);
    u.searchParams.set('partial', '1');
    busy(true);
    return fetch(u, { headers: { Accept: 'application/json' }, signal: controller.signal, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { location.href = 'login.php?next=' + encodeURIComponent('vault/clients.php'); throw new Error('401'); }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        results.innerHTML = data.html;
        typeInput.value = data.type;
        etatInput.value = data.etat || '';
        lastQuery = data.q;
        document.title = (data.q ? data.q + ' · ' : '') + 'GTC-Vault · Répertoire KYC | Dashboard GTC';
        if (mode === 'push') history.pushState({}, '', data.url);
        else if (mode === 'replace') history.replaceState({}, '', data.url);
        busy(false);
      })
      .catch(function (e) {
        if (e.name === 'AbortError' || e.message === '401') return;
        busy(false);
        location.href = url;                                  // en cas d'échec : chargement classique de la page
      });
  }

  function searchUrl() {
    var p = new URLSearchParams();
    var q = input.value.trim();
    if (q) p.set('q', q);
    if (typeInput.value) p.set('type', typeInput.value);
    if (etatInput.value) p.set('etat', etatInput.value);
    return 'vault/clients.php' + (p.toString() ? '?' + p : '');
  }

  // Frappe : recherche après une courte pause (pas de requête par lettre)
  input.addEventListener('input', function () {
    syncClear();
    clearTimeout(timer);
    timer = setTimeout(function () {
      if (input.value.trim() === lastQuery) return;
      load(searchUrl(), 'replace');
    }, 250);
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); load(searchUrl(), 'replace'); });
  clearBtn.addEventListener('click', function () { input.value = ''; syncClear(); input.focus(); load(searchUrl(), 'replace'); });
  input.addEventListener('keydown', function (e) { if (e.key === 'Escape' && input.value) { e.preventDefault(); clearBtn.click(); } });

  // Raccourci « / » : aller à la recherche
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && !e.ctrlKey && !e.metaKey) {
      e.preventDefault(); input.focus(); input.select();
    }
  });

  // Filtres, pagination et « Effacer » : chargés sans recharger la page
  results.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-nav]');
    if (a && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) {
      e.preventDefault();
      if (a.hasAttribute('data-reset')) { input.value = ''; syncClear(); }
      load(a.getAttribute('href'), 'push').then(function () {
        if (a.hasAttribute('data-page')) results.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      return;
    }
    // Ligne du tableau cliquable (hors liens, boutons et sélection de texte)
    var tr = e.target.closest('tr[data-href]');
    if (tr && !e.target.closest('a, button, summary, details, form') && !String(window.getSelection())) {
      var href = new URL(tr.getAttribute('data-href'), document.baseURI).href;
      if (e.ctrlKey || e.metaKey) window.open(href, '_blank');
      else location.href = href;
    }
  });

  // Précédent / Suivant du navigateur
  window.addEventListener('popstate', function () {
    var p = new URLSearchParams(location.search);
    input.value = p.get('q') || ''; syncClear();
    load(location.href, null);
  });

  lastQuery = input.value.trim();
})();
</script>

<?php dash_footer(); ?>
