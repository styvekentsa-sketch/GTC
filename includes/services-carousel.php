<?php
/**
 * Carrousel des services de GTC (page d'accueil) : image, icône, titre, accroche, description, points clés et
 * boutons WhatsApp / simulateur. La page Services présente le catalogue complet (includes/services-catalogue.php).
 *
 * Même carrousel « vitrine » que les témoignages (assets/js/main.js, § 13 ; styles .tst-slider dans style.css) :
 *   - les cartes glissent de droite à gauche, une à la fois ; la carte centrale est mise en avant, son contenu
 *     apparaît en cascade et une barre indique le temps avant le service suivant (SERVICES_DELAY) ;
 *   - manuel : boutons Précédent / Suivant, points, flèches du clavier, clic sur une carte voisine, glisser à la
 *     souris, balayage au doigt, molette / pavé tactile ; bouton « Pause » ;
 *   - pause au survol, au focus clavier, hors écran, onglet masqué ; rien d'automatique si le visiteur a demandé
 *     moins d'animations.
 * Sans JavaScript : simple bande défilable au doigt ou à la souris (commandes masquées).
 * Images : clés de GTC_IMAGES (includes/media.php). Styles de la carte : .svc-card dans assets/css/style.css.
 */
$gtcServices = array_values(require __DIR__ . '/services-data.php');   // liste commune avec la page Services
$gtcServicesCount = count($gtcServices);
const SERVICES_DELAY = 5000;   // ms par service (barre de progression)
?>
<div class="tst-slider tst-slider--services" data-tst-slider role="region" aria-roledescription="carrousel" aria-label="Nos services"
     style="--tst-delay: <?= SERVICES_DELAY ?>ms">
    <div class="tst-slider__viewport" data-tst-viewport>
        <ul class="tst-slider__track" role="list" data-tst-track aria-live="off">
            <?php foreach ($gtcServices as $i => $s): ?>
            <li class="tst-slide" data-tst-idx="<?= $i ?>" aria-roledescription="diapositive" aria-label="<?= $i + 1 ?> sur <?= $gtcServicesCount ?> : <?= e($s['title']) ?>">
                <article class="svc-card">
                    <div class="svc-card__media">
                        <?= unsplash_img($s['image'], $s['alt'], ['sizes' => '360px']) ?>
                    </div>
                    <div class="svc-card__body">
                        <span class="svc-card__icon"><?= icon($s['icon']) ?></span>
                        <h3 class="svc-card__title"><?= e($s['title']) ?></h3>
                        <p class="svc-card__tagline"><?= e($s['tagline']) ?></p>
                        <p class="svc-card__text"><?= e($s['text']) ?></p>

                        <ul class="svc-list">
                            <?php foreach ($s['points'] as $point): ?>
                                <li><?= icon('check') ?><span><?= e($point) ?></span></li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="svc-card__actions">
                            <a href="<?= e(wa_link($s['message'])) ?>" class="btn btn--accent" target="_blank" rel="noopener">
                                <?= icon('whatsapp') ?> <?= e($s['cta']) ?>
                            </a>
                            <?php if (!empty($s['simulate'])): ?>
                                <a href="services.php#simulateur" class="btn btn--outline open-calculator"><?= icon('calc') ?> Simuler</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="tst-progress" aria-hidden="true"></span>
                </article>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Commandes : affichées par assets/js/main.js (sans JavaScript, la bande reste défilable) -->
    <div class="tst-slider__controls" data-tst-controls hidden>
        <button type="button" class="svc-carousel__btn" data-tst-prev aria-label="Service précédent"><?= icon('arrow-left') ?></button>
        <div class="svc-carousel__dots" role="group" aria-label="Choisir un service">
            <?php foreach ($gtcServices as $i => $s): ?>
                <button type="button" class="svc-carousel__dot" data-tst-dot="<?= $i ?>" aria-label="<?= e($s['title']) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button>
            <?php endforeach; ?>
        </div>
        <button type="button" class="svc-carousel__btn" data-tst-next aria-label="Service suivant"><?= icon('arrow') ?></button>
        <button type="button" class="svc-carousel__play" data-tst-toggle aria-pressed="false">Pause</button>
    </div>
</div>
