<?php
/**
 * Section Témoignages clients (partagée : index.php, a-propos.php).
 * Requiert includes/icons.php et la fonction e() de header.php.
 *
 * Variable optionnelle : $testimonialsClass (classe de fond de la section)
 */
$testimonials = [
    [
        'name' => 'M. Kenfack Alfred',
        'role' => 'CEO',
        'text' => "Ma première fois chez Global Trading Corporation remonte à sa création et, jusqu'à aujourd'hui, la qualité de service reste impeccable ; je suis satisfait.",
    ],
    [
        'name' => 'Mlle Sidonie Diane',
        'role' => 'Étudiante',
        'text' => "Mes parents résidant en Amérique du Nord passent par cette entreprise pour me transférer de l'argent pour ma location, ma nutrition et tous mes besoins universitaires.",
    ],
    [
        'name' => 'Dr Ghislain',
        'role' => 'Cardiologue',
        'text' => "Cette entreprise est vraie, la qualité de service est wow ; bien qu'elle soit très loin de mon lieu d'habitation, je prends plaisir à toujours me tourner vers eux pour mes transactions.",
    ],
];
?>
<section class="section <?= e($testimonialsClass ?? 'section--white') ?>" id="temoignages">
    <div class="container">
        <header class="section__header section__header--center">
            <span class="section__eyebrow">Témoignages</span>
            <h2>Ce que disent nos clients</h2>
        </header>

        <div class="grid grid--3">
            <?php foreach ($testimonials as $t): ?>
                <figure class="card testimonial">
                    <span class="testimonial__quote"><?= icon('quote') ?></span>
                    <blockquote class="testimonial__text">
                        <p>« <?= e($t['text']) ?> »</p>
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
    </div>
</section>
