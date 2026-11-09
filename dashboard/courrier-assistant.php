<?php
/**
 * API JSON de l'assistant de rédaction des courriers (assets/js/courrier-assistant.js).
 *
 *   POST action=correct|formal|english|closing, csrf_token, subject, salutation, body (HTML), closing, recipient
 *   → { fields: { subject, salutation, body, closing }, notes: [...], warnings: [...] }   (correct, formal, english)
 *   → { options: [ { text, tone } ] }                                                       (closing)
 *
 * Rien n'est enregistré ici : l'agent accepte ou rejette les propositions dans le formulaire, puis enregistre.
 * Accès : comptes des opérations (comme dashboard/courriers.php), jeton CSRF, 20 demandes par 10 minutes et par compte.
 */
// Réponse JSON : aucun message PHP ne doit s'y glisser (il casserait la lecture côté navigateur)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/courriers.php';
require_once __DIR__ . '/../includes/ai_assistant.php';

auth_session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$reply = function (int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$user = current_user();
if (!$user) {
    $reply(401, ['error' => 'Session expirée : reconnectez-vous (votre courrier n\'est pas perdu tant que la page reste ouverte).']);
}
if (!in_array($user['role'], OPERATION_ROLES, true)) {
    $reply(403, ['error' => 'Accès refusé.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reply(405, ['error' => 'Méthode non autorisée.']);
}
$token = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    $reply(403, ['error' => 'La page a expiré : rechargez-la avant d\'utiliser l\'assistant.']);
}

// Limite d'usage : 20 demandes par tranche de 10 minutes et par session
$now = time();
$hits = array_values(array_filter((array) ($_SESSION['ai_hits'] ?? []), fn ($t) => $t > $now - 600));
if (count($hits) >= 20) {
    $reply(429, ['error' => 'Trop de demandes à l\'assistant en peu de temps. Patientez quelques minutes.']);
}
$hits[] = $now;
$_SESSION['ai_hits'] = $hits;
session_write_close();   // l'appel peut durer : les autres pages de l'agent ne sont pas bloquées

$action = (string) ($_POST['action'] ?? '');
if (!isset(AI_ACTIONS[$action])) {
    $reply(400, ['error' => 'Action inconnue.']);
}
if (!ai_enabled()) {
    $reply(503, ['error' => 'L\'assistant n\'est pas encore activé sur ce serveur : l\'administrateur doit renseigner la clé API (GTC_ANTHROPIC_API_KEY dans includes/db.local.php).']);
}

$field = function (string $key, int $max): string {
    $v = trim((string) ($_POST[$key] ?? ''));
    $v = mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    return mb_substr($v, 0, $max);
};
$letter = [
    'recipient'  => $field('recipient', 300),
    'subject'    => $field('subject', COURRIER_LIMITS['subject'] ?? 255),
    'salutation' => $field('salutation', COURRIER_LIMITS['salutation'] ?? 150),
    'body'       => courrier_sanitize_html($field('body', COURRIER_LIMITS['body'] ?? 60000)),
    'closing'    => $field('closing', COURRIER_LIMITS['closing'] ?? 500),
];
if (courrier_body_text($letter['body']) === '' && $action !== 'closing') {
    $reply(422, ['error' => 'Le corps du courrier est vide : rédigez d\'abord le texte à traiter.']);
}

try {
    $out = ai_assist_letter($action, $letter);
} catch (AiAssistantException $e) {
    $reply(502, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[Assistant courrier] ' . $e->getMessage());
    $reply(500, ['error' => 'Erreur inattendue de l\'assistant. Réessayez.']);
}

if (isset($out['fields'])) {
    // Corps renvoyé : même filtrage strict qu'à l'enregistrement (balises et styles autorisés seulement)
    $out['fields']['body'] = courrier_sanitize_html($out['fields']['body']);
    foreach (['subject', 'salutation', 'closing'] as $k) {
        $out['fields'][$k] = mb_substr(trim(strip_tags($out['fields'][$k])), 0, COURRIER_LIMITS[$k] ?? 500);
    }
    // Contrôle : les variables de publipostage [ … ] doivent toutes être conservées
    $warnings = [];
    // (comparées sur le texte décodé : le corps est du HTML, où l'apostrophe est écrite &#039;)
    $plain = fn (array $parts) => html_entity_decode(implode(' ', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('/\[[^\[\]\n]{1,60}\]/u', $plain([$letter['subject'], $letter['salutation'], $letter['body'], $letter['closing']]), $before);
    preg_match_all('/\[[^\[\]\n]{1,60}\]/u', $plain($out['fields']), $after);
    $lost = array_diff(array_unique($before[0]), $after[0]);
    if ($lost) {
        $warnings[] = 'Variable(s) absente(s) de la proposition : ' . implode(', ', $lost) . '. Vérifiez avant d\'accepter.';
    }
    $out['warnings'] = $warnings;
}
$reply(200, $out);
