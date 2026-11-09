<?php
/**
 * GTC-Vault — Rendu sécurisé d'une pièce justificative
 *
 *   viewer.php?d=ID&m=image&e=EXPIRATION&s=SIGNATURE   (lien produit par vault_viewer_url(), valable 10 minutes)
 *
 * - Les fichiers sont rangés hors de la racine web : ce script est le seul chemin pour les voir.
 * - Accès : session + rôle KYC + lien signé pour CETTE session (un lien copié ailleurs ou expiré est refusé).
 * - Images (JPG, PNG) : filigrane incrusté dans les pixels côté serveur (GD), original jamais envoyé,
 *   image réduite à 1 800 px (lisible à l'écran, peu exploitable pour une contrefaçon).
 * - PDF (m=pdf) : envoyé uniquement au lecteur de la fiche (PDF.js), qui peint le filigrane dans chaque page.
 * - Chaque consultation est enregistrée dans audit_logs ; chaque refus aussi.
 */
declare(strict_types=1);

// Réponse binaire (image, PDF) : aucun message PHP ne doit s'y glisser (il corromprait le fichier
// et pourrait révéler des chemins du serveur). Les erreurs restent écrites dans le journal PHP.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../includes/vault.php';

$user = vault_guard('plain');   // connexion du Dashboard + rôle VAULT_ROLES

$docId = (int) ($_GET['d'] ?? 0);
// image : aperçu filigrané · print : même image pour le dossier imprimé (print.php), journalisée comme impression
$mode  = in_array($_GET['m'] ?? '', ['pdf', 'print'], true) ? (string) $_GET['m'] : 'image';
$valid = vault_viewer_check($docId, $mode, (int) ($_GET['e'] ?? 0), (string) ($_GET['s'] ?? ''));
session_write_close();   // la session n'est plus modifiée : les autres requêtes de l'agent ne sont pas bloquées

/** Réponse d'erreur sans aucun contenu du dossier. */
function viewer_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

if (!$valid) {
    vault_audit('viewer_invalid_link', null, $docId ?: null, 'Lien de consultation invalide ou expiré (document #' . $docId . ')');
    viewer_fail(403, 'Lien de consultation expiré ou invalide. Rouvrez la fiche du client.');
}

// ---------- Document et client ----------
$st = vault_db()->prepare('SELECT d.id, d.type_document, d.chemin_fichier, d.mime_type, c.uuid, c.nom_complet
                           FROM client_documents d JOIN clients c ON c.id = d.client_id WHERE d.id = ?');
$st->execute([$docId]);
$doc = $st->fetch();
if (!$doc) {
    viewer_fail(404, 'Document introuvable.');
}

// Fichier : obligatoirement à l'intérieur du dossier de stockage (aucun chemin « ../ » ne peut en sortir)
$base = realpath(KYC_STORAGE_DIR);
$path = realpath(KYC_STORAGE_DIR . '/' . $doc['chemin_fichier']);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    error_log('[GTC-Vault viewer] fichier absent ou hors stockage : document #' . $docId);
    viewer_fail(404, 'Fichier introuvable sur le serveur.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
$label = KYC_DOC_LABELS[$doc['type_document']] ?? 'Document';

// En-têtes communs : jamais en cache, jamais interprété autrement, jamais intégrable par un autre site
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

// ---------- PDF : lecteur de la fiche uniquement ----------
if ($mode === 'pdf') {
    if ($mime !== 'application/pdf') {
        viewer_fail(415, 'Ce document n\'est pas un PDF.');
    }
    // Requête Fetch du lecteur (en-tête ajouté par client.php) : ouvrir l'adresse directement ne télécharge rien
    if (($_SERVER['HTTP_X_GTC_VIEWER'] ?? '') !== '1' || ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'empty') !== 'empty') {
        viewer_fail(403, 'Ce document se consulte uniquement depuis la fiche du client.');
    }
    // PDF lu pour le dossier imprimé (print.php envoie X-GTC-Print) : journalisé comme impression
    $printing = ($_SERVER['HTTP_X_GTC_PRINT'] ?? '') === '1';
    vault_audit($printing ? 'document_print' : 'document_view', $doc, (int) $doc['id'],
        $user['name'] . ($printing ? ' a imprimé « ' : ' a consulté « ') . $label . ' » (PDF) du client ' . $doc['nom_complet']);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="document.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---------- Image : filigrane incrusté côté serveur ----------
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    viewer_fail(415, 'Aperçu indisponible pour ce type de fichier.');
}
if (!function_exists('imagecreatefromjpeg')) {
    error_log('[GTC-Vault viewer] extension GD absente : aperçu impossible');
    viewer_fail(500, 'Aperçu indisponible : le serveur ne gère pas les images (extension PHP GD).');
}
ini_set('memory_limit', '256M');   // photo de 12 Mpx décompressée : ~50 Mo

// Image ouverte et remise à l'endroit (EXIF) — même lecture que le recadrage des photos de profil (avatar.php)
$img = kyc_open_image($path, $mime);
if (!$img) {
    viewer_fail(422, 'Image illisible.');
}

$printing = $mode === 'print';

// Impression : la carte est détourée du fond de la photo (table, main…) pour occuper tout le cadre imprimé,
// à pleine définition — c'est ce qui rend lisibles les mentions de la pièce sur le papier
$sx = 0;
$sy = 0;
$w = imagesx($img);
$h = imagesy($img);
if ($printing && ($box = viewer_card_bounds($img))) {
    [$sx, $sy, $w, $h] = $box;
}

// Réduction (écran : 1 800 px ; impression : 2 200 px, ≈ 600 ppp sur 9 cm) et fond blanc sous la transparence des PNG
$scale = min(1, ($printing ? 2200 : 1800) / max($w, $h));
$W = max(1, (int) round($w * $scale));
$H = max(1, (int) round($h * $scale));
$out = imagecreatetruecolor($W, $H);
imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
imagecopyresampled($out, $img, 0, 0, $sx, $sy, $W, $H, $w, $h);

if ($printing) {
    // Netteté et contraste légers : texte fin de la carte mieux restitué par l'imprimante
    imagefilter($out, IMG_FILTER_CONTRAST, -8);
    imageconvolution($out, [[0, -1, 0], [-1, 6, -1], [0, -1, 0]], 2, 0);
}
viewer_watermark($out, vault_watermark_text(), 'Réf. ' . substr($doc['uuid'], 0, 8) . ' · pièce #' . $doc['id'] . ($printing ? ' · impression enregistrée' : ' · consultation enregistrée'), $printing);

vault_audit($printing ? 'document_print' : 'document_view', $doc, (int) $doc['id'],
    $user['name'] . ($printing ? ' a imprimé « ' : ' a consulté « ') . $label . ' » du client ' . $doc['nom_complet']);

header('Content-Type: image/jpeg');
header('Content-Disposition: inline; filename="apercu-filigrane.jpg"');
imagejpeg($out, null, $printing ? 90 : 82);
exit;

/**
 * Filigrane : texte répété en diagonale sur toute l'image (semi-transparent, sombre bordé de clair pour rester
 * visible sur fond clair comme sur fond sombre) + bandeau de traçabilité en bas.
 * Impression ($print) : filigrane plus léger et plus espacé, sans liseré clair, pour que les mentions de la carte
 * restent lisibles au travers ; le bandeau est ajouté SOUS l'image, il ne masque plus le bas de la carte.
 */
function viewer_watermark(GdImage &$im, string $text, string $footer, bool $print = false): void
{
    $W = imagesx($im);
    $H = imagesy($im);
    $font = viewer_font();
    $dark  = imagecolorallocatealpha($im, 15, 23, 42, $print ? 100 : 88);     // alpha GD : 0 opaque → 127 transparent
    $light = imagecolorallocatealpha($im, 255, 255, 255, 96);

    if ($font) {
        $size  = max(11, (int) round(min($W, $H) / ($print ? 34 : 30)));
        $angle = 30;
        $box   = imagettfbbox($size, 0, $font, $text);
        $tw    = $box[2] - $box[0];
        $stepX = $tw + $size * ($print ? 6 : 4);
        $stepY = $size * ($print ? 10 : 7);
        $row = 0;
        for ($y = -$H; $y < $H * 2; $y += $stepY, $row++) {
            for ($x = -$W + ($row % 2) * (int) ($stepX / 2); $x < $W * 2; $x += $stepX) {
                if (!$print) {
                    imagettftext($im, $size, $angle, $x + 1, $y + 1, $light, $font, $text);
                }
                imagettftext($im, $size, $angle, $x, $y, $dark, $font, $text);
            }
        }
        // Bandeau du bas (impression : sous l'image, texte ajusté à la largeur)
        $fs = max(9, (int) round($print ? max($W, $H) / 55 : $size * 0.7));
        $bandH = (int) round($fs * 2.6);
        $line = $text . '  ·  ' . $footer;
        if ($print) {
            $im = viewer_extend_bottom($im, $bandH);
            $H += $bandH;
            $lb = imagettfbbox($fs, 0, $font, $line);
            $fs = max(8, min($fs, (int) floor($fs * ($W - 2 * $fs) / max(1, $lb[2] - $lb[0]))));
        }
        imagefilledrectangle($im, 0, $H - $bandH, $W, $H, imagecolorallocatealpha($im, 15, 23, 42, $print ? 0 : 30));
        imagettftext($im, $fs, 0, (int) round($fs * 0.9), $H - (int) round($bandH * 0.32), imagecolorallocate($im, 255, 255, 255), $font, $line);
    } else {
        // Sans police TrueType : police intégrée de GD, à l'horizontale
        $line = $text . '   ';
        $cw = imagefontwidth(5);
        $ch = imagefontheight(5);
        $row = 0;
        for ($y = 0; $y < $H; $y += $ch * 4, $row++) {
            for ($x = -($row % 3) * $cw * 12; $x < $W; $x += strlen($line) * $cw) {
                imagestring($im, 5, $x, $y, $line, $dark);
            }
        }
        if ($print) {
            $im = viewer_extend_bottom($im, $ch * 2);
            $H += $ch * 2;
        }
        imagefilledrectangle($im, 0, $H - $ch * 2, $W, $H, imagecolorallocatealpha($im, 15, 23, 42, $print ? 0 : 30));
        imagestring($im, 3, 6, $H - (int) ($ch * 1.5), $text . ' - ' . $footer, imagecolorallocate($im, 255, 255, 255));
    }
}

/** Ajoute une bande sombre de $px pixels sous l'image (support du bandeau de traçabilité à l'impression). */
function viewer_extend_bottom(GdImage $im, int $px): GdImage
{
    $W = imagesx($im);
    $H = imagesy($im);
    $big = imagecreatetruecolor($W, $H + $px);
    imagefill($big, 0, 0, imagecolorallocate($big, 15, 23, 42));
    imagecopy($big, $im, 0, 0, 0, 0, $W, $H);
    return $big;
}

/**
 * Détourage d'une pièce photographiée : rectangle de la carte posée sur un fond (table, tissu, main…).
 * Analyse d'une miniature : couleur du fond lue sur le pourtour, puis lignes et colonnes qui traversent la carte.
 * Renvoie [x, y, largeur, hauteur] dans l'image d'origine, ou null quand rien de fiable n'est trouvé (scan déjà
 * cadré, fond trop chargé, carte trop petite…) : l'image est alors imprimée entière, rien n'est rogné à tort.
 */
function viewer_card_bounds(GdImage $img): ?array
{
    $w = imagesx($img);
    $h = imagesy($img);
    $k = min(1, 260 / max($w, $h));
    $tw = max(16, (int) round($w * $k));
    $th = max(16, (int) round($h * $k));
    $t = imagecreatetruecolor($tw, $th);
    imagecopyresampled($t, $img, 0, 0, 0, 0, $tw, $th, $w, $h);

    // Fond : médiane, canal par canal, des pixels du pourtour (bande de 4 %)
    $bw = max(2, (int) round(min($tw, $th) * 0.04));
    $px = [];
    $edge = [[], [], []];
    for ($y = 0; $y < $th; $y++) {
        for ($x = 0; $x < $tw; $x++) {
            $c = imagecolorat($t, $x, $y);
            $p = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            $px[] = $p;
            if ($x < $bw || $y < $bw || $x >= $tw - $bw || $y >= $th - $bw) {
                $edge[0][] = $p[0];
                $edge[1][] = $p[1];
                $edge[2][] = $p[2];
            }
        }
    }
    $n = count($edge[0]);
    $bg = [];
    foreach ($edge as $c => $vals) {
        $sorted = $vals;
        sort($sorted);
        $bg[$c] = $sorted[intdiv($n, 2)];
    }
    // Fond uniforme ? Écart moyen du pourtour : trop grand, il n'y a pas de fond identifiable (pièce déjà cadrée)
    $dev = 0;
    for ($i = 0; $i < $n; $i++) {
        $dev += abs($edge[0][$i] - $bg[0]) + abs($edge[1][$i] - $bg[1]) + abs($edge[2][$i] - $bg[2]);
    }
    $dev /= max(1, $n);
    if ($dev > 70) {
        return null;
    }
    $thr = 32 + 2 * $dev;   // fond uni : seuil bas (carte claire sur feuille blanche) ; fond texturé : plus haut

    // Pixels « carte » (nettement différents du fond), comptés par colonne et par ligne
    $mask = [];
    $cols = array_fill(0, $tw, 0);
    $rows = array_fill(0, $th, 0);
    foreach ($px as $i => $p) {
        $on = abs($p[0] - $bg[0]) + abs($p[1] - $bg[1]) + abs($p[2] - $bg[2]) > $thr;
        $mask[$i] = $on;
        if ($on) {
            $cols[$i % $tw]++;
            $rows[intdiv($i, $tw)]++;
        }
    }
    // Plus longue suite de colonnes (lignes) où la carte occupe au moins 12 % de la hauteur (largeur)
    $span = static function (array $counts, int $len): ?array {
        $best = null;
        $start = null;
        $limit = max(3, $len * 0.12);
        $counts[] = 0;   // sentinelle : ferme une suite qui touche le bord
        foreach ($counts as $i => $v) {
            if ($v >= $limit && $start === null) {
                $start = $i;
            } elseif ($v < $limit && $start !== null) {
                if (!$best || $i - $start > $best[1] - $best[0]) {
                    $best = [$start, $i];
                }
                $start = null;
            }
        }
        return $best;
    };
    $sx = $span($cols, $th);
    $sy = $span($rows, $tw);
    if (!$sx || !$sy) {
        return null;
    }
    [$x0, $x1] = $sx;
    [$y0, $y1] = $sy;
    $bw2 = $x1 - $x0;
    $bh2 = $y1 - $y0;

    // Zone plausible : 4 % à 90 % de la photo, forme d'une carte ou d'une page (rapport 1,2 à 2,1), bien pleine,
    // et couvrant le centre de la photo (une pièce photographiée est cadrée au centre ; sur un scan déjà cadré,
    // la zone repérée serait la photo d'identité, toujours sur un côté de la carte : elle n'est pas retenue)
    $area  = ($bw2 * $bh2) / ($tw * $th);
    $ratio = max($bw2, $bh2) / max(1, min($bw2, $bh2));
    if ($ratio < 1.2 || $x0 > $tw * 0.42 || $x1 < $tw * 0.58 || $y0 > $th * 0.42 || $y1 < $th * 0.58) {
        return null;
    }
    $fill = 0;
    for ($y = $y0; $y < $y1; $y++) {
        for ($x = $x0; $x < $x1; $x++) {
            $fill += $mask[$y * $tw + $x] ? 1 : 0;
        }
    }
    $fill /= max(1, $bw2 * $bh2);
    if ($area < 0.04 || $area > 0.90 || $ratio > 2.1 || $fill < 0.55) {
        return null;
    }

    // Marge de sécurité de 3 % autour de la carte (aucune mention coupée), ramenée à l'image d'origine
    $mx = $bw2 * 0.03 + 1;
    $my = $bh2 * 0.03 + 1;
    $X0 = max(0, (int) floor(($x0 - $mx) / $k));
    $Y0 = max(0, (int) floor(($y0 - $my) / $k));
    $X1 = min($w, (int) ceil(($x1 + $mx) / $k));
    $Y1 = min($h, (int) ceil(($y1 + $my) / $k));
    return [$X0, $Y0, $X1 - $X0, $Y1 - $Y0];
}

/** Police TrueType disponible (VAULT_WATERMARK_FONT si définie, sinon polices courantes Linux / Windows). */
function viewer_font(): ?string
{
    if (!function_exists('imagettftext')) {
        return null;
    }
    $candidates = array_filter([
        getenv('VAULT_WATERMARK_FONT') ?: null,
        __DIR__ . '/assets/fonts/watermark.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
    ]);
    foreach ($candidates as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}
