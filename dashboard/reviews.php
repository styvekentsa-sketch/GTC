<?php
/**
 * Avis clients — modération des avis déposés sur le site (avis.php → api-reviews.php) et invitations.
 *
 *   reviews.php                     Avis : En attente / Publiés / Refusés / Tous, recherche, pagination
 *                                   Menu « ⋮ » : Publier, Refuser, Remettre en attente, Supprimer (administrateurs)
 *   reviews.php?f=invites           Invitations : créer un lien personnel (nom, téléphone, e-mail du client,
 *                                   facultatifs) ou utiliser le lien général ; suivi (ouvert, avis reçu)
 *   reviews.php?f=invites&share=ID  Envoi d'une invitation : WhatsApp, Gmail, e-mail, SMS, Telegram, Facebook,
 *                                   copie du lien ou du message, « Autres applications » (partage du téléphone)
 *
 * Le client qui ouvre le lien (mon-avis.php) fait une visite guidée du site (includes/review-tour.php) qui se
 * termine sur le formulaire d'avis ; son avis est rattaché à l'invitation.
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
    'invites'  => 'Invitations',
];

/* ==========================================================================
   Actions (POST + CSRF)
   ========================================================================== */

if (is_post()) {
    csrf_verify();
    $op   = post('op');
    $id   = (int) post('id');
    $back = safe_back(post('back'), 'reviews.php', 'reviews.php');

    // ---------- Invitations ----------
    if ($op === 'invite_create') {
        $name  = mb_substr(review_clean(post('client_name')), 0, REVIEW_NAME_MAX);
        $phone = mb_substr(trim(post('client_phone')), 0, 30);
        $email = mb_substr(trim(post('client_email')), 0, 120);
        $errors = [];
        if ($phone !== '' && !preg_match('/^[0-9+().\s-]{6,30}$/', $phone)) {
            $errors[] = 'Numéro de téléphone invalide.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse e-mail invalide.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('reviews.php?f=invites');
        }
        $inviteId = review_invite_create($name, $phone, $email, $user);
        activity_log('create', 'review_invite', $inviteId, 'Invitation' . ($name !== '' ? ' de ' . $name : ' (sans nom)'));
        flash('success', 'Lien d\'invitation créé. Envoyez-le au client avec l\'un des boutons ci-dessous.');
        redirect('reviews.php?f=invites&share=' . $inviteId . '#partage');
    }
    if ($op === 'invite_delete') {
        $inv = db_one('SELECT id, client_name FROM review_invites WHERE id = ?', [$id]);
        if (!$inv || !is_admin()) {
            flash('error', $inv ? 'Seul un administrateur peut supprimer une invitation.' : 'Invitation introuvable.');
            redirect('reviews.php?f=invites');
        }
        db_query('DELETE FROM review_invites WHERE id = ?', [$id]);
        activity_log('delete', 'review_invite', $id, 'Invitation' . ($inv['client_name'] ? ' de ' . $inv['client_name'] : ' (sans nom)'));
        flash('success', 'Invitation supprimée : son lien ne déclenche plus de suivi.');
        redirect('reviews.php?f=invites');
    }

    // ---------- Avis ----------
    $review = db_one('SELECT id, author_name, rating, status FROM reviews WHERE id = ?', [$id]);
    if (!$review) {
        flash('error', 'Avis introuvable (déjà supprimé ?).');
        redirect($back);
    }
    $label = 'Avis de ' . $review['author_name'] . ' (' . $review['rating'] . '/5)';

    $changes = [
        // op => [nouveau statut, message]
        'approve' => ['approved', 'Avis de ' . $review['author_name'] . ' publié sur le site.'],
        'reject'  => ['rejected', 'Avis de ' . $review['author_name'] . ' refusé : il ne sera pas publié.'],
        'pending' => ['pending',  'Avis de ' . $review['author_name'] . ' remis en attente.'],
    ];
    if (isset($changes[$op])) {
        [$status, $text] = $changes[$op];
        if ($review['status'] !== $status) {
            db_query('UPDATE reviews SET status = ?, moderated_at = ?, moderated_by = ?, moderated_by_name = ? WHERE id = ?',
                [$status, db_now(), (int) $user['id'], (string) $user['name'], $id]);
            activity_log('status', 'review', $id, $label, ['statut' => REVIEW_STATUSES[$review['status']]['label'] . ' → ' . REVIEW_STATUSES[$status]['label']]);
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
        db_query('UPDATE review_invites SET review_id = NULL, reviewed_at = NULL WHERE review_id = ?', [$id]);
        activity_log('delete', 'review', $id, $label);
        flash('success', 'Avis de ' . $review['author_name'] . ' supprimé.');
        redirect($back);
    }

    flash('error', 'Action inconnue.');
    redirect($back);
}

/* ==========================================================================
   Compteurs (onglets)
   ========================================================================== */

$filter = isset(REVIEW_FILTERS[query('f')]) ? query('f') : 'pending';
$counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'invites' => (int) db_value('SELECT COUNT(*) FROM review_invites')];
foreach (db_all('SELECT status, COUNT(*) AS n FROM reviews GROUP BY status') as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']] = (int) $r['n'];
    }
    $counts['all'] += (int) $r['n'];
}
$stats = review_stats();

/**
 * Bloc d'envoi d'un lien : lien + copie, message modifiable, boutons des applications.
 * Les adresses des boutons sont recalculées en direct par assets/js/dashboard.js (§ 11) quand le message change.
 */
function review_share_box(string $id, string $title, string $intro, string $link, string $message, string $phone = '', string $email = '', bool $highlight = false): string
{
    ob_start(); ?>
    <section class="panel share-box<?= $highlight ? ' share-box--new' : '' ?>" id="<?= e($id) ?>" data-share
             data-link="<?= e($link) ?>" data-phone="<?= e(review_phone_intl($phone)) ?>" data-email="<?= e($email) ?>" data-subject="<?= e(REVIEW_INVITE_SUBJECT) ?>">
        <div class="panel__head">
            <h2 class="panel__title"><?= icon('share') ?> <?= e($title) ?></h2>
        </div>
        <p class="share-box__intro"><?= $intro ?></p>

        <label class="share-box__label" for="<?= e($id) ?>-link">Lien à partager</label>
        <div class="share-box__link">
            <input id="<?= e($id) ?>-link" type="text" readonly value="<?= e($link) ?>" data-share-link onfocus="this.select()">
            <button type="button" class="btn btn--primary btn--sm" data-copy="link"><?= icon('file') ?> <span>Copier le lien</span></button>
        </div>

        <label class="share-box__label" for="<?= e($id) ?>-text">Message <small class="field__hint">(modifiable avant l'envoi)</small></label>
        <textarea id="<?= e($id) ?>-text" rows="8" data-share-text><?= e($message) ?></textarea>

        <p class="share-box__label">Envoyer avec</p>
        <div class="share-box__channels">
            <?php foreach (review_share_links($message, $link, $phone, $email) as $key => [$label, $ic, $href]): ?>
                <a href="<?= e($href) ?>" class="share-btn share-btn--<?= e($key) ?>" data-share-to="<?= e($key) ?>"<?= $key === 'email' || $key === 'sms' ? '' : ' target="_blank" rel="noopener noreferrer"' ?>>
                    <?= icon($ic) ?><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
            <button type="button" class="share-btn share-btn--native" data-share-native hidden><?= icon('more') ?><span>Autres applications</span></button>
            <button type="button" class="share-btn share-btn--copy" data-copy="text"><?= icon('file') ?><span>Copier le message</span></button>
        </div>
        <?php if ($phone !== '' || $email !== ''): ?>
            <p class="field__hint share-box__to">
                <?= $phone !== '' ? 'WhatsApp et SMS s\'ouvrent directement sur le <span class="nowrap">' . e($phone) . '</span>.' : '' ?>
                <?= $email !== '' ? 'Gmail et l\'e-mail sont adressés à ' . e($email) . '.' : '' ?>
            </p>
        <?php endif; ?>
    </section>
    <?php
    return (string) ob_get_clean();
}

dash_header('Avis clients', 'reviews');
?>
<div class="toolbar reviews-toolbar">
    <nav class="chips" aria-label="Filtrer les avis">
        <?php foreach (REVIEW_FILTERS as $key => $label): ?>
            <a href="reviews.php<?= $key === 'pending' ? '' : '?f=' . $key ?>" class="chip<?= $key === $filter ? ' is-active' : '' ?>"
               <?= $key === $filter ? 'aria-current="page"' : '' ?>><?= e($label) ?> (<?= $counts[$key] ?>)</a>
        <?php endforeach; ?>
    </nav>
    <div class="toolbar__actions">
        <?php if ($filter !== 'invites'): ?>
            <a href="reviews.php?f=invites#inviter" class="btn btn--primary btn--sm"><?= icon('share') ?> Inviter un client</a>
        <?php endif; ?>
        <a href="<?= e(site_root()) ?>avis.php" class="btn btn--ghost btn--sm" target="_blank" rel="noopener"><?= icon('external') ?> <span class="wide-only">Voir la page publique</span><span class="narrow-only">Page publique</span></a>
    </div>
</div>

<?php
/* ==========================================================================
   Vue : invitations
   ========================================================================== */
if ($filter === 'invites'):
    $shareId = (int) query('share');
    $shared  = $shareId ? db_one('SELECT * FROM review_invites WHERE id = ?', [$shareId]) : null;
    $page    = max(1, (int) query('page', '1'));
    $perPage = 20;
    $total   = $counts['invites'];
    $page    = min($page, max(1, (int) ceil($total / $perPage)));
    $offset  = ($page - 1) * $perPage;
    $invites = db_all("SELECT i.*, r.rating, r.comment, r.status AS review_status FROM review_invites i LEFT JOIN reviews r ON r.id = i.review_id
                       ORDER BY i.created_at DESC, i.id DESC LIMIT {$perPage} OFFSET {$offset}");
    $opened   = (int) db_value('SELECT COUNT(*) FROM review_invites WHERE opened_at IS NOT NULL');
    $answered = (int) db_value('SELECT COUNT(*) FROM review_invites WHERE review_id IS NOT NULL');
    $pct      = fn (int $n) => $total ? round($n / $total * 100) . " %" : '—';
    $general  = review_invite_link();
    ?>

    <section class="stats stats--summary stats--compact" aria-label="Suivi des invitations">
        <article class="stat"><span class="stat__icon stat__icon--blue"><?= icon('send') ?></span>
            <div><p class="stat__label">Invitations créées</p><p class="stat__value"><?= $total ?></p></div></article>
        <article class="stat"><span class="stat__icon stat__icon--gold"><?= icon('eye') ?></span>
            <div><p class="stat__label">Liens ouverts</p><p class="stat__value"><?= $opened ?></p><p class="stat__note"><?= $pct($opened) ?><span class="wide-only"> des invitations</span></p></div></article>
        <article class="stat"><span class="stat__icon stat__icon--green"><?= icon('star') ?></span>
            <div><p class="stat__label">Avis reçus</p><p class="stat__value"><?= $answered ?></p><p class="stat__note"><span class="wide-only">Taux de réponse : </span><?= $pct($answered) ?><span class="narrow-only"> de réponse</span></p></div></article>
    </section>

    <?php if ($shared):
        $sharedLink = review_invite_link($shared['token']);
        echo review_share_box('partage', 'Envoyer l\'invitation' . ($shared['client_name'] ? ' à ' . $shared['client_name'] : ''),
            'Lien personnel : vous saurez quand le client l\'ouvre et quand il laisse son avis. Il découvre le site en 5 étapes, puis arrive sur le formulaire'
            . ($shared['client_name'] ? ' où son nom est déjà saisi.' : '.'),
            $sharedLink, review_invite_message($shared['client_name'], $sharedLink), (string) $shared['client_phone'], (string) $shared['client_email'], true);
    endif; ?>

    <div class="invite-grid">
        <!-- Nouvelle invitation personnelle -->
        <section class="panel" id="inviter">
            <div class="panel__head"><h2 class="panel__title"><?= icon('plus') ?> Nouvelle invitation personnelle</h2></div>
            <p class="share-box__intro">Créez un lien pour un client précis : vous suivez s'il l'ouvre et s'il répond. Tous les champs sont facultatifs.</p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="invite_create">
                <div class="field field--full">
                    <label for="inv-name">Nom du client</label>
                    <input id="inv-name" name="client_name" type="text" maxlength="<?= REVIEW_NAME_MAX ?>" placeholder="Ex. M. Kamga" autocomplete="off">
                    <small class="field__hint">Utilisé pour le saluer et pré-remplir son avis.</small>
                </div>
                <div class="field">
                    <label for="inv-phone">Téléphone / WhatsApp</label>
                    <input id="inv-phone" name="client_phone" type="tel" maxlength="30" placeholder="6 XX XX XX XX" autocomplete="off">
                </div>
                <div class="field">
                    <label for="inv-email">E-mail</label>
                    <input id="inv-email" name="client_email" type="email" maxlength="120" placeholder="client@exemple.com" autocomplete="off">
                </div>
                <div class="field field--full">
                    <button type="submit" class="btn btn--primary"><?= icon('share') ?> Créer le lien d'invitation</button>
                </div>
            </form>
        </section>

        <!-- Lien général -->
        <?= review_share_box('lien-general', 'Lien général',
            'Un seul lien pour tout le monde : statut WhatsApp, groupes, réseaux sociaux, affichage au guichet. Sans suivi individuel.',
            $general, review_invite_message(null, $general)) ?>
    </div>

    <section class="panel">
        <div class="panel__head"><h2 class="panel__title">Invitations envoyées</h2></div>
        <?php if (!$invites): ?>
            <p class="empty">Aucune invitation pour le moment : créez-en une ci-dessus.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--hover table--stack table--card-menu">
                    <thead><tr><th>Client</th><th>Créée le</th><th>Suivi</th><th>Avis</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($invites as $inv):
                        [$st, $stLabel, $tone] = review_invite_status($inv);
                        $iid = (int) $inv['id']; ?>
                        <tr<?= $iid === $shareId ? ' class="is-highlight"' : '' ?>>
                            <td data-label="Client"><span class="cell-stack">
                                <strong><?= $inv['client_name'] ? e($inv['client_name']) : '<span class="cell-sub">Sans nom</span>' ?></strong>
                                <?php if ($inv['client_phone'] || $inv['client_email']): ?>
                                    <small class="cell-sub"><?= e(implode(' · ', array_filter([$inv['client_phone'], $inv['client_email']]))) ?></small>
                                <?php endif; ?>
                            </span></td>
                            <td data-label="Créée le"><span class="cell-stack"><?= e(fdate($inv['created_at'], true)) ?><small class="cell-sub">par <?= e((string) $inv['created_by_name']) ?></small></span></td>
                            <td data-label="Suivi"><span class="cell-stack">
                                <span class="badge badge--<?= e($tone) ?>"><?= e($stLabel) ?></span>
                                <?php if ($inv['opened_at'] && !$inv['review_id']): ?><small class="cell-sub">le <?= e(fdate($inv['opened_at'], true)) ?></small><?php endif; ?>
                                <?php if ($inv['reviewed_at']): ?><small class="cell-sub">le <?= e(fdate($inv['reviewed_at'], true)) ?></small><?php endif; ?>
                            </span></td>
                            <td data-label="Avis">
                                <?php if ($inv['review_id'] && $inv['rating'] !== null): ?>
                                    <span class="cell-stack"><?= review_stars((float) $inv['rating'], 'stars stars--sm') ?>
                                        <small class="cell-sub"><?= e(mb_strimwidth((string) $inv['comment'], 0, 70, '…')) ?> · <?= e(REVIEW_STATUSES[$inv['review_status']]['label'] ?? '') ?></small></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="actions">
                                <?= action_menu(array_values(array_filter([
                                    ['link', $inv['review_id'] ? 'Voir le lien' : 'Envoyer / renvoyer', 'reviews.php?f=invites&share=' . $iid . '#partage', 'share'],
                                    $inv['review_id'] ? ['link', 'Modérer l\'avis', 'reviews.php?f=all', 'star'] : [],
                                    is_admin() ? ['sep'] : [],
                                    is_admin() ? ['post', 'Supprimer', 'trash', ['op' => 'invite_delete', 'id' => $iid, 'back' => 'reviews.php?f=invites'],
                                        'Supprimer cette invitation ? Son lien mènera toujours au site, mais sans suivi.', 'danger' => true] : [],
                                ])), 'Actions de l\'invitation') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?= pagination($total, $page, $perPage, ['f' => 'invites']) ?>
    </section>

    <?php
    dash_footer();
    exit;
endif;

/* ==========================================================================
   Vue : avis
   ========================================================================== */

$q       = mb_substr(query('q'), 0, 100);
$page    = max(1, (int) query('page', '1'));
$perPage = 20;

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
?>

<div class="toolbar">
    <p class="cell-sub">
        Note affichée sur le site : <strong><?= $stats['count'] ? e(str_replace('.', ',', number_format($stats['average'], 1))) . ' / 5' : '—' ?></strong>
        · <?= $stats['count'] ?> avis publié<?= $stats['count'] > 1 ? 's' : '' ?>. Un avis n'apparaît sur le site qu'une fois publié.
    </p>
    <form method="get" class="filters" role="search">
        <?php if ($filter !== 'pending'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
        <div class="search">
            <?= icon('search') ?>
            <label class="visually-hidden" for="q">Rechercher</label>
            <input id="q" name="q" type="search" placeholder="Nom, texte de l'avis…" value="<?= e($q) ?>">
        </div>
    </form>
</div>

<section class="panel">
    <?php if (!$rows): ?>
        <p class="empty"><?= $q !== '' ? 'Aucun avis ne correspond à cette recherche.' : ($filter === 'pending' ? 'Aucun avis en attente de validation.' : 'Aucun avis dans cette catégorie.') ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--hover table--stack table--card-menu">
                <thead><tr><th>Auteur</th><th>Note</th><th>Avis</th><th>Reçu le</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $rid  = (int) $r['id'];
                    $post = fn (string $op) => ['op' => $op, 'id' => $rid, 'back' => $back]; ?>
                    <tr>
                        <td data-label="Auteur"><span class="cell-stack">
                            <strong><?= e($r['author_name']) ?></strong>
                            <?php if (!empty($r['invite_id'])): ?><small class="cell-sub"><?= icon('share') ?> Via une invitation</small><?php endif; ?>
                            <?php if ($r['moderated_by_name']): ?><small class="cell-sub">Modéré par <?= e($r['moderated_by_name']) ?> le <?= e(fdate($r['moderated_at'], true)) ?></small><?php endif; ?>
                        </span></td>
                        <td data-label="Note" class="nowrap"><?= review_stars((float) $r['rating'], 'stars stars--sm') ?></td>
                        <td data-label="Avis" class="review-cell"><?= e($r['comment']) ?></td>
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
