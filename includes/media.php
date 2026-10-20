<?php
/**
 * Médias — images Unsplash (licence libre) et badges de devises.
 *
 * Les images sont servies par le CDN d'Unsplash, redimensionnées et compressées
 * à la volée (paramètres w / q / auto=format → WebP/AVIF selon le navigateur).
 * Pour les héberger soi-même, il suffit de remplacer unsplash_url().
 */

const GTC_IMAGES = [
    'globe'     => '1451187580459-43490279c0fa', // Terre de nuit, lumières des villes
    'buildings' => '1486406146926-c627a92ad1ab', // Tours de bureaux vitrées
    'exchange'  => '1580048915913-4f8f5cb481c4', // Billets euros & dollars
    'transfer'  => '1563013544-824ae1b704d3',    // Carte bancaire & ordinateur
    'port'      => '1494412574643-ff11b0a5c1c3', // Port à conteneurs
    'phone'     => '1512428559087-560fa5ceab42', // Smartphone
    'office'    => '1497366811353-6870744d04b2', // Salle de réunion
    'handshake' => '1521791136064-7986c2920216', // Poignée de main
    'desk'      => '1520607162513-77705c0f0d4a', // Bureau, téléphone, ordinateur
];

/** URL optimisée d'une image Unsplash. */
function unsplash_url(string $key, int $width, ?int $height = null, int $quality = 70): string
{
    $id  = GTC_IMAGES[$key] ?? GTC_IMAGES['globe'];
    $url = "https://images.unsplash.com/photo-{$id}?auto=format&fit=crop&w={$width}&q={$quality}";
    return $height ? $url . "&h={$height}" : $url;
}

/**
 * Balise <img> responsive (srcset) avec dimensions pour éviter les décalages de mise en page.
 *
 * @param string $key    Clé de GTC_IMAGES
 * @param string $alt    Texte alternatif ('' pour une image décorative)
 * @param array  $opts   ratio (float, largeur/hauteur), sizes, class, eager (bool)
 */
function unsplash_img(string $key, string $alt, array $opts = []): string
{
    $ratio  = $opts['ratio'] ?? 16 / 9;
    $sizes  = $opts['sizes'] ?? '(max-width: 640px) 100vw, 50vw';
    $class  = $opts['class'] ?? '';
    $eager  = $opts['eager'] ?? false;
    $widths = [480, 800, 1200, 1800];

    $srcset = implode(', ', array_map(
        fn ($w) => htmlspecialchars(unsplash_url($key, $w, (int) round($w / $ratio)), ENT_QUOTES) . " {$w}w",
        $widths
    ));

    $attrs = [
        'src'      => htmlspecialchars(unsplash_url($key, 1200, (int) round(1200 / $ratio)), ENT_QUOTES),
        'srcset'   => $srcset,
        'sizes'    => $sizes,
        'alt'      => htmlspecialchars($alt, ENT_QUOTES),
        'width'    => 1200,
        'height'   => (int) round(1200 / $ratio),
        'loading'  => $eager ? 'eager' : 'lazy',
        'decoding' => $eager ? 'sync' : 'async',
    ];
    if ($eager) {
        $attrs['fetchpriority'] = 'high';
    }
    if ($class !== '') {
        $attrs['class'] = htmlspecialchars($class, ENT_QUOTES);
    }

    $html = '<img';
    foreach ($attrs as $name => $value) {
        $html .= " {$name}=\"{$value}\"";
    }
    return $html . '>';
}

/**
 * Grande image hébergée sur le site (bannières) : assets/img/hero/{nom}-{640|1024|1600|2400}.{webp|jpg},
 * WebP pour les navigateurs récents, JPEG sinon. Nouvelle image : générer ces 8 fichiers depuis l'original.
 *
 * @param array $opts width / height (dimensions de l'original réduit à 1600 px), class, prefix (chemin vers la racine)
 */
function local_picture(string $name, array $opts = []): string
{
    $prefix = $opts['prefix'] ?? '';
    $url = fn (int $w, string $ext) => htmlspecialchars(asset_url("assets/img/hero/{$name}-{$w}.{$ext}", $prefix), ENT_QUOTES);
    $set = fn (string $ext) => implode(', ', array_map(fn ($w) => $url($w, $ext) . " {$w}w", [640, 1024, 1600, 2400]));
    $class = isset($opts['class']) ? ' class="' . htmlspecialchars($opts['class'], ENT_QUOTES) . '"' : '';

    return '<picture><source type="image/webp" srcset="' . $set('webp') . '" sizes="100vw">'
         . '<img src="' . $url(1600, 'jpg') . '" srcset="' . $set('jpg') . '" sizes="100vw" alt=""'
         . ' width="' . (int) ($opts['width'] ?? 1600) . '" height="' . (int) ($opts['height'] ?? 900) . '"'
         . ' loading="eager" fetchpriority="high" decoding="async"' . $class . '></picture>';
}

/** Badge visuel d'une devise (symbole dans une pastille colorée). */
function currency_badge(string $code): string
{
    static $symbols = [
        'CAD'  => 'C$',
        'USD'  => '$',
        'EUR'  => '€',
        'FCFA' => 'F',
        'NGN'  => '₦',
        'AED'  => 'د.إ',
        'CNY'  => '¥',
    ];
    $symbol = $symbols[$code] ?? mb_substr($code, 0, 1);
    $mod    = strtolower($code);

    return '<span class="cur-badge cur-badge--' . htmlspecialchars($mod, ENT_QUOTES) . '" aria-hidden="true">'
         . htmlspecialchars($symbol, ENT_QUOTES) . '</span>';
}
