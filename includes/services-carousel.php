<?php
/**
 * Bandeau défilant (droite → gauche) des services de GTC, même carte partout.
 *   index.php    : $carouselFull = false → aperçu (icône, titre, accroche), chaque carte mène à services.php
 *   services.php : $carouselFull = true  → mêmes cartes + boutons WhatsApp / simulateur
 * Le défilement s'arrête au survol ou au focus ; il est remplacé par une grille si l'utilisateur
 * a demandé moins d'animations. Styles : .svc-carousel / .svc-slide dans assets/css/style.css.
 */
$carouselFull = $carouselFull ?? false;

$gtcServices = [
    ['icon' => 'exchange',   'title' => 'Change de devises',              'text' => 'Vos devises au taux du jour, sans détour.',
     'cta' => 'Demander un change', 'simulate' => true, 'message' => 'Bonjour GTC, je souhaite effectuer un change de devises. Quel est votre taux du jour ?'],
    ['icon' => 'transfer',   'title' => 'Transfert de fonds international', 'text' => 'Votre argent arrive là où il doit arriver.',
     'cta' => 'Demander un transfert', 'message' => "Bonjour GTC, je souhaite effectuer un transfert d'argent international."],
    ['icon' => 'box',        'title' => 'Import / Export',                'text' => "Vos marchandises, de l'achat à la livraison.",
     'cta' => 'Demander un devis', 'message' => "Bonjour GTC, j'ai un projet d'import-export et je souhaite un devis."],
    ['icon' => 'file-check', 'title' => 'Transit',                        'text' => 'Formalités douanières et acheminement rapide de vos marchandises.',
     'cta' => 'Demander un devis', 'message' => 'Bonjour GTC, je souhaite un devis pour une opération de transit.'],
    ['icon' => 'ship',       'title' => 'Agent Maritime',                 'text' => "Gestion complète de l'escale et assistance aux navires.",
     'cta' => 'Nous contacter', 'message' => "Bonjour GTC, je souhaite vos services d'agent maritime."],
    ['icon' => 'forklift',   'title' => 'Acconier',                       'text' => 'Manutention, chargement et sécurisation de vos cargaisons à quai.',
     'cta' => 'Demander un devis', 'message' => 'Bonjour GTC, je souhaite un devis pour de la manutention portuaire (acconage).'],
    ['icon' => 'anchor',     'title' => 'Consignation',                   'text' => 'Prise en charge administrative et protection des marchandises.',
     'cta' => 'Nous contacter', 'message' => 'Bonjour GTC, je souhaite vos services de consignation.'],
    ['icon' => 'handshake',  'title' => 'Négoce',                         'text' => 'Achat, vente et intermédiation commerciale internationale.',
     'cta' => 'Nous contacter', 'message' => 'Bonjour GTC, je souhaite échanger avec vous sur une opération de négoce.'],
];

$serviceSlide = function (array $s, bool $copy) use ($carouselFull): void {
    $tab = $copy ? ' tabindex="-1"' : '';
    ?>
    <li class="svc-slide"<?= $copy ? ' aria-hidden="true"' : '' ?>>
        <?php if (!$carouselFull): ?><a class="svc-slide__link" href="services.php#services"<?= $tab ?>><?php endif; ?>
        <span class="svc-slide__icon"><?= icon($s['icon']) ?></span>
        <h3 class="svc-slide__title"><?= e($s['title']) ?></h3>
        <p class="svc-slide__text"><?= e($s['text']) ?></p>
        <?php if ($carouselFull): ?>
            <span class="svc-slide__actions">
                <a href="<?= e(wa_link($s['message'])) ?>" class="btn btn--accent btn--sm" target="_blank" rel="noopener"<?= $tab ?>><?= icon('whatsapp') ?> <?= e($s['cta']) ?></a>
                <?php if (!empty($s['simulate'])): ?>
                    <a href="#simulateur" class="btn btn--outline btn--sm open-calculator"<?= $tab ?>><?= icon('calc') ?> Simuler</a>
                <?php endif; ?>
            </span>
        <?php else: ?></a><?php endif; ?>
    </li>
    <?php
};
?>
<div class="svc-carousel">
    <ul class="svc-carousel__track" role="list">
        <?php foreach ($gtcServices as $s) { $serviceSlide($s, false); } ?>
        <?php // Copie pour la boucle continue : ignorée par les lecteurs d'écran et le clavier ?>
        <?php foreach ($gtcServices as $s) { $serviceSlide($s, true); } ?>
    </ul>
</div>
