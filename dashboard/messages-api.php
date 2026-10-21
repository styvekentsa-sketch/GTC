<?php
/**
 * Actualisation automatique de la messagerie (assets/js/messages-live.js, toutes les 8 secondes).
 *
 *   GET messages-api.php?since=42[&back=messages.php][&rows=1]
 *   → {
 *       unread: 3,                                  pastille du menu, titre de l'onglet
 *       counts: { all, unread, read, replied, archived },
 *       latest_id: 45,                              prochain « since »
 *       new: [ { id, sender, subject } ],           messages arrivés depuis « since » (notification)
 *       rows: [ { id, status, html } ]              avec rows=1 : lignes prêtes à insérer (même rendu que la page)
 *     }
 *
 * Sécurité : réservé aux comptes de la messagerie (MESSAGE_ROLES) ; lecture seule (GET), aucune action.
 * Session vérifiée sans être prolongée ni renouvelée (GTC_PASSIVE_REQUEST, voir includes/auth.php) :
 * la déconnexion après 30 min d'inactivité reste effective même si l'onglet reste ouvert.
 * Léger : 3 requêtes au plus, session libérée aussitôt (les autres pages ne sont pas bloquées).
 */
const GTC_PASSIVE_REQUEST = true;

require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/messages.php';

auth_session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function messages_api_reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    messages_api_reply(405, ['error' => 'Méthode non autorisée.']);
}
$user = current_user();
if (!$user) {
    messages_api_reply(401, ['error' => 'Session expirée : reconnectez-vous.']);
}
if (!can_access_messages()) {
    messages_api_reply(403, ['error' => 'Accès refusé.']);
}
csrf_token();          // jeton créé avant de libérer la session (formulaires des lignes renvoyées)
session_write_close(); // lecture seule : la session n'est plus verrouillée pendant la réponse

$since = max(0, (int) ($_GET['since'] ?? 0));
$back  = messages_safe_back((string) ($_GET['back'] ?? 'messages.php'));

$data = [
    'unread'    => 0,
    'counts'    => messages_counts(),
    'latest_id' => (int) db_value('SELECT COALESCE(MAX(id), 0) FROM messages'),
    'new'       => [],
    'rows'      => [],
];
$data['unread'] = $data['counts']['unread'];

// Nouveaux messages (au plus 20 : au-delà, la page est simplement à recharger)
if ($since > 0 && $data['latest_id'] > $since) {
    $fresh = db_all('SELECT * FROM messages WHERE id > ? ORDER BY id DESC LIMIT 20', [$since]);
    foreach ($fresh as $m) {
        $data['new'][] = ['id' => (int) $m['id'], 'sender' => $m['sender_name'], 'subject' => $m['subject'], 'status' => $m['status']];
        if (($_GET['rows'] ?? '') === '1' && $m['status'] !== 'archived') {
            $data['rows'][] = ['id' => (int) $m['id'], 'status' => $m['status'], 'html' => message_row($m, $back)];
        }
    }
}

messages_api_reply(200, $data);
