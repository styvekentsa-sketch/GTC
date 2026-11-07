<?php
/**
 * Référencement naturel (SEO) — Global Trading Corporation
 *
 * - URL canonique de chaque page (GTC_SITE_URL + chemin du fichier) ;
 * - fichiers CSS / JS versionnés (?v=date de modification) et version minifiée utilisée
 *   automatiquement si elle est à jour (générée par « php tools/build.php ») ;
 * - données structurées Schema.org (JSON-LD) : FinancialService (référencement local),
 *   WebSite (accueil), BreadcrumbList (pages secondaires).
 */

require_once __DIR__ . '/dev-router.php';   // dev_docroot_is_dashboard() : serveur de développement lancé depuis dashboard\

const SEO_ROOT = __DIR__ . '/..';

/** Horaires d'ouverture (une seule source pour les données structurées). */
const SEO_OPENING_HOURS = [
    ['days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '08:00', 'closes' => '17:30'],
    ['days' => ['Saturday'], 'opens' => '09:00', 'closes' => '14:00'],
];

/** Image de partage (Open Graph / Twitter), 1200 × 630 px. */
const SEO_SHARE_IMAGE = 'assets/img/og-image.jpg';

/** Chemin de la page courante depuis la racine du site : '' (accueil), 'services.php', 'legal/cgu.php'… */
function seo_current_path(): string
{
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $root   = realpath(SEO_ROOT) ?: '';
    $path   = ($root !== '' && str_starts_with($script, $root))
        ? str_replace('\\', '/', ltrim(substr($script, strlen($root)), '\\/'))
        : ltrim((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $path === 'index.php' ? '' : $path;
}

/** Adresse absolue d'une page ou d'un fichier du site. */
function seo_url(string $path = ''): string
{
    return GTC_SITE_URL . '/' . ltrim($path, '/');
}

/**
 * Chemin absolu de la racine du site dans l'adresse, avec « / » final : « / » si le site est à la racine du domaine,
 * « /gtc/ » s'il est installé dans un sous-dossier. Sert de préfixe aux fichiers (CSS, JS, images) des pages du
 * Dashboard : contrairement à « ../ », il reste juste quelle que soit l'adresse de la page (barre oblique finale,
 * segment supplémentaire, alias de l'hébergeur…).
 * Calculé à partir du script exécuté, sans réglage ; GTC_BASE_PATH (includes/db.local.php) permet de le forcer.
 */
function site_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    if (defined('GTC_BASE_PATH')) {
        $forced = trim((string) GTC_BASE_PATH, '/');
        return $root = $forced === '' ? '/' : '/' . $forced . '/';
    }
    // Serveur de développement lancé depuis dashboard\ : fichiers du site servis sous /__site/ (includes/dev-router.php)
    if (dev_docroot_is_dashboard()) {
        return $root = DEV_SITE_PREFIX;
    }
    // Ex. : fichier …/Site GTC/dashboard/index.php exécuté sous l'adresse /gtc/dashboard/index.php → racine « /gtc/ »
    $base    = '';
    $siteDir = str_replace('\\', '/', (string) realpath(SEO_ROOT));
    $script  = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $name    = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($siteDir !== '' && $script !== '' && stripos($script, $siteDir . '/') === 0) {
        $rel = substr($script, strlen($siteDir));                       // « /dashboard/index.php »
        if (strlen($name) >= strlen($rel) && strcasecmp(substr($name, -strlen($rel)), $rel) === 0) {
            $base = substr($name, 0, strlen($name) - strlen($rel));     // « » ou « /gtc »
        }
    }
    return $root = rtrim($base, '/') . '/';
}

/**
 * URL d'un fichier CSS / JS, versionnée par sa date de modification.
 * Si « fichier.min.css » existe et est au moins aussi récent que la source, il est servi à la place.
 * $prefix : chemin vers la racine du site ('' ou '../').
 */
function asset_url(string $path, string $prefix = ''): string
{
    $source = SEO_ROOT . '/' . $path;
    $min    = preg_replace('/\.(css|js)$/', '.min.$1', $path);
    if ($min !== $path && is_file(SEO_ROOT . '/' . $min) && is_file($source) && filemtime(SEO_ROOT . '/' . $min) >= filemtime($source)) {
        $path = $min;
        $source = SEO_ROOT . '/' . $min;
    }
    return $prefix . $path . (is_file($source) ? '?v=' . filemtime($source) : '');
}

/** Bloc <script type="application/ld+json"> (balises HTML neutralisées dans le JSON). */
function seo_jsonld(array $data): string
{
    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
        . "</script>\n";
}

/** Entreprise : FinancialService (type LocalBusiness) pour le référencement local et la fiche Google. */
function seo_business(): array
{
    $data = [
        '@context'    => 'https://schema.org',
        '@type'       => 'FinancialService',
        '@id'         => seo_url('#entreprise'),
        'name'        => GTC_NAME,
        'alternateName' => 'GTC',
        'legalName'   => GTC_NAME,
        'url'         => seo_url(),
        'logo'        => seo_url('assets/img/logo-gtc-print.jpg'),
        'image'       => seo_url(SEO_SHARE_IMAGE),
        'description' => "Bureau de change et transfert d'argent à Douala : change FCFA, EUR, USD, CAD, Naira, Dirham de Dubaï et Yuan, "
                       . "transferts de fonds vers l'Afrique, l'Europe, l'Amérique et l'Asie, accompagnement import-export.",
        'telephone'   => GTC_PHONE_LINK,
        'email'       => GTC_EMAIL,
        'currenciesAccepted' => 'XAF, EUR, USD, CAD, NGN, AED, CNY',
        'knowsLanguage' => 'fr',
        'address'     => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => '1.229 Rue Paul Soppo Priso, Bonapriso',
            'addressLocality' => 'Douala',
            'addressRegion'   => 'Littoral',
            'addressCountry'  => 'CM',
        ],
        'openingHoursSpecification' => array_map(fn ($h) => [
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => count($h['days']) === 1 ? $h['days'][0] : $h['days'],
            'opens'     => $h['opens'],
            'closes'    => $h['closes'],
        ], SEO_OPENING_HOURS),
        'contactPoint' => [
            ['@type' => 'ContactPoint', 'telephone' => GTC_PHONE_LINK, 'contactType' => 'customer service', 'areaServed' => 'CM', 'availableLanguage' => 'French'],
            ['@type' => 'ContactPoint', 'telephone' => GTC_PHONE_2_LINK, 'contactType' => 'customer service', 'areaServed' => 'CM', 'availableLanguage' => 'French'],
        ],
        'areaServed' => [
            ['@type' => 'City', 'name' => 'Douala'],
            ['@type' => 'Country', 'name' => 'Cameroun'],
        ],
        'hasOfferCatalog' => [
            '@type'           => 'OfferCatalog',
            'name'            => 'Services de Global Trading Corporation',
            'itemListElement' => array_map(fn ($name) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $name]], [
                'Change de devises (FCFA, EUR, USD, CAD, NGN, AED, CNY)',
                "Transfert d'argent international",
                'Accompagnement import-export et commerce international',
            ]),
        ],
    ];
    if (GTC_GEO_LAT !== '' && GTC_GEO_LNG !== '') {
        $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) GTC_GEO_LAT, 'longitude' => (float) GTC_GEO_LNG];
        $data['hasMap'] = 'https://www.google.com/maps/search/?api=1&query=' . GTC_GEO_LAT . ',' . GTC_GEO_LNG;
    }
    $social = array_values(array_filter(defined('GTC_SOCIAL') ? GTC_SOCIAL : [], fn ($u) => is_string($u) && preg_match('#^https?://#', $u)));
    if ($social) {
        $data['sameAs'] = $social;
    }
    return $data;
}

/** Site web (page d'accueil). */
function seo_website(): array
{
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'WebSite',
        '@id'        => seo_url('#site'),
        'name'       => GTC_NAME,
        'url'        => seo_url(),
        'inLanguage' => 'fr-CM',
        'publisher'  => ['@id' => seo_url('#entreprise')],
    ];
}

/**
 * Page courante (WebPage, AboutPage, ContactPage…) rattachée au site et à l'entreprise : Google relie ainsi chaque
 * page à la même fiche (nom, adresse, avis) au lieu de les traiter comme des documents isolés.
 */
function seo_webpage(string $type, string $title, string $description): array
{
    $url = seo_url(seo_current_path());
    return [
        '@context'    => 'https://schema.org',
        '@type'       => $type,
        '@id'         => $url . '#page',
        'url'         => $url,
        'name'        => $title,
        'description' => $description,
        'inLanguage'  => 'fr-CM',
        'isPartOf'    => ['@id' => seo_url('#site')],
        'about'       => ['@id' => seo_url('#entreprise')],
        'primaryImageOfPage' => seo_url(SEO_SHARE_IMAGE),
    ];
}

/**
 * Note moyenne et avis publiés (page Avis) : complète la fiche de l'entreprise (même @id).
 * Rien n'est publié tant qu'aucun avis n'est validé (Google refuse une note sans avis).
 * $stats : review_stats() · $rows : avis publiés tels que lus par reviews_approved().
 */
function seo_reviews(array $stats, array $rows): ?array
{
    if (($stats['count'] ?? 0) < 1) {
        return null;
    }
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'FinancialService',
        '@id'             => seo_url('#entreprise'),
        'name'            => GTC_NAME,
        'aggregateRating' => [
            '@type'       => 'AggregateRating',
            'ratingValue' => $stats['average'],
            'reviewCount' => $stats['count'],
            'bestRating'  => 5,
            'worstRating' => 1,
        ],
        'review' => array_map(fn ($r) => [
            '@type'        => 'Review',
            'author'       => ['@type' => 'Person', 'name' => (string) $r['author_name']],
            'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $r['rating'], 'bestRating' => 5, 'worstRating' => 1],
            'reviewBody'   => (string) $r['comment'],
        ] + (strtotime((string) $r['created_at']) ? ['datePublished' => date('Y-m-d', strtotime((string) $r['created_at']))] : []), array_slice($rows, 0, 10)),
    ];
}

/**
 * Taux du guichet (page Taux du jour) : une ExchangeRateSpecification par devise, en FCFA.
 * Taux publié = milieu entre achat et vente ; écart = vente − achat. $rates : company_rates_js('FCFA').
 */
function seo_exchange_rates(array $rates): ?array
{
    $items = [];
    foreach ($rates as $code => $r) {
        $buy = (float) ($r['buy'] ?? 0);
        $sell = (float) ($r['sell'] ?? 0);
        if (in_array($code, ['FCFA', 'XAF'], true) || $buy <= 0 || $sell <= 0) {
            continue;
        }
        $items[] = [
            '@type'               => 'ExchangeRateSpecification',
            'currency'            => $code,
            'currentExchangeRate' => ['@type' => 'UnitPriceSpecification', 'price' => round(($buy + $sell) / 2, 4), 'priceCurrency' => 'XAF'],
            'exchangeRateSpread'  => ['@type' => 'MonetaryAmount', 'value' => round($sell - $buy, 4), 'currency' => 'XAF'],
        ];
    }
    if (!$items) {
        return null;
    }
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => 'Taux de change du jour — ' . GTC_NAME . ', Douala',
        'itemListElement' => array_map(fn ($item, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => $item], $items, array_keys($items)),
    ];
}

/** Fil d'Ariane : [[libellé, chemin], …] (le dernier élément est la page courante). */
function seo_breadcrumb(array $items): array
{
    $list = [];
    foreach (array_values($items) as $i => [$name, $path]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => seo_url($path)];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}
