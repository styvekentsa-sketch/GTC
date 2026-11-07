<?php
/**
 * GTC-Vault — capture des pièces avec un téléphone : côté POSTE DE L'AGENT (session du Dashboard + rôle VAULT_ROLES)
 *
 * Un seul QR code pour plusieurs pièces à la suite (ex. CNI recto puis verso) :
 *   POST ?action=create  (csrf_token, slots = « doc_identite,doc_verso », optional = « doc_statuts », piece)
 *                        → { id (session), url, expires_in, steps: [{ id, slot, label, required }] }
 *   GET  ?action=status&id=SESSION → { status: pending|opened|expired|cancelled, expires_in,
 *                                      steps: [{ id, slot, status, version }] }   (requête passive, toutes les 1,5 s)
 *   GET  ?action=file&id=PIÈCE     → la photo reçue (puis effacée du serveur) ; une photo reprise est servie à nouveau
 *   POST ?action=cancel (csrf_token, id = SESSION)  → { ok }   fin de session : plus aucun envoi possible
 *
 * Une session n'est visible que par l'agent qui l'a créée. Le téléphone utilise capture.php (jeton du QR code).
 */
declare(strict_types=1);

// Suivi automatique : la session du Dashboard n'est pas prolongée par ces requêtes (déconnexion après inactivité)
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

/** Première pièce (porteuse de la session) d'une session de l'agent connecté, jamais celle d'un autre compte. */
function capture_session_of(int $batchId, int $userId): ?array
{
    return db_one('SELECT * FROM kyc_captures WHERE id = ? AND batch_id = id AND created_by = ?', [$batchId, $userId]) ?: null;
}

// Actions qui modifient : POST + jeton CSRF du Dashboard
if (in_array($action, ['create', 'cancel'], true)
    && (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? '')))) {
    capture_json(['error' => 'csrf', 'message' => 'Session expirée : rechargez la page.'], 403);
}
session_write_close();   // le jeton de session n'est plus modifié : les autres requêtes de l'agent ne sont pas bloquées

switch ($action) {
    // ---------- Nouvelle session : un QR code pour toutes les pièces demandées ----------
    case 'create':
        capture_purge();
        $piece    = ($_POST['piece'] ?? '') === 'passeport' ? 'passeport' : 'cni';
        $slots    = array_values(array_unique(array_filter(explode(',', (string) ($_POST['slots'] ?? '')))));
        $optional = array_filter(explode(',', (string) ($_POST['optional'] ?? '')));
        if (!$slots || count($slots) > CAPTURE_MAX_STEPS || array_diff($slots, array_keys(CAPTURE_SLOTS))) {
            capture_json(['error' => 'slot', 'message' => 'Pièces à photographier inconnues.'], 422);
        }
        // Limite : 20 sessions par agent et par heure (un oubli de boucle ne remplit pas la table)
        if ((int) db_value('SELECT COUNT(*) FROM kyc_captures WHERE created_by = ? AND step = 0 AND created_ts > ?', [$user['id'], time() - 3600]) >= 20) {
            capture_json(['error' => 'limit', 'message' => 'Trop de QR codes générés en une heure. Réessayez plus tard.'], 429);
        }
        // Une seule session active par agent : la précédente est close
        foreach (db_all("SELECT DISTINCT batch_id FROM kyc_captures WHERE created_by = ? AND status <> 'cancelled' AND expires_ts >= ?", [$user['id'], time()]) as $b) {
            capture_cancel_batch((int) $b['batch_id']);
        }
        $token = bin2hex(random_bytes(32));
        $now   = time();
        $steps = [];
        $batch = 0;
        $pdo   = db();
        $pdo->beginTransaction();
        foreach ($slots as $i => $slot) {
            $label = is_array(CAPTURE_SLOTS[$slot]) ? CAPTURE_SLOTS[$slot][$piece] : CAPTURE_SLOTS[$slot];
            $required = !in_array($slot, $optional, true);
            // Seul le jeton de la 1re pièce est remis (QR code) ; les suivantes reçoivent une empreinte inutilisable
            $hash = hash('sha256', $i === 0 ? $token : bin2hex(random_bytes(32)));
            db_query('INSERT INTO kyc_captures (token_hash, slot, slot_label, status, created_by, created_ts, expires_ts, batch_id, step, required)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$hash, $slot, $label, 'pending', $user['id'], $now, $now + CAPTURE_TTL, $batch ?: null, $i, $required ? 1 : 0]);
            $id = (int) $pdo->lastInsertId();
            if ($i === 0) {
                $batch = $id;
                db_query('UPDATE kyc_captures SET batch_id = ? WHERE id = ?', [$batch, $id]);
            }
            $steps[] = ['id' => $id, 'slot' => $slot, 'label' => $label, 'required' => $required];
        }
        $pdo->commit();
        vault_audit('capture_create', null, null, $user['name'] . ' a généré un QR code de capture mobile pour : ' . implode(', ', array_column($steps, 'label')));
        capture_json(['id' => $batch, 'url' => capture_public_url($token), 'expires_in' => CAPTURE_TTL, 'steps' => $steps]);

    // ---------- Suivi de la session ----------
    case 'status':
        $first = capture_session_of((int) ($_GET['id'] ?? 0), $user['id']);
        if (!$first) {
            capture_json(['error' => 'not_found'], 404);
        }
        $status = $first['status'] === 'cancelled' ? 'cancelled'
            : ((int) $first['expires_ts'] < time() ? 'expired' : ($first['opened_ts'] ? 'opened' : 'pending'));
        capture_json([
            'status'     => $status,
            'expires_in' => max(0, (int) $first['expires_ts'] - time()),
            // version : change à chaque photo reçue (nom de fichier aléatoire) → une photo reprise est récupérée à nouveau
            'steps'      => array_map(fn ($c) => [
                'id' => (int) $c['id'], 'slot' => $c['slot'], 'status' => $c['status'],
                'version' => $c['file_path'] ? substr(hash('sha256', (string) $c['file_path']), 0, 12) : '',
            ], capture_batch((int) $first['id'])),
        ]);

    // ---------- Récupération d'une photo (effacée du serveur aussitôt) ----------
    case 'file':
        $c = db_one('SELECT * FROM kyc_captures WHERE id = ? AND created_by = ?', [(int) ($_GET['id'] ?? 0), $user['id']]);
        $path = $c && $c['status'] === 'uploaded' && $c['file_path'] ? capture_tmp_dir() . '/' . $c['file_path'] : null;
        if (!$path || !is_file($path) || (int) $c['expires_ts'] + 600 < time()) {
            vault_fail('plain', 404, 'not_found', 'Photo indisponible : générez un nouveau QR code.');
        }
        $data = (string) file_get_contents($path);
        capture_delete_file($c['file_path']);
        db_query("UPDATE kyc_captures SET status = 'consumed', file_path = NULL WHERE id = ? AND status = 'uploaded'", [(int) $c['id']]);
        vault_audit('capture_consumed', null, null, $user['name'] . ' a récupéré la photo « ' . $c['slot_label'] . ' » prise au téléphone');
        header('Content-Type: ' . ($c['mime_type'] === 'image/png' ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        echo $data;
        exit;

    // ---------- Fin de session ----------
    case 'cancel':
        if ($first = capture_session_of((int) ($_POST['id'] ?? 0), $user['id'])) {
            capture_cancel_batch((int) $first['id']);
        }
        capture_json(['ok' => true]);

    default:
        capture_json(['error' => 'action'], 400);
}
