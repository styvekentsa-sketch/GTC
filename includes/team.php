<?php
/**
 * Équipe — fonctions partagées (page « À propos » et dashboard/team.php).
 *
 * Les photos sont stockées dans assets/img/team/ ; la colonne `photo` ne contient
 * que le nom du fichier. Si le fichier est absent (ou vaut la valeur par défaut
 * 'default-avatar.png' sans fichier correspondant), un avatar à initiales est affiché.
 */

require_once __DIR__ . '/db.php';

const TEAM_PHOTO_DIR = __DIR__ . '/../assets/img/team';
const TEAM_PHOTO_URL = 'assets/img/team';

/** Membres par défaut, utilisés si la base de données est indisponible. */
const TEAM_FALLBACK = [
    ['id' => 0, 'name' => 'Chérif Alioum',  'role' => 'Directeur Général',        'photo' => null, 'display_order' => 1],
    ['id' => 0, 'name' => 'Vanessa Ngakam', 'role' => 'Secrétaire',               'photo' => null, 'display_order' => 2],
    ['id' => 0, 'name' => 'Flore Sany',     'role' => 'Réceptionniste',           'photo' => null, 'display_order' => 3],
    ['id' => 0, 'name' => 'Faissal Issa',   'role' => 'Responsable Opérationnel', 'photo' => null, 'display_order' => 4],
];

/**
 * Liste des membres triés par ordre d'affichage.
 * Page publique : ne bloque jamais l'affichage (repli sur TEAM_FALLBACK).
 */
function team_members(): array
{
    $pdo = db_try();
    if (!$pdo) {
        return TEAM_FALLBACK;
    }
    try {
        return $pdo->query('SELECT id, name, role, photo, display_order FROM team_members ORDER BY display_order ASC, id ASC')->fetchAll();
    } catch (PDOException $e) {
        error_log('[GTC] Lecture de l\'équipe impossible : ' . $e->getMessage());
        return TEAM_FALLBACK;
    }
}

/** Nom de fichier sûr (aucun chemin), ou null. */
function team_photo_file(?string $photo): ?string
{
    $file = basename((string) $photo);
    return ($file !== '' && preg_match('/^[a-zA-Z0-9._-]+\.(jpe?g|png)$/', $file)) ? $file : null;
}

/**
 * URL de la photo si le fichier existe réellement, sinon null (→ avatar par défaut).
 * $prefix : chemin relatif vers la racine du site ('' depuis la racine, '../' depuis dashboard/).
 * $name   : nom du membre ; sans photo envoyée depuis le Dashboard, le fichier portant son nom est
 *           utilisé s'il existe (ex. « Chérif Alioum » → assets/img/team/cherif-alioum.jpg).
 */
function team_photo_url(?string $photo, string $prefix = '', ?string $name = null): ?string
{
    $file = team_photo_file($photo);
    if ((!$file || $file === 'default-avatar.png' || !is_file(TEAM_PHOTO_DIR . '/' . $file)) && $name !== null) {
        $file = null;
        foreach (['jpg', 'jpeg', 'png'] as $ext) {
            if (is_file(TEAM_PHOTO_DIR . '/' . team_slug($name) . '.' . $ext)) {
                $file = team_slug($name) . '.' . $ext;
                break;
            }
        }
    }
    if (!$file || !is_file(TEAM_PHOTO_DIR . '/' . $file)) {
        return null;
    }
    // Paramètre de version : la nouvelle photo s'affiche immédiatement après un remplacement
    return $prefix . TEAM_PHOTO_URL . '/' . rawurlencode($file) . '?v=' . filemtime(TEAM_PHOTO_DIR . '/' . $file);
}

/** Nom de fichier tiré du nom du membre : « Chérif Alioum » → « cherif-alioum ». */
function team_slug(string $name): string
{
    $ascii = strtr(mb_strtolower(trim($name)), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ]);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
}

/** Supprime le fichier photo d'un membre (jamais l'avatar par défaut). */
function team_delete_photo(?string $photo): void
{
    $file = team_photo_file($photo);
    if ($file && $file !== 'default-avatar.png' && is_file(TEAM_PHOTO_DIR . '/' . $file)) {
        @unlink(TEAM_PHOTO_DIR . '/' . $file);
    }
}
