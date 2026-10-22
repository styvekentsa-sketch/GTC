<?php
/**
 * API JSON des imprimantes (appelée par assets/js/printers.js).
 *
 *   GET  ?action=list[&refresh=1][&scan=0][&force=1]
 *        Imprimantes actives ; refresh=1 : état en temps réel (connexion TCP) + découverte du réseau
 *        local si un sous-réseau est configuré (résultat en cache 2 min, force=1 pour l'administrateur).
 *   POST action=send, id, printer_id, csrf_token
 *        Envoi direct de la facture (double souche) au périphérique choisi ; travail noté dans print_jobs.
 *
 * Accès : utilisateur connecté ; les appareils détectés non enregistrés ne sont détaillés qu'aux administrateurs.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/printers.php';
require_once __DIR__ . '/../includes/print-direct.php';

auth_session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function api_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = current_user();
if (!$user) {
    api_reply(401, ['error' => 'Session expirée : reconnectez-vous.']);
}
if (!in_array($user['role'], OPERATION_ROLES, true)) {
    api_reply(403, ['error' => 'Accès refusé.']);
}

$action = is_post() ? post('action') : (string) ($_GET['action'] ?? 'list');

/* ---------- Envoi direct ---------- */
if ($action === 'send') {
    if (!is_post()) {
        api_reply(405, ['error' => 'Méthode non autorisée.']);
    }
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        api_reply(403, ['error' => 'La page a expiré : rechargez-la puis réessayez.']);
    }
    session_write_close();   // l'envoi peut durer quelques secondes : la session n'est pas bloquée

    $inv = db_one('SELECT * FROM invoices WHERE id = ?', [(int) post('id')]);
    $printer = printer_find((int) post('printer_id'));
    if (!$inv) {
        api_reply(404, ['error' => 'Reçu introuvable.']);
    }
    if (!printer_is_direct($printer)) {
        api_reply(422, ['error' => "Cette imprimante n'accepte pas l'envoi direct : utilisez la boîte d'impression du navigateur."]);
    }

    [$ok, $message, $copies] = print_invoice_direct($inv, $printer, $user);
    db_query(
        'INSERT INTO print_jobs (title, printer_id, copies, color, duplex, priority, status, notes, requested_by, created_at, updated_at)
         VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?)',
        ['Reçu ' . $inv['invoice_number'] . ($copies > 1 ? ' (client + caisse)' : ''), $printer['id'], max(1, $copies),
         'normal', $ok ? 'done' : 'failed', $message, $user['id'], db_now(), db_now()]
    );
    api_reply($ok ? 200 : 502, $ok ? ['ok' => true, 'message' => $message] : ['ok' => false, 'error' => $message]);
}

/* ---------- Liste / actualisation ---------- */
if ($action !== 'list') {
    api_reply(400, ['error' => 'Action inconnue.']);
}
session_write_close();

$refresh = ($_GET['refresh'] ?? '') === '1';
$printers = printers_list(true);
if ($refresh) {
    printers_probe($printers);
    $printers = printers_list(true);
}

$subnet = setting('printer_scan_subnet');
$discovery = ['enabled' => $subnet !== '' && printer_scan_targets($subnet) !== null, 'subnet' => $subnet, 'scanned_at' => null, 'devices' => []];
if ($refresh && ($_GET['scan'] ?? '1') !== '0' && $discovery['enabled']) {
    $discovery = printers_discover($subnet, is_admin() && ($_GET['force'] ?? '') === '1');
}

api_reply(200, [
    'checked_at' => $refresh ? date('H:i') : null,
    'printers'   => array_map(fn (array $p) => [
        'id'           => (int) $p['id'],
        'name'         => $p['printer_name'],
        'location'     => $p['printer_location'],
        'format'       => $p['paper_format'],
        'format_label' => ['a4' => 'A4', 'a5' => 'A5', 'ticket' => 'Ticket 80 mm'][$p['paper_format']] ?? $p['paper_format'],
        'is_default'   => (int) $p['is_default'] === 1,
        'direct'       => printer_is_direct($p),
        'status'       => (string) $p['host'] === '' ? 'nohost' : ($p['last_status'] ?: 'unknown'),
        'checked'      => $p['last_checked_at'] ? fdate($p['last_checked_at'], true) : null,
    ], $printers),
    'discovery' => [
        'enabled'    => $discovery['enabled'],
        'subnet'     => $discovery['enabled'] ? $discovery['subnet'] : null,
        'scanned_at' => $discovery['scanned_at'] ? date('H:i', $discovery['scanned_at']) : null,
        'count'      => count($discovery['devices']),
        // Adresses détaillées pour les administrateurs uniquement (ajout dans Paramètres)
        'devices'    => is_admin() ? array_map(fn ($d) => ['host' => $d[0], 'port' => $d[1], 'protocol' => PRINTER_PORTS[$d[1]] ?? (string) $d[1]], $discovery['devices']) : [],
    ],
    'can_manage' => is_admin(),
]);
