<?php
/**
 * Catalogue des services (page Services) — présentation détaillée, différente du carrousel de l'accueil :
 *   - sommaire : une pastille par service, ancre vers sa rangée (défilement horizontal sur téléphone) ;
 *   - une rangée par service : grande photo (alternativement à gauche et à droite, numéro, famille), accroche,
 *     description, points clés sur deux colonnes, bouton WhatsApp, simulateur et liens utiles (calculateur, suivi).
 * Apparition douce au défilement (assets/js/main.js § 6) ; sans JavaScript ou avec moins d'animations, tout est visible.
 * Données : includes/services-data.php (communes avec le carrousel). Styles : .svc-cat / .svc-row (style.css).
 */
$gtcServices = require __DIR__ . '/services-data.php';
$accents = ['blue', 'green', 'magenta'];   // couleur décorative de chaque rangée, dans l'ordre de la charte
?>
<div class="svc-cat" data-svc-catalogue>
    <!-- Sommaire -->
    <nav class="container svc-cat__nav" aria-label="Accès direct à un service">
        <ul class="svc-cat__chips">
            <?php foreach ($gtcServices as $s): ?>
                <li><a href="#<?= e($s['id']) ?>" class="svc-cat__chip"><?= icon($s['icon']) ?> <?= e($s['title']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="container svc-cat__list">
        <?php foreach (array_values($gtcServices) as $i => $s):
            $num = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $accent = $accents[$i % count($accents)];
            ?>
            <article class="svc-row svc-row--<?= $accent ?><?= $i % 2 ? ' svc-row--reverse' : '' ?>" id="<?= e($s['id']) ?>" aria-labelledby="<?= e($s['id']) ?>-title">
                <figure class="svc-row__media">
                    <div class="svc-row__frame">
                        <?= unsplash_img($s['image'], $s['alt'], ['ratio' => 4 / 3, 'sizes' => '(max-width: 991px) 100vw, 560px']) ?>
                    </div>
                    <span class="svc-row__num" aria-hidden="true"><?= $num ?></span>
                    <figcaption class="svc-row__badge"><?= icon($s['icon']) ?> <?= e($s['category']) ?></figcaption>
                </figure>

                <div class="svc-row__body">
                    <p class="svc-row__eyebrow"><span><?= $num ?></span> <?= e($s['category']) ?></p>
                    <h3 class="svc-row__title" id="<?= e($s['id']) ?>-title"><?= e($s['title']) ?></h3>
                    <p class="svc-row__tagline"><?= e($s['tagline']) ?></p>
                    <p class="svc-row__text"><?= e($s['text']) ?></p>

                    <ul class="svc-row__points">
                        <?php foreach ($s['points'] as $point): ?>
                            <li><span class="svc-row__check"><?= icon('check') ?></span><?= e($point) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="svc-row__actions">
                        <a href="<?= e(wa_link($s['message'])) ?>" class="btn btn--accent" target="_blank" rel="noopener"><?= icon('whatsapp') ?> <?= e($s['cta']) ?></a>
                        <?php if (!empty($s['simulate'])): ?>
                            <a href="services.php#simulateur" class="btn btn--outline open-calculator"><?= icon('calc') ?> Simuler</a>
                        <?php endif; ?>
                        <?php foreach ($s['links'] ?? [] as [$label, $href, $ico]): ?>
                            <a href="<?= e($href) ?>" class="svc-row__link"><?= icon($ico) ?> <?= e($label) ?> <?= icon('arrow') ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
