<?php
/**
 * Lien d'invitation à donner son avis (créé dans dashboard/reviews.php?f=invites).
 *
 *   /mon-avis?t=JETON     Lien personnel : ouverture enregistrée, visite guidée du site (prénom du client affiché)
 *   /mon-avis             Lien général : même visite guidée, sans suivi individuel
 *   /mon-avis?stop=1      Le visiteur ferme la visite guidée (retour à la page où il était)
 *
 * La visite (bandeau includes/review-tour.php, étapes REVIEW_TOUR dans includes/reviews.php) conduit le visiteur de
 * page en page jusqu'au formulaire d'avis (avis.php). Un client qui a déjà répondu va directement aux avis.
 */
require_once __DIR__ . '/includes/config.php';
gtc_public_session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/reviews.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// Fermeture de la visite : retour à la page du site où se trouvait le visiteur
if (isset($_GET['stop'])) {
    review_tour_end();
    $back = (string) ($_GET['back'] ?? '');
    header('Location: ' . (preg_match('#^(legal/)?[a-z0-9-]+\.php$#', $back) ? $back : './'), true, 303);
    exit;
}

$invite = null;
$token  = (string) ($_GET['t'] ?? '');
if ($token !== '' && db_try()) {
    $invite = review_invite_by_token($token);
    if ($invite && review_is_automated_visit()) {
        // Aperçu du lien (WhatsApp, Facebook, Telegram…) ou robot : pas compté, simple redirection vers l'accueil
        // (l'aperçu affiche alors le titre et l'image du site)
        header('Location: ./', true, 303);
        exit;
    }
    if ($invite) {
        $now = db_now();
        db_query('UPDATE review_invites SET open_count = open_count + 1, opened_at = COALESCE(opened_at, ?), last_opened_at = ? WHERE id = ?',
            [$now, $now, (int) $invite['id']]);
        if ($invite['review_id']) {
            // Avis déjà donné : inutile de refaire la visite
            review_tour_end();
            header('Location: avis.php', true, 303);
            exit;
        }
    }
}

review_tour_start($invite);
header('Location: ./', true, 303);   // 1re étape : l'accueil
exit;
