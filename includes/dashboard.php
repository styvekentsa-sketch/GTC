<?php
/**
 * Gabarit & utilitaires du Dashboard — Global Trading Corporation
 *
 * Usage dans une page de dashboard/ :
 *   require_once __DIR__ . '/../includes/dashboard.php';
 *   $user = require_login();
 *   dash_header('Titre', 'cle-du-menu');
 *   … contenu …
 *   dash_footer();
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/seo.php';      // asset_url() : fichiers CSS / JS versionnés

/* ==========================================================================
   Référentiels
   ========================================================================== */

const DASH_CURRENCIES = [
    'XAF' => ['label' => 'FCFA',            'decimals' => 0],
    'EUR' => ['label' => 'Euro',            'decimals' => 2],
    'USD' => ['label' => 'Dollar US',       'decimals' => 2],
    'CAD' => ['label' => 'Dollar canadien', 'decimals' => 2],
    'NGN' => ['label' => 'Naira',           'decimals' => 2],
    'AED' => ['label' => 'Dirham (Dubaï)',  'decimals' => 2],
    'CNY' => ['label' => 'Yuan',            'decimals' => 2],
];

/** Rôles des collaborateurs (table users). */
const USER_ROLES = [
    'admin'      => 'Administrateur',
    'caissier'   => 'Caissier',
    'agent'      => 'Agent',
    'secretaire' => 'Secrétaire / Accueil',
];

/** Rôles autorisés sur les opérations (tableau de bord, factures, simulateur, impression). */
const OPERATION_ROLES = ['admin', 'caissier', 'agent'];

/** Rôles autorisés sur la messagerie (messages du formulaire de contact). */
const MESSAGE_ROLES = ['admin', 'secretaire'];

/** Le compte connecté peut-il consulter les messages du site ? */
function can_access_messages(): bool
{
    return in_array(current_user()['role'] ?? '', MESSAGE_ROLES, true);
}

/** Statuts d'un message (valeurs de la colonne messages.status). */
const MESSAGE_STATUSES = [
    'unread'   => ['label' => 'Non lu',   'tone' => 'info'],
    'read'     => ['label' => 'Lu',       'tone' => 'muted'],
    'replied'  => ['label' => 'Répondu',  'tone' => 'success'],
    'archived' => ['label' => 'Archivé',  'tone' => 'warning'],
];

function role_label(?string $role): string
{
    return USER_ROLES[$role ?? ''] ?? 'Collaborateur';
}

const INVOICE_STATUSES = [
    'draft'     => ['label' => 'Brouillon',  'tone' => 'muted'],
    'pending'   => ['label' => 'En attente', 'tone' => 'warning'],
    'paid'      => ['label' => 'Payée',      'tone' => 'success'],
    'cancelled' => ['label' => 'Annulée',    'tone' => 'danger'],
];

/** Types d'opération (valeurs de la colonne invoices.operation_type). */
const OPERATION_TYPES = [
    'Dépôt'         => 'Dépôt',
    'Retrait'       => 'Retrait',
    'Exchange'      => 'Change / Exchange',
    'Transfert'     => 'Transfert de fonds',
    'Import/Export' => 'Import/Export',
];

/**
 * Texte de la ligne « Motif » d'une facture : motif et/ou nom de la personne qui effectue l'opération.
 * Les factures plus anciennes qui avaient un « Émetteur » séparé le reprennent ici.
 */
function invoice_reason_text(array $inv): string
{
    $reason = trim((string) ($inv['transaction_reason'] ?? ''));
    $sender = trim((string) ($inv['sender_name'] ?? ''));
    if ($reason !== '' && $sender !== '') {
        return $reason . ' (' . $sender . ')';
    }
    return $reason !== '' ? $reason : ($sender !== '' ? $sender : 'Non spécifié');
}

/** Modes de paiement (valeurs de la colonne invoices.payment_method). */
const PAYMENT_METHODS = [
    'Espèces'      => 'Espèces',
    'Orange Money' => 'Orange Money',
    'Mobile Money' => 'Mobile Money',
    'Virement'     => 'Virement',
];

/** Sens de saisie du taux. */
const RATE_DIRECTIONS = ['multiply', 'divide'];

/** Libellé d'affichage d'une devise (XAF → FCFA). */
function currency_label(string $code): string
{
    return $code === 'XAF' ? 'FCFA' : $code;
}

/**
 * Montant à recevoir.
 *   multiply : taux saisi « 1 devise source = X devise cible »  → montant × taux
 *   divide   : taux saisi « 1 devise cible = X devise source »  → montant ÷ taux
 * Arrondi selon la devise cible (0 décimale pour le FCFA).
 */
function compute_received(float $given, float $rate, string $direction, string $target): float
{
    $decimals = DASH_CURRENCIES[$target]['decimals'] ?? 2;
    $value    = $direction === 'divide' ? $given / $rate : $given * $rate;
    return round($value, $decimals);
}

/** Taux lisible : « 1 EUR = 655,957 FCFA » (selon le sens de saisie). */
function rate_text(array $inv): string
{
    $src = currency_label($inv['source_currency']);
    $tgt = currency_label($inv['target_currency']);
    $rate = rate_format($inv['exchange_rate']);
    return ($inv['rate_direction'] ?? 'multiply') === 'divide'
        ? "1 {$tgt} = {$rate} {$src}"
        : "1 {$src} = {$rate} {$tgt}";
}

/** Taux formaté : jusqu'à 4 décimales, sans zéros inutiles (655,957 / 0,9215). */
function rate_format(float|string|null $rate): string
{
    $formatted = rtrim(rtrim(number_format((float) $rate, 4, ',', "\u{202F}"), '0'), ',');
    return $formatted === '' ? '0' : $formatted;
}

const JOB_STATUSES = [
    'queued'    => ['label' => 'En file',  'tone' => 'muted'],
    'printing'  => ['label' => 'En cours', 'tone' => 'info'],
    'done'      => ['label' => 'Terminé',  'tone' => 'success'],
    'failed'    => ['label' => 'Échec',    'tone' => 'danger'],
    'cancelled' => ['label' => 'Annulé',   'tone' => 'muted'],
];

const JOB_PRIORITIES = [
    'low'    => 'Basse',
    'normal' => 'Normale',
    'high'   => 'Urgente',
];

/* ==========================================================================
   Formatage
   ========================================================================== */

/** Montant formaté à la française : 1 250 000 FCFA / 1 250,50 EUR */
function money(float|string|null $amount, string $currency = 'XAF'): string
{
    $decimals = DASH_CURRENCIES[$currency]['decimals'] ?? 2;
    $label    = $currency === 'XAF' ? 'FCFA' : $currency;
    return number_format((float) $amount, $decimals, ',', "\u{202F}") . "\u{00A0}" . $label;
}

/** Date SQL → JJ/MM/AAAA (avec heure si demandé). */
function fdate(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts) : '—';
}

/**
 * Bouton « Supprimer » (réservé aux administrateurs : rien n'est affiché pour les autres rôles).
 * Formulaire POST avec jeton CSRF et confirmation obligatoire (data-confirm, assets/js/dashboard.js) ;
 * « back » ramène, après suppression, à la page d'origine (liste filtrée, page en cours).
 * $label vide : icône seule (tableaux) ; sinon bouton rouge avec texte (pages de détail).
 */
function delete_button(int $id, string $confirm, string $label = ''): string
{
    if (!is_admin()) {
        return '';
    }
    $back = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) . (($q = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY)) ? '?' . $q : '');
    $button = $label === ''
        ? '<button type="submit" class="icon-btn icon-btn--danger" title="Supprimer" data-confirm="' . e($confirm) . '">' . icon('trash') . '<span class="visually-hidden">Supprimer</span></button>'
        : '<button type="submit" class="btn btn--danger btn--sm" data-confirm="' . e($confirm) . '">' . icon('trash') . ' ' . e($label) . '</button>';
    return '<form method="post" class="inline-form">' . csrf_field()
        . '<input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">' . $button . '</form>';
}

/** Adresse de la page en cours (fichier + paramètres), pour revenir au même endroit après une action. */
function current_back(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $q   = parse_url($uri, PHP_URL_QUERY);
    return basename((string) parse_url($uri, PHP_URL_PATH)) . ($q ? '?' . $q : '');
}

/**
 * Menu d'actions d'une ligne de tableau (bouton « ⋮ ») — assets/js/dashboard.js (§ Menus d'actions) l'ouvre au-dessus
 * de la page (jamais coupé par un tableau à défilement), le ferme au clic ailleurs, à Échap ou au défilement.
 * Sans JavaScript, l'élément <details> natif l'ouvre et le ferme quand même.
 *
 * Éléments :
 *   ['link', 'Libellé', 'url', 'icône', ['target' => '_blank']]
 *   ['post', 'Libellé', 'icône', ['op' => 'delete', 'id' => 3], 'confirmation ou null', 'danger' => bool, 'action' => 'page.php']
 *   ['sub',  'Libellé', 'icône', '<html du sous-panneau>']          sous-panneau dépliable (formulaire, choix…)
 *   ['off',  'Libellé', 'icône', 'raison affichée']                 action indisponible, expliquée
 *   ['sep']                                                        séparateur
 */
function action_menu(array $items, string $label): string
{
    $html = '';
    foreach ($items as $it) {
        $kind = $it[0];
        if ($kind === 'sep') {
            $html .= '<hr class="amenu__sep">';
        } elseif ($kind === 'link') {
            $attrs = '';
            foreach (($it[4] ?? []) as $k => $v) {
                $attrs .= ' ' . e($k) . '="' . e($v) . '"';
            }
            if (($it[4]['target'] ?? '') === '_blank') {
                $attrs .= ' rel="noopener"';
            }
            $html .= '<a href="' . e($it[2]) . '" class="amenu__item" role="menuitem"' . $attrs . '>' . icon($it[3]) . '<span>' . e($it[1]) . '</span></a>';
        } elseif ($kind === 'post') {
            $fields = '';
            foreach ($it[3] as $k => $v) {
                $fields .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
            }
            $danger = !empty($it['danger']);
            $html .= '<form method="post"' . (isset($it['action']) ? ' action="' . e($it['action']) . '"' : '') . ' class="amenu__form">' . csrf_field() . $fields
                . '<button type="submit" class="amenu__item' . ($danger ? ' amenu__item--danger' : '') . '" role="menuitem"'
                . (!empty($it[4]) ? ' data-confirm="' . e($it[4]) . '"' : '') . '>' . icon($it[2]) . '<span>' . e($it[1]) . '</span></button></form>';
        } elseif ($kind === 'sub') {
            $html .= '<details class="amenu__sub"><summary class="amenu__item" role="menuitem">' . icon($it[2]) . '<span>' . e($it[1]) . '</span>'
                . icon('arrow') . '</summary><div class="amenu__subpanel">' . $it[3] . '</div></details>';
        } elseif ($kind === 'off') {
            $html .= '<span class="amenu__item amenu__item--off" role="menuitem" aria-disabled="true">' . icon($it[2])
                . '<span>' . e($it[1]) . '<small>' . e($it[3]) . '</small></span></span>';
        }
    }
    return '<details class="amenu" data-amenu><summary class="icon-btn amenu__toggle" title="Actions" aria-label="' . e($label) . '" aria-haspopup="menu">'
        . icon('more') . '</summary><div class="amenu__panel" role="menu" aria-label="' . e($label) . '">' . $html . '</div></details>';
}

/** Élément « Supprimer » d'un menu d'actions (administrateurs uniquement : rien pour les autres rôles). */
function delete_menu_item(int $id, string $confirm, string $label = 'Supprimer'): array
{
    return is_admin() ? ['post', $label, 'trash', ['op' => 'delete', 'id' => $id, 'back' => current_back()], $confirm, 'danger' => true] : [];
}

/** Page de retour sûre : uniquement $page (avec ses paramètres), jamais une autre page ni un autre site. */
function safe_back(string $back, string $page, string $default): string
{
    return preg_match('/^' . preg_quote($page, '/') . '(\?[a-z0-9_=&%+.,:-]*)?$/i', $back) ? $back : $default;
}

/** Pastille de statut. */
function badge(string $key, array $map): string
{
    $item = $map[$key] ?? ['label' => $key, 'tone' => 'muted'];
    return '<span class="badge badge--' . e($item['tone']) . '">' . e($item['label']) . '</span>';
}

/** Valeur POST nettoyée (UTF-8 garanti : une saisie Windows-1252 est convertie). */
function post(string $key, string $default = ''): string
{
    $value = trim((string) ($_POST[$key] ?? $default));
    return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
}

/** Valeur GET nettoyée. */
function query(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

/* ==========================================================================
   Gabarit
   ========================================================================== */

/**
 * Identité de la session affichée (compte + éventuelle prise de contrôle), comparée entre onglets
 * par assets/js/dashboard.js : un onglet resté ouvert sur une autre identité est averti.
 */
function session_identity(): string
{
    if (empty($_SESSION['user_id'])) {
        return '';
    }
    return 'u' . (int) $_SESSION['user_id'] . (impersonation_active() ? '-par-' . (int) $_SESSION['original_admin_id'] : '');
}

/**
 * Modules accessibles au compte connecté — source unique du menu latéral et du Hub d'accueil (dashboard/index.php).
 * label : libellé court du menu ; title / desc : bouton du Hub ; tone : couleur de la charte (blue, green, magenta).
 * La secrétaire / l'accueil ne voit que la messagerie (et ses paramètres).
 */
function dash_modules(): array
{
    $user    = current_user();
    $modules = [];
    if (in_array($user['role'] ?? '', OPERATION_ROLES, true)) {
        $modules = [
            'invoices'   => ['href' => 'invoices.php',      'icon' => 'receipt',  'label' => 'Reçus de caisse',   'title' => 'Reçus de caisse',            'desc' => 'Émettre et imprimer un reçu',      'tone' => 'blue'],
            'closing'    => ['href' => 'cash-closing.php',  'icon' => 'wallet',   'label' => 'Clôture de caisse', 'title' => 'Clôture de caisse',          'desc' => 'Totaux du jour par devise et paiement', 'tone' => 'magenta'],
            'commercial' => ['href' => 'invoices-list.php', 'icon' => 'file',     'label' => 'Factures',          'title' => 'Factures',                   'desc' => 'Proformas et factures commerciales', 'tone' => 'green'],
            'courriers'  => ['href' => 'courriers.php',     'icon' => 'letter',   'label' => 'Courriers',         'title' => 'Courriers & Correspondances', 'desc' => 'Lettres sur papier à en-tête',     'tone' => 'magenta'],
            'simulator'  => ['href' => 'simulator.php',     'icon' => 'calc',     'label' => 'Simulateur Avancé', 'title' => 'Simulateur Avancé',          'desc' => 'Calculer une opération de change', 'tone' => 'blue'],
            'rates'      => ['href' => 'rates.php',         'icon' => 'exchange', 'label' => 'Taux de change',    'title' => 'Taux de change',             'desc' => 'Grille des devises du jour',       'tone' => 'green'],
            'printing'   => ['href' => 'printing.php',      'icon' => 'printer',  'label' => 'Impression réseau', 'title' => 'Impression réseau',          'desc' => 'Imprimantes et file d\'attente',   'tone' => 'magenta'],
        ];
    }
    if (can_access_messages()) {
        $modules['messages'] = ['href' => 'messages.php', 'icon' => 'mail', 'label' => 'Messages', 'title' => 'Messages', 'desc' => 'Demandes reçues par le site', 'tone' => 'blue'];
    }
    if (is_admin()) {
        $modules['team']     = ['href' => 'team.php',     'icon' => 'users',  'label' => 'Équipe',       'title' => 'Gestion d\'équipe', 'desc' => 'Membres affichés sur le site',  'tone' => 'green'];
        $modules['partners'] = ['href' => 'partners.php', 'icon' => 'hand',   'label' => 'Partenaires',  'title' => 'Partenaires',       'desc' => 'Logos des partenaires du site', 'tone' => 'magenta'];
        $modules['users']    = ['href' => 'users.php',    'icon' => 'shield', 'label' => 'Utilisateurs', 'title' => 'Utilisateurs',      'desc' => 'Comptes et droits d\'accès',    'tone' => 'blue'];
        $modules['activity'] = ['href' => 'activity.php', 'icon' => 'history', 'label' => "Journal d'activité", 'title' => "Journal d'activité", 'desc' => 'Qui a fait quoi, et quand', 'tone' => 'green'];
    }
    return $modules;
}

function dash_header(string $title, string $active = ''): void
{
    $user = current_user();
    $unread = can_access_messages() ? (int) db_value("SELECT COUNT(*) FROM messages WHERE status = 'unread'") : 0;
    // Actualisation automatique de la messagerie (assets/js/messages-live.js) : dernier message connu au chargement
    $liveAttrs = can_access_messages()
        ? ' data-messages-api="messages-api.php" data-messages-last="' . (int) db_value('SELECT COALESCE(MAX(id), 0) FROM messages') . '"'
        : '';

    // Hub d'accueil (dashboard/index.php) : pas de barre latérale, toute la largeur pour la grille des modules
    $hub = $active === 'index';
    $hasHub = in_array($user['role'] ?? '', OPERATION_ROLES, true);
    $menu = ($hasHub ? ['index' => ['href' => 'index.php', 'icon' => 'grid', 'label' => 'Accueil (Hub)']] : []) + dash_modules();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> | Dashboard GTC</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="../assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
    <!-- Application installable « GTC Dashboard » (dashboard/manifest.webmanifest, assets/js/pwa-install.js) -->
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#1F2630">
    <meta name="application-name" content="GTC Dashboard">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="GTC Dashboard">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/custom-select.css', '../')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/dashboard.css', '../')) ?>">
</head>
<body class="dash<?= $hub ? ' dash--hub' : '' ?>" data-session="<?= e(session_identity()) ?>" data-session-name="<?= e($user['name'] ?? '') ?>"<?= $liveAttrs ?>>

<?php if (!$hub): ?>
<aside class="sidebar" id="sidebar" aria-label="Menu du dashboard">
    <div class="sidebar__brand">
        <span class="sidebar__logo"><img src="<?= e(asset_url('assets/img/logo-gtc-light.png', '../')) ?>" alt="<?= e(GTC_NAME) ?>" width="166" height="41"></span>
        <span class="sidebar__name"><small>Dashboard</small></span>
    </div>

    <nav class="sidebar__nav">
        <?php foreach ($menu as $key => $item): ?>
            <a href="<?= e($item['href']) ?>" class="sidebar__link<?= $key === $active ? ' is-active' : '' ?>"
               <?= $key === $active ? 'aria-current="page"' : '' ?>>
                <?= icon($item['icon']) ?>
                <span><?= e($item['label']) ?></span>
                <?php if ($key === 'messages' && $unread > 0): ?>
                    <span class="sidebar__count" data-unread-count title="<?= $unread ?> message<?= $unread > 1 ? 's' : '' ?> non lu<?= $unread > 1 ? 's' : '' ?>"><?= $unread ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar__footer">
        <button type="button" class="sidebar__link sidebar__link--btn sidebar__link--install" data-pwa-install hidden>
            <?= icon('download') ?><span>Installer l'application</span>
        </button>
        <a href="../index.php" class="sidebar__link" target="_blank" rel="noopener">
            <?= icon('external') ?><span>Voir le site</span>
        </a>
        <a href="settings.php" class="sidebar__link<?= in_array($active, ['settings', 'password'], true) ? ' is-active' : '' ?>"
           <?= in_array($active, ['settings', 'password'], true) ? 'aria-current="page"' : '' ?>>
            <?= icon('settings') ?><span>Paramètres</span>
        </a>
        <form action="logout.php" method="post">
            <?= csrf_field() ?>
            <button type="submit" class="sidebar__link sidebar__link--btn">
                <?= icon('logout') ?><span>Déconnexion</span>
            </button>
        </form>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebar-backdrop" hidden></div>
<?php endif; ?>

<div class="dash-main">
    <?php if (impersonation_active()): ?>
        <!-- Prise de contrôle de compte en cours (dashboard/switch-user.php) -->
        <div class="impersonation-bar" role="alert">
            <p class="impersonation-bar__text">
                <?= icon('alert') ?>
                Vous êtes actuellement connecté en tant que <strong><?= e($user['name'] ?? '') ?></strong>
                (<?= e(role_label($user['role'] ?? null)) ?>) — <em>Prise de contrôle Admin</em>.
            </p>
            <form action="switch-user.php" method="post" class="impersonation-bar__form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="stop">
                <button type="submit" class="impersonation-bar__btn"><?= icon('arrow-left') ?> Revenir au compte Administrateur</button>
            </form>
        </div>
    <?php endif; ?>
    <header class="topbar">
        <?php if ($hub): ?>
            <a href="index.php" class="topbar__brand" aria-label="<?= e(GTC_NAME) ?> — Accueil du Dashboard">
                <img src="<?= e(asset_url('assets/img/logo-gtc.png', '../')) ?>" alt="" width="166" height="41">
            </a>
        <?php else: ?>
            <button class="topbar__menu" id="sidebar-toggle" type="button" aria-label="Ouvrir le menu"
                    aria-controls="sidebar" aria-expanded="false">
                <?= icon('menu') ?>
            </button>
            <?php if ($hasHub): ?>
                <a href="index.php" class="topbar__hub" title="Retour au Hub : tous les modules"><?= icon('arrow-left') ?><span>Hub</span></a>
            <?php endif; ?>
        <?php endif; ?>
        <h1 class="topbar__title"><?= e($title) ?></h1>
        <div class="topbar__user">
            <span class="topbar__avatar" aria-hidden="true"><?= e(initials($user['name'] ?? '?')) ?></span>
            <span class="topbar__meta">
                <strong><?= e($user['name'] ?? '') ?></strong>
                <small><?= e(role_label($user['role'] ?? null)) ?></small>
            </span>
        </div>
    </header>

    <main class="dash-content" id="contenu">
        <?php foreach (flash_pull() as $f): ?>
            <div class="alert alert--<?= e($f['type']) ?>" role="status" data-autohide>
                <?= icon($f['type'] === 'success' ? 'check' : 'alert') ?>
                <p><?= e($f['message']) ?></p>
            </div>
        <?php endforeach; ?>
    <?php
}

function dash_footer(): void
{
    ?>
    </main>
    <footer class="dash-footer">© <?= date('Y') ?> <?= e(GTC_NAME) ?> — Espace de gestion interne</footer>
</div>

<script src="<?= e(asset_url('assets/js/custom-select.js', '../')) ?>" defer></script>
<script src="<?= e(asset_url('assets/js/dashboard.js', '../')) ?>" defer></script>
<script src="<?= e(asset_url('assets/js/pwa-install.js', '../')) ?>" defer></script>
<?php if (can_access_messages()): ?>
<script src="<?= e(asset_url('assets/js/messages-live.js', '../')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
    <?php
}

/** Gabarit des pages publiques du Dashboard (connexion, installation). */
function auth_layout_open(string $title, string $subtitle): void
{
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> | Dashboard GTC</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="../assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
    <!-- Application installable « GTC Dashboard » (dashboard/manifest.webmanifest, assets/js/pwa-install.js) -->
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#1F2630">
    <meta name="application-name" content="GTC Dashboard">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="GTC Dashboard">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/custom-select.css', '../')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/dashboard.css', '../')) ?>">
</head>
<body class="auth" data-session="">
<main class="auth-card">
    <div class="auth-card__brand">
        <span class="sidebar__logo auth-card__logo"><img src="<?= e(asset_url('assets/img/logo-gtc.png', '../')) ?>" alt="<?= e(GTC_NAME) ?>" width="218" height="54"></span>
    </div>
    <h1 class="auth-card__title"><?= e($title) ?></h1>
    <p class="auth-card__subtitle"><?= e($subtitle) ?></p>
    <?php foreach (flash_pull() as $f): ?>
        <div class="alert alert--<?= e($f['type']) ?>" role="status">
            <?= icon($f['type'] === 'success' ? 'check' : 'alert') ?>
            <p><?= e($f['message']) ?></p>
        </div>
    <?php endforeach; ?>
    <?php
}

function auth_layout_close(): void
{
    ?>
    <p class="auth-card__install" data-pwa-install hidden>
        <button type="button" class="btn btn--ghost btn--block"><?= icon('download') ?> Installer l'application GTC Dashboard</button>
    </p>
    <p class="auth-card__back"><a href="../index.php"><?= icon('arrow-left') ?> Retour au site</a></p>
</main>
<script src="<?= e(asset_url('assets/js/custom-select.js', '../')) ?>" defer></script>
<script src="<?= e(asset_url('assets/js/dashboard.js', '../')) ?>" defer></script>
<script src="<?= e(asset_url('assets/js/pwa-install.js', '../')) ?>" defer></script>
<?php if (can_access_messages()): ?>
<script src="<?= e(asset_url('assets/js/messages-live.js', '../')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
    <?php
}

/** Pagination simple. */
function pagination(int $total, int $page, int $perPage, array $params = []): string
{
    $pages = (int) ceil($total / $perPage);
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="Pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        $url = '?' . http_build_query(array_merge($params, ['page' => $i]));
        $html .= $i === $page
            ? '<span class="pagination__item is-active" aria-current="page">' . $i . '</span>'
            : '<a class="pagination__item" href="' . e($url) . '">' . $i . '</a>';
    }
    return $html . '</nav>';
}
