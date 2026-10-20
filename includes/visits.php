<?php
/**
 * Comptage des visiteurs du site public.
 *
 * - Un visiteur est compté une seule fois par jour : INSERT IGNORE (MySQL) / INSERT OR IGNORE
 *   (SQLite) sur la contrainte UNIQUE (ip_address, visit_date) → les rafraîchissements ne
 *   faussent pas les chiffres.
 * - Confidentialité : l'adresse IP n'est jamais stockée en clair. La colonne ip_address contient
 *   une empreinte HMAC-SHA256 (clé secrète propre au site, stockée dans data/), impossible à
 *   retransformer en adresse IP mais suffisante pour compter les visiteurs uniques.
 * - Les robots (moteurs de recherche, outils de surveillance) et les préchargements ne sont pas comptés.
 * - Ne bloque jamais l'affichage du site : toute erreur est simplement journalisée.
 */

require_once __DIR__ . '/db.php';

const VISIT_KEY_FILE = __DIR__ . '/../data/visit.key';
const VISIT_BOT_PATTERN = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|curl|wget|python|httpclient|monitor|uptime|lighthouse|headless/i';

/** Clé secrète de hachage, créée automatiquement à la première visite. */
function visit_secret(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    if (is_file(VISIT_KEY_FILE)) {
        $key = trim((string) file_get_contents(VISIT_KEY_FILE));
    }
    if (!$key) {
        $key = bin2hex(random_bytes(32));
        @file_put_contents(VISIT_KEY_FILE, $key, LOCK_EX);
        @chmod(VISIT_KEY_FILE, 0600);
    }
    return $key;
}

/** Faut-il compter cette requête ? */
function visit_should_count(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($agent === '' || preg_match(VISIT_BOT_PATTERN, $agent)) {
        return false;
    }
    // Préchargement de page par le navigateur (pas une vraie visite)
    $purpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ($_SERVER['HTTP_PURPOSE'] ?? '') . ($_SERVER['HTTP_X_MOZ'] ?? ''));
    return !str_contains($purpose, 'prefetch');
}

/** Enregistre la visite du jour (une fois par visiteur et par jour). */
function track_visit(): void
{
    if (!visit_should_count()) {
        return;
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '' || !($pdo = db_try())) {
        return;
    }

    $fingerprint = substr(hash_hmac('sha256', $ip, visit_secret()), 0, 45);
    $sql = DB_DRIVER === 'mysql'
        ? 'INSERT IGNORE INTO site_visits (ip_address, visit_date) VALUES (?, ?)'
        : 'INSERT OR IGNORE INTO site_visits (ip_address, visit_date) VALUES (?, ?)';

    try {
        $pdo->prepare($sql)->execute([$fingerprint, date('Y-m-d')]);
    } catch (PDOException $e) {
        error_log('[GTC] Enregistrement de la visite impossible : ' . $e->getMessage());
    }
}
