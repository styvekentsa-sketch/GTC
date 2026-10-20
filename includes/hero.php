<?php
/**
 * Composant Hero — bannière des pages secondaires.
 *
 * Variable requise (définie avant l'inclusion) :
 *   $hero = [
 *     'eyebrow' => 'Sur-titre',
 *     'title'   => 'Titre principal (h1)',
 *     'lead'    => 'Paragraphe d'introduction (optionnel)',
 *     'crumb'   => 'Libellé du fil d'Ariane',
 *     'parent'  => ['label' => 'Rubrique', 'href' => 'lien'] (optionnel, niveau intermédiaire du fil d'Ariane),
 *     'image'   => 'Clé GTC_IMAGES pour l'image de fond (optionnel, requiert media.php)',
 *     'picture' => ['name' => 'contact', 'width' => 1600, 'height' => 800] : image hébergée sur le site (prioritaire, voir local_picture()),
 *     'overlay' => 'light' (optionnel : voile plus léger, pour une image déjà sombre),
 *     'badges'  => [['icon' => 'zap', 'label' => 'Texte'], …] (optionnel, requiert icons.php),
 *     'actions' => [['label' => 'Texte', 'href' => '#', 'class' => 'btn--accent', 'icon' => 'calc'], …] (optionnel),
 *   ];
 */
$heroImage   = $hero['image'] ?? null;
$heroPicture = $hero['picture'] ?? null;

// Fil d'Ariane en données structurées (affiché sous forme de chemin dans les résultats Google)
if (function_exists('seo_breadcrumb')) {
    $crumbs = [['Accueil', '']];
    if (!empty($hero['parent'])) {
        $crumbs[] = [$hero['parent']['label'], dirname(seo_current_path()) . '/' . basename($hero['parent']['href'])];
    }
    $crumbs[] = [$hero['crumb'], seo_current_path()];
    echo seo_jsonld(seo_breadcrumb($crumbs));
}
?>
<section class="page-hero<?= $heroImage || $heroPicture ? ' page-hero--image' : '' ?><?= ($hero['overlay'] ?? '') === 'light' ? ' page-hero--light' : '' ?>">
    <?php if ($heroPicture): ?>
        <div class="page-hero__bg" aria-hidden="true">
            <?= local_picture($heroPicture['name'], $heroPicture + ['prefix' => $root ?? '']) ?>
        </div>
    <?php elseif ($heroImage): ?>
        <div class="page-hero__bg" aria-hidden="true">
            <?= unsplash_img($heroImage, '', ['ratio' => 21 / 9, 'sizes' => '100vw', 'eager' => true]) ?>
        </div>
    <?php endif; ?>

    <div class="container page-hero__inner">
        <nav class="breadcrumb" aria-label="Fil d'Ariane">
            <ol>
                <li><a href="<?= e($root ?? '') ?>index.php">Accueil</a></li>
                <?php if (!empty($hero['parent'])): ?>
                    <li><a href="<?= e($hero['parent']['href']) ?>"><?= e($hero['parent']['label']) ?></a></li>
                <?php endif; ?>
                <li aria-current="page"><?= e($hero['crumb']) ?></li>
            </ol>
        </nav>
        <span class="section__eyebrow section__eyebrow--light"><?= e($hero['eyebrow']) ?></span>
        <h1 class="page-hero__title"><?= e($hero['title']) ?></h1>
        <?php if (!empty($hero['lead'])): ?>
            <p class="page-hero__lead"><?= e($hero['lead']) ?></p>
        <?php endif; ?>
        <?php if (!empty($hero['actions'])): ?>
            <div class="page-hero__actions">
                <?php foreach ($hero['actions'] as $action): ?>
                    <a href="<?= e($action['href']) ?>" class="btn btn--lg <?= e($action['class'] ?? 'btn--accent') ?>">
                        <?= !empty($action['icon']) ? icon($action['icon']) : '' ?> <?= e($action['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($hero['badges'])): ?>
            <ul class="page-hero__badges">
                <?php foreach ($hero['badges'] as $badge): ?>
                    <li class="page-hero__badge"><?= icon($badge['icon']) ?> <?= e($badge['label']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
