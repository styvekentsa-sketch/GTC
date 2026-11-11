<?php
/**
 * Enregistrement rapide d'un client dans GTC-Vault depuis la caisse (reçu de caisse, facture en cours de rédaction).
 * Appelé par assets/js/kyc-link.js (fenêtre « Enregistrer ce nouveau client dans GTC-Vault »).
 *
 *   POST (multipart) csrf_token, context=receipt|invoice, type_client, nom_complet, telephone, email, adresse,
 *        numero_identifiant, doc_recto, doc_verso (CNI ou pièce du représentant, facultatives : JPG, PNG, PDF ≤ 5 Mo)
 *   → 201 { client: {…} }          dossier créé, à lier au document en cours
 *   → 409 { error, existing: {…} } un dossier existe déjà avec ce N° de pièce ou ce téléphone : il est proposé à la place
 *   → 422 { error, fields: {…} }   saisie à corriger
 *
 * Le dossier est créé PROVISOIRE (review_status = 'pending', review_source = 'caisse:…') : la conformité le complète
 * puis le valide dans GTC-Vault (dashboard/vault/client.php). Création inscrite au journal de sécurité (audit_logs)
 * et au journal d'activité, avec la mention « depuis le poste de caisse ».
 * Accès : comptes des opérations (caisse, agents, administration) ; jeton CSRF ; aucune pièce n'est renvoyée.
 */
declare(strict_types=1);

ini_set('display_errors', '0');   // réponse JSON : aucun message PHP ne doit s'y glisser
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/vault.php';
require_once __DIR__ . '/../includes/kyc_link.php';

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
    $reply(401, ['error' => 'Session expirée : reconnectez-vous (le document en cours n\'est pas perdu tant que la page reste ouverte).']);
}
if (!in_array($user['role'], OPERATION_ROLES, true)) {
    $reply(403, ['error' => 'Accès refusé.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reply(405, ['error' => 'Méthode non autorisée.']);
}
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $reply(413, ['error' => 'Envoi trop volumineux : chaque fichier doit faire 5 Mo au maximum.']);
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    $reply(403, ['error' => 'La page a expiré : rechargez-la avant d\'enregistrer le client.']);
}
session_write_close();

// ---------- Saisie ----------
$context = ($_POST['context'] ?? '') === 'invoice' ? 'invoice' : 'receipt';
$contextLabel = $context === 'invoice' ? 'facture en cours de rédaction' : 'reçu de caisse en cours de rédaction';
$text = fn (string $k, int $max) => mb_substr(trim((string) preg_replace('/[ \t]+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
$in = [
    'type_client'        => (string) ($_POST['type_client'] ?? 'particulier'),
    'nom_complet'        => $text('nom_complet', 255),
    'telephone'          => (string) ($_POST['telephone'] ?? ''),
    'email'              => mb_strtolower($text('email', 150)),
    'adresse'            => trim(mb_substr((string) ($_POST['adresse'] ?? ''), 0, 2000)),
    'numero_identifiant' => strtoupper($text('numero_identifiant', 100)),
];
$errors = [];
if (!in_array($in['type_client'], ['particulier', 'entreprise'], true)) {
    $errors['type_client'] = 'Choisissez le type de client.';
}
if (mb_strlen($in['nom_complet']) < 2) {
    $errors['nom_complet'] = $in['type_client'] === 'entreprise' ? 'Indiquez la raison sociale.' : 'Indiquez le nom complet.';
}
// Téléphone : « +237 6 70 00 00 00 », « 670000000 » (indicatif du Cameroun ajouté) ou « 00237… »
$digits = preg_replace('/\D/', '', $in['telephone']);
$intl = str_starts_with(trim($in['telephone']), '+') || str_starts_with($digits, '00');
$digits = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
if (!$intl && strlen($digits) === 9) {
    $digits = '237' . $digits;
}
if (strlen($digits) < 8 || strlen($digits) > 15) {
    $errors['telephone'] = 'Numéro invalide (ex. 6 70 00 00 00 ou +237 6 70 00 00 00).';
}
$in['telephone'] = '+' . $digits;
if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Adresse e-mail invalide.';
}
if (preg_match(KYC_EMAIL_PATTERN, $in['adresse'])) {
    $errors['adresse'] = 'L\'adresse ne doit pas contenir d\'e-mail : indiquez-le dans le champ « E-mail ».';
}
if (!preg_match('~^[A-Z0-9][A-Z0-9 ./\-]{2,99}$~', $in['numero_identifiant'])) {
    $errors['numero_identifiant'] = $in['type_client'] === 'entreprise'
        ? 'N° RCCM invalide (lettres, chiffres, « / », « - », « . »).'
        : 'N° CNI / passeport invalide (lettres, chiffres, « / », « - », « . »).';
}
// Pièces : facultatives (dossier provisoire), contrôlées comme dans GTC-Vault
$files = [];
foreach (['doc_recto' => 'cni_recto', 'doc_verso' => 'cni_verso'] as $slot => $type) {
    $f = $_FILES[$slot] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    $check = kyc_check_file($f);
    if (is_string($check)) {
        $errors[$slot] = $check;
    } else {
        $files[$type] = $f + ['ext' => $check[0], 'mime' => $check[1]];
    }
}
if ($errors) {
    $reply(422, ['error' => 'Merci de corriger les champs indiqués.', 'fields' => $errors]);
}

// ---------- Doublon : même N° de pièce ou même téléphone → dossier existant proposé ----------
$pdo = vault_db();
$dup = db_one("SELECT id, uuid, type_client, nom_complet, telephone, email, adresse, numero_identifiant, review_status, archived_at
               FROM clients WHERE UPPER(numero_identifiant) = ? OR REPLACE(REPLACE(telephone, ' ', ''), '-', '') = ?
               ORDER BY CASE WHEN UPPER(numero_identifiant) = ? THEN 0 ELSE 1 END LIMIT 1",
    [$in['numero_identifiant'], $in['telephone'], $in['numero_identifiant']]);
if ($dup) {
    $same = strtoupper((string) $dup['numero_identifiant']) === $in['numero_identifiant'] ? 'ce N° de pièce' : 'ce numéro de téléphone';
    $existing = kyc_client_public($dup, kyc_doc_types([(int) $dup['id']])[(int) $dup['id']] ?? []);
    $reply(409, ['error' => 'Un dossier GTC-Vault existe déjà avec ' . $same . ' : ' . $dup['nom_complet']
        . ($dup['archived_at'] ? ' (archivé)' : '') . '. Utilisez ce dossier plutôt que d\'en créer un second.',
        'existing' => $dup['archived_at'] ? null : $existing]);
}

// ---------- Création : client provisoire + pièces, en une transaction ----------
$saved = [];
try {
    $now  = date('Y-m-d H:i:s');
    $uuid = uuid_v4();
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO clients (uuid, type_client, nom_complet, telephone, email, adresse, numero_identifiant, created_at, updated_at, created_by,
                                        review_status, review_source)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)")
        ->execute([$uuid, $in['type_client'], $in['nom_complet'], $in['telephone'], $in['email'] ?: null, $in['adresse'] ?: null,
                   $in['numero_identifiant'], $now, $now, (int) $user['id'], 'caisse:' . $context]);
    $clientId = (int) $pdo->lastInsertId();
    foreach ($files as $type => $f) {
        $saved[] = kyc_store_document($pdo, $clientId, $type, $f, (int) $user['id'], $now);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($saved as $path) {
        @unlink($path);
    }
    error_log('[GTC-Vault saisie rapide] ' . $e->getMessage());
    $reply(500, ['error' => 'Le dossier n\'a pas pu être enregistré. Réessayez ; si le problème persiste, prévenez l\'administrateur.']);
}

// ---------- Traçabilité : origine « poste de caisse », statut provisoire ----------
$n = count($files);
$pieces = $n ? $n . ' pièce' . ($n > 1 ? 's' : '') . ' (' . implode(', ', array_map(fn ($t) => KYC_DOC_LABELS[$t] ?? $t, array_keys($files))) . ')' : 'aucune pièce';
vault_audit('client_create', ['uuid' => $uuid, 'nom_complet' => $in['nom_complet']], null,
    $user['name'] . ' a créé le dossier KYC PROVISOIRE de ' . $in['nom_complet'] . ' depuis le poste de caisse ('
    . $contextLabel . ') — ' . $pieces . ' ; à vérifier et valider par la conformité', $user);
activity_log('create', 'kyc_client', $clientId, $in['nom_complet'],
    ['origine' => 'poste de caisse (' . $contextLabel . ')', 'statut' => 'provisoire, à vérifier', 'pieces' => $pieces], $user);

$client = kyc_client_by_uuid($uuid);
unset($client['id']);
$reply(201, ['client' => $client]);
