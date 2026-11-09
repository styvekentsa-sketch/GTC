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

// Réduction (affichage écran) et fond blanc sous la transparence des PNG
$w = imagesx($img);
$h = imagesy($img);
$scale = min(1, 1800 / max($w, $h));
$W = max(1, (int) round($w * $scale));
$H = max(1, (int) round($h * $scale));
$out = imagecreatetruecolor($W, $H);
imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
imagecopyresampled($out, $img, 0, 0, 0, 0, $W, $H, $w, $h);

$printing = $mode === 'print';
viewer_watermark($out, vault_watermark_text(), 'Réf. ' . substr($doc['uuid'], 0, 8) . ' · pièce #' . $doc['id'] . ($printing ? ' · impression enregistrée' : ' · consultation enregistrée'));

vault_audit($printing ? 'document_print' : 'document_view', $doc, (int) $doc['id'],
    $user['name'] . ($printing ? ' a imprimé « ' : ' a consulté « ') . $label . ' » du client ' . $doc['nom_complet']);

header('Content-Type: image/jpeg');
header('Content-Disposition: inline; filename="apercu-filigrane.jpg"');
imagejpeg($out, null, 82);
exit;

/**
 * Filigrane : texte répété en diagonale sur toute l'image (semi-transparent, sombre bordé de clair pour rester
 * visible sur fond clair comme sur fond sombre) + bandeau de traçabilité en bas.
 */
function viewer_watermark(GdImage $im, string $text, string $footer): void
{
    $W = imagesx($im);
    $H = imagesy($im);
    $font = viewer_font();
    $dark  = imagecolorallocatealpha($im, 15, 23, 42, 88);     // alpha GD : 0 opaque → 127 transparent
    $light = imagecolorallocatealpha($im, 255, 255, 255, 96);

    if ($font) {
        $size  = max(11, (int) round(min($W, $H) / 30));
        $angle = 30;
        $box   = imagettfbbox($size, 0, $font, $text);
        $tw    = $box[2] - $box[0];
        $stepX = $tw + $size * 4;
        $stepY = $size * 7;
        $row = 0;
        for ($y = -$H; $y < $H * 2; $y += $stepY, $row++) {
            for ($x = -$W + ($row % 2) * (int) ($stepX / 2); $x < $W * 2; $x += $stepX) {
                imagettftext($im, $size, $angle, $x + 1, $y + 1, $light, $font, $text);
                imagettftext($im, $size, $angle, $x, $y, $dark, $font, $text);
            }
        }
        // Bandeau du bas
        $fs = max(9, (int) round($size * 0.7));
        $bandH = (int) round($fs * 2.6);
        imagefilledrectangle($im, 0, $H - $bandH, $W, $H, imagecolorallocatealpha($im, 15, 23, 42, 30));
        imagettftext($im, $fs, 0, (int) round($fs * 0.9), $H - (int) round($bandH * 0.32), imagecolorallocate($im, 255, 255, 255), $font, $text . '  ·  ' . $footer);
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
        imagefilledrectangle($im, 0, $H - $ch * 2, $W, $H, imagecolorallocatealpha($im, 15, 23, 42, 30));
        imagestring($im, 3, 6, $H - (int) ($ch * 1.5), $text . ' - ' . $footer, imagecolorallocate($im, 255, 255, 255));
    }
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
