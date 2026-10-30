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
        'url'         => seo_url(),
        'logo'        => seo_url('assets/img/logo-gtc-print.jpg'),
        'image'       => seo_url(SEO_SHARE_IMAGE),
        'description' => "Bureau de change et transfert d'argent à Douala : change FCFA, EUR, USD, CAD, Naira, Dirham de Dubaï et Yuan, "
                       . "transferts de fonds vers l'Afrique, l'Europe, l'Amérique et l'Asie, accompagnement import-export.",
        'telephone'   => GTC_PHONE_LINK,
        'email'       => GTC_EMAIL,
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

/** Fil d'Ariane : [[libellé, chemin], …] (le dernier élément est la page courante). */
function seo_breadcrumb(array $items): array
{
    $list = [];
    foreach (array_values($items) as $i => [$name, $path]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => seo_url($path)];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}
