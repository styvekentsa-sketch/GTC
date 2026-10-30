<?php
/**
 * Accueil du Dashboard — Hub : grille de boutons vers chaque module (dash_modules(), selon le rôle),
 * puis aperçu de l'activité (statistiques, graphique, derniers reçus, messages).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/courriers.php';   // courrier_long_date()

$user = require_login();
if ($user['role'] === 'secretaire') {
    redirect('messages.php');   // Secrétaire / Accueil : la messagerie est sa page d'accueil
}
require_roles(OPERATION_ROLES);

// ---------- Statistiques ----------
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');

$stats = [
    'invoices_month' => (int) db_value('SELECT COUNT(*) FROM invoices WHERE issue_date >= ?', [$monthStart]),
    'pending'        => (int) db_value("SELECT COUNT(*) FROM invoices WHERE status = 'pending'"),
    'overdue'        => (int) db_value("SELECT COUNT(*) FROM invoices WHERE status = 'pending' AND due_date IS NOT NULL AND due_date < ?", [$today]),
    'paid_xaf_month' => (float) db_value("SELECT COALESCE(SUM(amount_given), 0) FROM invoices WHERE status = 'paid' AND source_currency = 'XAF' AND issue_date >= ?", [$monthStart]),
    'jobs_active'    => (int) db_value("SELECT COUNT(*) FROM print_jobs WHERE status IN ('queued', 'printing')"),
    'unread'         => can_access_messages() ? (int) db_value("SELECT COUNT(*) FROM messages WHERE status = 'unread'") : 0,
];

// ---------- Synthèse : visiteurs du site & factures du jour ----------
$summary = [
    'visitors_total' => (int) db_value('SELECT COUNT(*) FROM site_visits'),
    'visitors_today' => (int) db_value('SELECT COUNT(*) FROM site_visits WHERE visit_date = ?', [$today]),
    'invoices_today' => (int) db_value('SELECT COUNT(*) FROM invoices WHERE issue_date = ?', [$today]),
];

// ---------- Graphique : factures sur 6 mois ----------
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -{$i} month"));
    $months[$key] = ['total' => 0, 'paid' => 0];
}
$rows = db_all('SELECT issue_date, status FROM invoices WHERE issue_date >= ?', [array_key_first($months) . '-01']);
foreach ($rows as $r) {
    $key = substr($r['issue_date'], 0, 7);
    if (isset($months[$key])) {
        $months[$key]['total']++;
        if ($r['status'] === 'paid') {
            $months[$key]['paid']++;
        }
    }
}
$maxBar = max(1, ...array_column($months, 'total'));
$monthNames = ['01' => 'Janv.', '02' => 'Févr.', '03' => 'Mars', '04' => 'Avr.', '05' => 'Mai', '06' => 'Juin',
               '07' => 'Juil.', '08' => 'Août', '09' => 'Sept.', '10' => 'Oct.', '11' => 'Nov.', '12' => 'Déc.'];

// ---------- Listes ----------
$recentInvoices = db_all('SELECT id, invoice_number, client_name, amount_given, source_currency, status, issue_date FROM invoices ORDER BY id DESC LIMIT 10');
$invoiceCount   = (int) db_value('SELECT COUNT(*) FROM invoices');
$messages       = can_access_messages()
    ? db_all("SELECT * FROM messages WHERE status = 'unread' ORDER BY id DESC LIMIT 5")
    : [];
$printers       = db_all('SELECT printer_name, printer_location, is_default, last_status, last_checked_at FROM printers WHERE is_active = 1 ORDER BY is_default DESC, printer_name LIMIT 5');

$modules = dash_modules() + ['settings' => is_admin()
    ? ['href' => 'settings.php', 'icon' => 'settings', 'title' => 'Paramètres & BDD', 'desc' => 'Entreprise, sauvegardes, système', 'tone' => 'green']
    : ['href' => 'settings.php', 'icon' => 'settings', 'title' => 'Paramètres', 'desc' => 'Mon profil et mot de passe', 'tone' => 'green']];

dash_header('Accueil', 'index');
?>

<!-- ==================== HUB : BIENVENUE & GRILLE DES MODULES ==================== -->
<section class="hub" aria-labelledby="hub-title">
    <header class="hub__head">
        <div>
            <h2 class="hub__title" id="hub-title">Bienvenue, <?= e($user['name']) ?> <span class="hub__sep" aria-hidden="true">—</span> <span class="hub__cta">Choisissez un module</span></h2>
            <p class="page-intro"><?= e(role_label($user['role'])) ?> · <?= e(ucfirst(courrier_long_date($today))) ?></p>
        </div>
        <div class="hub__actions">
            <button type="button" class="btn btn--ghost btn--sm" data-pwa-install hidden><?= icon('download') ?> Installer l'application</button>
            <a href="<?= e(site_root()) ?>index.php" class="btn btn--ghost btn--sm" target="_blank" rel="noopener"><?= icon('external') ?> Voir le site</a>
            <form action="logout.php" method="post">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--ghost btn--sm"><?= icon('logout') ?> Déconnexion</button>
            </form>
        </div>
    </header>

    <nav class="hub__grid" aria-label="Modules du Dashboard">
        <?php $i = 0; foreach ($modules as $key => $m): ?>
            <?php /* couleurs de la charte en alternance régulière, quels que soient les modules visibles du rôle */ ?>
            <a href="<?= e($m['href']) ?>" class="hub-tile hub-tile--<?= ['blue', 'green', 'magenta'][$i++ % 3] ?>">
                <span class="hub-tile__icon"><?= icon($m['icon']) ?></span>
                <span class="hub-tile__title"><?= e($m['title']) ?></span>
                <span class="hub-tile__desc"><?= e($m['desc']) ?></span>
                <?php if ($key === 'messages'): ?>
                    <span class="hub-tile__badge" data-unread-badge<?= $stats['unread'] ? '' : ' hidden' ?>><?= $stats['unread'] ?> non lu<?= $stats['unread'] > 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
</section>

<!-- ==================== DERNIERS REÇUS DE CAISSE (juste sous la grille : donnée la plus consultée) ==================== -->
<section class="panel">
    <header class="panel__head">
        <h2 class="panel__title">Derniers reçus de caisse <span class="cell-sub">(<?= $invoiceCount ?> au total)</span></h2>
        <div class="panel__actions">
            <a href="invoices.php?action=new" class="btn btn--primary btn--sm"><?= icon('plus') ?> Nouveau reçu de caisse</a>
            <a href="invoices.php?action=list" class="panel__link">Tous les reçus</a>
        </div>
    </header>
    <?php if (!$recentInvoices): ?>
        <p class="empty">Aucun reçu de caisse pour le moment. <a href="invoices.php?action=new">Créer le premier reçu</a></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>N°</th><th>Client</th><th>Date</th><th class="num">Montant</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($recentInvoices as $inv): ?>
                    <tr>
                        <td><a href="invoices.php?action=view&amp;id=<?= (int) $inv['id'] ?>" class="link-strong"><?= e($inv['invoice_number']) ?></a></td>
                        <td><?= e($inv['client_name']) ?></td>
                        <td><?= e(fdate($inv['issue_date'])) ?></td>
                        <td class="num"><?= e(money($inv['amount_given'], $inv['source_currency'])) ?></td>
                        <td><?= badge($inv['status'], INVOICE_STATUSES) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<h2 class="hub-section-title">Aperçu de l'activité</h2>
<!-- ==================== SYNTHÈSE ==================== -->
<section class="stats stats--summary" aria-label="Synthèse du jour">
    <article class="stat">
        <span class="stat__icon stat__icon--blue"><?= icon('users') ?></span>
        <div>
            <p class="stat__label">Total visiteurs</p>
            <p class="stat__value"><?= number_format($summary['visitors_total'], 0, ',', "\u{202F}") ?></p>
            <p class="stat__note">Visites uniques depuis le lancement</p>
        </div>
    </article>
    <article class="stat">
        <span class="stat__icon stat__icon--green"><?= icon('eye') ?></span>
        <div>
            <p class="stat__label">Visiteurs aujourd'hui</p>
            <p class="stat__value"><?= number_format($summary['visitors_today'], 0, ',', "\u{202F}") ?></p>
            <p class="stat__note">Visites uniques du <?= date('d/m/Y') ?></p>
        </div>
    </article>
    <article class="stat">
        <span class="stat__icon stat__icon--gold"><?= icon('receipt') ?></span>
        <div>
            <p class="stat__label">Reçus du jour</p>
            <p class="stat__value"><?= $summary['invoices_today'] ?></p>
            <p class="stat__note"><a href="invoices.php">Voir les dernières transactions</a></p>
        </div>
    </article>
</section>

<!-- ==================== CARTES STATISTIQUES ==================== -->
<section class="stats" aria-label="Indicateurs clés">
    <article class="stat">
        <span class="stat__icon stat__icon--blue"><?= icon('file') ?></span>
        <div>
            <p class="stat__label">Reçus ce mois</p>
            <p class="stat__value"><?= $stats['invoices_month'] ?></p>
        </div>
    </article>
    <article class="stat">
        <span class="stat__icon stat__icon--gold"><?= icon('clock') ?></span>
        <div>
            <p class="stat__label">En attente de paiement</p>
            <p class="stat__value"><?= $stats['pending'] ?></p>
            <?php if ($stats['overdue']): ?>
                <p class="stat__note stat__note--danger"><?= $stats['overdue'] ?> en retard</p>
            <?php endif; ?>
        </div>
    </article>
    <article class="stat">
        <span class="stat__icon stat__icon--green"><?= icon('wallet') ?></span>
        <div>
            <p class="stat__label">Encaissé ce mois (FCFA)</p>
            <p class="stat__value stat__value--sm"><?= e(money($stats['paid_xaf_month'], 'XAF')) ?></p>
        </div>
    </article>
    <article class="stat">
        <span class="stat__icon stat__icon--slate"><?= icon('printer') ?></span>
        <div>
            <p class="stat__label">Impressions en cours</p>
            <p class="stat__value"><?= $stats['jobs_active'] ?></p>
        </div>
    </article>
</section>

<div class="dash-grid">
    <!-- ==================== GRAPHIQUE ==================== -->
    <section class="panel">
        <header class="panel__head">
            <h2 class="panel__title">Reçus de caisse sur 6 mois</h2>
            <div class="legend">
                <span class="legend__item"><span class="legend__dot legend__dot--total"></span>Émis</span>
                <span class="legend__item"><span class="legend__dot legend__dot--paid"></span>Payés</span>
            </div>
        </header>
        <div class="chart" role="img" aria-label="Nombre de reçus émis et payés par mois sur les 6 derniers mois">
            <?php foreach ($months as $key => $m): ?>
                <div class="chart__col">
                    <div class="chart__bars">
                        <span class="chart__bar chart__bar--total" style="height: <?= round($m['total'] / $maxBar * 100) ?>%"
                              title="<?= $m['total'] ?> émise(s)"></span>
                        <span class="chart__bar chart__bar--paid" style="height: <?= round($m['paid'] / $maxBar * 100) ?>%"
                              title="<?= $m['paid'] ?> payée(s)"></span>
                    </div>
                    <span class="chart__value"><?= $m['total'] ?></span>
                    <span class="chart__label"><?= $monthNames[substr($key, 5, 2)] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ==================== IMPRIMANTES ==================== -->
    <section class="panel">
        <header class="panel__head">
            <h2 class="panel__title">Imprimantes</h2>
            <a href="<?= is_admin() ? 'settings.php#systeme' : 'printing.php?tab=printers' ?>" class="panel__link"><?= is_admin() ? 'Gérer' : 'Voir' ?></a>
        </header>
        <?php if (!$printers): ?>
            <p class="empty">Aucune imprimante configurée.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($printers as $p): ?>
                    <li class="list__item">
                        <span class="dot dot--<?= $p['last_status'] === 'online' ? 'success' : ($p['last_status'] === 'offline' ? 'danger' : 'muted') ?>"></span>
                        <span class="list__main">
                            <strong><?= e($p['printer_name']) ?><?= (int) $p['is_default'] === 1 ? ' · par défaut' : '' ?></strong>
                            <small><?= e($p['printer_location']) ?></small>
                        </span>
                        <small class="list__meta"><?= $p['last_checked_at'] ? 'Testée le ' . e(fdate($p['last_checked_at'], true)) : 'Jamais testée' ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php if (can_access_messages()): ?>
<!-- ==================== MESSAGES DU SITE (aperçu ; gestion complète : messages.php) ==================== -->
<section class="panel" id="messages">
    <header class="panel__head">
        <h2 class="panel__title">Messages du site <span class="badge badge--info" data-unread-badge<?= $stats['unread'] ? '' : ' hidden' ?>><?= $stats['unread'] ?> non lu<?= $stats['unread'] > 1 ? 's' : '' ?></span></h2>
        <a href="messages.php" class="panel__link">Ouvrir la messagerie</a>
    </header>
    <?php if (!$messages): ?>
        <p class="empty">Aucun nouveau message.</p>
    <?php else: ?>
        <ul class="messages">
            <?php foreach ($messages as $m): ?>
                <li class="message message--unread">
                    <a href="messages.php?id=<?= (int) $m['id'] ?>" class="message__head">
                        <span class="message__from"><strong><?= e($m['sender_name']) ?></strong> — <?= e($m['subject']) ?></span>
                        <small class="message__date"><?= e(fdate($m['created_at'], true)) ?></small>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php dash_footer(); ?>
