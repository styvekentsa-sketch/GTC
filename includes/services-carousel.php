<?php
/**
 * Carrousel des services de GTC, même carte sur index.php et services.php : image, icône, titre, accroche,
 * description, points clés et boutons WhatsApp / simulateur.
 *
 * Défilement hybride (assets/js/main.js, § 5) :
 *   - manuel : glisser à la souris, balayage au doigt, molette / pavé tactile, boutons Précédent / Suivant, points ;
 *     les cartes s'alignent à chaque arrêt (scroll-snap) ;
 *   - automatique : une carte toutes les 3 s, en pause dès que le visiteur survole, touche, fait défiler, utilise
 *     les boutons ou le clavier ; reprise 3 s après qu'il a quitté le carrousel. Arrêté hors écran, onglet masqué,
 *     ou si le visiteur a demandé moins d'animations.
 * Sans JavaScript : simple bande défilable au doigt ou à la souris (commandes masquées).
 * Images : clés de GTC_IMAGES (includes/media.php). Styles : .svc-carousel / .svc-card dans assets/css/style.css.
 */
$gtcServices = require __DIR__ . '/services-data.php';   // liste commune avec la page Services

$serviceCard = function (array $s, int $index, int $count): void {
    ?>
    <li class="svc-card" aria-roledescription="diapositive" aria-label="<?= $index + 1 ?> sur <?= $count ?> : <?= e($s['title']) ?>">
        <div class="svc-card__media">
            <?= unsplash_img($s['image'], $s['alt'], ['sizes' => '400px']) ?>
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
    </li>
    <?php
};
?>
<div class="svc-carousel" data-svc-carousel role="region" aria-roledescription="carrousel" aria-label="Nos services">
    <ul class="svc-carousel__track" role="list" data-svc-track>
        <?php foreach (array_values($gtcServices) as $i => $s) { $serviceCard($s, $i, count($gtcServices)); } ?>
    </ul>

    <!-- Commandes : affichées par assets/js/main.js (sans JavaScript, la bande reste défilable) -->
    <div class="container svc-carousel__controls" data-svc-controls hidden>
        <button type="button" class="svc-carousel__btn" data-svc-prev aria-label="Service précédent"><?= icon('arrow-left') ?></button>
        <div class="svc-carousel__dots" role="group" aria-label="Choisir un service">
            <?php foreach (array_values($gtcServices) as $i => $s): ?>
                <button type="button" class="svc-carousel__dot" data-svc-dot="<?= $i ?>" aria-label="<?= e($s['title']) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button>
            <?php endforeach; ?>
        </div>
        <button type="button" class="svc-carousel__btn" data-svc-next aria-label="Service suivant"><?= icon('arrow') ?></button>
        <button type="button" class="svc-carousel__play" data-svc-toggle aria-pressed="false">
            <span data-svc-toggle-label>Pause</span>
        </button>
    </div>
</div>
