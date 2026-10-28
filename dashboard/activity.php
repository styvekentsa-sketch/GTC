<?php
/**
 * Journal d'activité — qui a créé, modifié, supprimé quoi, et quand (administrateurs).
 *
 *   activity.php                 Liste filtrable (recherche, personne, action, élément, période), paginée
 *   activity.php?export=xlsx|csv Export Excel (.xlsx) ou CSV des entrées filtrées
 *
 * Écriture : activity_log() (includes/activity.php), appelée par chaque action sensible du Dashboard.
 * Les prises de contrôle de compte restent aussi détaillées dans Utilisateurs (impersonation_log).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/csv_export.php';

$user = require_login();
require_role('admin');

/* ---------- Filtres ---------- */
$q       = mb_substr(query('q'), 0, 100);
$who     = (int) query('user');
$action  = isset(ACTIVITY_ACTIONS[query('action')]) ? query('action') : '';
$entity  = isset(ACTIVITY_ENTITIES[query('entity')]) ? query('entity') : '';
$page    = max(1, (int) query('page', '1'));
$perPage = 50;

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(label LIKE ? OR details LIKE ? OR user_name LIKE ? OR ip LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
}
if ($who) {
    $where[] = 'user_id = ?';
    $params[] = $who;
}
if ($action !== '') {
    $where[] = 'action = ?';
    $params[] = $action;
}
if ($entity !== '') {
    $where[] = 'entity = ?';
    $params[] = $entity;
}
[$from, $to, $dateWhere, $dateParams] = list_date_range('created_at');
array_push($where, ...$dateWhere);
array_push($params, ...$dateParams);
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$filters  = array_filter(['q' => $q, 'user' => $who ?: '', 'action' => $action, 'entity' => $entity, 'from' => $from, 'to' => $to]);

$who_label = fn (array $r) => trim(($r['user_name'] ?: '—') . ($r['acting_admin'] ? ' (prise de contrôle par ' . $r['acting_admin'] . ')' : ''));

if ($format = export_format()) {
    $stmt = db_query("SELECT * FROM activity_log {$whereSql} ORDER BY id DESC", $params);
    export_send($format, 'journal-activite', "Journal d'activité", [
        ['label' => 'Date',        'type' => 'date', 'width' => 12],
        ['label' => 'Heure',       'type' => 'time', 'width' => 8],
        ['label' => 'Utilisateur', 'width' => 28],
        ['label' => 'Rôle',        'width' => 16],
        ['label' => 'Action',      'width' => 20],
        ['label' => 'Élément',     'width' => 20],
        ['label' => 'Référence',   'width' => 28],
        ['label' => 'Détails',     'width' => 60],
        ['label' => 'Adresse IP',  'width' => 15],
    ], (function () use ($stmt, $who_label) {
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield [$r['created_at'], $r['created_at'], $who_label($r), $r['user_role'] ? role_label($r['user_role']) : '',
                ACTIVITY_ACTIONS[$r['action']]['label'] ?? $r['action'], ACTIVITY_ENTITIES[$r['entity']] ?? $r['entity'],
                $r['label'], activity_details_text($r['details']), $r['ip']];
        }
    })());
}

$total = (int) db_value("SELECT COUNT(*) FROM activity_log {$whereSql}", $params);
$page  = min($page, max(1, (int) ceil($total / $perPage)));
$rows  = db_all("SELECT * FROM activity_log {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params);
$users = db_all('SELECT DISTINCT user_id, user_name FROM activity_log WHERE user_id IS NOT NULL ORDER BY user_name');

// Synthèse des 24 dernières heures
$since   = (new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->modify('-1 day')->format('Y-m-d H:i:s');
$recent  = db_all('SELECT action, COUNT(*) AS n FROM activity_log WHERE created_at >= ? GROUP BY action', [$since]);
$recentN = array_column($recent, 'n', 'action');

/** Lien vers l'élément concerné (s'il existe encore). */
function activity_link(array $r): ?string
{
    if (!$r['entity_id'] || $r['action'] === 'delete') {
        return null;
    }
    return match ($r['entity']) {
        'invoice'    => 'invoices.php?action=view&id=' . (int) $r['entity_id'],
        'commercial' => 'invoices-list.php?action=edit&id=' . (int) $r['entity_id'],
        'courrier'   => 'courriers.php?action=edit&id=' . (int) $r['entity_id'],
        default      => null,
    };
}

dash_header("Journal d'activité", 'activity');
?>
<p class="page-intro">Toutes les actions sensibles du Dashboard : créations, modifications, suppressions, connexions, exports et sauvegardes.
    Conservé <?= (int) (ACTIVITY_KEEP_DAYS / 365) ?> ans. Les prises de contrôle de compte sont aussi détaillées dans <a href="users.php">Utilisateurs</a>.</p>

<section class="stats stats--summary" aria-label="Dernières 24 heures">
    <article class="stat">
        <span class="stat__icon stat__icon--blue"><?= icon('history') ?></span>
        <div><p class="stat__label">Actions (24 h)</p><p class="stat__value"><?= array_sum($recentN) ?></p></div>
    </article>
    <article class="stat">
        <span class="stat__icon stat__icon--gold"><?= icon('trash') ?></span>
        <div><p class="stat__label">Suppressions (24 h)</p><p class="stat__value"><?= (int) ($recentN['delete'] ?? 0) ?></p></div>
    </article>
    <article class="stat">
        <span class="stat__icon stat__icon--slate"><?= icon('lock') ?></span>
        <div>
            <p class="stat__label">Échecs de connexion (24 h)</p>
            <p class="stat__value"><?= (int) ($recentN['login_failed'] ?? 0) ?></p>
            <?php if (($recentN['login_failed'] ?? 0) > 0): ?><p class="stat__note"><a href="activity.php?action=login_failed">Voir le détail</a></p><?php endif; ?>
        </div>
    </article>
</section>

<div class="toolbar">
    <form method="get" class="filters" role="search">
        <div class="search">
            <?= icon('search') ?>
            <label class="visually-hidden" for="q">Rechercher</label>
            <input id="q" name="q" type="search" placeholder="Référence, détail, IP…" value="<?= e($q) ?>">
        </div>
        <label class="visually-hidden" for="f-user">Utilisateur</label>
        <select id="f-user" name="user" data-autosubmit>
            <option value="">Tous les utilisateurs</option>
            <?php foreach ($users as $u): ?><option value="<?= (int) $u['user_id'] ?>" <?= (int) $u['user_id'] === $who ? 'selected' : '' ?>><?= e($u['user_name']) ?></option><?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="f-action">Action</label>
        <select id="f-action" name="action" data-autosubmit>
            <option value="">Toutes les actions</option>
            <?php foreach (ACTIVITY_ACTIONS as $key => $a): ?><option value="<?= e($key) ?>" <?= $key === $action ? 'selected' : '' ?>><?= e($a['label']) ?></option><?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="f-entity">Élément</label>
        <select id="f-entity" name="entity" data-autosubmit>
            <option value="">Tous les éléments</option>
            <?php foreach (ACTIVITY_ENTITIES as $key => $label): ?><option value="<?= e($key) ?>" <?= $key === $entity ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <?= list_date_inputs($from, $to) ?>
        <?php if ($filters): ?><a href="activity.php" class="btn btn--ghost btn--sm">Réinitialiser</a><?php endif; ?>
    </form>
    <div class="toolbar__actions">
        <?= export_buttons('activity.php', $filters, $total, $total > 1 ? 'entrées' : 'entrée') ?>
    </div>
</div>

<section class="panel">
    <?php if (!$rows): ?>
        <p class="empty"><?= $filters ? 'Aucune entrée ne correspond à ces critères.' : "Aucune action enregistrée pour l'instant." ?></p>
    <?php else: ?>
        <p class="cell-sub activity-count"><?= $total ?> entrée<?= $total > 1 ? 's' : '' ?></p>
        <div class="table-wrap">
            <table class="table table--stack activity-table">
                <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Élément</th><th>Détails</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $link = activity_link($r); ?>
                    <tr>
                        <td data-label="Date"><span class="activity-date"><?= e(fdate($r['created_at'], true)) ?></span></td>
                        <td data-label="Utilisateur">
                            <span class="cell-stack"><?= e($r['user_name'] ?: '—') ?>
                                <?php if ($r['user_role']): ?><small class="cell-sub"><?= e(role_label($r['user_role'])) ?></small><?php endif; ?>
                                <?php if ($r['acting_admin']): ?><small class="cell-sub activity-takeover">Prise de contrôle par <?= e($r['acting_admin']) ?></small><?php endif; ?>
                            </span>
                        </td>
                        <td data-label="Action"><?= badge($r['action'], ACTIVITY_ACTIONS) ?></td>
                        <td data-label="Élément">
                            <span class="cell-stack"><small class="cell-sub"><?= e(ACTIVITY_ENTITIES[$r['entity']] ?? $r['entity']) ?></small>
                                <?php if ($link): ?><a href="<?= e($link) ?>" class="link-strong"><?= e($r['label']) ?></a><?php else: ?><?= e($r['label']) ?><?php endif; ?>
                            </span>
                        </td>
                        <td data-label="Détails" class="activity-details"><?= e(activity_details_text($r['details'])) ?: '<span class="cell-sub">—</span>' ?></td>
                        <td data-label="IP"><code class="activity-ip"><?= e((string) $r['ip']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination($total, $page, $perPage, $filters) ?>
    <?php endif; ?>
</section>
<?php dash_footer(); ?>
