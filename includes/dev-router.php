<?php
/**
 * Serveur de développement lancé depuis le mauvais dossier — rattrapage automatique.
 *
 * Cas visé : « php -S localhost:8000 » tapé dans le dossier dashboard\ (racine du serveur = dashboard\). Le serveur
 * intégré de PHP ne voit alors que ce dossier : CSS, scripts, images et pages publiques sont hors d'atteinte et le
 * Dashboard s'affiche sans mise en page. Ce serveur envoie toute adresse introuvable à dashboard/index.php, qui
 * appelle dev_route() : les fichiers du site sont servis sous le préfixe virtuel /__site/ (site_root() le renvoie
 * dans ce cas, includes/seo.php), et /dashboard/… redirige vers la page correspondante.
 *
 * Sans effet ailleurs : uniquement avec le serveur intégré (PHP_SAPI « cli-server ») ET une racine égale à dashboard\.
 * Apache, Nginx, l'hébergeur ou un serveur lancé depuis le dossier du site ne passent jamais par ce code.
 * Mêmes interdictions que .htaccess : data/, includes/, tools/, fichiers cachés et sensibles jamais servis.
 */

const DEV_SITE_PREFIX = '/__site/';

/** Serveur intégré de PHP dont la racine est le dossier dashboard\ ? */
function dev_docroot_is_dashboard(): bool
{
    if (PHP_SAPI !== 'cli-server') {
        return false;
    }
    $docroot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $dash    = realpath(__DIR__ . '/../dashboard');
    return $docroot !== false && $dash !== false && strcasecmp($docroot, $dash) === 0;
}

/**
 * Traite la requête si elle vise un fichier du site. Retourne le chemin d'une page PHP du site à exécuter
 * (à inclure par l'appelant, dans la portée globale), sinon null (requête ordinaire du Dashboard).
 * Les fichiers statiques sont envoyés directement (fin du script).
 */
function dev_route(): ?string
{
    if (!dev_docroot_is_dashboard()) {
        return null;
    }
    $uri = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

    // Adresse habituelle du Dashboard (/dashboard/…) : même page, à la racine de ce serveur
    if (preg_match('#^/dashboard(/.*)?$#i', $uri, $m)) {
        $query = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        header('Location: ' . (($m[1] ?? '') !== '' ? $m[1] : '/') . ($query !== '' ? '?' . $query : ''), true, 302);
        exit;
    }
    if (str_starts_with($uri, DEV_SITE_PREFIX)) {
        $rel = substr($uri, strlen(DEV_SITE_PREFIX));
    } elseif (preg_match('#^/(assets/|favicon\.ico$|site\.webmanifest$|robots\.txt$|sitemap\.xml$)#', $uri)) {
        $rel = ltrim($uri, '/');   // fichiers du site demandés sans préfixe (ex. favicon par défaut du navigateur)
    } else {
        return null;
    }
    $rel = $rel === '' ? 'index.php' : $rel;

    // Interdits (comme .htaccess) : dossiers internes, fichiers cachés, extensions sensibles
    $site = realpath(__DIR__ . '/..');
    $file = realpath($site . '/' . $rel);
    $deny = '#(^|/)(\.|includes/|data/|tools/)|\.(bak|backup|old|orig|sql|sqlite|db|log|ini|env|sh|dist|md|lock|swp)$#i';
    if ($file === false || is_dir($file) || !str_starts_with($file, $site . DIRECTORY_SEPARATOR) || preg_match($deny, str_replace('\\', '/', $rel))) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Introuvable.');
    }

    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
        // Page du site : exécutée comme si elle avait été demandée directement
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $_SERVER['SCRIPT_NAME']     = $_SERVER['PHP_SELF'] = DEV_SITE_PREFIX . str_replace('\\', '/', $rel);
        chdir(dirname($file));
        return $file;
    }

    $types = [
        'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8', 'json' => 'application/json',
        'webmanifest' => 'application/manifest+json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2',
        'woff' => 'font/woff', 'html' => 'text/html; charset=UTF-8', 'xml' => 'application/xml', 'txt' => 'text/plain; charset=UTF-8',
        'pdf' => 'application/pdf',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-cache');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}
