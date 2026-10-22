<?php
/**
 * En-tête commun — Global Trading Corporation
 *
 * Variables optionnelles à définir AVANT l'inclusion :
 *   $pageTitle       (string) Titre de la page          ex. 'Services'
 *   $seoTitle        (string) Balise <title> complète (sinon « $pageTitle | Global Trading Corporation »)
 *   $pageDescription (string) Meta description (≈ 150 à 160 caractères), reprise par Open Graph
 *   $robots          (string) Directive robots (défaut : index, follow)
 *   $currentPage     (string) Clé du lien actif         'accueil' | 'services' | 'a-propos' | 'contact'
 *   $root            (string) Chemin vers la racine du site : '' (défaut) ou '../' pour les pages
 *                             d'un sous-dossier (ex. legal/), appliqué à tous les liens et fichiers.
 */

// ---------- Coordonnées (une seule source de vérité, réutilisée dans le footer) ----------
if (!defined('GTC_NAME')) {
    define('GTC_NAME', 'Global Trading Corporation');
    define('GTC_PHONE', '+237 233 43 13 22');
    define('GTC_PHONE_LINK', '+237233431322');
    define('GTC_WHATSAPP', '237673660000'); // format international, sans "+" ni espaces
    define('GTC_EMAIL', 'gtc8181@gmail.com');
    define('GTC_ADDRESS', '1.229 Paul Soppo Priso, Douala');
}

// ---------- Statistiques : visiteur compté une fois par jour (voir includes/visits.php) ----------
require_once __DIR__ . '/visits.php';
require_once __DIR__ . '/social.php';
require_once __DIR__ . '/seo.php';
track_visit();

$pageTitle       = $pageTitle       ?? 'Transfert de fonds & Échange de devises';
$pageDescription = $pageDescription ?? 'Global Trading Corporation : transfert de fonds à l\'international et échange de devises. Douala, Cameroun.';
$currentPage     = $currentPage     ?? 'accueil';
$root            = $root            ?? '';
$seoTitle        = $seoTitle        ?? $pageTitle . ' | ' . GTC_NAME;
$robots          = $robots          ?? 'index, follow, max-image-preview:large, max-snippet:-1';
$canonical       = seo_url(seo_current_path());
$shareImage      = seo_url(SEO_SHARE_IMAGE);

$navItems = [
    'accueil'  => ['label' => 'Accueil',   'href' => $root . 'index.php'],
    'services' => ['label' => 'Services',  'href' => $root . 'services.php'],
    'a-propos' => ['label' => 'À propos',  'href' => $root . 'a-propos.php'],
    'contact'  => ['label' => 'Contact',   'href' => $root . 'contact.php'],
];

if (!function_exists('e')) {
    /** Échappement HTML */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= e($seoTitle) ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">
    <meta name="robots" content="<?= e($robots) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta name="theme-color" content="#0F172A">

    <!-- Partage (WhatsApp, Facebook, LinkedIn : Open Graph · X / Twitter : Twitter Cards) -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(GTC_NAME) ?>">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:title" content="<?= e($seoTitle) ?>">
    <meta property="og:description" content="<?= e($pageDescription) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:image" content="<?= e($shareImage) ?>">
    <meta property="og:image:secure_url" content="<?= e($shareImage) ?>">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= e(GTC_NAME) ?> — bureau de change et transfert d'argent à Douala">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($seoTitle) ?>">
    <meta name="twitter:description" content="<?= e($pageDescription) ?>">
    <meta name="twitter:image" content="<?= e($shareImage) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://images.unsplash.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="<?= e($root) ?>assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="<?= e($root) ?>assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="alternate icon" href="<?= e($root) ?>favicon.ico">
    <link rel="apple-touch-icon" href="<?= e($root) ?>assets/img/apple-touch-icon.png">
    <link rel="manifest" href="<?= e($root) ?>site.webmanifest">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/custom-select.css', $root)) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css', $root)) ?>">

    <!-- Données structurées (référencement local Google) -->
    <?= seo_jsonld(seo_business()) ?>
    <?php if ($currentPage === 'accueil'): ?><?= seo_jsonld(seo_website()) ?><?php endif; ?>
</head>
<body>

<a class="skip-link" href="#contenu">Aller au contenu</a>

<!-- ==================== BARRE SUPÉRIEURE ==================== -->
<div class="topbar">
    <div class="container topbar__inner">
        <ul class="topbar__list">
            <li class="topbar__item">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.1 9.9a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/></svg>
                <a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a>
            </li>
            <li class="topbar__item">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                <span>Lun-Ven : 08h-17h30 &nbsp;·&nbsp; Sam : 09h-14h</span>
            </li>
        </ul>
        <p class="topbar__item topbar__zones">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/></svg>
            <span>Cameroun · Afrique · Europe · Amérique · Asie</span>
        </p>
    </div>
</div>

<!-- ==================== EN-TÊTE / NAVIGATION ==================== -->
<header class="site-header" id="site-header">
    <div class="container site-header__inner">
        <a href="<?= e($root) ?>index.php" class="logo" aria-label="<?= e(GTC_NAME) ?> — Accueil">
            <!-- Logo officiel (globe + GLOBAL TRADING CORPORATION) : le nom fait partie de l'image -->
            <img class="logo__img" src="<?= e(asset_url('assets/img/logo-gtc.png', $root)) ?>" alt="<?= e(GTC_NAME) ?>" width="202" height="50">
        </a>

        <button class="burger" id="burger" type="button"
                aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="main-nav">
            <span class="burger__bar"></span>
            <span class="burger__bar"></span>
            <span class="burger__bar"></span>
        </button>

        <nav class="main-nav" id="main-nav" aria-label="Navigation principale">
            <ul class="main-nav__list">
                <?php foreach ($navItems as $key => $item): ?>
                    <li>
                        <a href="<?= e($item['href']) ?>"
                           class="main-nav__link<?= $key === $currentPage ? ' is-active' : '' ?>"
                           <?= $key === $currentPage ? 'aria-current="page"' : '' ?>>
                            <?= e($item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?= social_links(['facebook', 'linkedin'], 'social--nav') ?>

            <a href="https://wa.me/<?= e(GTC_WHATSAPP) ?>" class="btn btn--accent main-nav__cta"
               target="_blank" rel="noopener">
                <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5Z"/></svg>
                WhatsApp / Contact
            </a>
        </nav>
    </div>
</header>

<main id="contenu">
