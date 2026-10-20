<?php
/**
 * Préparation de la mise en ligne — à lancer en ligne de commande :  php tools/build.php
 *
 * 1. Minifie les feuilles de style et scripts (assets/css/*.css, assets/js/*.js → *.min.css / *.min.js).
 *    Le site sert automatiquement la version minifiée tant qu'elle est à jour (asset_url(), includes/seo.php) ;
 *    si une source est modifiée ensuite, c'est la source qui est servie jusqu'au prochain lancement.
 * 2. Régénère sitemap.xml (pages publiques, date de dernière modification de chaque page).
 *
 * Le dossier tools/ est inaccessible depuis le web (.htaccess).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/includes/config.php';

/* ==========================================================================
   Minification
   ========================================================================== */

/** CSS : commentaires et espaces superflus supprimés ; chaînes et url() intactes. */
function minify_css(string $css): string
{
    $out = '';
    $len = strlen($css);
    for ($i = 0; $i < $len; $i++) {
        $c = $css[$i];
        if ($c === '/' && ($css[$i + 1] ?? '') === '*') {          // commentaire
            $end = strpos($css, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
            continue;
        }
        if ($c === '"' || $c === "'") {                              // chaîne : copiée telle quelle
            $j = $i + 1;
            while ($j < $len && $css[$j] !== $c) {
                $j += $css[$j] === '\\' ? 2 : 1;
            }
            $out .= substr($css, $i, $j - $i + 1);
            $i = $j;
            continue;
        }
        if (ctype_space($c)) {                                       // espaces → un seul
            while ($i + 1 < $len && ctype_space($css[$i + 1])) {
                $i++;
            }
            $out .= ' ';
            continue;
        }
        $out .= $c;
    }
    // Espaces inutiles autour de { } ; , > ~ et après « : » (jamais autour de + et - : calc())
    $out = preg_replace('/\s*([{};,>~])\s*/', '$1', $out);
    $out = preg_replace('/:\s+/', ':', $out);
    $out = str_replace(';}', '}', $out);
    return trim($out) . "\n";
}

/**
 * JS (prudent) : indentation, lignes vides et commentaires occupant des lignes entières supprimés.
 * Le code n'est jamais modifié à l'intérieur d'une ligne (chaînes, expressions régulières préservées).
 */
function minify_js(string $js): string
{
    $out = [];
    $inBlock = false;
    foreach (preg_split('/\R/', $js) as $line) {
        $t = trim($line);
        if ($inBlock) {
            if (str_contains($t, '*/')) {
                $inBlock = false;
                $rest = trim(substr($t, strpos($t, '*/') + 2));
                if ($rest !== '') {
                    $out[] = $rest;
                }
            }
            continue;
        }
        if ($t === '' || str_starts_with($t, '//')) {
            continue;
        }
        if (str_starts_with($t, '/*')) {
            if (!str_contains($t, '*/')) {
                $inBlock = true;
            } else {
                $rest = trim(substr($t, strpos($t, '*/') + 2));
                if ($rest !== '') {
                    $out[] = $rest;
                }
            }
            continue;
        }
        $out[] = $t;
    }
    return implode("\n", $out) . "\n";
}

$total = [0, 0];
foreach (array_merge(glob($root . '/assets/css/*.css'), glob($root . '/assets/js/*.js')) as $file) {
    if (preg_match('/\.min\.(css|js)$/', $file)) {
        continue;
    }
    $source = file_get_contents($file);
    $isCss  = str_ends_with($file, '.css');
    $min    = $isCss ? minify_css($source) : minify_js($source);
    $target = preg_replace('/\.(css|js)$/', '.min.$1', $file);
    file_put_contents($target, $min);
    touch($target, max(filemtime($file), time()));
    $total[0] += strlen($source);
    $total[1] += strlen($min);
    printf("  %-34s %7s → %7s  (-%d %%)\n", str_replace($root . '/', '', $file), number_format(strlen($source), 0, ',', ' '),
        number_format(strlen($min), 0, ',', ' '), round((1 - strlen($min) / max(1, strlen($source))) * 100));
}
printf("  Total : %s → %s octets (avant compression GZIP)\n\n", number_format($total[0], 0, ',', ' '), number_format($total[1], 0, ',', ' '));

/* ==========================================================================
   sitemap.xml
   ========================================================================== */

$pages = [
    ''                                    => 'index.php',
    'services.php'                        => 'services.php',
    'a-propos.php'                        => 'a-propos.php',
    'contact.php'                         => 'contact.php',
    'legal/mentions-legales.php'          => 'legal/mentions-legales.php',
    'legal/politique-confidentialite.php' => 'legal/politique-confidentialite.php',
    'legal/cgu.php'                       => 'legal/cgu.php',
    'legal/cookies.php'                   => 'legal/cookies.php',
];
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
     . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $path => $file) {
    $xml .= "  <url>\n"
          . '    <loc>' . htmlspecialchars(GTC_SITE_URL . '/' . $path, ENT_XML1) . "</loc>\n"
          . '    <lastmod>' . date('Y-m-d', filemtime($root . '/' . $file)) . "</lastmod>\n"
          . "  </url>\n";
}
$xml .= "</urlset>\n";
file_put_contents($root . '/sitemap.xml', $xml);
echo '  sitemap.xml : ' . count($pages) . " pages\n";
