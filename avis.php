<?php
/**
 * Avis clients — page publique : note moyenne, répartition des notes, avis publiés et formulaire de dépôt.
 *
 *   avis.php                    (aussi /laisser-un-avis → avis.php#laisser-un-avis)
 *   Dépôt : api-reviews.php (fetch, assets/js/main.js § 10) ; sans JavaScript, envoi classique avec retour ici.
 *   « Afficher plus d'avis » : api-reviews.php?page=n.
 * Seuls les avis validés dans le Dashboard (dashboard/reviews.php) sont affichés. Règles : includes/reviews.php.
 */
require_once __DIR__ . '/includes/config.php';
gtc_public_session_start();
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/reviews.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_SESSION['review_form_at'] = time();   // délai minimal avant l'envoi (anti-robot)

// Résultat d'un envoi sans JavaScript (lu une seule fois)
$result = $_SESSION['review_result'] ?? null;
unset($_SESSION['review_result']);
$old    = $result['old'] ?? [];
$errors = $result['errors'] ?? [];

$dbOk    = db_try() !== null;
$stats   = $dbOk ? review_stats() : ['count' => 0, 'average' => 0.0, 'dist' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]];
$first   = $dbOk ? reviews_approved(1, 1) : [];
$more    = count($first) > REVIEW_PAGE_SIZE;
$reviews = array_map('review_public', array_slice($first, 0, REVIEW_PAGE_SIZE));
$ratingWords = [1 => 'Décevant', 2 => 'Moyen', 3 => 'Correct', 4 => 'Très bien', 5 => 'Excellent'];

// ---------- Méta & données de la page ----------
$pageTitle       = 'Avis clients';
$seoTitle        = 'Avis clients : change et transfert d\'argent à Douala | GTC';
$pageDescription = 'Lisez les avis des clients de Global Trading Corporation à Douala et partagez votre expérience : change de devises, transfert d\'argent, fret et transit.';
$currentPage     = 'avis';

include __DIR__ . '/includes/header.php';

$hero = [
    'crumb'   => 'Avis clients',
    'image'   => 'globe',   // Terre de nuit, lumières des villes — GTC_IMAGES, includes/media.php
    'eyebrow' => 'Avis clients',
    'title'   => 'Ce que nos clients pensent de nous',
    'lead'    => 'Votre expérience compte : lisez les avis de nos clients et partagez le vôtre en une minute.',
    'actions' => [['label' => 'Laisser un avis', 'href' => '#laisser-un-avis', 'class' => 'btn--accent', 'icon' => 'edit']],
];
include __DIR__ . '/includes/hero.php';

/** Carte d'un avis publié (même rendu que celles ajoutées par « Afficher plus », main.js § 10). */
$reviewCard = function (array $r): void {
    ?>
    <article class="card review">
        <header class="review__head">
            <span class="review__avatar" aria-hidden="true"><?= e(initials($r['name'])) ?></span>
            <div>
                <p class="review__name"><?= e($r['name']) ?></p>
                <p class="review__date"><?= e($r['date']) ?></p>
            </div>
            <?= review_stars($r['rating'], 'stars stars--sm') ?>
        </header>
        <p class="review__text"><?= nl2br(e($r['comment'])) ?></p>
    </article>
    <?php
};
?>

<section class="section section--white">
    <div class="container reviews" data-reviews data-api="api-reviews.php">

        <div class="reviews__top">
            <!-- ==================== NOTE MOYENNE ==================== -->
            <aside class="reviews-summary" aria-labelledby="summary-title">
                <h2 class="reviews-summary__title" id="summary-title">Note globale</h2>
                <?php if ($stats['count']): ?>
                    <p class="reviews-summary__avg"><strong data-review-avg><?= e(str_replace('.', ',', number_format($stats['average'], 1))) ?></strong><span>/ 5</span></p>
                    <?= review_stars($stats['average'], 'stars stars--lg') ?>
                    <p class="reviews-summary__count"><span data-review-count><?= $stats['count'] ?></span> avis publié<?= $stats['count'] > 1 ? 's' : '' ?></p>
                    <ul class="reviews-summary__dist" aria-label="Répartition des notes">
                        <?php foreach ($stats['dist'] as $stars => $n): ?>
                            <li>
                                <span><?= $stars ?> <span class="visually-hidden">étoiles</span><span class="star star--tiny" style="--fill:100%" aria-hidden="true"></span></span>
                                <span class="reviews-summary__bar"><span style="width: <?= $stats['count'] ? round($n / $stats['count'] * 100) : 0 ?>%"></span></span>
                                <span class="reviews-summary__n"><?= $n ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="reviews-summary__empty"><?= icon('quote') ?> Aucun avis publié pour le moment : soyez le premier à partager votre expérience.</p>
                <?php endif; ?>
                <p class="reviews-summary__note"><?= icon('shield') ?> Chaque avis est relu par notre équipe avant publication. Nous ne modifions jamais le contenu d'un avis.</p>
            </aside>

            <!-- ==================== FORMULAIRE ==================== -->
            <div class="review-form-card" id="laisser-un-avis">
                <h2 class="review-form-card__title">Laisser un avis</h2>
                <p class="review-form-card__intro">Comment s'est passée votre opération chez GTC ? Votre avis aide les autres clients.</p>

                <?php if ($result): ?>
                    <div class="alert <?= $result['ok'] ? 'alert--success' : 'alert--error' ?>" role="status"><?= icon($result['ok'] ? 'check' : 'alert') ?><p><?= e($result['message']) ?></p></div>
                <?php endif; ?>

                <form class="review-form" action="api-reviews.php" method="post" novalidate data-review-form>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <!-- Champ piège anti-robot (masqué) -->
                    <div class="form-hp" aria-hidden="true">
                        <label for="gtc_hp_rv">Ne pas remplir</label>
                        <input type="text" id="gtc_hp_rv" name="gtc_hp_rv" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore data-bwignore>
                    </div>

                    <fieldset class="rating-input<?= isset($errors['rating']) ? ' is-invalid' : '' ?>" data-rating>
                        <legend class="form-label">Votre note <span class="req">*</span></legend>
                        <div class="rating-input__stars">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" id="rating-<?= $i ?>" name="rating" value="<?= $i ?>"<?= (int) ($old['rating'] ?? 0) === $i ? ' checked' : '' ?>>
                                <label for="rating-<?= $i ?>" title="<?= e($ratingWords[$i]) ?>"><span class="visually-hidden"><?= $i ?> étoile<?= $i > 1 ? 's' : '' ?> — <?= e($ratingWords[$i]) ?></span></label>
                            <?php endfor; ?>
                        </div>
                        <span class="rating-input__word" data-rating-word aria-hidden="true"><?= isset($old['rating']) && isset($ratingWords[(int) $old['rating']]) ? e($ratingWords[(int) $old['rating']]) : 'Cliquez sur une étoile' ?></span>
                        <p class="form-error" data-error-for="rating"<?= isset($errors['rating']) ? '' : ' hidden' ?>><?= e($errors['rating'] ?? '') ?></p>
                    </fieldset>

                    <div class="form-group">
                        <label for="review-name" class="form-label">Nom ou pseudo <span class="req">*</span></label>
                        <input id="review-name" name="author_name" type="text" class="form-control<?= isset($errors['author_name']) ? ' is-invalid' : '' ?>"
                               maxlength="<?= REVIEW_NAME_MAX ?>" autocomplete="name" placeholder="Ex. Paul E. ou Client de Bonapriso" value="<?= e($old['author_name'] ?? '') ?>" required>
                        <p class="form-error" data-error-for="author_name"<?= isset($errors['author_name']) ? '' : ' hidden' ?>><?= e($errors['author_name'] ?? '') ?></p>
                    </div>

                    <div class="form-group">
                        <label for="review-comment" class="form-label">Votre avis <span class="req">*</span></label>
                        <textarea id="review-comment" name="comment" class="form-control<?= isset($errors['comment']) ? ' is-invalid' : '' ?>" rows="5"
                                  maxlength="<?= REVIEW_TEXT_MAX ?>" placeholder="Accueil, rapidité, taux, suivi de votre opération…" required data-counter><?= e($old['comment'] ?? '') ?></textarea>
                        <div class="review-form__meta">
                            <p class="form-error" data-error-for="comment"<?= isset($errors['comment']) ? '' : ' hidden' ?>><?= e($errors['comment'] ?? '') ?></p>
                            <span class="review-form__counter" data-counter-out aria-live="polite"><?= mb_strlen($old['comment'] ?? '') ?> / <?= REVIEW_TEXT_MAX ?></span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn--primary btn--block review-form__submit" data-review-submit>
                        <span class="review-form__spinner" aria-hidden="true"></span>
                        <span data-submit-label>Publier mon avis</span>
                    </button>
                    <p class="review-form__legal">Votre avis est publié avec le nom ou pseudo indiqué, après validation.
                        <a href="legal/politique-confidentialite.php">Données personnelles</a></p>
                </form>
            </div>
        </div>

        <!-- ==================== AVIS PUBLIÉS ==================== -->
        <?php if ($reviews): ?>
            <h2 class="reviews__list-title">Les avis de nos clients</h2>
            <div class="grid grid--3 reviews__list" data-review-list>
                <?php foreach ($reviews as $r) { $reviewCard($r); } ?>
            </div>
            <?php if ($more): ?>
                <div class="reviews__more"><button type="button" class="btn btn--outline" data-review-more data-next-page="2">Afficher plus d'avis</button></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Notification après envoi (assets/js/main.js § 10) -->
    <div class="toast" data-toast role="status" aria-live="polite" hidden></div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
