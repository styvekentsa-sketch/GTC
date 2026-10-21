<?php
/**
 * Messagerie — messages envoyés depuis le formulaire de contact du site (contact.php → process-contact.php).
 *
 *   messages.php              Liste (filtres Tous / Non lus / Lus / Répondus / Archivés, recherche, pagination)
 *   messages.php?id=X         Lecture d'un message (passe automatiquement de « Non lu » à « Lu »)
 *
 * Accès : Administrateur et Secrétaire / Accueil (MESSAGE_ROLES).
 * Actualisation automatique : assets/js/messages-live.js interroge dashboard/messages-api.php (nouveaux messages
 * ajoutés en tête de liste, compteurs et pastille du menu mis à jour sans recharger la page).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/messages.php';   // filtres, lignes de la liste, actions

$user = require_login();
require_roles(MESSAGE_ROLES);

/* ==========================================================================
   Actions (POST + CSRF)
   ========================================================================== */

if (is_post()) {
    csrf_verify();
    $op = post('op');
    $id = (int) post('id');
    $back = messages_safe_back(post('back'));

    if ($op === 'read_all') {
        $n = db_query("UPDATE messages SET status = 'read' WHERE status = 'unread'")->rowCount();
        flash('success', $n ? "{$n} message(s) marqué(s) comme lu(s)." : 'Aucun message non lu.');
        redirect($back);
    }

    $msg = db_one('SELECT id, sender_name, status FROM messages WHERE id = ?', [$id]);
    if (!$msg) {
        flash('error', 'Message introuvable (déjà supprimé ?).');
        redirect('messages.php');
    }

    $changes = [
        'read'      => ['read',     'Message marqué comme lu.'],
        'unread'    => ['unread',   'Message marqué comme non lu.'],
        'replied'   => ['replied',  'Message marqué comme répondu.'],
        'archive'   => ['archived', 'Message archivé.'],
        'unarchive' => ['read',     'Message replacé dans la boîte de réception.'],
    ];
    if (isset($changes[$op])) {
        [$status, $text] = $changes[$op];
        db_query('UPDATE messages SET status = ? WHERE id = ?', [$status, $id]);
        flash('success', $text);
        // Après « non lu » ou « archiver » depuis la lecture, on revient à la liste (sinon la lecture le marquerait lu)
        redirect(in_array($op, ['unread', 'archive'], true) && str_contains($back, 'id=') ? 'messages.php' : $back);
    }

    if ($op === 'delete') {
        db_query('DELETE FROM messages WHERE id = ?', [$id]);
        flash('success', 'Le message de ' . $msg['sender_name'] . ' a été supprimé.');
        redirect(str_contains($back, 'id=') ? 'messages.php' : $back);
    }

    flash('error', 'Action inconnue.');
    redirect($back);
}

/* ==========================================================================
   Vue : lecture d'un message
   ========================================================================== */

$id = (int) query('id');
if ($id) {
    $m = db_one('SELECT * FROM messages WHERE id = ?', [$id]);
    if (!$m) {
        flash('error', 'Message introuvable.');
        redirect('messages.php');
    }
    if ($m['status'] === 'unread') {
        db_query("UPDATE messages SET status = 'read' WHERE id = ?", [$id]);
        $m['status'] = 'read';
    }

    $back     = 'messages.php?id=' . $id;
    $replyUrl = 'mailto:' . rawurlencode($m['sender_email'])
              . '?subject=' . rawurlencode('Re: ' . $m['subject'])
              . '&body=' . rawurlencode("Bonjour " . $m['sender_name'] . ",\n\n\n\n— Global Trading Corporation\n\n"
                  . '> Message du ' . fdate($m['created_at'], true) . " :\n> " . str_replace("\n", "\n> ", mb_strimwidth($m['message'], 0, 1200, '…')));
    $whatsapp = message_whatsapp($m['sender_phone']);

    dash_header('Message de ' . $m['sender_name'], 'messages');
    ?>
    <div class="toolbar">
        <a href="messages.php<?= $m['status'] === 'archived' ? '?f=archived' : '' ?>" class="btn btn--ghost"><?= icon('arrow-left') ?> Tous les messages</a>
        <div class="toolbar__actions">
            <a href="<?= e($replyUrl) ?>" class="btn btn--primary"><?= icon('reply') ?> Répondre par e-mail</a>
            <?php if ($whatsapp): ?>
                <a href="<?= e($whatsapp) ?>" class="btn btn--whatsapp" target="_blank" rel="noopener noreferrer"><?= icon('whatsapp') ?> WhatsApp</a>
            <?php endif; ?>
        </div>
    </div>

    <article class="panel mail-view">
        <header class="detail-head">
            <div>
                <p class="detail-head__label">Message du site</p>
                <h2 class="detail-head__number"><?= e($m['subject']) ?></h2>
                <p class="cell-sub">Reçu le <?= e(fdate($m['created_at'], true)) ?></p>
            </div>
            <?= badge($m['status'], MESSAGE_STATUSES) ?>
        </header>

        <dl class="detail-list mail-view__from">
            <div><dt>Expéditeur</dt><dd><?= e($m['sender_name']) ?></dd></div>
            <div><dt>E-mail</dt><dd><a href="<?= e($replyUrl) ?>" class="link-strong"><?= e($m['sender_email']) ?></a></dd></div>
            <div><dt>Téléphone</dt><dd>
                <?php if ($m['sender_phone']): ?>
                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['sender_phone'])) ?>" class="link-strong"><?= e($m['sender_phone']) ?></a>
                <?php else: ?>—<?php endif; ?>
            </dd></div>
        </dl>

        <div class="mail-view__body"><?= nl2br(e($m['message'])) ?></div>

        <footer class="mail-view__actions">
            <?php if ($m['status'] !== 'replied'): ?>
                <?= message_action($id, 'replied', 'Marquer comme répondu', 'check', $back) ?>
            <?php endif; ?>
            <?= message_action($id, 'unread', 'Marquer comme non lu', 'mail', $back) ?>
            <?= $m['status'] === 'archived'
                ? message_action($id, 'unarchive', 'Désarchiver', 'inbox', $back)
                : message_action($id, 'archive', 'Archiver', 'archive', $back) ?>
            <?= message_action($id, 'delete', 'Supprimer', 'trash', $back, 'btn btn--danger btn--sm',
                'Supprimer définitivement le message de ' . $m['sender_name'] . ' ?') ?>
        </footer>
    </article>
    <?php
    dash_footer();
    exit;
}

/* ==========================================================================
   Vue : liste
   ========================================================================== */

$filter  = isset(MESSAGE_FILTERS[query('f')]) ? query('f') : 'all';
$q       = mb_substr(query('q'), 0, 100);
$page    = max(1, (int) query('page', '1'));
$perPage = 20;

$counts = messages_counts();

$where  = [MESSAGE_FILTERS[$filter][1]];
$params = [];
if ($q !== '') {
    $where[] = '(sender_name LIKE ? OR sender_email LIKE ? OR sender_phone LIKE ? OR subject LIKE ? OR message LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
}
$whereSql = 'WHERE ' . implode(' AND ', $where);
$total    = (int) db_value("SELECT COUNT(*) FROM messages {$whereSql}", $params);
$page     = min($page, max(1, (int) ceil($total / $perPage)));
$offset   = ($page - 1) * $perPage;
$rows     = db_all("SELECT * FROM messages {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

$listParams = array_filter(['f' => $filter === 'all' ? '' : $filter, 'q' => $q, 'page' => $page > 1 ? $page : '']);
$back       = 'messages.php' . ($listParams ? '?' . http_build_query($listParams) : '');

dash_header('Messages', 'messages');
?>
<div class="toolbar">
    <nav class="chips" aria-label="Filtrer les messages">
        <?php foreach (MESSAGE_FILTERS as $key => [$label]): ?>
            <a href="messages.php<?= $key === 'all' ? '' : '?f=' . $key ?>" class="chip<?= $key === $filter ? ' is-active' : '' ?>"
               <?= $key === $filter ? 'aria-current="page"' : '' ?>><?= e($label) ?> (<span data-filter-count="<?= e($key) ?>"><?= $counts[$key] ?></span>)</a>
        <?php endforeach; ?>
    </nav>
    <div class="toolbar__actions">
        <form method="get" class="filters" role="search">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
            <div class="search">
                <?= icon('search') ?>
                <label class="visually-hidden" for="q">Rechercher</label>
                <input id="q" name="q" type="search" placeholder="Nom, e-mail, sujet…" value="<?= e($q) ?>">
            </div>
        </form>
        <form method="post" class="inline-form" data-read-all<?= $counts['unread'] ? '' : ' hidden' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="read_all">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <button type="submit" class="btn btn--ghost btn--sm"><?= icon('check') ?> Tout marquer comme lu</button>
        </form>
    </div>
</div>

<?php
// Nouveaux messages ajoutés en direct seulement là où ils apparaîtraient : 1re page de « Tous » ou « Non lus », sans recherche
$live = $page === 1 && $q === '' && in_array($filter, ['all', 'unread'], true);
?>
<section class="panel"<?= $live ? ' data-messages-live data-back="' . e($back) . '"' : '' ?>>
    <p class="empty" data-messages-empty<?= $rows ? ' hidden' : '' ?>><?= $q !== '' ? 'Aucun message ne correspond à cette recherche.' : ($filter === 'all' ? 'Aucun message reçu via le formulaire de contact.' : 'Aucun message dans cette catégorie.') ?></p>
    <div class="table-wrap" data-messages-table<?= $rows ? '' : ' hidden' ?>>
        <table class="table table--hover table--stack mail-table">
            <thead><tr><th>Expéditeur</th><th>Sujet</th><th>Reçu le</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody data-messages-list>
            <?php foreach ($rows as $m) { echo message_row($m, $back); } ?>
            </tbody>
        </table>
    </div>
    <?= pagination($total, $page, $perPage, array_filter(['f' => $filter === 'all' ? '' : $filter, 'q' => $q])) ?>
</section>

<?php dash_footer(); ?>
