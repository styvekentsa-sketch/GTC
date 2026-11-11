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

const TESTIMONIALS_MAX  = 10;    // cartes distinctes dans le bandeau défilant
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
// Carrousel (assets/js/main.js, § 13) : glisse de droite à gauche, une carte toutes les TESTIMONIALS_DELAY ms ;
// carte centrale mise en avant, barre de progression, boutons / points / Pause, balayage au doigt.
// Le script duplique les cartes pour une boucle sans fin. Sans JavaScript : bande défilable au doigt ou à la souris.
const TESTIMONIALS_DELAY = 5500;
$testimonialsCount = count($testimonials);
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

        <div class="tst-slider" data-tst-slider role="region" aria-roledescription="carrousel" aria-label="Avis de nos clients"
             style="--tst-delay: <?= TESTIMONIALS_DELAY ?>ms">
          <div class="tst-slider__viewport" data-tst-viewport>
            <ul class="tst-slider__track" role="list" data-tst-track aria-live="off">
            <?php foreach ($testimonials as $i => $t):
                $long = mb_strlen($t['text']) > TESTIMONIALS_CHARS;
                $text = $long ? rtrim(mb_substr($t['text'], 0, TESTIMONIALS_CHARS - 1), " ,;.") . '…' : $t['text']; ?>
              <li class="tst-slide" data-tst-idx="<?= $i ?>" aria-roledescription="diapositive" aria-label="<?= $i + 1 ?> sur <?= $testimonialsCount ?> : <?= e($t['name']) ?>">
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
                    <span class="tst-progress" aria-hidden="true"></span>
                </figure>
              </li>
            <?php endforeach; ?>
            </ul>
          </div>

          <!-- Commandes : affichées par assets/js/main.js (sans JavaScript, la bande reste défilable) -->
          <div class="tst-slider__controls" data-tst-controls hidden>
            <button type="button" class="svc-carousel__btn" data-tst-prev aria-label="Avis précédent"><?= icon('arrow-left') ?></button>
            <div class="svc-carousel__dots" role="group" aria-label="Choisir un avis">
              <?php foreach ($testimonials as $i => $t): ?>
                <button type="button" class="svc-carousel__dot" data-tst-dot="<?= $i ?>" aria-label="Avis de <?= e($t['name']) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button>
              <?php endforeach; ?>
            </div>
            <button type="button" class="svc-carousel__btn" data-tst-next aria-label="Avis suivant"><?= icon('arrow') ?></button>
            <button type="button" class="svc-carousel__play" data-tst-toggle aria-pressed="false">Pause</button>
          </div>
        </div>

        <!-- Avis déposés et validés : avis.php -->
        <div class="testimonials__more">
            <a href="<?= e($root ?? '') ?>avis.php" class="btn btn--outline"><?= icon('star') ?> Lire tous les avis</a>
            <a href="<?= e($root ?? '') ?>avis.php#laisser-un-avis" class="btn btn--primary"><?= icon('edit') ?> Laisser un avis</a>
        </div>
    </div>
</section>
