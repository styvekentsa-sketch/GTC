<?php
/**
 * API des avis clients (page avis.php, assets/js/main.js § 10).
 *
 *   GET  api-reviews.php?page=2   → { stats: { count, average, dist }, reviews: [ { id, name, rating, comment, date } ], more: bool }
 *   POST api-reviews.php          → dépôt d'un avis (champs author_name, rating, comment, csrf_token, gtc_hp_rv)
 *        réponse JSON { ok, message, errors: { champ: message } }  (requête Accept: application/json)
 *        sans JavaScript : redirection vers avis.php avec le résultat en session (motif Post/Redirect/Get)
 *
 * Nouvel avis enregistré « en attente » : publié après validation dans le Dashboard (dashboard/reviews.php).
 * Règles et protections : includes/reviews.php.
 */
require_once __DIR__ . '/includes/config.php';
gtc_public_session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/reviews.php';
if (!function_exists('e')) {
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

/** Réponse JSON et fin du script. */
function reviews_json(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!db_try()) {
    if (!$wantsJson && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['review_result'] = ['ok' => false, 'message' => 'Service momentanément indisponible. Merci de réessayer plus tard.', 'errors' => [], 'old' => []];
        header('Location: avis.php#laisser-un-avis', true, 303);
        exit;
    }
    reviews_json(503, ['ok' => false, 'message' => 'Service momentanément indisponible. Merci de réessayer plus tard.']);
}

/* ---------- Lecture : avis publiés ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = max(1, min(500, (int) ($_GET['page'] ?? 1)));
    $list = reviews_approved($page, 1);   // un de plus : y a-t-il une page suivante ?
    reviews_json(200, [
        'stats'   => review_stats(),
        'reviews' => array_map('review_public', array_slice($list, 0, REVIEW_PAGE_SIZE)),
        'more'    => count($list) > REVIEW_PAGE_SIZE,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reviews_json(405, ['ok' => false, 'message' => 'Méthode non autorisée.']);
}

/* ---------- Dépôt d'un avis ---------- */
$respond = function (int $status, bool $ok, string $message, array $errors = [], array $old = []) use ($wantsJson): never {
    if ($wantsJson) {
        reviews_json($status, ['ok' => $ok, 'message' => $message, 'errors' => (object) $errors]);
    }
    $_SESSION['review_result'] = ['ok' => $ok, 'message' => $message, 'errors' => $errors, 'old' => $ok ? [] : $old];
    header('Location: avis.php#laisser-un-avis', true, 303);
    exit;
};

$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
$success = 'Merci ! Votre avis a bien été reçu : il sera publié après validation par notre équipe.';

if (review_is_bot($_POST, $_SESSION)) {
    $respond(200, true, $success);   // faux succès : le robot ne sait pas qu'il a été écarté
}
[$data, $errors, $global] = review_validate($_POST, $_SESSION);
if ($global !== null) {
    $respond(419, false, $global, [], $data);
}
if ($errors) {
    $respond(422, false, 'Merci de corriger les champs indiqués.', $errors, $data);
}
if (review_throttled($ip)) {
    $respond(429, false, 'Vous avez déjà envoyé plusieurs avis aujourd\'hui. Merci de réessayer demain.', [], $data);
}
try {
    $reviewId = review_create($data, $ip);
    // Avis donné à la suite d'une invitation (mon-avis.php) : rattaché à l'invitation, fin de la visite guidée
    $tour = review_tour();
    if ($tour !== null) {
        if (!empty($tour['invite_id'])) {
            review_invite_answered((int) $tour['invite_id'], $reviewId);
        }
        review_tour_end();
    }
} catch (Throwable $e) {
    error_log('[GTC] Avis non enregistré : ' . $e->getMessage());
    $respond(500, false, 'Votre avis n\'a pas pu être enregistré. Merci de réessayer dans quelques instants.', [], $data);
}
$_SESSION['review_form_at'] = time();   // un nouvel envoi immédiat reste soumis au délai minimal
$respond(201, true, $success);
