<?php
/**
 * Messagerie — messages envoyés depuis le formulaire de contact du site (contact.php → process-contact.php).
 *
 * Les messages d'une même adresse e-mail (casse ignorée) forment UNE conversation :
 *   messages.php              Liste des conversations (filtres Tous / Non lus / Lus / Répondus / Archivés,
 *                             recherche, pagination), avec le nombre de messages et de non lus de chacune
 *   messages.php?id=X         Conversation de l'expéditeur du message X, du plus ancien au plus récent
 *                             (le message X est mis en évidence ; les non lus passent à « Lu »)
 *
 * Accès : Administrateur et Secrétaire / Accueil (MESSAGE_ROLES).
 * Actualisation automatique : assets/js/messages-live.js interroge dashboard/messages-api.php (la conversation
 * qui reçoit un nouveau message remonte en tête de liste, compteurs et pastille du menu mis à jour).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/messages.php';   // filtres, conversations, lignes de la liste, actions

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

    $msg = db_one('SELECT id, sender_name, sender_email, status FROM messages WHERE id = ?', [$id]);
    if (!$msg) {
        flash('error', 'Message introuvable (déjà supprimé ?).');
        redirect('messages.php');
    }
    $key     = message_thread_key($msg['sender_email']);
    $inView  = str_contains($back, 'id=');   // action lancée depuis la conversation ouverte
    $thread  = 'WHERE ' . MESSAGE_THREAD_KEY . ' = ?';

    // ---------- Conversation entière ----------
    $threadChanges = [
        // op => [nouveau statut, statuts concernés, message]
        'thread_read'      => ['read',     ['unread'],                     'Conversation marquée comme lue.'],
        'thread_replied'   => ['replied',  ['unread', 'read'],             'Conversation marquée comme répondue.'],
        'thread_archive'   => ['archived', ['unread', 'read', 'replied'],  'Conversation archivée.'],
        'thread_unarchive' => ['read',     ['archived'],                   'Conversation replacée dans la boîte de réception.'],
    ];
    if (isset($threadChanges[$op])) {
        [$status, $from, $text] = $threadChanges[$op];
        $in = implode(', ', array_fill(0, count($from), '?'));
        db_query("UPDATE messages SET status = ? {$thread} AND status IN ({$in})", [$status, $key, ...$from]);
        flash('success', $text);
        redirect($op === 'thread_archive' && $inView ? 'messages.php' : $back);
    }
    if ($op === 'thread_unread') {
        // Le dernier message redevient non lu : la conversation remonte dans « Non lus »
        $lastId = (int) db_value("SELECT MAX(id) FROM messages {$thread} AND status <> 'archived'", [$key]);
        if ($lastId) {
            db_query("UPDATE messages SET status = 'unread' WHERE id = ?", [$lastId]);
        }
        flash('success', 'Conversation marquée comme non lue.');
        redirect($inView ? 'messages.php' : $back);   // rester sur la conversation la marquerait lue
    }
    if ($op === 'thread_delete') {
        $n = db_query("DELETE FROM messages {$thread}", [$key])->rowCount();
        activity_log('delete', 'message', $id, 'Conversation avec ' . $msg['sender_name'], ['e-mail' => $msg['sender_email'], 'messages' => $n]);
        flash('success', "Conversation avec {$msg['sender_name']} supprimée ({$n} message" . ($n > 1 ? 's' : '') . ').');
        redirect($inView ? 'messages.php' : $back);
    }

    // ---------- Un seul message de la conversation ----------
    $changes = [
        'read'      => ['read',     'Message marqué comme lu.'],
        'replied'   => ['replied',  'Message marqué comme répondu.'],
        'archive'   => ['archived', 'Message archivé.'],
        'unarchive' => ['read',     'Message replacé dans la boîte de réception.'],
    ];
    if (isset($changes[$op])) {
        [$status, $text] = $changes[$op];
        db_query('UPDATE messages SET status = ? WHERE id = ?', [$status, $id]);
        flash('success', $text);
        redirect($back);
    }

    if ($op === 'delete') {
        db_query('DELETE FROM messages WHERE id = ?', [$id]);
        activity_log('delete', 'message', $id, 'Message de ' . $msg['sender_name'], ['e-mail' => $msg['sender_email']]);
        flash('success', 'Le message de ' . $msg['sender_name'] . ' a été supprimé.');
        if ($inView) {
            // Reste sur la conversation tant qu'elle contient d'autres messages
            $other = (int) db_value("SELECT MAX(id) FROM messages {$thread}", [$key]);
            redirect($other ? 'messages.php?id=' . $other : 'messages.php');
        }
        redirect($back);
    }

    flash('error', 'Action inconnue.');
    redirect($back);
}

/* ==========================================================================
   Vue : conversation avec un expéditeur
   ========================================================================== */

$id = (int) query('id');
if ($id) {
    $current = db_one('SELECT id, sender_email FROM messages WHERE id = ?', [$id]);
    if (!$current) {
        flash('error', 'Message introuvable.');
        redirect('messages.php');
    }
    $key      = message_thread_key($current['sender_email']);
    $messages = db_all('SELECT * FROM messages WHERE ' . MESSAGE_THREAD_KEY . ' = ? ORDER BY id ASC', [$key]);

    // Ouverture = lecture : les non lus passent à « Lu » (ils restent signalés « Nouveau » sur cet affichage)
    $fresh = array_column(array_filter($messages, fn ($m) => $m['status'] === 'unread'), 'id');
    if ($fresh) {
        db_query("UPDATE messages SET status = 'read' WHERE " . MESSAGE_THREAD_KEY . " = ? AND status = 'unread'", [$key]);
        foreach ($messages as &$m) {
            if ($m['status'] === 'unread') {
                $m['status'] = 'read';
            }
        }
        unset($m);
    }

    $last      = end($messages);
    $total     = count($messages);
    $archived  = count(array_filter($messages, fn ($m) => $m['status'] === 'archived'));
    $phone     = '';
    foreach (array_reverse($messages) as $m) {   // numéro le plus récent renseigné
        if ($m['sender_phone']) {
            $phone = $m['sender_phone'];
            break;
        }
    }
    $back      = 'messages.php?id=' . $id;
    $replyUrl  = 'mailto:' . rawurlencode($last['sender_email'])
               . '?subject=' . rawurlencode('Re: ' . $last['subject'])
               . '&body=' . rawurlencode("Bonjour " . $last['sender_name'] . ",\n\n\n\n— Global Trading Corporation\n\n"
                   . '> Message du ' . fdate($last['created_at'], true) . " :\n> " . str_replace("\n", "\n> ", mb_strimwidth($last['message'], 0, 1200, '…')));
    $whatsapp  = message_whatsapp($phone);
    $lastId    = (int) $last['id'];

    dash_header('Conversation avec ' . $last['sender_name'], 'messages');
    ?>
    <div class="toolbar">
        <a href="messages.php<?= $archived === $total ? '?f=archived' : '' ?>" class="btn btn--ghost"><?= icon('arrow-left') ?> Toutes les conversations</a>
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
                <p class="detail-head__label">Conversation</p>
                <h2 class="detail-head__number"><?= e($last['sender_name']) ?></h2>
                <p class="cell-sub">
                    <?= $total ?> message<?= $total > 1 ? 's' : '' ?> reçu<?= $total > 1 ? 's' : '' ?>
                    <?php if ($total > 1): ?>· du <?= e(fdate($messages[0]['created_at'], true)) ?> au <?= e(fdate($last['created_at'], true)) ?><?php else: ?>· le <?= e(fdate($last['created_at'], true)) ?><?php endif; ?>
                    <?php if ($archived): ?>· <?= $archived ?> archivé<?= $archived > 1 ? 's' : '' ?><?php endif; ?>
                </p>
            </div>
        </header>

        <dl class="detail-list mail-view__from">
            <div><dt>Expéditeur</dt><dd><?= e($last['sender_name']) ?></dd></div>
            <div><dt>E-mail</dt><dd><a href="<?= e($replyUrl) ?>" class="link-strong"><?= e($last['sender_email']) ?></a></dd></div>
            <div><dt>Téléphone</dt><dd>
                <?php if ($phone): ?>
                    <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>" class="link-strong"><?= e($phone) ?></a>
                <?php else: ?>—<?php endif; ?>
            </dd></div>
        </dl>

        <ol class="thread">
            <?php foreach ($messages as $m): ?>
                <?php $mid = (int) $m['id']; ?>
                <li class="thread__item<?= $mid === $id ? ' is-current' : '' ?><?= $m['status'] === 'archived' ? ' is-archived' : '' ?>" id="m-<?= $mid ?>">
                    <header class="thread__head">
                        <div>
                            <h3 class="thread__subject"><?= e($m['subject']) ?></h3>
                            <p class="cell-sub">
                                Reçu le <?= e(fdate($m['created_at'], true)) ?>
                                <?php if ($m['sender_name'] !== $last['sender_name']): ?>· signé « <?= e($m['sender_name']) ?> »<?php endif; ?>
                            </p>
                        </div>
                        <span class="thread__badges">
                            <?php if (in_array($m['id'], $fresh, false)): ?><span class="badge badge--info">Nouveau</span><?php endif; ?>
                            <?= badge($m['status'], MESSAGE_STATUSES) ?>
                        </span>
                    </header>
                    <div class="mail-view__body"><?= nl2br(e($m['message'])) ?></div>
                    <footer class="thread__actions">
                        <?php if (!in_array($m['status'], ['replied', 'archived'], true)): ?>
                            <?= message_action($mid, 'replied', 'Répondu', 'check', $back) ?>
                        <?php endif; ?>
                        <?= $m['status'] === 'archived'
                            ? message_action($mid, 'unarchive', 'Désarchiver', 'inbox', $back)
                            : message_action($mid, 'archive', 'Archiver ce message', 'archive', $back) ?>
                        <?= message_action($mid, 'delete', 'Supprimer', 'trash', $back, 'btn btn--ghost btn--sm',
                            'Supprimer définitivement ce message de ' . $m['sender_name'] . ' ?') ?>
                    </footer>
                </li>
            <?php endforeach; ?>
        </ol>

        <footer class="mail-view__actions">
            <?= message_action($lastId, 'thread_replied', 'Conversation répondue', 'check', $back) ?>
            <?= message_action($lastId, 'thread_unread', 'Marquer comme non lue', 'mail', $back) ?>
            <?= $archived === $total
                ? message_action($lastId, 'thread_unarchive', 'Désarchiver la conversation', 'inbox', $back)
                : message_action($lastId, 'thread_archive', 'Archiver la conversation', 'archive', $back) ?>
            <?= message_action($lastId, 'thread_delete', 'Supprimer la conversation', 'trash', $back, 'btn btn--danger btn--sm',
                'Supprimer définitivement toute la conversation avec ' . $last['sender_name'] . ' (' . $total . ' message' . ($total > 1 ? 's' : '') . ') ?') ?>
        </footer>
    </article>
    <?php if ($id !== $lastId): ?>
        <script>document.getElementById('m-<?= $id ?>').scrollIntoView({ block: 'center' });</script>
    <?php endif; ?>
    <?php
    dash_footer();
    exit;
}

/* ==========================================================================
   Vue : liste des conversations
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
$total    = messages_threads_count($whereSql, $params);
$page     = min($page, max(1, (int) ceil($total / $perPage)));
$threads  = messages_threads($whereSql, $params, $perPage, ($page - 1) * $perPage);

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
// Conversations mises à jour en direct seulement là où elles apparaîtraient : 1re page de « Tous » ou « Non lus », sans recherche
$live = $page === 1 && $q === '' && in_array($filter, ['all', 'unread'], true);
?>
<section class="panel"<?= $live ? ' data-messages-live data-back="' . e($back) . '"' : '' ?>>
    <p class="empty" data-messages-empty<?= $threads ? ' hidden' : '' ?>><?= $q !== '' ? 'Aucune conversation ne correspond à cette recherche.' : ($filter === 'all' ? 'Aucun message reçu via le formulaire de contact.' : 'Aucune conversation dans cette catégorie.') ?></p>
    <div class="table-wrap" data-messages-table<?= $threads ? '' : ' hidden' ?>>
        <table class="table table--hover table--stack mail-table">
            <thead><tr><th>Interlocuteur</th><th>Dernier message</th><th>Dernier reçu le</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody data-messages-list>
            <?php foreach ($threads as $t) { echo message_thread_row($t, $back); } ?>
            </tbody>
        </table>
    </div>
    <?= pagination($total, $page, $perPage, array_filter(['f' => $filter === 'all' ? '' : $filter, 'q' => $q])) ?>
</section>

<?php dash_footer(); ?>
