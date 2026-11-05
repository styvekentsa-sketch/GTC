<?php
/**
 * Avis clients — modération des avis déposés sur le site (avis.php → api-reviews.php).
 *
 *   reviews.php                 Liste filtrée (En attente / Publiés / Refusés / Tous), recherche, pagination
 *   Actions (menu « ⋮ ») :      Publier, Refuser, Remettre en attente, Supprimer (administrateurs)
 *
 * Seuls les avis « Publiés » apparaissent sur le site (note moyenne et nombre d'avis compris).
 * Accès : Administrateur et Secrétaire / Accueil (MESSAGE_ROLES). Règles communes : includes/reviews.php.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/reviews.php';

$user = require_login();
require_roles(MESSAGE_ROLES);

const REVIEW_FILTERS = [
    'pending'  => 'En attente',
    'approved' => 'Publiés',
    'rejected' => 'Refusés',
    'all'      => 'Tous',
];

/* ==========================================================================
   Actions (POST + CSRF)
   ========================================================================== */

if (is_post()) {
    csrf_verify();
    $op   = post('op');
    $id   = (int) post('id');
    $back = safe_back(post('back'), 'reviews.php', 'reviews.php');

    $review = db_one('SELECT id, author_name, rating, status FROM reviews WHERE id = ?', [$id]);
    if (!$review) {
        flash('error', 'Avis introuvable (déjà supprimé ?).');
        redirect($back);
    }
    $label = 'Avis de ' . $review['author_name'] . ' (' . $review['rating'] . '/5)';

    $changes = [
        // op => [nouveau statut, action du journal, message]
        'approve' => ['approved', 'status', 'Avis de ' . $review['author_name'] . ' publié sur le site.'],
        'reject'  => ['rejected', 'status',  'Avis de ' . $review['author_name'] . ' refusé : il ne sera pas publié.'],
        'pending' => ['pending',  'status',  'Avis de ' . $review['author_name'] . ' remis en attente.'],
    ];
    if (isset($changes[$op])) {
        [$status, $action, $text] = $changes[$op];
        if ($review['status'] !== $status) {
            db_query('UPDATE reviews SET status = ?, moderated_at = ?, moderated_by = ?, moderated_by_name = ? WHERE id = ?',
                [$status, db_now(), (int) $user['id'], (string) $user['name'], $id]);
            activity_log($action, 'review', $id, $label, ['statut' => REVIEW_STATUSES[$review['status']]['label'] . ' → ' . REVIEW_STATUSES[$status]['label']]);
        }
        flash('success', $text);
        redirect($back);
    }

    if ($op === 'delete') {
        if (!is_admin()) {
            flash('error', 'Seul un administrateur peut supprimer un avis.');
            redirect($back);
        }
        db_query('DELETE FROM reviews WHERE id = ?', [$id]);
        activity_log('delete', 'review', $id, $label);
        flash('success', 'Avis de ' . $review['author_name'] . ' supprimé.');
        redirect($back);
    }

    flash('error', 'Action inconnue.');
    redirect($back);
}

/* ==========================================================================
   Vue : liste
   ========================================================================== */

$filter  = isset(REVIEW_FILTERS[query('f')]) ? query('f') : 'pending';
$q       = mb_substr(query('q'), 0, 100);
$page    = max(1, (int) query('page', '1'));
$perPage = 20;

$counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach (db_all('SELECT status, COUNT(*) AS n FROM reviews GROUP BY status') as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']] = (int) $r['n'];
    }
    $counts['all'] += (int) $r['n'];
}
$stats = review_stats();

$where  = [];
$params = [];
if ($filter !== 'all') {
    $where[]  = 'status = ?';
    $params[] = $filter;
}
if ($q !== '') {
    $where[] = '(author_name LIKE ? OR comment LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%');
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total    = (int) db_value("SELECT COUNT(*) FROM reviews {$whereSql}", $params);
$page     = min($page, max(1, (int) ceil($total / $perPage)));
$offset   = ($page - 1) * $perPage;
$rows     = db_all("SELECT * FROM reviews {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

$listParams = array_filter(['f' => $filter === 'pending' ? '' : $filter, 'q' => $q, 'page' => $page > 1 ? $page : '']);
$back       = 'reviews.php' . ($listParams ? '?' . http_build_query($listParams) : '');

dash_header('Avis clients', 'reviews');
?>
<div class="toolbar">
    <nav class="chips" aria-label="Filtrer les avis">
        <?php foreach (REVIEW_FILTERS as $key => $label): ?>
            <a href="reviews.php<?= $key === 'pending' ? '' : '?f=' . $key ?>" class="chip<?= $key === $filter ? ' is-active' : '' ?>"
               <?= $key === $filter ? 'aria-current="page"' : '' ?>><?= e($label) ?> (<?= $counts[$key] ?>)</a>
        <?php endforeach; ?>
    </nav>
    <div class="toolbar__actions">
        <form method="get" class="filters" role="search">
            <?php if ($filter !== 'pending'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
            <div class="search">
                <?= icon('search') ?>
                <label class="visually-hidden" for="q">Rechercher</label>
                <input id="q" name="q" type="search" placeholder="Nom, texte de l'avis…" value="<?= e($q) ?>">
            </div>
        </form>
        <a href="../avis.php" class="btn btn--ghost btn--sm" target="_blank" rel="noopener"><?= icon('external') ?> Voir la page publique</a>
    </div>
</div>

<p class="cell-sub" style="margin: 0 0 12px">
    Note affichée sur le site : <strong><?= $stats['count'] ? e(str_replace('.', ',', number_format($stats['average'], 1))) . ' / 5' : '—' ?></strong>
    · <?= $stats['count'] ?> avis publié<?= $stats['count'] > 1 ? 's' : '' ?>. Un avis n'apparaît sur le site qu'une fois publié.
</p>

<section class="panel">
    <?php if (!$rows): ?>
        <p class="empty"><?= $q !== '' ? 'Aucun avis ne correspond à cette recherche.' : ($filter === 'pending' ? 'Aucun avis en attente de validation.' : 'Aucun avis dans cette catégorie.') ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--hover table--stack">
                <thead><tr><th>Auteur</th><th>Note</th><th>Avis</th><th>Reçu le</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $rid  = (int) $r['id'];
                    $post = fn (string $op) => ['op' => $op, 'id' => $rid, 'back' => $back]; ?>
                    <tr>
                        <td data-label="Auteur"><span class="cell-stack">
                            <strong><?= e($r['author_name']) ?></strong>
                            <?php if ($r['moderated_by_name']): ?><small class="cell-sub">Modéré par <?= e($r['moderated_by_name']) ?> le <?= e(fdate($r['moderated_at'], true)) ?></small><?php endif; ?>
                        </span></td>
                        <td data-label="Note" style="white-space: nowrap"><?= review_stars((float) $r['rating'], 'stars stars--sm') ?></td>
                        <td data-label="Avis" style="max-width: 460px; white-space: pre-line; overflow-wrap: anywhere"><?= e($r['comment']) ?></td>
                        <td data-label="Reçu le"><?= e(fdate($r['created_at'], true)) ?></td>
                        <td data-label="Statut"><?= badge($r['status'], REVIEW_STATUSES) ?></td>
                        <td class="actions">
                            <?= action_menu(array_values(array_filter([
                                $r['status'] !== 'approved' ? ['post', 'Publier sur le site', 'check', $post('approve')] : [],
                                $r['status'] !== 'rejected' ? ['post', $r['status'] === 'approved' ? 'Retirer du site (refuser)' : 'Refuser', 'x', $post('reject'),
                                    $r['status'] === 'approved' ? 'Retirer cet avis du site ?' : null] : [],
                                $r['status'] !== 'pending' ? ['post', 'Remettre en attente', 'history', $post('pending')] : [],
                                is_admin() ? ['sep'] : [],
                                is_admin() ? ['post', 'Supprimer', 'trash', $post('delete'), 'Supprimer définitivement l\'avis de ' . $r['author_name'] . ' ?', 'danger' => true] : [],
                            ])), 'Actions de l\'avis de ' . $r['author_name']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination($total, $page, $perPage, array_filter(['f' => $filter === 'pending' ? '' : $filter, 'q' => $q])) ?>
</section>

<?php dash_footer(); ?>
