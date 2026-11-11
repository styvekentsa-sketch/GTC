<?php
/**
 * Paramètres : profil & sécurité, entreprise & facturation, taux & commissions, système & sauvegardes.
 *
 * Accès : tout utilisateur connecté (onglet Profil).
 *   Entreprise & Facturation, Système & Sauvegardes → administrateurs
 *   Taux & Commissions                              → administrateurs et caissiers
 * L'onglet Système regroupe aussi la gestion des imprimantes réseau (ajout, modification, imprimante par défaut).
 * Chaque action POST est protégée par un jeton CSRF et revérifie le rôle côté serveur.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/printers.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/owner.php';

$user = require_login();   // exige $_SESSION['logged_in'] === true

$role       = $user['role'];
$canCompany = $role === 'admin';
$canRates   = in_array($role, ['admin', 'caissier'], true);
$canSystem  = $role === 'admin';
$isOwner    = owner_is_current();   // alertes e-mail et envoi : compte de Styve Kentsa uniquement (includes/owner.php)

const LOGO_MAX_BYTES = 2 * 1024 * 1024;

/* ==========================================================================
   Fonctions locales
   ========================================================================== */

/** Accès refusé à une action : message et retour à l'onglet Profil. */
function deny(): never
{
    http_response_code(403);
    flash('error', "Vous n'avez pas les droits nécessaires pour cette action.");
    redirect('settings.php#profil');
}

/**
 * Logo des factures : PNG ou JPG, 2 Mo maximum, contenu réellement vérifié.
 * Réencodé avec GD si disponible (redimensionné à 800 px de large maximum).
 * Retourne [nom_de_fichier|null, erreur|null].
 */
function store_logo(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > LOGO_MAX_BYTES) {
        return [null, 'Le logo dépasse 2 Mo.'];
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        return [null, "L'envoi du logo a échoué."];
    }
    $tmp  = $file['tmp_name'];
    $info = @getimagesize($tmp);
    $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : null;
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
        || ($mime !== null && !in_array($mime, ['image/png', 'image/jpeg'], true))) {
        return [null, 'Format non accepté : PNG ou JPG uniquement.'];
    }
    if ($info[0] < 32 || $info[1] < 32 || $info[0] > 4000 || $info[1] > 4000) {
        return [null, 'Dimensions du logo invalides (entre 32 et 4000 pixels).'];
    }
    $dir = upload_store_dir('brand');   // hors du dossier du site : le logo survit aux mises à jour
    if ($dir === null) {
        return [null, "Le dossier du logo n'est pas accessible en écriture."];
    }

    $ext  = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $name = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    if (function_exists('imagecreatetruecolor')) {
        $src = $ext === 'png' ? @imagecreatefrompng($tmp) : @imagecreatefromjpeg($tmp);
        if (!$src) {
            return [null, 'Image illisible ou corrompue.'];
        }
        $w = min(800, $info[0]);
        $h = (int) round($info[1] * $w / $info[0]);
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

function delete_logo(string $file): void
{
    $file = basename($file);
    if (preg_match('/^logo-[a-f0-9]{16}\.(png|jpe?g)$/', $file)) {
        upload_delete('brand', $file);
    }
}

/** Sauvegarde SQL complète (structure + données) envoyée en téléchargement (contenu : includes/backup.php). */
function send_backup(): never
{
    $file = 'gtc-sauvegarde-' . date('Ymd-His') . '.sql';

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    backup_dump(function (string $chunk): void { echo $chunk; }, current_user()['name']);
    exit;
}

/* ==========================================================================
   Actions POST
   ========================================================================== */

$errors  = [];
$prnForm = ['id' => 0, 'printer_name' => '', 'printer_location' => '', 'paper_format' => 'a4', 'host' => '', 'port' => 9100, 'direct_print' => 0, 'is_active' => 1, 'is_default' => 0];
$scan = null;   // résultat de « Détecter maintenant »

if (is_post()) {
    csrf_verify();
    $op = post('op');

    // ----- Profil -----
    if ($op === 'profile') {
        $name  = post('name');
        $phone = post('phone');
        $email = mb_strtolower(post('email'));
        $tab   = 'profil';

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors['name'] = 'Indiquez votre nom (2 à 100 caractères).';
        }
        if ($phone !== '' && !preg_match('/^[0-9+().\s-]{6,50}$/', $phone)) {
            $errors['phone'] = 'Numéro de téléphone invalide.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
            $errors['email'] = 'Adresse e-mail invalide.';
        } elseif ($email !== $user['email'] && $isOwner) {
            $errors['email'] = 'Adresse du compte propriétaire : elle ne se modifie pas ici (constante GTC_OWNER_EMAIL de includes/db.local.php).';
        } elseif ($email !== $user['email']) {
            // Changement d'identifiant : mot de passe actuel exigé
            $hash = (string) db_value('SELECT password FROM users WHERE id = ?', [$user['id']]);
            if (!password_verify((string) ($_POST['confirm_password'] ?? ''), $hash)) {
                $errors['confirm_password'] = "Saisissez votre mot de passe actuel pour changer d'adresse e-mail.";
            } elseif (db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) {
                $errors['email'] = 'Cette adresse e-mail est déjà utilisée.';
            }
        }

        if (!$errors) {
            db_query('UPDATE users SET name = ?, phone = ?, email = ? WHERE id = ?', [$name, $phone ?: null, $email, $user['id']]);
            $_SESSION['user_name'] = $name;
            activity_log('update', 'user', (int) $user['id'], $user['name'], ['action' => 'Profil modifié par son titulaire']);
            flash('success', 'Profil mis à jour.');
            redirect('settings.php#profil');
        }
    }

    // ----- Mot de passe -----
    if ($op === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $hash    = (string) db_value('SELECT password FROM users WHERE id = ?', [$user['id']]);
        $tab     = 'profil';

        if (!password_verify($current, $hash)) {
            $errors['current_password'] = 'Mot de passe actuel incorrect.';
        }
        if ($msg = auth_password_error($new)) {
            $errors['new_password'] = $msg;
        } elseif (password_verify($new, $hash) || in_array($new, db_seed_passwords(), true)) {
            $errors['new_password'] = "Choisissez un mot de passe différent de l'actuel et des mots de passe par défaut.";
        }
        if (!hash_equals($new, $confirm)) {
            $errors['new_password_confirm'] = 'Les deux mots de passe ne correspondent pas.';
        }

        if (!$errors) {
            db_query('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            // Sessions ouvertes sur les autres appareils fermées ; celle-ci reste active
            $_SESSION['auth_version'] = auth_revoke_sessions((int) $user['id']);
            if (isset($_COOKIE[AUTH_REMEMBER_COOKIE])) {
                auth_remember_issue((int) $user['id']);   // cet appareil reste mémorisé, les autres sont oubliés
            }
            session_regenerate_id(true);
            $_SESSION['regenerated_at'] = time();
            activity_log('security', 'user', (int) $user['id'], $user['name'], ['action' => 'Mot de passe changé par son titulaire']);
            flash('success', 'Mot de passe modifié. Vos autres sessions ouvertes (autres appareils) ont été fermées.');
            redirect('settings.php#profil');
        }
    }

    // ----- Entreprise & facturation (admin) -----
    if ($op === 'company') {
        $canCompany || deny();
        $tab = 'entreprise';
        $fields = [
            'company_name'    => [post('company_name'), 2, 150],
            'company_address' => [post('company_address'), 5, 200],
            'company_phone'   => [post('company_phone'), 6, 50],
            'company_phone2'  => [post('company_phone2'), 0, 50],
            'company_email'   => [mb_strtolower(post('company_email')), 0, 150],
            'company_website' => [preg_replace('#^https?://#i', '', post('company_website')), 0, 150],
            'company_niu'     => [strtoupper(preg_replace('/\s+/', '', post('company_niu'))), 0, 20],
        ];
        foreach ($fields as $key => [$value, $min, $max]) {
            $len = mb_strlen($value);
            if (($min > 0 && $len < $min) || $len > $max) {
                $errors[$key] = 'Valeur invalide (' . ($min ? "{$min} à " : 'au plus ') . "{$max} caractères).";
            }
        }
        foreach (['company_phone', 'company_phone2'] as $key) {
            if ($fields[$key][0] !== '' && !preg_match('/^[0-9+().\s-]{6,50}$/', $fields[$key][0])) {
                $errors[$key] = 'Numéro de téléphone invalide.';
            }
        }
        if ($fields['company_email'][0] !== '' && !filter_var($fields['company_email'][0], FILTER_VALIDATE_EMAIL)) {
            $errors['company_email'] = 'Adresse e-mail invalide.';
        }
        if ($fields['company_website'][0] !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(\/[^\s]*)?$/i', $fields['company_website'][0])) {
            $errors['company_website'] = 'Adresse de site invalide (ex. globaltradingcorporation.net).';
        }
        if ($fields['company_niu'][0] !== '' && !preg_match('/^[A-Z0-9]{8,20}$/', $fields['company_niu'][0])) {
            $errors['company_niu'] = 'NIU invalide : 8 à 20 lettres majuscules et chiffres (ex. M012345678901A).';
        }

        $newLogo = null;
        if (!$errors) {
            [$newLogo, $logoError] = store_logo($_FILES['company_logo'] ?? []);
            if ($logoError) {
                $errors['company_logo'] = $logoError;
            }
        }

        if (!$errors) {
            $values = array_map(fn ($f) => $f[0], $fields);
            $oldLogo = setting('company_logo');
            if ($newLogo) {
                delete_logo($oldLogo);
                $values['company_logo'] = $newLogo;
            } elseif (isset($_POST['remove_logo'])) {
                delete_logo($oldLogo);
                $values['company_logo'] = '';
            }
            settings_save($values);
            activity_log('update', 'settings', null, "Coordonnées de l'entreprise");
            flash('success', 'Coordonnées de l\'entreprise enregistrées : elles figurent désormais sur les reçus de caisse.');
            redirect('settings.php#entreprise');
        }
    }

    // ----- Commission (admin, caissier) ; la grille des taux est gérée dans dashboard/rates.php -----
    if ($op === 'rates') {
        $canRates || deny();
        $tab = 'taux';
        $commission = str_replace(',', '.', post('commission_percent', '0'));
        if (!is_numeric($commission) || (float) $commission < 0 || (float) $commission >= 100) {
            $errors['commission_percent'] = 'Commission invalide (0 à 99,99 %).';
        }
        if (!$errors) {
            settings_save(['commission_percent' => (string) round((float) $commission, 2)]);
            activity_log('update', 'settings', null, 'Commission de guichet par défaut');
            flash('success', 'Commission de guichet par défaut enregistrée.');
            redirect('settings.php#taux');
        }
    }

    // ----- Tarifs du calculateur de fret du site (admin) -----
    if ($op === 'freight') {
        $canCompany || deny();
        $tab = 'taux';
        $values = [];
        foreach (['freight_sea_rate' => 'Tarif maritime', 'freight_air_rate' => 'Tarif aérien', 'freight_sea_min_cbm' => 'Minimum'] as $key => $label) {
            $raw = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], post($key));
            if ($raw === '') {
                $values[$key] = $key === 'freight_sea_min_cbm' ? '0' : '';
            } elseif (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100000000) {
                $errors[$key] = $label . ' invalide.';
            } else {
                $values[$key] = (string) round((float) $raw, 2);
            }
        }
        if (!$errors) {
            settings_save($values);
            activity_log('update', 'settings', null, 'Tarifs du calculateur de fret', [
                'maritime' => $values['freight_sea_rate'] !== '' ? $values['freight_sea_rate'] . ' FCFA/m³' : 'non publié',
                'aérien'   => $values['freight_air_rate'] !== '' ? $values['freight_air_rate'] . ' FCFA/kg' : 'non publié',
                'minimum'  => $values['freight_sea_min_cbm'] . ' m³',
            ]);
            flash('success', 'Tarifs du calculateur de fret enregistrés.');
            redirect('settings.php#taux');
        }
    }

    // ----- Envoi des e-mails (admin) : boîte mail SMTP de l'hébergement (includes/mailer.php) -----
    if ($op === 'smtp') {
        $isOwner || deny();
        $tab  = 'systeme';
        $host = trim(post('smtp_host'));
        $port = (int) post('smtp_port');
        $usr  = mb_strtolower(trim(post('smtp_user')));
        $pass = (string) ($_POST['smtp_pass'] ?? '');
        // Boîte Gmail : seul le serveur de Google l'accepte (mot de passe d'application Google)
        if (preg_match('/@(gmail|googlemail)\.com$/', $usr) && ($host === '' || stripos($host, 'hostinger') !== false)) {
            $host = 'smtp.gmail.com';
        }
        if ($usr !== '' && !filter_var($usr, FILTER_VALIDATE_EMAIL)) {
            $errors['smtp_user'] = 'Indiquez l\'adresse complète de la boîte mail (ex. no-reply@globaltradingcorporation.net).';
        }
        if ($usr !== '' && ($host === '' || !preg_match('/^[a-z0-9.\-]+$/i', $host))) {
            $errors['smtp_host'] = 'Serveur SMTP invalide.';
        }
        if ($usr !== '' && !in_array($port, [465, 587], true)) {
            $errors['smtp_port'] = 'Port 465 (SSL) ou 587 (STARTTLS).';
        }
        if ($usr !== '' && $pass === '' && setting('smtp_pass') === '') {
            $errors['smtp_pass'] = 'Mot de passe de la boîte mail requis.';
        }
        if (!$errors) {
            $values = ['smtp_host' => $host ?: 'smtp.hostinger.com', 'smtp_port' => (string) ($port ?: 465), 'smtp_user' => $usr];
            if ($usr === '') {
                $values = ['smtp_host' => 'smtp.hostinger.com', 'smtp_port' => '465', 'smtp_user' => ''];
                $values['smtp_pass'] = '';               // SMTP désactivé : mot de passe effacé
            } elseif ($pass !== '') {
                $values['smtp_pass'] = $pass;            // champ laissé vide : mot de passe actuel conservé
            }
            settings_save($values);
            activity_log('update', 'settings', null, 'Envoi des e-mails', ['smtp' => $usr !== '' ? $usr . ' @ ' . $values['smtp_host'] . ':' . $values['smtp_port'] : 'désactivé (mail())']);
            flash('success', $usr !== '' ? 'Envoi par la boîte ' . $usr . ' enregistré. Envoyez un e-mail de test pour vérifier.' : 'SMTP désactivé : envoi par la fonction mail() du serveur.');
            redirect('settings.php#systeme');
        }
    }

    // ----- Alertes de connexion (admin) : e-mail envoyé à chaque connexion au Dashboard -----
    if ($op === 'login_alert' || $op === 'login_alert_test') {
        $isOwner || deny();
        $tab = 'systeme';
        $to = mb_strtolower(trim(post('login_alert_email')));
        if ($to !== '' && (!filter_var($to, FILTER_VALIDATE_EMAIL) || mb_strlen($to) > 150)) {
            $errors['login_alert_email'] = 'Adresse e-mail invalide.';
        }
        if ($op === 'login_alert_test' && $to === '') {
            $errors['login_alert_email'] = 'Indiquez l\'adresse qui doit recevoir l\'e-mail de test.';
        }
        if (!$errors) {
            settings_save(['login_alert_email' => $to, 'activity_mail' => isset($_POST['activity_mail']) ? '1' : '0']);
            if ($op === 'login_alert_test') {
                require_once __DIR__ . '/../includes/login_alert.php';
                $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Douala'));
                $me = db_one('SELECT name, email, role FROM users WHERE id = ?', [(int) $user['id']]) ?: $user;
                $ok = login_alert_send([
                    'to' => $to, 'name' => (string) $me['name'], 'email' => (string) ($me['email'] ?? ''), 'role' => role_label($me['role'] ?? null),
                    'kind' => 'password', 'method' => 'E-mail de TEST envoyé depuis Paramètres → Système',
                    'date' => $now->format('d/m/Y'), 'time' => $now->format('H:i'),
                    'ip' => client_ip(), 'device' => login_alert_device((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
                ] + login_alert_site());
                activity_log('update', 'settings', null, 'Alertes de connexion', ['test' => $to]);
                flash($ok ? 'success' : 'error', $ok
                    ? 'E-mail de test envoyé à ' . $to . '. Vérifiez la boîte de réception (et les spams).'
                    : 'Échec de l\'envoi à ' . $to . ' : ' . rtrim((mailer_last()['error'] ?? '') ?: 'erreur inconnue', '. ') . '.');
            } else {
                activity_log('update', 'settings', null, 'Alertes de connexion', ['destinataire' => $to !== '' ? $to : 'désactivées']);
                flash('success', $to !== '' ? 'Alertes envoyées à ' . $to . (isset($_POST['activity_mail']) ? ' : connexions et opérations du Dashboard.' : ' : connexions uniquement.') : 'Alertes de connexion désactivées.');
            }
            redirect('settings.php#systeme');
        }
    }

    // ----- Système (admin) -----
    if ($op === 'system') {
        $canSystem || deny();
        $tab    = 'systeme';
        $format = strtoupper(post('print_format'));
        $cur    = post('currency');
        if (!in_array($format, ['A4', 'A5', 'TICKET'], true)) {
            $errors['print_format'] = "Format d'impression invalide.";
        }
        if (!isset(DASH_CURRENCIES[$cur])) {
            $errors['currency'] = 'Devise invalide.';
        }
        if (!$errors) {
            settings_save(['print_format' => $format, 'currency' => $cur === 'XAF' ? 'FCFA' : $cur]);
            activity_log('update', 'settings', null, 'Préférences système');
            flash('success', 'Préférences système enregistrées.');
            redirect('settings.php#systeme');
        }
    }

    if ($op === 'backup') {
        $canSystem || deny();
        activity_log('backup', 'database', null, "Téléchargement d'une sauvegarde complète");
        send_backup();
    }

    // ----- Imprimantes réseau (admin) -----
    if (in_array($op, ['printer_save', 'printer_default', 'printer_delete', 'printer_test', 'printer_scan'], true)) {
        $canSystem || deny();
        $tab = 'systeme';
    }

    if ($op === 'printer_save') {
        $prnForm = [
            'id'               => (int) post('id'),
            'printer_name'     => post('printer_name'),
            'printer_location' => post('printer_location'),
            'paper_format'     => post('paper_format', 'a4'),
            'host'             => mb_strtolower(post('host')),
            'port'             => (int) post('port', '9100'),
            'direct_print'     => isset($_POST['direct_print']) ? 1 : 0,
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
            'is_default'       => 0,
        ];
        $existing = $prnForm['id'] ? printer_find($prnForm['id'], false) : null;
        if ($prnForm['id'] && !$existing) {
            flash('error', 'Imprimante introuvable.');
            redirect('settings.php#systeme');
        }
        $prnForm['is_default'] = $existing ? (int) $existing['is_default'] : 0;
        $makeDefault = isset($_POST['is_default']) || $prnForm['is_default'] === 1;

        if (mb_strlen($prnForm['printer_name']) < 2 || mb_strlen($prnForm['printer_name']) > 100) {
            $errors['printer_name'] = "Nom de l'imprimante requis (2 à 100 caractères).";
        }
        if (mb_strlen($prnForm['printer_location']) < 2 || mb_strlen($prnForm['printer_location']) > 100) {
            $errors['printer_location'] = 'Emplacement requis (2 à 100 caractères).';
        }
        if (!isset(PRINTER_FORMATS[$prnForm['paper_format']])) {
            $errors['paper_format'] = 'Format de papier invalide.';
        }
        if ($prnForm['host'] !== '' && !printer_valid_host($prnForm['host'])) {
            $errors['host'] = "Adresse IP ou nom d'hôte invalide.";
        }
        if (!isset(PRINTER_PORTS[$prnForm['port']])) {
            $errors['port'] = 'Port non autorisé.';
        }
        if ($prnForm['direct_print'] && ($prnForm['host'] === '' || !in_array($prnForm['port'], PRINTER_DIRECT_PORTS, true))) {
            $errors['direct_print'] = "L'envoi direct nécessite l'adresse IP de l'imprimante et le protocole RAW (9100) ou IPP (631).";
        } elseif ($prnForm['direct_print'] && $prnForm['paper_format'] === 'ticket' && $prnForm['port'] !== 9100) {
            $errors['direct_print'] = "Une imprimante ticket reçoit l'envoi direct par le protocole RAW (9100).";
        }
        // L'imprimante par défaut doit rester active
        if ($makeDefault && !$prnForm['is_active']) {
            $errors['is_active'] = "L'imprimante par défaut doit rester active : choisissez d'abord une autre imprimante par défaut.";
        }

        if (!$errors) {
            $values = [$prnForm['printer_name'], $prnForm['printer_location'], $prnForm['paper_format'],
                       $prnForm['host'] ?: null, $prnForm['port'], $prnForm['direct_print'], $prnForm['is_active']];
            if ($existing) {
                // Adresse modifiée : l'ancien résultat du test de connexion n'est plus valable
                $hostChanged = (string) $existing['host'] !== $prnForm['host'] || (int) $existing['port'] !== $prnForm['port'];
                db_query('UPDATE printers SET printer_name = ?, printer_location = ?, paper_format = ?, host = ?, port = ?, direct_print = ?, is_active = ?'
                    . ($hostChanged ? ', last_status = NULL, last_checked_at = NULL' : '') . ' WHERE id = ?', [...$values, $existing['id']]);
                $pid = (int) $existing['id'];
            } else {
                db_query('INSERT INTO printers (printer_name, printer_location, paper_format, host, port, direct_print, is_active, is_default, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)', [...$values, db_now()]);
                $pid = (int) db()->lastInsertId();
            }
            // Case cochée, ou aucune imprimante par défaut active : celle-ci le devient
            if ($makeDefault || ($prnForm['is_active'] && !db_value('SELECT 1 FROM printers WHERE is_default = 1 AND is_active = 1'))) {
                printer_set_default($pid);
            }
            activity_log($existing ? 'update' : 'create', 'printer', $existing ? (int) $prnForm['id'] : null, $prnForm['printer_name'],
                array_filter(['adresse' => $prnForm['host'] !== '' ? $prnForm['host'] . ':' . $prnForm['port'] : '']));
            flash('success', $existing ? "Imprimante « {$prnForm['printer_name']} » mise à jour." : "Imprimante « {$prnForm['printer_name']} » ajoutée.");
            redirect('settings.php#systeme');
        }
    }

    if ($op === 'printer_default') {
        $printer = printer_find((int) post('id'));   // imprimante active uniquement
        if ($printer) {
            printer_set_default((int) $printer['id']);
            activity_log('update', 'printer', (int) $printer['id'], $printer['printer_name'], ['action' => 'Imprimante par défaut']);
            flash('success', "« {$printer['printer_name']} » est désormais l'imprimante par défaut de l'agence.");
        } else {
            flash('error', 'Seule une imprimante active peut être définie par défaut.');
        }
        redirect('settings.php#systeme');
    }

    if ($op === 'printer_delete') {
        $printer = printer_find((int) post('id'), false);
        if ($printer) {
            $pid = (int) $printer['id'];
            db_query('UPDATE print_jobs SET printer_id = NULL WHERE printer_id = ?', [$pid]);
            db_query('UPDATE invoices SET printer_id = NULL WHERE printer_id = ?', [$pid]);
            db_query('DELETE FROM printers WHERE id = ?', [$pid]);
            // Imprimante par défaut supprimée : la première imprimante active la remplace
            if ((int) $printer['is_default'] === 1 && ($next = printer_default())) {
                printer_set_default((int) $next['id']);
            }
            activity_log('delete', 'printer', (int) $printer['id'], $printer['printer_name']);
            flash('success', "Imprimante « {$printer['printer_name']} » supprimée.");
        }
        redirect('settings.php#systeme');
    }

    // Découverte du réseau local : enregistre le sous-réseau puis l'analyse
    if ($op === 'printer_scan') {
        $subnet = trim(post('printer_scan_subnet'));
        if ($subnet !== '' && printer_scan_targets($subnet) === null) {
            $errors['printer_scan_subnet'] = 'Plage refusée : indiquez un réseau privé de 256 adresses au plus (ex. 192.168.1.0/24).';
        } else {
            settings_save(['printer_scan_subnet' => $subnet]);
            if ($subnet === '') {
                flash('success', 'Détection réseau désactivée.');
                redirect('settings.php#systeme');
            }
            $scan = printers_discover($subnet, true);
        }
    }

    if ($op === 'printer_test') {
        $printer = printer_find((int) post('id'), false);
        if ($printer && (string) $printer['host'] === '') {
            flash('warning', "Renseignez l'adresse IP de « {$printer['printer_name']} » pour tester la connexion.");
        } elseif ($printer) {
            $socket = @fsockopen($printer['host'], (int) $printer['port'], $errno, $errstr, 3);
            $online = $socket !== false;
            if ($socket) {
                fclose($socket);
            }
            db_query('UPDATE printers SET last_status = ?, last_checked_at = ? WHERE id = ?',
                [$online ? 'online' : 'offline', db_now(), $printer['id']]);
            flash($online ? 'success' : 'warning', $online
                ? "« {$printer['printer_name']} » répond sur {$printer['host']}:{$printer['port']}."
                : "« {$printer['printer_name']} » ne répond pas ({$printer['host']}:{$printer['port']}).");
        }
        redirect('settings.php#systeme');
    }
}

// Modification d'une imprimante (GET ?edit=ID)
if ($canSystem && query('edit') && !$errors && ($row = printer_find((int) query('edit'), false))) {
    $prnForm = $row;
    $prnForm['host'] = (string) $row['host'];
    $tab = 'systeme';
}

// Appareil détecté sur le réseau : formulaire d'ajout pré-rempli (GET ?add_host=IP&add_port=9100)
if ($canSystem && !is_post() && query('add_host') && filter_var(query('add_host'), FILTER_VALIDATE_IP)) {
    $port = (int) query('add_port');
    $prnForm['host'] = query('add_host');
    $prnForm['port'] = isset(PRINTER_PORTS[$port]) ? $port : 9100;
    $prnForm['printer_name'] = 'Imprimante ' . $prnForm['host'];
    $tab = 'systeme';
}

/* ==========================================================================
   Données d'affichage
   ========================================================================== */

$me    = db_one('SELECT name, email, phone, role, last_login_at, last_login_ip, previous_login_at, previous_login_ip FROM users WHERE id = ?', [$user['id']]);
$s     = settings_all();
$companyRates = $canRates ? company_rates() : [];
$printers  = $canSystem ? printers_list() : [];
// Sous-réseau suggéré : celui du serveur s'il est sur un réseau privé (serveur installé à l'agence)
$serverIp = (string) ($_SERVER['SERVER_ADDR'] ?? '');
$scanHint = filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && printer_scan_targets($serverIp) !== null && !str_starts_with($serverIp, '127.')
    ? preg_replace('/\.\d+$/', '.0/24', $serverIp) : '192.168.1.0/24';
$activeTab = $tab ?? null;

$val = fn (string $key, string $fallback = '') => e(is_post() && isset($_POST[$key]) ? (string) $_POST[$key] : $fallback);
$err = fn (string $k) => isset($errors[$k]) ? '<small class="field__error">' . e($errors[$k]) . '</small>' : '';
$inv = fn (string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';

$tabs = ['profil' => ['icon' => 'users', 'label' => 'Mon profil & sécurité']];
if ($canCompany) $tabs['entreprise'] = ['icon' => 'store', 'label' => 'Entreprise & facturation'];
if ($canRates)   $tabs['taux']       = ['icon' => 'calc',  'label' => 'Taux & commissions'];
if ($canSystem)  $tabs['systeme']    = ['icon' => 'shield', 'label' => 'Système & sauvegardes'];

dash_header('Paramètres', 'settings');
?>

<?php if ($errors): ?>
    <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>Merci de corriger les champs indiqués.</p></div>
<?php endif; ?>

<div class="settings" data-settings-tabs<?= $activeTab ? ' data-active="' . e($activeTab) . '"' : '' ?>>
    <nav class="tabs settings__tabs" role="tablist" aria-label="Paramètres">
        <?php foreach ($tabs as $id => $t): ?>
            <a href="#<?= e($id) ?>" class="tabs__item" role="tab" id="tab-<?= e($id) ?>" aria-controls="panel-<?= e($id) ?>"><?= icon($t['icon']) ?> <?= e($t['label']) ?></a>
        <?php endforeach; ?>
        <?php if (is_admin()): ?>
            <!-- Gestion des comptes : page dédiée (dashboard/users.php, administrateurs uniquement) — simple lien, pas un onglet -->
            <a href="users.php" class="tabs__item"><?= icon('shield') ?> Utilisateurs <?= icon('arrow') ?></a>
        <?php endif; ?>
    </nav>

    <!-- ==================== 1. PROFIL & SÉCURITÉ ==================== -->
    <section class="settings__panel" id="panel-profil" role="tabpanel" aria-labelledby="tab-profil">
        <div class="dash-grid">
            <form method="post" class="panel form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="profile">
                <h2 class="panel__title">Mes informations</h2>
                <div class="form-grid form-grid--2">
                    <div class="field">
                        <label for="p-name">Nom complet</label>
                        <input id="p-name" name="name" required maxlength="100" value="<?= $val('name', $me['name']) ?>"<?= $inv('name') ?>>
                        <?= $err('name') ?>
                    </div>
                    <div class="field">
                        <label for="p-phone">Téléphone</label>
                        <input id="p-phone" name="phone" type="tel" maxlength="50" value="<?= $val('phone', (string) $me['phone']) ?>"<?= $inv('phone') ?>>
                        <?= $err('phone') ?>
                    </div>
                </div>
                <div class="field">
                    <label for="p-email">Adresse e-mail (identifiant de connexion)</label>
                    <input id="p-email" name="email" type="email" required maxlength="100" autocomplete="username" value="<?= $val('email', $me['email']) ?>"<?= $inv('email') ?>>
                    <?= $err('email') ?>
                </div>
                <div class="field">
                    <label for="p-confirm">Mot de passe actuel <small class="field__hint">(requis seulement pour changer d'e-mail)</small></label>
                    <input id="p-confirm" name="confirm_password" type="password" autocomplete="current-password"<?= $inv('confirm_password') ?>>
                    <?= $err('confirm_password') ?>
                </div>
                <div class="form-actions"><button type="submit" class="btn btn--primary"><?= icon('check') ?> Enregistrer</button></div>
            </form>

            <div class="settings__stack">
                <form method="post" class="panel form" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="op" value="password">
                    <input type="text" value="<?= e($me['email']) ?>" autocomplete="username" readonly hidden>
                    <h2 class="panel__title">Changer mon mot de passe</h2>
                    <div class="field">
                        <label for="pw-current">Mot de passe actuel</label>
                        <input id="pw-current" name="current_password" type="password" required autocomplete="current-password"<?= $inv('current_password') ?>>
                        <?= $err('current_password') ?>
                    </div>
                    <div class="field">
                        <label for="pw-new">Nouveau mot de passe</label>
                        <input id="pw-new" name="new_password" type="password" required minlength="10" autocomplete="new-password"<?= $inv('new_password') ?>>
                        <small class="field__hint">10 caractères minimum, avec au moins une lettre et un chiffre.</small>
                        <?= $err('new_password') ?>
                    </div>
                    <div class="field">
                        <label for="pw-confirm">Confirmation</label>
                        <input id="pw-confirm" name="new_password_confirm" type="password" required minlength="10" autocomplete="new-password"<?= $inv('new_password_confirm') ?>>
                        <?= $err('new_password_confirm') ?>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn btn--primary"><?= icon('lock') ?> Modifier</button></div>
                </form>

                <section class="panel">
                    <h2 class="panel__title">Historique de session</h2>
                    <dl class="detail-list">
                        <div><dt>Rôle</dt><dd><?= e(role_label($me['role'])) ?></dd></div>
                        <div><dt>Connexion actuelle</dt><dd><?= e(fdate($me['last_login_at'], true)) ?><small class="cell-sub">IP <?= e($me['last_login_ip'] ?: '—') ?></small></dd></div>
                        <div><dt>Connexion précédente</dt><dd><?= e(fdate($me['previous_login_at'], true)) ?><small class="cell-sub">IP <?= e($me['previous_login_ip'] ?: '—') ?></small></dd></div>
                    </dl>
                    <p class="help-text"><?= icon('alert') ?> Une connexion précédente que vous ne reconnaissez pas ? Changez immédiatement votre mot de passe.</p>
                </section>
            </div>
        </div>
    </section>

    <?php if ($canCompany): ?>
    <!-- ==================== 2. ENTREPRISE & FACTURATION ==================== -->
    <section class="settings__panel" id="panel-entreprise" role="tabpanel" aria-labelledby="tab-entreprise">
        <form method="post" enctype="multipart/form-data" class="panel form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="company">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= LOGO_MAX_BYTES ?>">
            <header class="panel__head">
                <h2 class="panel__title">Coordonnées de l'agence</h2>
                <span class="cell-sub">Reprises automatiquement sur les reçus de caisse imprimés</span>
            </header>
            <div class="form-grid form-grid--2">
                <div class="field">
                    <label for="c-name">Raison sociale *</label>
                    <input id="c-name" name="company_name" required maxlength="150" value="<?= $val('company_name', $s['company_name']) ?>"<?= $inv('company_name') ?>>
                    <?= $err('company_name') ?>
                </div>
                <div class="field">
                    <label for="c-address">Adresse *</label>
                    <input id="c-address" name="company_address" required maxlength="200" value="<?= $val('company_address', $s['company_address']) ?>"<?= $inv('company_address') ?>>
                    <?= $err('company_address') ?>
                </div>
                <div class="field">
                    <label for="c-phone">Téléphone principal *</label>
                    <input id="c-phone" name="company_phone" type="tel" required maxlength="50" value="<?= $val('company_phone', $s['company_phone']) ?>"<?= $inv('company_phone') ?>>
                    <?= $err('company_phone') ?>
                </div>
                <div class="field">
                    <label for="c-phone2">Téléphone secondaire</label>
                    <input id="c-phone2" name="company_phone2" type="tel" maxlength="50" value="<?= $val('company_phone2', $s['company_phone2']) ?>"<?= $inv('company_phone2') ?>>
                    <?= $err('company_phone2') ?>
                </div>
                <div class="field">
                    <label for="c-email">E-mail</label>
                    <input id="c-email" name="company_email" type="email" maxlength="150" value="<?= $val('company_email', $s['company_email']) ?>"<?= $inv('company_email') ?>>
                    <?= $err('company_email') ?>
                </div>
                <div class="field">
                    <label for="c-website">Site web</label>
                    <input id="c-website" name="company_website" maxlength="150" value="<?= $val('company_website', $s['company_website']) ?>"<?= $inv('company_website') ?>>
                    <?= $err('company_website') ?>
                </div>
                <div class="field">
                    <label for="c-niu">NIU (Numéro d'Identifiant Unique)</label>
                    <input id="c-niu" name="company_niu" maxlength="20" autocomplete="off" spellcheck="false" placeholder="M012345678901A"
                           style="text-transform: uppercase" value="<?= $val('company_niu', $s['company_niu']) ?>"<?= $inv('company_niu') ?>>
                    <small class="field__hint">Imprimé automatiquement sur tous les reçus de caisse, sous l'adresse et le téléphone.</small>
                    <?= $err('company_niu') ?>
                </div>
            </div>

            <div class="field">
                <label for="c-logo">Logo des reçus et factures (PNG ou JPG, 2 Mo max)</label>
                <div class="logo-current">
                    <img src="<?= e(settings_logo_url(site_root())) ?>" alt="Logo actuel des reçus et factures" height="56">
                    <?php if ($s['company_logo'] !== ''): ?>
                        <label class="check"><input type="checkbox" name="remove_logo"> Revenir au logo GTC par défaut</label>
                    <?php else: ?>
                        <small class="field__hint">Logo GTC par défaut</small>
                    <?php endif; ?>
                </div>
                <input id="c-logo" name="company_logo" type="file" accept="image/png,image/jpeg"<?= $inv('company_logo') ?>>
                <?= $err('company_logo') ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> Enregistrer</button>
                <a href="print-invoice.php?id=<?= (int) db_value('SELECT MAX(id) FROM invoices') ?>&amp;autoprint=0" class="btn btn--ghost" target="_blank" rel="noopener"><?= icon('eye') ?> Aperçu d'un reçu</a>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($canRates): ?>
    <!-- ==================== 3. TAUX & COMMISSIONS ==================== -->
    <section class="settings__panel" id="panel-taux" role="tabpanel" aria-labelledby="tab-taux">
        <!-- Grille des taux : gérée dans Dashboard → Taux de change (dashboard/rates.php) -->
        <section class="panel">
            <header class="panel__head">
                <div>
                    <h2 class="panel__title">Taux de change de l'entreprise</h2>
                    <span class="cell-sub">Mis à jour le <?= e(company_rates_updated_label()) ?> · appliqués au site, aux simulateurs et aux reçus de caisse</span>
                </div>
                <a href="rates.php" class="btn btn--primary btn--sm"><?= icon('exchange') ?> <?= $canCompany ? 'Gérer la grille des taux' : 'Voir la grille' ?></a>
            </header>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead><tr><th>Devise</th><th class="num">Achat / Dépôt</th><th class="num">Vente / Retrait</th></tr></thead>
                    <tbody>
                    <?php foreach ($companyRates as $code => $r): ?>
                        <tr>
                            <td data-label="Devise"><strong><?= e($code) ?></strong> <small class="cell-sub"><?= e($r['name']) ?></small></td>
                            <td data-label="Achat / Dépôt" class="num"><?= e(rate_format($r['buy'])) ?> FCFA</td>
                            <td data-label="Vente / Retrait" class="num"><?= e(rate_format($r['sell'])) ?> FCFA</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <form method="post" class="panel form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="rates">
            <h2 class="panel__title">Commission</h2>
            <div class="field">
                <label for="r-commission">Commission de guichet par défaut (%)</label>
                <input id="r-commission" name="commission_percent" inputmode="decimal" value="<?= $val('commission_percent', str_replace('.', ',', $s['commission_percent'])) ?>"<?= $inv('commission_percent') ?>>
                <small class="field__hint">Préremplie dans le simulateur avancé ; modifiable à chaque opération.</small>
                <?= $err('commission_percent') ?>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><?= icon('check') ?> Enregistrer</button></div>
        </form>

        <?php if ($canCompany): ?>
        <form method="post" class="panel form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="freight">
            <header class="panel__head">
                <div>
                    <h2 class="panel__title">Calculateur de fret (site public)</h2>
                    <span class="cell-sub">Tarifs indicatifs affichés sur <a href="<?= e(site_root()) ?>calculateur-cbm.php" target="_blank" rel="noopener">la page du calculateur</a>. Laissez un tarif vide pour n'afficher que les volumes et poids.</span>
                </div>
            </header>
            <div class="form-grid">
                <div class="field">
                    <label for="f-sea">Fret maritime (FCFA par m³)</label>
                    <input id="f-sea" name="freight_sea_rate" inputmode="decimal" value="<?= $val('freight_sea_rate', str_replace('.', ',', $s['freight_sea_rate'] ?? '')) ?>"<?= $inv('freight_sea_rate') ?> placeholder="Non publié">
                    <?= $err('freight_sea_rate') ?>
                </div>
                <div class="field">
                    <label for="f-air">Fret aérien (FCFA par kg)</label>
                    <input id="f-air" name="freight_air_rate" inputmode="decimal" value="<?= $val('freight_air_rate', str_replace('.', ',', $s['freight_air_rate'] ?? '')) ?>"<?= $inv('freight_air_rate') ?> placeholder="Non publié">
                    <?= $err('freight_air_rate') ?>
                </div>
                <div class="field">
                    <label for="f-min">Minimum facturé en maritime (m³)</label>
                    <input id="f-min" name="freight_sea_min_cbm" inputmode="decimal" value="<?= $val('freight_sea_min_cbm', str_replace('.', ',', $s['freight_sea_min_cbm'] ?? '1')) ?>"<?= $inv('freight_sea_min_cbm') ?>>
                    <small class="field__hint">Groupage : un envoi plus petit est facturé sur ce volume.</small>
                    <?= $err('freight_sea_min_cbm') ?>
                </div>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><?= icon('check') ?> Enregistrer les tarifs</button></div>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($canSystem): ?>
    <!-- ==================== 4. SYSTÈME & SAUVEGARDES ==================== -->
    <section class="settings__panel" id="panel-systeme" role="tabpanel" aria-labelledby="tab-systeme">
        <header class="settings-group">
            <h2 class="settings-group__title"><?= icon('settings') ?> Préférences et sauvegardes</h2>
            <p class="settings-group__sub">Format d'impression, devise par défaut et sauvegarde de la base de données.</p>
        </header>
        <div class="settings-row">
            <form method="post" class="panel form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="system">
                <h2 class="panel__title">Préférences</h2>
                <fieldset class="form-section">
                    <legend>Format d'impression par défaut</legend>
                    <div class="choice-grid">
                        <?php foreach (['A4' => 'Feuille A4', 'A5' => 'Feuille A5', 'TICKET' => 'Ticket thermique 80 mm'] as $key => $label): ?>
                            <label class="choice">
                                <input type="radio" name="print_format" value="<?= e($key) ?>" <?= strtoupper($s['print_format']) === $key ? 'checked' : '' ?>>
                                <span><?= icon('printer') ?> <?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?= $err('print_format') ?>
                </fieldset>
                <div class="field">
                    <label for="s-currency">Devise locale (devise reçue par défaut)</label>
                    <select id="s-currency" name="currency">
                        <?php foreach (DASH_CURRENCIES as $code => $c): ?>
                            <option value="<?= e($code) ?>" <?= $code === settings_currency() ? 'selected' : '' ?>><?= e(currency_label($code)) ?><?= $c['label'] !== currency_label($code) ? ' — ' . e($c['label']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions"><button type="submit" class="btn btn--primary"><?= icon('check') ?> Enregistrer</button></div>
            </form>

            <section class="panel">
                <h2 class="panel__title">Sauvegarde de la base de données</h2>
                <p class="page-intro">Télécharge un fichier <code>.sql</code> contenant la structure et toutes les données : reçus de caisse, factures, comptes, équipe, messages, paramètres.</p>
                <p class="help-text"><?= icon('alert') ?> Fichier confidentiel (il contient les empreintes des mots de passe) : conservez-le hors du serveur, en lieu sûr.</p>
                <form method="post" class="form-actions">
                    <?= csrf_field() ?>
                    <input type="hidden" name="op" value="backup">
                    <button type="submit" class="btn btn--primary"><?= icon('receipt') ?> Télécharger une sauvegarde BDD (.sql)</button>
                    <a href="backups.php" class="btn btn--ghost"><?= icon('archive') ?> Historique, restauration…</a>
                </form>
                <dl class="detail-list settings__sysinfo">
                    <div><dt>Moteur</dt><dd><?= DB_DRIVER === 'mysql' ? 'MySQL' : 'SQLite' ?></dd></div>
                    <div><dt>Reçus de caisse enregistrés</dt><dd><?= (int) db_value('SELECT COUNT(*) FROM invoices') ?></dd></div>
                    <div><dt>Comptes</dt><dd><?= (int) db_value('SELECT COUNT(*) FROM users') ?></dd></div>
                    <div><dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd></div>
                    <?php $autoBackups = backup_list(); $lastAuto = $autoBackups[0] ?? null; ?>
                    <div><dt>Sauvegardes automatiques</dt><dd>
                        <?php if (!$lastAuto): ?>
                            <span class="badge badge--warning">Aucune</span> <small class="cell-sub">Programmer <code>php tools/auto-backup.php</code> chaque nuit (tâche cron).</small>
                        <?php else: ?>
                            <?= $lastAuto['time'] < time() - 2 * 86400 ? '<span class="badge badge--danger">En retard</span> ' : '<span class="badge badge--success">Actives</span> ' ?>
                            dernière le <?= e(date('d/m/Y à H:i', $lastAuto['time'])) ?> · <?= count($autoBackups) ?> conservée<?= count($autoBackups) > 1 ? 's' : '' ?> (<?= (int) GTC_BACKUP_KEEP_DAYS ?> jours)
                        <?php endif; ?>
                    </dd></div>
                </dl>
            </section>
        </div>

        <?php if ($isOwner): ?>
        <header class="settings-group">
            <h2 class="settings-group__title"><?= icon('mail') ?> Alertes et envoi des e-mails <span class="badge badge--info"><?= icon('lock') ?> Visible par vous seul</span></h2>
            <p class="settings-group__sub">Connexions et opérations du Dashboard envoyées par e-mail, et boîte mail utilisée pour les expédier.</p>
        </header>
        <div class="settings-row">
            <!-- Alertes de connexion : e-mail à chaque connexion au Dashboard (includes/login_alert.php) — propriétaire uniquement -->
            <form method="post" action="settings.php#systeme" class="panel form" novalidate data-login-alert>
                <?= csrf_field() ?>
                <h2 class="panel__title"><?= icon('mail') ?> Alertes par e-mail</h2>
                <p class="cell-sub">Un e-mail est envoyé à chaque connexion au Dashboard (mot de passe, reconnexion automatique, prise de contrôle d'un compte) :
                    compte, date et heure, adresse IP, appareil et navigateur.</p>
                <div class="field">
                    <label for="login-alert-email">Adresse qui reçoit les alertes</label>
                    <input id="login-alert-email" name="login_alert_email" type="email" maxlength="150" inputmode="email" autocomplete="off"
                           placeholder="Laisser vide pour désactiver" value="<?= $val('login_alert_email', $s['login_alert_email']) ?>"<?= $inv('login_alert_email') ?>>
                    <small class="field__hint"><?= trim($s['login_alert_email']) !== '' ? 'Alertes actives.' : 'Alertes désactivées.' ?> Laisser vide pour ne plus en recevoir.</small>
                    <?= $err('login_alert_email') ?>
                </div>
                <label class="check">
                    <input type="checkbox" name="activity_mail" value="1" <?= (is_post() && post('op') === 'login_alert' ? isset($_POST['activity_mail']) : $s['activity_mail'] !== '0') ? 'checked' : '' ?>>
                    <span>Recevoir aussi chaque opération du Dashboard : reçus, factures, courriers, dossiers GTC-Vault, comptes, paramètres, sauvegardes…</span>
                </label>
                <div class="form-actions">
                    <button type="submit" name="op" value="login_alert_test" class="btn btn--ghost"><?= icon('send') ?> Envoyer un e-mail de test</button>
                    <button type="submit" name="op" value="login_alert" class="btn btn--primary"><?= icon('check') ?> Enregistrer</button>
                </div>
                <?php $last = json_decode($s['mail_last_status'] ?? '', true); if (is_array($last)): ?>
                    <p class="help-text" data-mail-last><?= icon($last['ok'] ? 'check' : 'alert') ?>
                        Dernier envoi : <?= e($last['at'] ?? '') ?> à <?= e($last['to'] ?? '') ?> —
                        <?= $last['ok'] ? 'accepté par ' . ($last['via'] === 'smtp' ? 'le serveur SMTP' : 'la fonction mail() du serveur') : 'échec : ' . e($last['error'] ?? '') ?></p>
                <?php endif; ?>
            </form>

            <!-- Envoi des e-mails : boîte mail SMTP de l'hébergement (includes/mailer.php) -->
            <?php $smtpConst = defined('GTC_SMTP_USER') && (string) constant('GTC_SMTP_USER') !== ''; $smtpOn = $smtpConst || trim($s['smtp_user']) !== ''; ?>
            <form method="post" action="settings.php#systeme" class="panel form" novalidate data-smtp autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="smtp">
                <h2 class="panel__title"><?= icon('send') ?> Envoi des e-mails</h2>
                <p class="cell-sub">Les alertes partent d'une vraie boîte mail : un compte <strong>Gmail</strong> (avec un mot de passe d'application Google)
                    ou une boîte du domaine créée dans hPanel → E-mails (ex. <strong>no-reply@globaltradingcorporation.net</strong>).
                    <?= $smtpOn ? '' : 'Actuellement : fonction mail() du serveur (souvent écartée par Gmail).' ?></p>
                <?php if ($smtpConst): ?>
                    <p class="help-text"><?= icon('lock') ?> Boîte définie dans includes/db.local.php (<?= e((string) constant('GTC_SMTP_USER')) ?>) : ces réglages sont prioritaires.</p>
                <?php endif; ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="smtp-user">Adresse de la boîte mail</label>
                        <input id="smtp-user" name="smtp_user" type="email" maxlength="150" inputmode="email" autocomplete="off"
                               placeholder="no-reply@globaltradingcorporation.net" value="<?= $val('smtp_user', $s['smtp_user']) ?>"<?= $inv('smtp_user') ?>>
                        <small class="field__hint">Boîte Gmail : serveur smtp.gmail.com et <strong>mot de passe d'application</strong> Google (pas le mot de passe Gmail). Laisser vide pour revenir à la fonction mail().</small>
                        <?= $err('smtp_user') ?>
                    </div>
                    <div class="field">
                        <label for="smtp-pass">Mot de passe de la boîte</label>
                        <input id="smtp-pass" name="smtp_pass" type="password" maxlength="200" autocomplete="new-password"
                               placeholder="<?= $s['smtp_pass'] !== '' ? 'Enregistré — laisser vide pour le conserver' : 'Mot de passe défini dans hPanel' ?>"<?= $inv('smtp_pass') ?>>
                        <?= $err('smtp_pass') ?>
                    </div>
                    <div class="field">
                        <label for="smtp-host">Serveur SMTP</label>
                        <input id="smtp-host" name="smtp_host" type="text" maxlength="120" value="<?= $val('smtp_host', $s['smtp_host']) ?>"<?= $inv('smtp_host') ?>>
                        <?= $err('smtp_host') ?>
                    </div>
                    <div class="field">
                        <label for="smtp-port">Port</label>
                        <select id="smtp-port" name="smtp_port"<?= $inv('smtp_port') ?>>
                            <?php foreach (['465' => '465 — SSL (recommandé)', '587' => '587 — STARTTLS'] as $p => $l): ?>
                                <option value="<?= $p ?>" <?= (string) ($_POST['smtp_port'] ?? $s['smtp_port']) === (string) $p ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $err('smtp_port') ?>
                    </div>
                </div>
                <div class="form-actions"><button type="submit" class="btn btn--primary"><?= icon('check') ?> Enregistrer</button></div>
            </form>
        </div>
        <?php endif; ?>

        <header class="settings-group">
            <h2 class="settings-group__title"><?= icon('printer') ?> Imprimantes de l'agence</h2>
            <p class="settings-group__sub">Imprimantes proposées à la création d'un reçu, et détection sur le réseau local.</p>
        </header>
        <!-- Imprimantes réseau -->
        <div class="dash-grid dash-grid--aside printers-admin">
            <section class="panel">
                <header class="panel__head">
                    <h2 class="panel__title">Imprimantes réseau de l'agence</h2>
                    <span class="cell-sub">Proposées dans « Imprimante de sortie » à la création d'un reçu</span>
                </header>
                <?php if (!$printers): ?>
                    <p class="empty">Aucune imprimante enregistrée : ajoutez la première avec le formulaire.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table table--stack">
                            <thead><tr><th>Imprimante</th><th>Papier</th><th>Réseau</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                            <tbody>
                            <?php foreach ($printers as $p): ?>
                                <?php $state = $p['last_status'] === 'online' ? ['success', 'En ligne'] : ($p['last_status'] === 'offline' ? ['danger', 'Hors ligne'] : ['muted', 'Non testée']); ?>
                                <tr class="<?= $p['is_active'] ? '' : 'is-disabled' ?>">
                                    <td data-label="Imprimante">
                                        <strong><?= e($p['printer_name']) ?></strong>
                                        <?php if ((int) $p['is_default'] === 1): ?><span class="badge badge--success">Par défaut</span><?php endif; ?>
                                        <?php if (!$p['is_active']): ?><span class="badge badge--muted">Désactivée</span><?php endif; ?>
                                        <?php if (printer_is_direct($p)): ?><span class="badge badge--info">Envoi direct</span><?php endif; ?>
                                        <small class="cell-sub"><?= e($p['printer_location']) ?></small>
                                    </td>
                                    <td data-label="Papier"><?= e(PRINTER_FORMATS[$p['paper_format']] ?? $p['paper_format']) ?></td>
                                    <td data-label="Réseau">
                                        <?php if ($p['host']): ?>
                                            <code><?= e($p['host']) ?>:<?= (int) $p['port'] ?></code>
                                            <span class="badge badge--<?= $state[0] ?>"><?= $state[1] ?></span>
                                        <?php else: ?>
                                            <small class="cell-sub">Adresse IP non renseignée</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions">
                                        <?= action_menu(array_filter([
                                            (int) $p['is_default'] !== 1 && $p['is_active'] ? ['post', 'Définir par défaut', 'check', ['op' => 'printer_default', 'id' => (int) $p['id']]] : [],
                                            $p['host'] ? ['post', 'Tester la connexion', 'wifi', ['op' => 'printer_test', 'id' => (int) $p['id']]] : [],
                                            ['link', 'Modifier', 'settings.php?edit=' . (int) $p['id'] . '#systeme', 'edit'],
                                            ['sep'],
                                            ['post', 'Supprimer', 'trash', ['op' => 'printer_delete', 'id' => (int) $p['id']], 'Supprimer l\'imprimante « ' . $p['printer_name'] . ' » ?', 'danger' => true],
                                        ]), 'Actions de l\'imprimante ' . $p['printer_name']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <p class="help-text"><?= icon('alert') ?> Le format de papier de l'imprimante choisie est appliqué automatiquement au reçu. Avec l'« envoi direct », le serveur transmet le reçu (exemplaire client + exemplaire caisse) au périphérique sans boîte de dialogue ; sinon, l'agent confirme l'imprimante dans la fenêtre d'impression du navigateur.</p>
            </section>

            <form method="post" action="settings.php<?= $prnForm['id'] ? '?edit=' . (int) $prnForm['id'] : '' ?>#systeme" class="panel form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="printer_save">
                <input type="hidden" name="id" value="<?= (int) $prnForm['id'] ?>">
                <h2 class="panel__title"><?= $prnForm['id'] ? "Modifier l'imprimante" : 'Ajouter une imprimante' ?></h2>
                <div class="field">
                    <label for="pr-name">Nom de l'imprimante *</label>
                    <input id="pr-name" name="printer_name" required maxlength="100" placeholder="Ex. Imprimante Guichet Caisse" value="<?= e($prnForm['printer_name']) ?>"<?= $inv('printer_name') ?>>
                    <?= $err('printer_name') ?>
                </div>
                <div class="field">
                    <label for="pr-location">Emplacement *</label>
                    <input id="pr-location" name="printer_location" required maxlength="100" placeholder="Ex. Guichet principal" value="<?= e($prnForm['printer_location']) ?>"<?= $inv('printer_location') ?>>
                    <?= $err('printer_location') ?>
                </div>
                <div class="field">
                    <label for="pr-format">Format de papier</label>
                    <select id="pr-format" name="paper_format"<?= $inv('paper_format') ?>>
                        <?php foreach (PRINTER_FORMATS as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $prnForm['paper_format'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field__hint">Mise en page appliquée aux reçus envoyés vers cette imprimante.</small>
                    <?= $err('paper_format') ?>
                </div>
                <div class="form-grid form-grid--2">
                    <div class="field">
                        <label for="pr-host">Adresse IP <small class="field__hint">(facultatif)</small></label>
                        <input id="pr-host" name="host" maxlength="190" placeholder="192.168.1.50" value="<?= e($prnForm['host']) ?>"<?= $inv('host') ?>>
                        <?= $err('host') ?>
                    </div>
                    <div class="field">
                        <label for="pr-port">Protocole</label>
                        <select id="pr-port" name="port"<?= $inv('port') ?>>
                            <?php foreach (PRINTER_PORTS as $port => $label): ?>
                                <option value="<?= $port ?>" <?= (int) $prnForm['port'] === $port ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $err('port') ?>
                    </div>
                </div>
                <div class="field">
                    <label class="check"><input type="checkbox" name="direct_print" <?= (is_post() ? isset($_POST['direct_print']) : (int) ($prnForm['direct_print'] ?? 0) === 1) ? 'checked' : '' ?>> Envoi direct à l'imprimante (sans boîte de dialogue)</label>
                    <small class="field__hint">Ticket : commandes ESC/POS par RAW 9100 · A4 / A5 : PDF par RAW 9100 ou IPP 631 (impression PDF directe). Le serveur doit pouvoir joindre l'imprimante.</small>
                    <?= $err('direct_print') ?>
                </div>
                <div class="checks">
                    <label class="check"><input type="checkbox" name="is_active" <?= $prnForm['is_active'] ? 'checked' : '' ?>> Imprimante active</label>
                    <?php if ((int) $prnForm['is_default'] === 1): ?>
                        <span class="badge badge--success">Imprimante par défaut</span>
                    <?php else: ?>
                        <label class="check"><input type="checkbox" name="is_default" <?= isset($_POST['is_default']) ? 'checked' : '' ?>> Définir par défaut</label>
                    <?php endif; ?>
                </div>
                <?= $err('is_active') ?>
                <div class="form-actions">
                    <button type="submit" class="btn btn--primary"><?= icon('check') ?> Enregistrer</button>
                    <?php if ($prnForm['id'] || query('add_host')): ?><a href="settings.php#systeme" class="btn btn--ghost">Annuler</a><?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Détection des imprimantes du réseau local -->
        <section class="panel printers-scan">
            <header class="panel__head">
                <h2 class="panel__title">Détection sur le réseau local</h2>
                <span class="cell-sub">Ports d'impression 9100 · 631 · 515 — réseau privé uniquement</span>
            </header>
            <form method="post" action="settings.php#systeme" class="printers-scan__form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="printer_scan">
                <div class="field">
                    <label for="scan-subnet">Sous-réseau de l'agence</label>
                    <input id="scan-subnet" name="printer_scan_subnet" maxlength="18" placeholder="<?= e($scanHint) ?>" value="<?= $val('printer_scan_subnet', $s['printer_scan_subnet']) ?>"<?= $inv('printer_scan_subnet') ?>>
                    <small class="field__hint">Laissez vide pour désactiver. Utilisé aussi par le bouton « Actualiser / Détecter » des reçus (résultat conservé 2 minutes).</small>
                    <?= $err('printer_scan_subnet') ?>
                </div>
                <button type="submit" class="btn btn--primary"><?= icon('refresh') ?> Détecter maintenant</button>
            </form>
            <?php if ($scan): ?>
                <?php if ($scan['devices']): ?>
                    <p class="printers-scan__result"><?= count($scan['devices']) ?> appareil<?= count($scan['devices']) > 1 ? 's' : '' ?> d'impression non enregistré<?= count($scan['devices']) > 1 ? 's' : '' ?> sur <?= e($scan['subnet']) ?> :</p>
                    <ul class="list printers-scan__list">
                        <?php foreach ($scan['devices'] as [$host, $port]): ?>
                            <li class="list__item">
                                <span class="dot dot--success"></span>
                                <span class="list__main"><strong><?= e($host) ?></strong><small><?= e(PRINTER_PORTS[$port] ?? (string) $port) ?></small></span>
                                <a href="settings.php?add_host=<?= e(rawurlencode($host)) ?>&amp;add_port=<?= (int) $port ?>#systeme" class="btn btn--ghost btn--sm"><?= icon('plus') ?> Ajouter</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="printers-scan__result">Aucun nouvel appareil d'impression trouvé sur <?= e($scan['subnet']) ?> (les imprimantes déjà enregistrées ne sont pas listées).</p>
                <?php endif; ?>
            <?php endif; ?>
            <p class="help-text"><?= icon('alert') ?> La détection part du serveur du dashboard : elle ne trouve les imprimantes que si ce serveur est installé dans l'agence (ou relié par VPN). Depuis un hébergement en ligne, le réseau de l'agence n'est pas visible.</p>
        </section>
    </section>
    <?php endif; ?>
</div>

<?php dash_footer(); ?>
