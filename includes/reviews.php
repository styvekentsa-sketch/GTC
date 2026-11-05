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
