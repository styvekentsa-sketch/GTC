<?php
/**
 * Visite guidée « Donner mon avis » — bandeau en bas des pages publiques (inclus par includes/footer.php).
 *
 * Affiché seulement si le visiteur est arrivé par un lien d'invitation (mon-avis.php, session « review_tour »).
 * Étapes : REVIEW_TOUR (includes/reviews.php) — Accueil → Services → Taux du jour → À propos → formulaire d'avis.
 * Sur chaque page : progression, présentation de la page, bouton vers l'étape suivante et raccourci
 * « Donner mon avis maintenant ». Le visiteur peut réduire le bandeau (assets/js/main.js § 11) ou arrêter la visite.
 */
if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['review_tour'])) {
    return;
}
require_once __DIR__ . '/reviews.php';

$tourRoot = $root ?? '';
$tourPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if ($tourRoot === '../' && $tourPage !== '') {
    $tourPage = 'legal/' . $tourPage;   // pages légales (sous-dossier) : jamais une étape de la visite
}
$tour = review_tour_visit($tourPage);
if ($tour === null) {
    return;
}

$steps   = array_keys(REVIEW_TOUR);
$total   = count($steps);
$index   = array_search($tourPage, $steps, true);
$isStep  = $index !== false;
$isLast  = $tourPage === 'avis.php';
$visited = array_values(array_intersect($steps, $tour['visited']));
$name    = $tour['name'] ?? null;

if ($isStep) {
    $position = $index + 1;
    [, $title, $text] = REVIEW_TOUR[$tourPage];
    if ($index === 0 && $name) {
        $title = 'Bienvenue ' . $name . ' !';
    }
    $next = $steps[$index + 1] ?? null;
} else {
    // Page hors parcours (FAQ, Contact…) : reprise à la première étape non visitée
    $next = null;
    foreach ($steps as $s) {
        if (!in_array($s, $visited, true)) {
            $next = $s;
            break;
        }
    }
    $next ??= 'avis.php';
    $position = count($visited);
    $title = 'Visite guidée en pause';
    $text  = 'Prenez le temps de lire cette page, puis reprenez la visite là où vous l\'avez laissée.';
}
$progress = round(max($position, 1) / $total * 100);
$stopUrl  = $tourRoot . 'mon-avis.php?stop=1&back=' . rawurlencode($tourPage);
?>
<!-- ==================== VISITE GUIDÉE « DONNER MON AVIS » ==================== -->
<aside class="tour<?= $isLast ? ' tour--last' : '' ?>" role="region" aria-label="Visite guidée du site" data-tour>
    <div class="tour__bar" aria-hidden="true"><span style="width: <?= $progress ?>%"></span></div>
    <div class="tour__inner">
        <ol class="tour__dots" aria-label="Étapes de la visite">
            <?php foreach ($steps as $i => $s): ?>
                <li class="<?= $s === $tourPage ? 'is-current' : (in_array($s, $visited, true) ? 'is-done' : '') ?>">
                    <a href="<?= e($tourRoot . ($s === 'avis.php' ? 'avis.php#laisser-un-avis' : $s)) ?>" title="<?= e(REVIEW_TOUR[$s][0]) ?>"<?= $s === $tourPage ? ' aria-current="step"' : '' ?>>
                        <span class="visually-hidden">Étape <?= $i + 1 ?> : <?= e(REVIEW_TOUR[$s][0]) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="tour__body">
            <p class="tour__step"><?= icon('compass') ?> Visite guidée<?= $isStep ? ' · étape ' . $position . ' sur ' . $total : '' ?></p>
            <p class="tour__title"><?= e($title) ?></p>
            <p class="tour__text"><?= e($text) ?></p>
        </div>

        <div class="tour__actions">
            <?php if ($isLast): ?>
                <a href="#laisser-un-avis" class="btn btn--accent tour__next"><?= icon('edit') ?> Écrire mon avis</a>
            <?php else: ?>
                <a href="<?= e($tourRoot . ($next === 'avis.php' ? 'avis.php#laisser-un-avis' : $next)) ?>" class="btn btn--accent tour__next">
                    <?= $isStep ? 'Étape suivante : ' : 'Reprendre : ' ?><?= e(REVIEW_TOUR[$next][0]) ?> <?= icon('arrow') ?>
                </a>
                <a href="<?= e($tourRoot) ?>avis.php#laisser-un-avis" class="tour__skip">Donner mon avis maintenant</a>
            <?php endif; ?>
        </div>

        <div class="tour__tools">
            <button type="button" class="tour__tool" data-tour-toggle aria-expanded="true" aria-label="Réduire la visite guidée"><?= icon('arrow') ?></button>
            <a href="<?= e($stopUrl) ?>" class="tour__tool" aria-label="Arrêter la visite guidée" title="Arrêter la visite"><?= icon('x') ?></a>
        </div>
    </div>
</aside>
