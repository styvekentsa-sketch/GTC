<?php
/**
 * Rapport d'activité par e-mail : chaque opération du Dashboard et de GTC-Vault est envoyée au propriétaire.
 *
 * Alimenté par activity_log() (includes/activity.php) et vault_audit() (includes/vault.php).
 * Destinataire : Paramètres → Système → « Alertes par e-mail » (login_alert_email) ; case « opérations » (activity_mail).
 * Toutes les opérations d'une même action (une requête) sont regroupées dans UN e-mail, envoyé APRÈS la réponse
 * au navigateur : la page n'est jamais ralentie et un échec d'envoi ne bloque jamais l'opération.
 * Non envoyés (bruit) : connexions (déjà signalées par includes/login_alert.php), affichage des images de pièces,
 * ouverture de la page de capture sur téléphone.
 */
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/login_alert.php';   // login_alert_device() : appareil et navigateur lisibles

/** Actions GTC-Vault => [libellé, ton]. */
const ACTIVITY_MAIL_VAULT = [
    'client_create'       => ['Création du dossier KYC', 'success'],
    'client_view'         => ['Consultation du dossier KYC', 'muted'],
    'client_print'        => ['Impression du dossier KYC', 'warning'],
    'client_archive'      => ['Archivage du dossier KYC', 'warning'],
    'client_restore'      => ['Restauration du dossier KYC', 'success'],
    'client_delete'       => ['Suppression du dossier KYC', 'danger'],
    'client_verify'       => ['Validation du dossier (conformité)', 'success'],
    'document_add'        => ['Ajout d\'une pièce', 'success'],
    'avatar_set'          => ['Photo du client enregistrée', 'info'],
    'avatar_delete'       => ['Photo du client supprimée', 'danger'],
    'capture_create'      => ['Capture par téléphone lancée', 'info'],
    'capture_upload'      => ['Photo reçue du téléphone', 'info'],
    'access_denied'       => ['Accès refusé à GTC-Vault', 'danger'],
    'client_not_found'    => ['Dossier introuvable', 'warning'],
    'viewer_invalid_link' => ['Lien de consultation invalide', 'warning'],
];
const ACTIVITY_MAIL_SKIP = ['dashboard:login', 'vault:document_view', 'vault:capture_open', 'vault:capture_consumed'];

/** Couleurs des badges (fond, texte). */
const ACTIVITY_MAIL_TONES = [
    'success' => ['#E3F4E9', '#0B6B33'],
    'info'    => ['#E6EEF8', '#003F82'],
    'danger'  => ['#FDECEC', '#B42318'],
    'warning' => ['#FEF3E2', '#8A5A00'],
    'muted'   => ['#EEF1F5', '#3D4756'],
];

/** Adresse qui reçoit les opérations ('' = désactivé). */
function activity_mail_recipient(): string
{
    $to = trim(setting('login_alert_email'));
    return setting('activity_mail', '1') !== '0' && filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
}

/**
 * Ajoute une opération au rapport de la requête en cours.
 * $op : src (dashboard|vault), code, action, tone, entity, label, details, user, role, admin, link.
 */
function activity_mail_queue(array $op): void
{
    static $registered = false;
    if (in_array($op['src'] . ':' . $op['code'], ACTIVITY_MAIL_SKIP, true) || activity_mail_recipient() === '') {
        return;
    }
    $op['time'] = (new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->format('H:i:s');
    $GLOBALS['gtc_activity_mail'][] = $op;
    if ($registered) {
        return;
    }
    $registered = true;
    $ctx = [
        'to'     => activity_mail_recipient(),
        'ip'     => function_exists('client_ip') ? client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'page'   => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
        'host'   => preg_replace('/:\d+$/', '', preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?: 'globaltradingcorporation.net'),
        'device' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
    ];
    register_shutdown_function(static function () use ($ctx): void {
        $ops = $GLOBALS['gtc_activity_mail'] ?? [];
        if (!$ops) {
            return;
        }
        ignore_user_abort(true);
        @set_time_limit(60);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        try {
            activity_mail_send($ctx, $ops);
        } catch (Throwable $e) {
            error_log('[GTC rapport d\'activité] ' . $e->getMessage());
        }
    });
}

/** Construit et envoie l'e-mail des opérations de la requête. */
function activity_mail_send(array $ctx, array $ops): bool
{
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
    $base = defined('GTC_SITE_URL') && GTC_SITE_URL !== '' ? rtrim((string) GTC_SITE_URL, '/') : 'https://' . $ctx['host'];
    $dash = $base . '/dashboard/';
    $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Douala'));
    $first = $ops[0];
    $who = $first['user'] !== '' ? $first['user'] : 'Visiteur non connecté';
    $count = count($ops);
    $device = function_exists('login_alert_device') ? login_alert_device($ctx['device']) : '';

    $headline = $count === 1 ? $first['action'] . ($first['entity'] !== '' ? ' — ' . $first['entity'] : '') : $count . ' opérations';
    $subject = 'GTC · ' . $headline . ($first['label'] !== '' && $count === 1 ? ' ' . $first['label'] : '') . ' — ' . $who;
    $subject = mb_strlen($subject) > 150 ? mb_substr($subject, 0, 147) . '…' : $subject;
    $preheader = $who . ($first['role'] !== '' ? ' (' . $first['role'] . ')' : '') . ' · ' . $now->format('d/m/Y') . ' à ' . $first['time']
        . ' · ' . ($count === 1 ? $first['action'] : $count . ' opérations');

    // ---------- Version texte ----------
    $text = "GLOBAL TRADING CORPORATION — Journal d'activité\n" . ($count === 1 ? 'Nouvelle opération' : $count . ' nouvelles opérations') . " sur le Dashboard\n"
        . str_repeat('-', 56) . "\n"
        . 'Par            : ' . $who . ($first['role'] !== '' ? ' (' . $first['role'] . ')' : '') . "\n"
        . ($first['admin'] !== '' ? 'Prise de contrôle par : ' . $first['admin'] . "\n" : '')
        . 'Date           : ' . $now->format('d/m/Y') . "\n"
        . 'Adresse IP     : ' . $ctx['ip'] . "\n" . ($device !== '' ? 'Appareil       : ' . $device . "\n" : '') . "\n";
    foreach ($ops as $op) {
        $text .= $op['time'] . '  ' . $op['action'] . ($op['entity'] !== '' ? ' — ' . $op['entity'] : '') . ($op['label'] !== '' ? ' : ' . $op['label'] : '') . "\n"
            . ($op['details'] !== '' ? '          ' . $op['details'] . "\n" : '');
    }
    $text .= "\nJournal complet : " . $dash . "activity.php\n\nRapport automatique de " . $ctx['host']
        . ". Pour le désactiver : Dashboard → Paramètres → Système → Alertes par e-mail.\n";

    // ---------- Version HTML ----------
    $rows = '';
    foreach ($ops as $i => $op) {
        [$bg, $fg] = ACTIVITY_MAIL_TONES[$op['tone']] ?? ACTIVITY_MAIL_TONES['info'];
        $title = ($op['entity'] !== '' ? $op['entity'] : '') . ($op['label'] !== '' ? ($op['entity'] !== '' ? ' · ' : '') . $op['label'] : '');
        $rows .= '<tr><td style="' . $font . 'padding:14px 0;' . ($i ? 'border-top:1px solid #EDF0F4;' : '') . 'vertical-align:top">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="vertical-align:top"><span style="display:inline-block;padding:3px 9px;border-radius:999px;background:' . $bg . ';color:' . $fg . ';font-size:12px;font-weight:700;white-space:nowrap">' . $e($op['action']) . '</span></td>'
            . '<td align="right" style="vertical-align:top;font-size:12px;color:#8A94A6;white-space:nowrap;font-family:Consolas,\'Courier New\',monospace">' . $e($op['time']) . '</td></tr></table>'
            . ($title !== '' ? '<div style="margin-top:7px;font-size:15px;font-weight:700;color:#1F2630;word-break:break-word">'
                . ($op['link'] !== '' ? '<a href="' . $e($dash . $op['link']) . '" style="color:#0051A5;text-decoration:none">' . $e($title) . '</a>' : $e($title)) . '</div>' : '')
            . ($op['details'] !== '' ? '<div style="margin-top:4px;font-size:13px;line-height:1.55;color:#5B6576;word-break:break-word">' . $e($op['details']) . '</div>' : '')
            . '</td></tr>';
    }
    $detail = static fn (string $label, string $value, bool $mono = false): string
        => '<tr><td style="' . $font . 'padding:9px 0;border-bottom:1px solid #EDF0F4;font-size:13px;color:#6B7689;width:38%">' . $e($label) . '</td>'
        . '<td style="' . $font . 'padding:9px 0;border-bottom:1px solid #EDF0F4;font-size:13.5px;color:#1F2630;font-weight:600;text-align:right;'
        . ($mono ? "font-family:Consolas,'Courier New',monospace;" : '') . '">' . $e($value) . '</td></tr>';
    $initials = mb_strtoupper(implode('', array_map(static fn ($w) => mb_substr($w, 0, 1), array_slice(array_values(array_filter(preg_split('/\s+/u', $who) ?: [])), 0, 2)))) ?: '?';

    $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . $e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#EEF1F5;-webkit-text-size-adjust:100%">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#EEF1F5">' . $e($preheader) . str_repeat('&#8199;&#65279;', 40) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#EEF1F5"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(15,23,42,.08)">'
        . '<tr><td style="padding:0;line-height:0;font-size:0"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="height:5px;background:#0051A5;width:50%"></td><td style="height:5px;background:#00963F;width:30%"></td><td style="height:5px;background:#9C006D;width:20%"></td></tr></table></td></tr>'
        . '<tr><td style="padding:22px 28px 6px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="vertical-align:middle"><img src="' . $e($base . '/assets/img/logo-gtc-436.png') . '" width="168" alt="Global Trading Corporation" style="display:block;width:168px;max-width:60%;height:auto;border:0"></td>'
        . '<td align="right" style="vertical-align:middle"><span style="' . $font . 'display:inline-block;padding:5px 11px;border-radius:999px;background:#E6EEF8;color:#003F82;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap">Journal d\'activité</span></td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="' . $font . 'padding:16px 28px 4px"><h1 style="margin:0;font-size:22px;line-height:1.3;color:#1F2630;font-weight:700">'
        . ($count === 1 ? 'Nouvelle opération sur le Dashboard' : $count . ' nouvelles opérations sur le Dashboard') . '</h1>'
        . '<p style="margin:6px 0 0;font-size:14px;color:#6B7689">Le ' . $e($now->format('d/m/Y')) . ' à <strong style="color:#1F2630">' . $e(substr($first['time'], 0, 5)) . '</strong> · heure de Douala</p></td></tr>'
        // Auteur
        . '<tr><td style="padding:18px 28px 4px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F7F9FC;border:1px solid #E5EAF1;border-radius:12px"><tr>'
        . '<td width="60" style="padding:14px 0 14px 14px;vertical-align:middle"><div style="' . $font . 'width:44px;height:44px;line-height:44px;border-radius:50%;background:#0051A5;color:#FFFFFF;font-size:16px;font-weight:700;text-align:center">' . $e($initials) . '</div></td>'
        . '<td style="' . $font . 'padding:14px 14px 14px 12px;vertical-align:middle"><div style="font-size:15px;font-weight:700;color:#1F2630">' . $e($who) . '</div>'
        . ($first['role'] !== '' ? '<div style="font-size:13px;color:#6B7689;margin-top:2px">' . $e($first['role']) . '</div>' : '')
        . ($first['admin'] !== '' ? '<div style="margin-top:6px"><span style="display:inline-block;padding:3px 9px;border-radius:999px;background:#FEF3E2;color:#8A5A00;font-size:12px;font-weight:700">Prise de contrôle par ' . $e($first['admin']) . '</span></div>' : '')
        . '</td></tr></table></td></tr>'
        // Opérations
        . '<tr><td style="padding:10px 28px 4px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">' . $rows . '</table></td></tr>'
        // Contexte technique
        . '<tr><td style="padding:6px 28px 4px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #EDF0F4">'
        . $detail('Adresse IP', $ctx['ip'], true) . ($device !== '' ? $detail('Appareil', $device) : '') . $detail('Page', $ctx['page'] !== '' ? $ctx['page'] : '/', true)
        . '</table></td></tr>'
        . '<tr><td style="padding:20px 28px 26px"><table role="presentation" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="border-radius:9px;background:#0051A5"><a href="' . $e($dash . 'activity.php') . '" style="' . $font . 'display:inline-block;padding:12px 20px;color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;border-radius:9px">Voir le journal d\'activité</a></td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="' . $font . 'padding:18px 28px;background:#F7F9FC;border-top:1px solid #E5EAF1;font-size:12px;line-height:1.6;color:#8A94A6">'
        . '<strong style="color:#3D4756">Global Trading Corporation</strong>' . (defined('GTC_ADDRESS') ? ' · ' . $e((string) GTC_ADDRESS) : '') . (defined('GTC_RCCM') ? ' · RCCM ' . $e((string) GTC_RCCM) : '') . '<br>'
        . 'Rapport automatique envoyé par ' . $e($ctx['host']) . ' à chaque opération du Dashboard. Pour le désactiver : Dashboard → Paramètres → Système → Alertes par e-mail.'
        . '</td></tr></table></td></tr></table></body></html>';

    return mailer_send_html($ctx['to'], $subject, $text, $html, 'activity', $ctx['host']);
}
