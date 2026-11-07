<?php
/**
 * GTC-Vault — capture d'une pièce avec un téléphone : côté POSTE DE L'AGENT (session du Dashboard + rôle VAULT_ROLES)
 *
 *   POST ?action=create  (csrf_token, slot, piece)  → { id, url, label, expires_in }   QR code pour une pièce
 *   GET  ?action=status&id=N                        → { status, expires_in }           suivi (requête passive)
 *   GET  ?action=file&id=N                          → la photo (une seule fois), effacée du serveur aussitôt
 *   POST ?action=cancel  (csrf_token, id)           → { ok }                           QR code abandonné
 *
 * Une capture n'est visible que par l'agent qui l'a créée. Le téléphone utilise capture.php (jeton du QR code).
 */
declare(strict_types=1);

// Suivi automatique toutes les 1,5 s : la session n'est pas prolongée par ces requêtes (déconnexion après inactivité)
if (($_GET['action'] ?? '') === 'status') {
    define('GTC_PASSIVE_REQUEST', true);
}

ini_set('display_errors', '0');   // réponse JSON ou image : aucun message PHP ne doit s'y glisser

require_once __DIR__ . '/../../includes/vault.php';

$action = (string) ($_GET['action'] ?? '');
$user   = vault_guard($action === 'file' ? 'plain' : 'json');

/** Réponse JSON. */
function capture_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Capture de l'agent connecté (jamais celle d'un autre compte). */
function capture_mine(int $id, int $userId): ?array
{
    return db_one('SELECT * FROM kyc_captures WHERE id = ? AND created_by = ?', [$id, $userId]) ?: null;
}

// Actions qui modifient : POST + jeton CSRF du Dashboard
if (in_array($action, ['create', 'cancel'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
        capture_json(['error' => 'csrf', 'message' => 'Session expirée : rechargez la page.'], 403);
    }
}
session_write_close();   // le jeton de session n'est plus modifié : les autres requêtes de l'agent ne sont pas bloquées

switch ($action) {
    // ---------- Nouveau QR code ----------
    case 'create':
        capture_purge();
        $slot  = (string) ($_POST['slot'] ?? '');
        $piece = ($_POST['piece'] ?? '') === 'passeport' ? 'passeport' : 'cni';
        if (!isset(CAPTURE_SLOTS[$slot])) {
            capture_json(['error' => 'slot', 'message' => 'Pièce inconnue.'], 422);
        }
        $label = is_array(CAPTURE_SLOTS[$slot]) ? CAPTURE_SLOTS[$slot][$piece] : CAPTURE_SLOTS[$slot];
        // Limite : 20 QR codes par agent et par heure (un oubli de boucle ne remplit pas la table)
        if ((int) db_value('SELECT COUNT(*) FROM kyc_captures WHERE created_by = ? AND created_ts > ?', [$user['id'], time() - 3600]) >= 20) {
            capture_json(['error' => 'limit', 'message' => 'Trop de QR codes générés en une heure. Réessayez plus tard.'], 429);
        }
        // Un seul QR code actif par pièce et par agent : l'ancien est annulé
        foreach (db_all("SELECT id, file_path FROM kyc_captures WHERE created_by = ? AND slot = ? AND status IN ('pending', 'opened', 'uploaded')", [$user['id'], $slot]) as $old) {
            capture_delete_file($old['file_path']);
            db_query("UPDATE kyc_captures SET status = 'cancelled', file_path = NULL WHERE id = ?", [(int) $old['id']]);
        }
        $token = bin2hex(random_bytes(32));
        $now   = time();
        db_query('INSERT INTO kyc_captures (token_hash, slot, slot_label, status, created_by, created_ts, expires_ts) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [hash('sha256', $token), $slot, $label, 'pending', $user['id'], $now, $now + CAPTURE_TTL]);
        $id = (int) db()->lastInsertId();
        vault_audit('capture_create', null, null, $user['name'] . ' a généré un QR code de capture mobile pour « ' . $label . ' »');
        capture_json(['id' => $id, 'url' => capture_public_url($token), 'label' => $label, 'expires_in' => CAPTURE_TTL]);

    // ---------- Suivi ----------
    case 'status':
        $c = capture_mine((int) ($_GET['id'] ?? 0), $user['id']);
        if (!$c) {
            capture_json(['error' => 'not_found'], 404);
        }
        $status = $c['status'];
        if (in_array($status, ['pending', 'opened'], true) && (int) $c['expires_ts'] < time()) {
            $status = 'expired';
        }
        capture_json(['status' => $status, 'expires_in' => max(0, (int) $c['expires_ts'] - time()),
                      'size' => $c['file_size'] !== null ? (int) $c['file_size'] : null]);

    // ---------- Récupération de la photo (une seule fois) ----------
    case 'file':
        $c = capture_mine((int) ($_GET['id'] ?? 0), $user['id']);
        $path = $c && $c['status'] === 'uploaded' && $c['file_path'] ? capture_tmp_dir() . '/' . $c['file_path'] : null;
        if (!$path || !is_file($path) || (int) $c['expires_ts'] + 600 < time()) {
            vault_fail('plain', 404, 'not_found', 'Photo indisponible : générez un nouveau QR code.');
        }
        $data = (string) file_get_contents($path);
        capture_delete_file($c['file_path']);
        db_query("UPDATE kyc_captures SET status = 'consumed', file_path = NULL WHERE id = ?", [(int) $c['id']]);
        vault_audit('capture_consumed', null, null, $user['name'] . ' a récupéré la photo « ' . $c['slot_label'] . ' » prise au téléphone');
        header('Content-Type: ' . ($c['mime_type'] === 'image/png' ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        echo $data;
        exit;

    // ---------- Annulation ----------
    case 'cancel':
        $c = capture_mine((int) ($_POST['id'] ?? 0), $user['id']);
        if ($c && in_array($c['status'], ['pending', 'opened', 'uploaded'], true)) {
            capture_delete_file($c['file_path']);
            db_query("UPDATE kyc_captures SET status = 'cancelled', file_path = NULL WHERE id = ?", [(int) $c['id']]);
        }
        capture_json(['ok' => true]);

    default:
        capture_json(['error' => 'action'], 400);
}
