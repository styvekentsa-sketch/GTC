<?php
/**
 * Traitement sécurisé du formulaire de contact — Global Trading Corporation
 *
 * POST → vérifications (CSRF, anti-robot, délai) → validation → enregistrement en base
 * (Dashboard) + notification mail() → redirection 303.
 * Les erreurs et la saisie sont stockées en session puis réaffichées par contact.php
 * (motif Post/Redirect/Get : pas de double envoi en cas d'actualisation).
 */
require_once __DIR__ . '/includes/config.php';
gtc_public_session_start();
require_once __DIR__ . '/includes/db.php';

const CONTACT_PAGE = 'contact.php#formulaire';

/**
 * Journalise un échec d'enregistrement : journal PHP du serveur + data/contact-errors.log
 * (dossier protégé, inaccessible depuis le web) pour un diagnostic facile chez l'hébergeur.
 */
function contact_log(string $reason): void
{
    $line = '[GTC] Message de contact NON enregistré : ' . $reason;
    error_log($line);
    @error_log(date('Y-m-d H:i:s') . ' ' . $line . PHP_EOL, 3, __DIR__ . '/data/contact-errors.log');
}

/** Redirige vers la page Contact et termine le script. */
function back_to_contact(): void
{
    header('Location: ' . CONTACT_PAGE, true, 303);
    exit;
}

// Accès direct (GET) : retour au formulaire
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_to_contact();
}

$fields = ['nom' => '', 'email' => '', 'telephone' => '', 'sujet' => '', 'message' => ''];
foreach ($fields as $key => $_) {
    $value = trim((string) ($_POST[$key] ?? ''));
    // UTF-8 garanti en base : une saisie Windows-1252 (ancien navigateur, outil externe) est convertie
    $fields[$key] = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
}

$errors = [];

// ---------- Vérifications de sécurité ----------
$token = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    $errors['global'] = 'Votre session a expiré. Merci de renvoyer le formulaire.';
} elseif (isset($_SESSION['last_submit']) && time() - $_SESSION['last_submit'] < 30) {
    $errors['global'] = 'Merci de patienter quelques secondes avant un nouvel envoi.';
}

// Pot de miel : champ invisible rempli = robot → succès simulé, aucun envoi
// (nom sans signification, voir contact.php : l'auto-remplissage des navigateurs ne le touche pas)
$isBot = trim((string) ($_POST['gtc_hp_7k'] ?? '')) !== '';

// ---------- Validation ----------
$len = fn (string $v) => mb_strlen($v);

if ($len($fields['nom']) < 2 || $len($fields['nom']) > 100) {
    $errors['nom'] = 'Veuillez indiquer votre nom et prénom.';
}
if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Veuillez indiquer une adresse e-mail valide.';
}
if ($fields['telephone'] !== '' && !preg_match('/^[0-9+().\s-]{6,25}$/', $fields['telephone'])) {
    $errors['telephone'] = 'Numéro de téléphone invalide.';
}
if ($len($fields['sujet']) < 2 || $len($fields['sujet']) > 150) {
    $errors['sujet'] = 'Veuillez indiquer le sujet de votre message.';
}
if ($len($fields['message']) < 10 || $len($fields['message']) > 5000) {
    $errors['message'] = 'Votre message doit contenir au moins 10 caractères.';
}

// ---------- Enregistrement + envoi ----------
if (!$errors) {
    $sent = true;

    if (!$isBot) {
        // 1. Enregistrement en base → visible dans le Dashboard (Messages). Seule cette étape décide du succès.
        $stored = false;
        $pdo    = db_try();
        if (!$pdo) {
            contact_log('base de données indisponible (voir aussi le journal PHP du serveur)');
        } else {
            try {
                $stored = $pdo->prepare(
                    "INSERT INTO messages (sender_name, sender_email, sender_phone, subject, message, ip, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'unread', ?)"
                )->execute([
                    $fields['nom'], $fields['email'], $fields['telephone'] ?: null, $fields['sujet'],
                    $fields['message'], substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), db_now(),
                ]);
                if (!$stored) {
                    contact_log("l'insertion n'a pas abouti");
                }
            } catch (PDOException $e) {
                contact_log($e->getMessage());
            }
        }

        // 2. Notification par e-mail
        // Suppression des retours à la ligne dans les valeurs utilisées en en-tête (anti-injection)
        $clean = fn (string $v) => str_replace(["\r", "\n", '%0a', '%0d'], ' ', $v);

        $host = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'globaltradingcorporation.net';
        $host = preg_replace('/^www\./i', '', $host);

        $subject = mb_encode_mimeheader('[Site web] ' . $clean($fields['sujet']), 'UTF-8');
        $body = "Nouveau message depuis le formulaire de contact\n"
              . "------------------------------------------------\n"
              . "Nom et Prénom : {$fields['nom']}\n"
              . "E-mail        : {$fields['email']}\n"
              . 'Téléphone     : ' . ($fields['telephone'] ?: '-') . "\n"
              . "Sujet         : {$fields['sujet']}\n\n"
              . "Message :\n{$fields['message']}\n\n"
              . '— Envoyé le ' . date('d/m/Y à H:i') . ' depuis ' . $host . "\n";

        $headers = [
            'From'                      => mb_encode_mimeheader(GTC_NAME, 'UTF-8') . " <no-reply@{$host}>",
            'Reply-To'                  => $clean($fields['email']),
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer'                  => 'PHP/' . PHP_VERSION,
        ];

        // Simple notification (pas une garantie de réception) : n'influe pas sur le résultat affiché
        @mail(GTC_EMAIL, $subject, $body, $headers);

        // Succès UNIQUEMENT si le message est enregistré en base, donc visible dans le Dashboard
        $sent = $stored;
    }

    if ($sent) {
        $_SESSION['last_submit'] = time();
        $_SESSION['csrf_token']  = bin2hex(random_bytes(32));
        $_SESSION['contact']     = ['status' => 'success'];
        back_to_contact();
    }

    $errors['global'] = "Désolé, votre message n'a pas pu être enregistré suite à un problème technique. "
                      . 'Votre saisie est conservée ci-dessous : réessayez dans quelques minutes, ou contactez-nous '
                      . 'directement par téléphone ou WhatsApp.';
}

// Échec : on renvoie erreurs + saisie pour réaffichage
$_SESSION['contact'] = [
    'status' => 'error',
    'errors' => $errors,
    'old'    => $fields,
];
back_to_contact();
