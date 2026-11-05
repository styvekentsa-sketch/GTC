<?php
/**
 * Avis clients — règles communes à la page publique (avis.php), à l'API (api-reviews.php) et à la modération
 * (dashboard/reviews.php). Table : includes/db.php → db_install_reviews().
 *
 * Dépôt : nom ou pseudo (2 à 60 caractères), note de 1 à 5, commentaire (10 à 1 000 caractères, texte simple).
 * Tout nouvel avis est « en attente » : il n'est publié qu'après validation dans le Dashboard (activité financière :
 * pas de contenu publié sans relecture). Protections : jeton CSRF de la session, champ piège anti-robot, délai
 * minimal de remplissage, 3 avis au plus par adresse IP sur 24 heures, liens refusés dans le commentaire.
 */

const REVIEW_STATUSES = [
    'pending'  => ['label' => 'En attente', 'tone' => 'warning'],
    'approved' => ['label' => 'Publié',     'tone' => 'success'],
    'rejected' => ['label' => 'Refusé',     'tone' => 'muted'],
];
const REVIEW_NAME_MIN = 2;
const REVIEW_NAME_MAX = 60;
const REVIEW_TEXT_MIN = 10;
const REVIEW_TEXT_MAX = 1000;
const REVIEW_MAX_PER_IP = 3;      // avis par adresse IP…
const REVIEW_WINDOW_HOURS = 24;   // …sur cette durée
const REVIEW_MIN_SECONDS = 3;     // délai minimal entre l'affichage du formulaire et l'envoi (robots)
const REVIEW_PAGE_SIZE = 9;       // avis affichés par page

/** Texte saisi nettoyé : UTF-8, sans balises, espaces normalisés (sauts de ligne conservés, 2 au plus). */
function review_clean(string $s, bool $multiline = false): string
{
    $s = mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    $s = strip_tags($s);
    $s = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    if (!$multiline) {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
    $s = preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $s));
    return trim(preg_replace("/\n{3,}/", "\n\n", $s));
}

/**
 * Valide un dépôt. Retourne [données, erreurs (champ => message), erreur globale|null].
 * $session : $_SESSION du visiteur (jeton CSRF, heure d'affichage du formulaire).
 */
function review_validate(array $in, array $session): array
{
    $data = [
        'author_name' => review_clean((string) ($in['author_name'] ?? '')),
        'rating'      => (int) ($in['rating'] ?? 0),
        'comment'     => review_clean((string) ($in['comment'] ?? ''), true),
    ];
    $errors = [];
    $nameLen = mb_strlen($data['author_name']);
    if ($nameLen < REVIEW_NAME_MIN || $nameLen > REVIEW_NAME_MAX) {
        $errors['author_name'] = 'Indiquez votre nom ou un pseudo (' . REVIEW_NAME_MIN . ' à ' . REVIEW_NAME_MAX . ' caractères).';
    }
    if ($data['rating'] < 1 || $data['rating'] > 5) {
        $errors['rating'] = 'Choisissez une note de 1 à 5 étoiles.';
    }
    $textLen = mb_strlen($data['comment']);
    if ($textLen < REVIEW_TEXT_MIN) {
        $errors['comment'] = 'Votre avis est trop court (' . REVIEW_TEXT_MIN . ' caractères au moins).';
    } elseif ($textLen > REVIEW_TEXT_MAX) {
        $errors['comment'] = 'Votre avis est trop long (' . REVIEW_TEXT_MAX . ' caractères au plus).';
    } elseif (preg_match('#(https?://|www\.|\[url|<a\s)#i', $data['comment'])) {
        $errors['comment'] = 'Les liens ne sont pas acceptés dans un avis.';
    }

    $global = null;
    $token = (string) ($in['csrf_token'] ?? '');
    if (empty($session['csrf_token']) || !hash_equals((string) $session['csrf_token'], $token)) {
        $global = 'Votre session a expiré : rechargez la page puis renvoyez votre avis.';
    }
    return [$data, $errors, $global];
}

/** Envoi automatisé ? (champ piège rempli ou formulaire envoyé trop vite) — le robot reçoit un faux succès. */
function review_is_bot(array $in, array $session): bool
{
    $shown = (int) ($session['review_form_at'] ?? 0);
    return trim((string) ($in['gtc_hp_rv'] ?? '')) !== '' || ($shown > 0 && time() - $shown < REVIEW_MIN_SECONDS);
}

/** Trop d'avis récents depuis cette adresse IP ? */
function review_throttled(string $ip): bool
{
    $since = (new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->modify('-' . REVIEW_WINDOW_HOURS . ' hours')->format('Y-m-d H:i:s');
    return (int) db_value('SELECT COUNT(*) FROM reviews WHERE ip = ? AND created_at >= ?', [$ip, $since]) >= REVIEW_MAX_PER_IP;
}

/** Enregistre un avis « en attente ». Retourne son identifiant. */
function review_create(array $data, string $ip): int
{
    db_query('INSERT INTO reviews (author_name, rating, comment, status, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$data['author_name'], $data['rating'], $data['comment'], 'pending', $ip, db_now()]);
    return (int) db()->lastInsertId();
}

/** Statistiques des avis publiés : ['count' => n, 'average' => 4.6, 'dist' => [5 => n, 4 => n, …]]. */
function review_stats(): array
{
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach (db_all("SELECT rating, COUNT(*) AS n FROM reviews WHERE status = 'approved' GROUP BY rating") as $r) {
        if (isset($dist[(int) $r['rating']])) {
            $dist[(int) $r['rating']] = (int) $r['n'];
        }
    }
    $count = array_sum($dist);
    $sum = 0;
    foreach ($dist as $stars => $n) {
        $sum += $stars * $n;
    }
    return ['count' => $count, 'average' => $count ? round($sum / $count, 1) : 0.0, 'dist' => $dist];
}

/**
 * Avis publiés, du plus récent au plus ancien : page n° $page (à partir de 1) de REVIEW_PAGE_SIZE avis.
 * $extra : avis supplémentaires lus après la page (1 = savoir s'il existe une page suivante).
 */
function reviews_approved(int $page = 1, int $extra = 0): array
{
    $offset = max(0, ($page - 1) * REVIEW_PAGE_SIZE);
    $size   = REVIEW_PAGE_SIZE + max(0, $extra);
    return db_all("SELECT id, author_name, rating, comment, created_at FROM reviews WHERE status = 'approved'
                   ORDER BY created_at DESC, id DESC LIMIT {$size} OFFSET {$offset}");
}

/** Données publiques d'un avis (API, affichage) : jamais l'adresse IP ni la modération. */
function review_public(array $r): array
{
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $ts = strtotime((string) $r['created_at']);
    return [
        'id'      => (int) $r['id'],
        'name'    => (string) $r['author_name'],
        'rating'  => (int) $r['rating'],
        'comment' => (string) $r['comment'],
        'date'    => $ts ? $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts) : '',
    ];
}

/** Étoiles en SVG (affichage) : $value peut être décimal (moyenne 4,6 → 4 pleines + 1 partielle). */
function review_stars(float $value, string $class = 'stars'): string
{
    $html = '<span class="' . e($class) . '" role="img" aria-label="' . e(str_replace('.', ',', (string) round($value, 1))) . ' sur 5">';
    for ($i = 1; $i <= 5; $i++) {
        $fill = max(0, min(1, $value - ($i - 1)));
        // Étoile dessinée par un masque CSS (.star) : couleur dorée jusqu'à --fill, grise au-delà
        $html .= '<span class="star" style="--fill:' . round($fill * 100) . '%" aria-hidden="true"></span>';
    }
    return $html . '</span>';
}

/* ==========================================================================
   Invitations à donner son avis + visite guidée du site
   Dashboard (dashboard/reviews.php) : création d'un lien personnel (nom du client facultatif) ou lien général,
   envoi par WhatsApp, Gmail, e-mail, SMS, Telegram… Le client ouvre mon-avis.php?t=… : une visite guidée
   (includes/review-tour.php, bandeau en bas des pages) le conduit de page en page jusqu'au formulaire d'avis
   (avis.php), où son nom est déjà saisi. L'avis déposé est rattaché à l'invitation (suivi : ouverte, avis reçu).
   ========================================================================== */

/** Étapes de la visite guidée : page => [libellé court, titre, texte]. La dernière est le formulaire d'avis. */
const REVIEW_TOUR = [
    'index.php'    => ['Accueil',      'Bienvenue chez GTC',  'Découvrez en un coup d\'œil qui nous sommes et ce que nous faisons pour vous.'],
    'services.php' => ['Services',     'Nos services',        'Change de devises, transferts, import-export, fret et transit : nos métiers en détail.'],
    'taux.php'     => ['Taux du jour', 'Les taux du jour',    'Nos taux de change, annoncés clairement et mis à jour chaque jour.'],
    'a-propos.php' => ['À propos',     'Qui sommes-nous ?',   'Notre histoire et l\'équipe qui traite vos opérations à Douala.'],
    'avis.php'     => ['Votre avis',   'Votre avis compte',   'Dernière étape : dites-nous ce que vous pensez de GTC et de notre site.'],
];

/** Objet de l'e-mail d'invitation. */
const REVIEW_INVITE_SUBJECT = 'Votre avis compte pour Global Trading Corporation';

/** Lien public d'une invitation (adresse du site en ligne). Sans jeton : lien général, sans suivi individuel. */
function review_invite_link(?string $token = null): string
{
    return rtrim(GTC_SITE_URL, '/') . '/mon-avis' . ($token ? '?t=' . rawurlencode($token) : '');
}

/** Jeton aléatoire, impossible à deviner (12 caractères, sans caractères ambigus). */
function review_invite_token(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $token = '';
    for ($i = 0; $i < 12; $i++) {
        $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $token;
}

/** Crée une invitation. Retourne son identifiant. */
function review_invite_create(string $name, string $phone, string $email, array $user): int
{
    do {
        $token = review_invite_token();
    } while (db_value('SELECT COUNT(*) FROM review_invites WHERE token = ?', [$token]));
    db_query('INSERT INTO review_invites (token, client_name, client_phone, client_email, created_by, created_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$token, $name !== '' ? $name : null, $phone !== '' ? $phone : null, $email !== '' ? $email : null, (int) $user['id'], (string) $user['name'], db_now()]);
    return (int) db()->lastInsertId();
}

/** Invitation correspondant à un jeton (ou null). */
function review_invite_by_token(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{8,24}$/', $token)) {
        return null;
    }
    return db_one('SELECT * FROM review_invites WHERE token = ?', [$token]) ?: null;
}

/** Statut d'une invitation pour l'affichage : [clé, libellé, ton]. */
function review_invite_status(array $inv): array
{
    if ($inv['review_id']) {
        return ['reviewed', 'Avis reçu', 'success'];
    }
    if ($inv['opened_at']) {
        $n = (int) $inv['open_count'];
        return ['opened', 'Lien ouvert' . ($n > 1 ? ' (' . $n . ' fois)' : ''), 'info'];
    }
    return ['sent', 'Pas encore ouvert', 'muted'];
}

/**
 * Visite automatique d'un lien (aperçu de WhatsApp, Facebook, Telegram…, robot d'indexation, préchargement du
 * navigateur) : elle ne compte pas comme une ouverture par le client. Ces applications visitent le lien dès
 * qu'il est envoyé pour fabriquer l'aperçu ; sans ce filtre, une invitation paraîtrait « ouverte » à tort.
 */
function review_is_automated_visit(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return true;   // HEAD : vérification du lien, pas une visite
    }
    $purpose = strtolower(($_SERVER['HTTP_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_X_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_X_MOZ'] ?? ''));
    if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'preview') || str_contains($purpose, 'prerender')) {
        return true;
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (trim($ua) === '') {
        return true;
    }
    return (bool) preg_match('/WhatsApp\/|facebookexternalhit|Facebot|meta-externalagent|TelegramBot|Twitterbot|Slackbot|LinkedInBot|Discordbot|SkypeUriPreview'
        . '|Googlebot|Google-InspectionTool|bingbot|Applebot|YandexBot|DuckDuckBot|Pinterestbot|redditbot|Embedly|vkShare|BingPreview|HeadlessChrome'
        . '|\b(bot|crawler|spider)\b/i', $ua);
}

/** Cellule « Suivi » d'une invitation (Dashboard, mise à jour en direct par dashboard/reviews-api.php). */
function review_invite_status_html(array $inv): string
{
    [$key, $label, $tone] = review_invite_status($inv);
    $sub = '';
    if ($key === 'reviewed') {
        $sub = 'le ' . fdate($inv['reviewed_at'], true);
    } elseif ($key === 'opened') {
        $sub = ((int) $inv['open_count'] > 1 ? 'dernière ouverture le ' : 'le ') . fdate($inv['last_opened_at'] ?: $inv['opened_at'], true);
    }
    return '<span class="cell-stack"><span class="badge badge--' . e($tone) . '">' . e($label) . '</span>'
        . ($sub !== '' ? '<small class="cell-sub">' . e($sub) . '</small>' : '') . '</span>';
}

/** Cellule « Avis » d'une invitation : note et début du texte, ou « — ». */
function review_invite_review_html(array $inv): string
{
    if (!$inv['review_id'] || $inv['rating'] === null) {
        return '—';
    }
    return '<span class="cell-stack">' . review_stars((float) $inv['rating'], 'stars stars--sm')
        . '<small class="cell-sub">' . e(mb_strimwidth((string) $inv['comment'], 0, 70, '…')) . ' · ' . e(REVIEW_STATUSES[$inv['review_status']]['label'] ?? '') . '</small></span>';
}

/** Requête des invitations avec leur avis éventuel (liste du Dashboard et suivi en direct). */
const REVIEW_INVITES_SQL = 'SELECT i.*, r.rating, r.comment, r.status AS review_status FROM review_invites i LEFT JOIN reviews r ON r.id = i.review_id';

/** Compteurs du suivi : invitations, liens ouverts, avis reçus. */
function review_invite_counts(): array
{
    return [
        'total'    => (int) db_value('SELECT COUNT(*) FROM review_invites'),
        'opened'   => (int) db_value('SELECT COUNT(*) FROM review_invites WHERE opened_at IS NOT NULL'),
        'answered' => (int) db_value('SELECT COUNT(*) FROM review_invites WHERE review_id IS NOT NULL'),
    ];
}

/** Message d'invitation proposé (modifiable avant l'envoi). */
function review_invite_message(?string $name, string $link): string
{
    $hello = $name ? 'Bonjour ' . $name . ',' : 'Bonjour,';
    return $hello . "\n\n"
        . 'Merci pour votre confiance envers ' . GTC_NAME . ".\n"
        . 'Nous vous invitons à découvrir notre site en quelques clics, puis à nous laisser votre avis : '
        . "cela ne prend qu'une minute et nous aide à mieux vous servir.\n\n"
        . $link . "\n\n"
        . "L'équipe GTC — Douala";
}

/** Numéro au format international pour wa.me / sms (chiffres seuls, indicatif du Cameroun ajouté si absent). */
function review_phone_intl(?string $phone): string
{
    $raw = trim((string) $phone);
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($raw, '00')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 9 && !str_starts_with($raw, '+')) {
        $digits = '237' . $digits;   // numéro camerounais saisi sans indicatif
    }
    return $digits;
}

/**
 * Liens d'envoi d'une invitation : canal => [libellé, icône, adresse].
 * Recalculés en direct par assets/js/dashboard.js (§ 11) quand le message est modifié.
 */
function review_share_links(string $message, string $link, string $phone = '', string $email = ''): array
{
    $intl = review_phone_intl($phone);
    $short = trim(str_replace($link, '', $message));   // Telegram ajoute le lien lui-même
    return [
        'whatsapp' => ['WhatsApp', 'whatsapp', 'https://wa.me/' . $intl . '?text=' . rawurlencode($message)],
        'gmail'    => ['Gmail',    'mail',     'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode($email) . '&su=' . rawurlencode(REVIEW_INVITE_SUBJECT) . '&body=' . rawurlencode($message)],
        'email'    => ['E-mail',   'send',     'mailto:' . rawurlencode($email) . '?subject=' . rawurlencode(REVIEW_INVITE_SUBJECT) . '&body=' . rawurlencode($message)],
        'sms'      => ['SMS',      'phone',    'sms:' . ($intl ? '+' . $intl : '') . '?body=' . rawurlencode($message)],
        'telegram' => ['Telegram', 'send',     'https://t.me/share/url?url=' . rawurlencode($link) . '&text=' . rawurlencode($short)],
        'facebook' => ['Facebook', 'globe',    'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($link)],
    ];
}

/* ---------- Visite guidée (session du visiteur) ---------- */

/** Visite en cours : ['invite_id' => ?int, 'name' => ?string, 'visited' => [pages]] ou null. */
function review_tour(): ?array
{
    return (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['review_tour']) && is_array($_SESSION['review_tour']))
        ? $_SESSION['review_tour'] : null;
}

/** Démarre la visite (lien d'invitation ouvert). */
function review_tour_start(?array $invite): void
{
    $_SESSION['review_tour'] = [
        'invite_id' => $invite ? (int) $invite['id'] : null,
        'name'      => $invite['client_name'] ?? null,
        'visited'   => [],
    ];
}

/** Note la page comme visitée ; retourne la visite à jour (null : pas de visite en cours). */
function review_tour_visit(string $page): ?array
{
    $tour = review_tour();
    if ($tour === null) {
        return null;
    }
    if (isset(REVIEW_TOUR[$page]) && !in_array($page, $tour['visited'], true)) {
        $tour['visited'][] = $page;
        $_SESSION['review_tour'] = $tour;
    }
    return $tour;
}

/** Fin de la visite (avis déposé ou visite fermée par le visiteur). */
function review_tour_end(): void
{
    unset($_SESSION['review_tour']);
}

/** Rattache un avis à son invitation (la première réponse compte). */
function review_invite_answered(int $inviteId, int $reviewId): void
{
    db_query('UPDATE reviews SET invite_id = ? WHERE id = ?', [$inviteId, $reviewId]);
    db_query('UPDATE review_invites SET review_id = ?, reviewed_at = ? WHERE id = ? AND review_id IS NULL', [$reviewId, db_now(), $inviteId]);
}
