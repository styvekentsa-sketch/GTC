<?php
/**
 * GTC-Vault — Répertoire des clients KYC
 *
 *   clients.php?q=mballa&type=particulier&page=2
 *   - q    : recherche dans le nom, le téléphone et le N° d'identifiant (plusieurs mots : tous doivent être trouvés) ;
 *   - type : '' (tous), 'particulier' ou 'entreprise' ;
 *   - page : 20 dossiers par page, du plus récent au plus ancien.
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
session_write_close();   // lecture seule : la session n'est plus bloquée pendant les requêtes de recherche

// ---------- Paramètres ----------
$q     = trim((string) preg_replace('/\s+/u', ' ', mb_substr((string) ($_GET['q'] ?? ''), 0, 100)));
$type  = isset(CLIENTS_TYPES[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';
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
    global $q, $type, $page;
    $params = array_filter(array_merge(['q' => $q, 'type' => $type, 'page' => $page > 1 ? $page : null], $change),
        fn ($v) => $v !== null && $v !== '' && $v !== 1);
    return 'clients.php' . ($params ? '?' . http_build_query($params) : '');
}

/**
 * Conditions de recherche : chaque mot doit apparaître dans le nom, le N° d'identifiant ou le téléphone
 * (pour le téléphone, les chiffres seuls sont aussi cherchés : « 670 00 » trouve « +237670000000 »).
 */
function clients_where(array $words, string $type): array
{
    $sql = [];
    $params = [];
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
        $out .= $i % 2 ? '<mark class="rounded bg-amber-100 px-0.5 text-inherit">' . h($part) . '</mark>' : h($part);
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
    [$whereAll, $paramsAll] = clients_where($words, '');
    $st = $pdo->prepare("SELECT c.type_client, COUNT(*) AS n FROM clients c {$whereAll} GROUP BY c.type_client");
    $st->execute($paramsAll);
    foreach ($st as $r) {
        $counts[$r['type_client']] = (int) $r['n'];
        $counts[''] += (int) $r['n'];
    }
    $total = $counts[$type];
    $pages = max(1, (int) ceil($total / CLIENTS_PER_PAGE));
    $page  = min($page, $pages);

    [$where, $params] = clients_where($words, $type);
    $offset = ($page - 1) * CLIENTS_PER_PAGE;
    $st = $pdo->prepare("SELECT c.uuid, c.type_client, c.nom_complet, c.telephone, c.numero_identifiant, c.created_at,
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
$isEmptyBase = !$error && $counts[''] === 0 && !$words;

// ---------- Gabarits ----------
function type_badge(string $type): string
{
    return $type === 'entreprise'
        ? '<span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">Entreprise</span>'
        : '<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Particulier</span>';
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
    return $n === 0
        ? '<span class="text-amber-700">Aucune pièce</span>'
        : $n . ' pièce' . ($n > 1 ? 's' : '');
}

const EYE_ICON = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';

/** Pastilles de filtre, résultats, pagination : renvoyés tels quels à la recherche fluide. */
function render_results(): string
{
    global $rows, $total, $page, $pages, $q, $type, $words, $counts, $error, $isEmptyBase;
    ob_start(); ?>
    <!-- Filtres par type -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex flex-wrap gap-2" aria-label="Filtrer par type de client">
            <?php foreach (['' => 'Tous'] + CLIENTS_TYPES as $key => $label):
                $active = $key === $type; ?>
                <a href="<?= h(clients_url(['type' => $key, 'page' => null])) ?>" data-nav data-type="<?= h($key) ?>"
                   class="inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-sm font-medium transition focus:outline-none focus:ring-4 focus:ring-blue-100
                          <?= $active ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-50 hover:text-slate-900' ?>"
                   <?= $active ? 'aria-current="true"' : '' ?>>
                    <?= h($label) ?>
                    <span class="rounded-full px-1.5 text-xs <?= $active ? 'bg-white/15' : 'bg-slate-100 text-slate-500' ?>"><?= $counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <p class="text-sm text-slate-500" data-summary>
            <?php if ($total > 0): ?>
                <?= $total === 1 ? '1 dossier' : number_format($total, 0, ',', "\u{202F}") . ' dossiers' ?><?= $q !== '' ? ' pour « ' . h($q) . ' »' : '' ?>
                <?php if ($total > CLIENTS_PER_PAGE): ?>
                    · <?= ($page - 1) * CLIENTS_PER_PAGE + 1 ?>–<?= min($total, $page * CLIENTS_PER_PAGE) ?>
                <?php endif; ?>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($error): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-900" role="alert"><?= h($error) ?></div>

    <?php elseif ($isEmptyBase): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
            </span>
            <h2 class="mt-4 text-base font-semibold text-slate-900">Aucun dossier KYC pour l'instant</h2>
            <p class="mt-1 text-sm text-slate-500">Enregistrez votre premier client et ses pièces justificatives.</p>
            <a href="clients-nouveau.php" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Nouveau client</a>
        </div>

    <?php elseif (!$rows): ?>
        <div class="rounded-2xl border border-slate-200 bg-white px-6 py-14 text-center" role="status">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3M8 11h6"/></svg>
            </span>
            <h2 class="mt-4 text-base font-semibold text-slate-900">Aucun client ne correspond<?= $q !== '' ? ' à « ' . h($q) . ' »' : '' ?></h2>
            <p class="mt-1 text-sm text-slate-500">
                Vérifiez l'orthographe, cherchez une partie du nom, du téléphone ou du N° d'identifiant<?= $type ? ', ou élargissez à tous les types' : '' ?>.
            </p>
            <div class="mt-5 flex flex-wrap justify-center gap-3">
                <a href="clients.php" data-nav data-reset class="inline-flex items-center rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">Effacer la recherche</a>
                <a href="clients-nouveau.php" class="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Nouveau client</a>
            </div>
        </div>

    <?php else: ?>
        <!-- Ordinateur et tablette : tableau -->
        <div class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <th scope="col" class="px-5 py-3">Type</th>
                            <th scope="col" class="px-5 py-3">Nom complet</th>
                            <th scope="col" class="px-5 py-3">Téléphone</th>
                            <th scope="col" class="px-5 py-3">N° identifiant</th>
                            <th scope="col" class="px-5 py-3">Enregistré le</th>
                            <th scope="col" class="px-5 py-3 text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($rows as $r):
                            $url = 'client.php?uuid=' . rawurlencode($r['uuid']);
                            [$day, $time] = vault_date($r['created_at']); ?>
                            <tr class="group cursor-pointer transition hover:bg-slate-50" data-href="<?= h($url) ?>">
                                <td class="whitespace-nowrap px-5 py-3.5"><?= type_badge($r['type_client']) ?></td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold
                                                     <?= $r['type_client'] === 'entreprise' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700' ?>" aria-hidden="true"><?= h(kyc_initials($r['nom_complet'])) ?></span>
                                        <div class="min-w-0">
                                            <a href="<?= h($url) ?>" class="block max-w-xs truncate font-medium text-slate-900 hover:text-blue-700 focus:outline-none focus-visible:underline"><?= mark($r['nom_complet'], $words) ?></a>
                                            <span class="text-xs text-slate-500"><?= docs_label((int) $r['nb_docs']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 tabular-nums text-slate-700"><?= mark(vault_phone($r['telephone']), $words) ?></td>
                                <td class="whitespace-nowrap px-5 py-3.5 font-mono text-[13px] text-slate-700"><?= $r['numero_identifiant'] !== null ? mark($r['numero_identifiant'], $words) : '<span class="text-slate-400">—</span>' ?></td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-slate-700"><?= h($day) ?> <span class="text-xs text-slate-400"><?= h($time) ?></span></td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right">
                                    <a href="<?= h($url) ?>" title="Ouvrir le dossier" aria-label="Ouvrir le dossier de <?= h($r['nom_complet']) ?>"
                                       class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-slate-500 transition hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100 group-hover:text-blue-700">
                                        <?= EYE_ICON ?><span class="hidden lg:inline">Ouvrir</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Téléphone : cartes -->
        <ul class="space-y-3 md:hidden">
            <?php foreach ($rows as $r):
                $url = 'client.php?uuid=' . rawurlencode($r['uuid']);
                [$day, $time] = vault_date($r['created_at']); ?>
                <li class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold
                                     <?= $r['type_client'] === 'entreprise' ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700' ?>" aria-hidden="true"><?= h(kyc_initials($r['nom_complet'])) ?></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <a href="<?= h($url) ?>" class="min-w-0 break-words font-semibold text-slate-900"><?= mark($r['nom_complet'], $words) ?></a>
                                <?= type_badge($r['type_client']) ?>
                            </div>
                            <dl class="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
                                <dt class="text-slate-500">Tél.</dt>
                                <dd class="tabular-nums text-slate-800"><a href="tel:<?= h($r['telephone']) ?>"><?= mark(vault_phone($r['telephone']), $words) ?></a></dd>
                                <dt class="text-slate-500">N°</dt>
                                <dd class="break-all font-mono text-[13px] text-slate-800"><?= $r['numero_identifiant'] !== null ? mark($r['numero_identifiant'], $words) : '—' ?></dd>
                                <dt class="text-slate-500">Le</dt>
                                <dd class="text-slate-800"><?= h($day) ?> <span class="text-xs text-slate-400"><?= h($time) ?></span> · <span class="text-xs text-slate-500"><?= docs_label((int) $r['nb_docs']) ?></span></dd>
                            </dl>
                        </div>
                    </div>
                    <a href="<?= h($url) ?>" class="mt-3 flex items-center justify-center gap-2 rounded-xl bg-slate-50 py-2.5 text-sm font-semibold text-blue-700 ring-1 ring-inset ring-slate-200 hover:bg-blue-50">
                        <?= EYE_ICON ?> Ouvrir le dossier
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($pages > 1):
            // Pages affichées : 1, …, page-1, page, page+1, …, dernière
            $show = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], fn ($p) => $p >= 1 && $p <= $pages));
            sort($show); ?>
            <nav class="mt-5 flex items-center justify-between gap-3" aria-label="Pagination">
                <?php $btn = 'inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset transition focus:outline-none focus:ring-4 focus:ring-blue-100'; ?>
                <?php if ($page > 1): ?>
                    <a href="<?= h(clients_url(['page' => $page - 1])) ?>" data-nav data-page class="<?= $btn ?> bg-white text-slate-700 ring-slate-300 hover:bg-slate-50" rel="prev"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg><span class="hidden sm:inline">Précédent</span></a>
                <?php else: ?>
                    <span class="<?= $btn ?> cursor-not-allowed bg-slate-50 text-slate-300 ring-slate-200" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg><span class="hidden sm:inline">Précédent</span></span>
                <?php endif; ?>

                <ol class="flex items-center gap-1">
                    <?php $prev = 0; foreach ($show as $p): ?>
                        <?php if ($p - $prev > 1): ?><li class="px-1 text-slate-400" aria-hidden="true">…</li><?php endif; ?>
                        <li>
                            <?php if ($p === $page): ?>
                                <span class="flex h-9 min-w-9 items-center justify-center rounded-lg bg-slate-900 px-2 text-sm font-semibold text-white" aria-current="page"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= h(clients_url(['page' => $p])) ?>" data-nav data-page aria-label="Page <?= $p ?>"
                                   class="flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-medium text-slate-600 hover:bg-white hover:text-slate-900 hover:ring-1 hover:ring-slate-200"><?= $p ?></a>
                            <?php endif; ?>
                        </li>
                    <?php $prev = $p; endforeach; ?>
                </ol>

                <?php if ($page < $pages): ?>
                    <a href="<?= h(clients_url(['page' => $page + 1])) ?>" data-nav data-page class="<?= $btn ?> bg-white text-slate-700 ring-slate-300 hover:bg-slate-50" rel="next"><span class="hidden sm:inline">Suivant</span><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a>
                <?php else: ?>
                    <span class="<?= $btn ?> cursor-not-allowed bg-slate-50 text-slate-300 ring-slate-200" aria-hidden="true"><span class="hidden sm:inline">Suivant</span><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span>
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
    echo json_encode(['html' => render_results(), 'url' => clients_url(), 'total' => $total, 'type' => $type, 'q' => $q],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $q !== '' ? h($q) . ' · ' : '' ?>Répertoire clients KYC · GTC-Vault</title>
    <!-- Tailwind compilé (assets/vault.css, voir assets/README-tailwind.txt) : aucun script externe -->
    <link rel="stylesheet" href="<?= h(vault_css()) ?>">
    <style>[hidden] { display: none !important; }</style>
</head>
<body class="min-h-full bg-slate-100 font-sans text-slate-900 antialiased">

<!-- ==================== BARRE SUPÉRIEURE ==================== -->
<?= vault_topbar([['Clients', null]]) ?>

<main class="mx-auto max-w-6xl px-4 pb-16 pt-8 sm:px-6">
    <!-- ==================== EN-TÊTE ==================== -->
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Répertoire Clients KYC</h1>
            <p class="mt-1 text-sm text-slate-600">Dossiers d'identification des clients particuliers et entreprises.</p>
        </div>
        <a href="clients-nouveau.php"
           class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-200">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Nouveau Client
        </a>
    </div>

    <!-- ==================== RECHERCHE ==================== -->
    <form method="get" action="clients.php" role="search" id="client-search" class="mb-5">
        <input type="hidden" name="type" value="<?= h($type) ?>">
        <label for="q" class="sr-only">Rechercher un client</label>
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                <svg class="h-5 w-5" data-search-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <svg class="hidden h-5 w-5 animate-spin text-blue-600" data-search-spinner viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            </span>
            <input type="search" id="q" name="q" value="<?= h($q) ?>" maxlength="100" autocomplete="off" spellcheck="false" enterkeyhint="search"
                   placeholder="Nom, téléphone, N° CNI ou RCCM…" title="Rechercher par nom, téléphone ou N° CNI / passeport / RCCM"
                   class="block w-full rounded-2xl border border-slate-300 bg-white py-3.5 pl-12 pr-12 sm:pr-24 text-base text-slate-900 shadow-sm placeholder:text-slate-400
                          focus:border-blue-600 focus:outline-none focus:ring-4 focus:ring-blue-100 [&::-webkit-search-cancel-button]:hidden">
            <div class="absolute inset-y-0 right-0 flex items-center gap-1 pr-3">
                <button type="button" data-clear <?= $q === '' ? 'hidden' : '' ?> aria-label="Effacer la recherche"
                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-4 focus:ring-blue-100">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
                <kbd class="hidden rounded-md border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-sans text-xs text-slate-500 sm:inline" title="Raccourci : touche /">/</kbd>
                <noscript><button type="submit" class="rounded-lg bg-slate-900 px-3 py-1.5 text-sm font-medium text-white">Rechercher</button></noscript>
            </div>
        </div>
    </form>

    <!-- ==================== RÉSULTATS ==================== -->
    <section id="results" aria-live="polite" aria-busy="false" class="transition-opacity">
        <?= render_results() ?>
    </section>
</main>

<script>
(function () {
  'use strict';

  var form = document.getElementById('client-search');
  var input = document.getElementById('q');
  var typeInput = form.elements.type;
  var results = document.getElementById('results');
  var clearBtn = form.querySelector('[data-clear]');
  var icon = form.querySelector('[data-search-icon]'), spinner = form.querySelector('[data-search-spinner]');
  var controller = null, timer = null, lastQuery = null;

  function busy(on) {
    results.setAttribute('aria-busy', on ? 'true' : 'false');
    results.classList.toggle('opacity-60', on);
    icon.classList.toggle('hidden', on); spinner.classList.toggle('hidden', !on);
  }

  /** Charge les résultats d'une adresse de la liste, sans recharger la page. */
  function load(url, mode) {
    if (controller) controller.abort();                       // une frappe plus récente annule la requête en cours
    controller = new AbortController();
    var u = new URL(url, location.href);
    u.searchParams.set('partial', '1');
    busy(true);
    return fetch(u, { headers: { Accept: 'application/json' }, signal: controller.signal, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { location.href = '../login.php?next=' + encodeURIComponent('vault/clients.php'); throw new Error('401'); }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        results.innerHTML = data.html;
        typeInput.value = data.type;
        lastQuery = data.q;
        document.title = (data.q ? data.q + ' · ' : '') + 'Répertoire clients KYC · GTC-Vault';
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
    return 'clients.php' + (p.toString() ? '?' + p : '');
  }

  // Frappe : recherche après une courte pause (pas de requête par lettre)
  input.addEventListener('input', function () {
    clearBtn.hidden = !input.value;
    clearTimeout(timer);
    timer = setTimeout(function () {
      if (input.value.trim() === lastQuery) return;
      load(searchUrl(), 'replace');
    }, 250);
  });
  form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); load(searchUrl(), 'replace'); });
  clearBtn.addEventListener('click', function () { input.value = ''; clearBtn.hidden = true; input.focus(); load(searchUrl(), 'replace'); });
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
      if (a.hasAttribute('data-reset')) { input.value = ''; clearBtn.hidden = true; }
      load(a.href, 'push').then(function () {
        if (a.hasAttribute('data-page')) results.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      return;
    }
    // Ligne du tableau cliquable (hors liens et sélection de texte)
    var tr = e.target.closest('tr[data-href]');
    if (tr && !e.target.closest('a, button') && !String(window.getSelection())) {
      if (e.ctrlKey || e.metaKey) window.open(tr.getAttribute('data-href'), '_blank');
      else location.href = tr.getAttribute('data-href');
    }
  });

  // Précédent / Suivant du navigateur
  window.addEventListener('popstate', function () {
    var p = new URLSearchParams(location.search);
    input.value = p.get('q') || ''; clearBtn.hidden = !input.value;
    load(location.href, null);
  });

  lastQuery = input.value.trim();
})();
</script>
</body>
</html>
