<?php
/**
 * Partenaires — fonctions partagées (section « Nos Partenaires » de l'accueil et dashboard/partners.php).
 *
 * Les logos sont stockés dans assets/img/partners/ ; la colonne `logo` ne contient que le nom du fichier.
 * Sans logo téléversé, le nom du partenaire est affiché en typographie sobre à la place.
 * Seuls des logos officiels fournis par les partenaires (avec leur accord) doivent être utilisés.
 */

require_once __DIR__ . '/db.php';

const PARTNER_LOGO_DIR   = __DIR__ . '/../assets/img/partners';
const PARTNER_LOGO_URL   = 'assets/img/partners';
const PARTNER_MAX_BYTES  = 2 * 1024 * 1024;
const PARTNER_MAX_WIDTH  = 600;   // logo réencodé : 600 × 240 px au plus (affiché à 56 px de haut)
const PARTNER_MAX_HEIGHT = 240;

/** Partenaires par défaut, utilisés si la base de données est indisponible. */
const PARTNERS_FALLBACK = [
    ['id' => 0, 'name' => 'DHL', 'logo' => null, 'website' => null],
    ['id' => 0, 'name' => 'IRAN CAMEROON Trade Center', 'logo' => null, 'website' => null],
    ['id' => 0, 'name' => 'Bytes', 'logo' => null, 'website' => null],
];

/** Partenaires visibles, triés. Page publique : ne bloque jamais l'affichage. */
function partners_public(): array
{
    $pdo = db_try();
    if (!$pdo) {
        return PARTNERS_FALLBACK;
    }
    try {
        return $pdo->query('SELECT id, name, logo, website FROM partners WHERE is_active = 1 ORDER BY display_order ASC, id ASC')->fetchAll();
    } catch (PDOException $e) {
        error_log('[GTC] Lecture des partenaires impossible : ' . $e->getMessage());
        return PARTNERS_FALLBACK;
    }
}

/**
 * URL du logo si le fichier existe réellement, sinon null (→ nom affiché). $prefix : '' ou '../'.
 * $name : sans logo envoyé depuis le Dashboard, le fichier portant le nom du partenaire est utilisé
 *         s'il existe (ex. « DHL » → assets/img/partners/dhl.png).
 */
function partner_logo_url(?string $logo, string $prefix = '', ?string $name = null): ?string
{
    $file = basename((string) $logo);
    if ($file === '' || !preg_match('/^partenaire-[a-f0-9]{16}\.(png|jpg)$/', $file) || !is_file(PARTNER_LOGO_DIR . '/' . $file)) {
        $file = null;
        if ($name !== null) {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name)), '-');
            foreach (['png', 'jpg'] as $ext) {
                if ($slug !== '' && is_file(PARTNER_LOGO_DIR . "/{$slug}.{$ext}")) {
                    $file = "{$slug}.{$ext}";
                    break;
                }
            }
        }
        if ($file === null) {
            return null;
        }
    }
    return $prefix . PARTNER_LOGO_URL . '/' . $file . '?v=' . filemtime(PARTNER_LOGO_DIR . '/' . $file);
}

/** Adresse web valide (http/https) ou chaîne vide. */
function partner_clean_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://[^/\s]+\.[a-z]{2,}#i', $url) && mb_strlen($url) <= 255 ? $url : null;
}

/**
 * Logo téléversé : PNG ou JPG, 2 Mo maximum, contenu réellement vérifié (finfo + getimagesize).
 * Réencodé avec GD si disponible (proportions et transparence conservées, 600 × 240 px au plus) :
 * tout contenu caché dans le fichier d'origine est éliminé. Retourne [nom|null, erreur|null].
 */
function partner_store_logo(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > PARTNER_MAX_BYTES) {
        return [null, 'Le logo dépasse 2 Mo.'];
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return [null, "L'envoi du logo a échoué. Merci de réessayer."];
    }
    $tmp  = $file['tmp_name'];
    $info = @getimagesize($tmp);
    $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : null;
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
        || ($mime !== null && !in_array($mime, ['image/png', 'image/jpeg'], true))) {
        return [null, 'Format non accepté : PNG (idéalement à fond transparent) ou JPG uniquement.'];
    }
    if ($info[0] < 32 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) {
        return [null, 'Dimensions du logo invalides.'];
    }
    if (!is_dir(PARTNER_LOGO_DIR) && !mkdir(PARTNER_LOGO_DIR, 0755, true)) {
        return [null, "Le dossier des logos n'est pas accessible en écriture."];
    }

    $ext  = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $name = 'partenaire-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = PARTNER_LOGO_DIR . '/' . $name;

    if (function_exists('imagecreatetruecolor')) {
        $src = $ext === 'png' ? @imagecreatefrompng($tmp) : @imagecreatefromjpeg($tmp);
        if (!$src) {
            return [null, 'Image illisible ou corrompue.'];
        }
        $ratio = min(1, PARTNER_MAX_WIDTH / $info[0], PARTNER_MAX_HEIGHT / $info[1]);
        $w = max(1, (int) round($info[0] * $ratio));
        $h = max(1, (int) round($info[1] * $ratio));
        $out = imagecreatetruecolor($w, $h);
        if ($ext === 'png') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        $saved = $ext === 'png' ? imagepng($out, $dest, 6) : imagejpeg($out, $dest, 90);
        if (!$saved) {
            return [null, "Le logo n'a pas pu être enregistré."];
        }
    } elseif (!move_uploaded_file($tmp, $dest)) {
        return [null, "Le logo n'a pas pu être enregistré."];
    }
    @chmod($dest, 0644);
    return [$name, null];
}

function partner_delete_logo(?string $logo): void
{
    $file = basename((string) $logo);
    if (preg_match('/^partenaire-[a-f0-9]{16}\.(png|jpg)$/', $file) && is_file(PARTNER_LOGO_DIR . '/' . $file)) {
        @unlink(PARTNER_LOGO_DIR . '/' . $file);
    }
}
