<?php
/**
 * Section Témoignages clients (page d'accueil).
 * Requiert includes/icons.php et la fonction e() de header.php.
 *
 * Contenu : les avis clients PUBLIÉS les plus récents (déposés sur avis.php, validés dans Dashboard → Avis clients),
 * avec leur note ; complétés par les témoignages historiques ci-dessous pour garder une grille pleine.
 * Un avis en attente ou refusé n'apparaît jamais. Base indisponible : seuls les témoignages historiques s'affichent.
 *
 * Variable optionnelle : $testimonialsClass (classe de fond de la section)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reviews.php';

const TESTIMONIALS_MAX  = 6;     // cartes affichées (2 rangées de 3)
const TESTIMONIALS_CHARS = 260;  // au-delà, texte abrégé + lien « Lire la suite »

$testimonialsStatic = [
    ['name' => 'M. Kenfack Alfred', 'role' => 'CEO',
     'text' => "Ma première fois chez Global Trading Corporation remonte à sa création et, jusqu'à aujourd'hui, la qualité de service reste impeccable ; je suis satisfait."],
    ['name' => 'Mlle Sidonie Diane', 'role' => 'Étudiante',
     'text' => "Mes parents résidant en Amérique du Nord passent par cette entreprise pour me transférer de l'argent pour ma location, ma nutrition et tous mes besoins universitaires."],
    ['name' => 'Dr Ghislain', 'role' => 'Cardiologue',
     'text' => "Cette entreprise est vraie, la qualité de service est wow ; bien qu'elle soit très loin de mon lieu d'habitation, je prends plaisir à toujours me tourner vers eux pour mes transactions."],
];

// Avis publiés les plus récents (jamais l'adresse IP : review_public)
$testimonialsStats = ['count' => 0, 'average' => 0.0];
$testimonials = [];
try {
    if (db_try() !== null) {
        $testimonialsStats = review_stats();
        foreach (array_slice(reviews_approved(1), 0, TESTIMONIALS_MAX) as $r) {
            $p = review_public($r);
            $testimonials[] = ['name' => $p['name'], 'role' => 'Avis client · ' . $p['date'], 'text' => $p['comment'], 'rating' => $p['rating'], 'new' => true];
        }
    }
} catch (Throwable $e) {
    error_log('[GTC] Témoignages de l\'accueil : ' . $e->getMessage());
}
foreach ($testimonialsStatic as $t) {
    if (count($testimonials) >= TESTIMONIALS_MAX) {
        break;
    }
    $testimonials[] = $t + ['rating' => 0, 'new' => false];
}
// Grille équilibrée : multiple de 3 (une rangée incomplète est retirée s'il y a au moins 3 cartes)
if (count($testimonials) > 3) {
    $testimonials = array_slice($testimonials, 0, intdiv(count($testimonials), 3) * 3);
}
?>
<section class="section <?= e($testimonialsClass ?? 'section--white') ?>" id="temoignages">
    <div class="container">
        <header class="section__header section__header--center">
            <span class="section__eyebrow">Témoignages</span>
            <h2>Ce que disent nos clients</h2>
            <?php if ($testimonialsStats['count'] > 0): ?>
                <p class="testimonials__score">
                    <?= review_stars((float) $testimonialsStats['average'], 'stars') ?>
                    <strong><?= e(str_replace('.', ',', (string) $testimonialsStats['average'])) ?>/5</strong>
                    <span>· <?= (int) $testimonialsStats['count'] ?> avis client<?= $testimonialsStats['count'] > 1 ? 's' : '' ?> vérifié<?= $testimonialsStats['count'] > 1 ? 's' : '' ?></span>
                </p>
            <?php endif; ?>
        </header>

        <div class="grid grid--3">
            <?php foreach ($testimonials as $t):
                $long = mb_strlen($t['text']) > TESTIMONIALS_CHARS;
                $text = $long ? rtrim(mb_substr($t['text'], 0, TESTIMONIALS_CHARS - 1), " ,;.") . '…' : $t['text']; ?>
                <figure class="card testimonial">
                    <?php if ($t['rating'] > 0): ?>
                        <div class="testimonial__rating"><?= review_stars((float) $t['rating'], 'stars stars--sm') ?></div>
                    <?php else: ?>
                        <span class="testimonial__quote"><?= icon('quote') ?></span>
                    <?php endif; ?>
                    <blockquote class="testimonial__text">
                        <p>« <?= nl2br(e($text)) ?> »</p>
                        <?php if ($long): ?><a href="<?= e($root ?? '') ?>avis.php" class="testimonial__more">Lire la suite</a><?php endif; ?>
                    </blockquote>
                    <figcaption class="testimonial__author">
                        <span class="testimonial__avatar" aria-hidden="true"><?= e(initials($t['name'])) ?></span>
                        <span>
                            <span class="testimonial__name"><?= e($t['name']) ?></span>
                            <span class="testimonial__role"><?= e($t['role']) ?></span>
                        </span>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>

        <!-- Avis déposés et validés : avis.php -->
        <div class="testimonials__more">
            <a href="<?= e($root ?? '') ?>avis.php" class="btn btn--outline"><?= icon('star') ?> Lire tous les avis</a>
            <a href="<?= e($root ?? '') ?>avis.php#laisser-un-avis" class="btn btn--primary"><?= icon('edit') ?> Laisser un avis</a>
        </div>
    </div>
</section>
