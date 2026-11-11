<?php
/**
 * Alerte par e-mail à chaque connexion au Dashboard (includes/auth.php).
 *
 * Destinataire : Paramètres → Système → « Alertes de connexion » (réglage login_alert_email, valeur par défaut dans
 * DEFAULT_SETTINGS, includes/db.php). Adresse vide : alertes désactivées.
 * Événements : connexion par mot de passe, reconnexion automatique (« Se souvenir de moi »), prise de contrôle d'un
 * compte par un administrateur.
 * Contenu : compte (nom, e-mail, rôle), méthode, date et heure (Douala), adresse IP, appareil et navigateur.
 * Envoi : includes/mailer.php (SMTP authentifié d'une boîte de l'hébergement, sinon mail()), APRÈS la réponse au navigateur — la connexion n'est
 * jamais ralentie, et un échec d'envoi ne bloque jamais l'accès (il est seulement écrit dans le journal PHP).
 */
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

/** Adresse qui reçoit les alertes ('' = désactivées). */
function login_alert_recipient(): string
{
    if (!function_exists('setting')) {
        require_once __DIR__ . '/settings.php';
    }
    $to = trim(setting('login_alert_email'));
    return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
}

/** Appareil et navigateur lisibles à partir de l'en-tête User-Agent. */
function login_alert_device(string $ua): string
{
    $os = match (true) {
        (bool) preg_match('/iPhone/i', $ua)             => 'iPhone',
        (bool) preg_match('/iPad/i', $ua)               => 'iPad',
        (bool) preg_match('/Android/i', $ua)            => preg_match('/Mobile/i', $ua) ? 'Téléphone Android' : 'Tablette Android',
        (bool) preg_match('/Windows NT/i', $ua)         => 'Ordinateur Windows',
        (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'Mac',
        (bool) preg_match('/CrOS/i', $ua)               => 'Chromebook',
        (bool) preg_match('/Linux/i', $ua)              => 'Ordinateur Linux',
        default                                          => 'Appareil inconnu',
    };
    $browser = match (true) {
        (bool) preg_match('/Edg\//', $ua)                  => 'Microsoft Edge',
        (bool) preg_match('/OPR\/|Opera/', $ua)            => 'Opera',
        (bool) preg_match('/SamsungBrowser/', $ua)         => 'Samsung Internet',
        (bool) preg_match('/Firefox\//', $ua)              => 'Firefox',
        (bool) preg_match('/Chrome\//', $ua)               => 'Google Chrome',
        (bool) preg_match('/Safari\//', $ua)               => 'Safari',
        default                                             => 'navigateur inconnu',
    };
    $app = preg_match('/standalone|wv\)/i', $ua) ? ' (application)' : '';
    return $os . ' · ' . $browser . $app;
}

/**
 * Prépare l'alerte d'une connexion réussie ; l'envoi a lieu à la fin de la requête.
 * $method : 'password' | 'remember' | 'impersonation' ; $by : administrateur à l'origine d'une prise de contrôle.
 */
function login_alert_notify(array $user, string $method, ?array $by = null): void
{
    $to = login_alert_recipient();
    if ($to === '') {
        return;
    }
    // Données lues maintenant (la requête est encore complète), message envoyé après la réponse
    $account = db_one('SELECT name, email, role FROM users WHERE id = ?', [(int) $user['id']]) ?: $user;
    $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    $when = new DateTimeImmutable('now', new DateTimeZone('Africa/Douala'));
    $data = [
        'to'     => $to,
        'name'   => (string) ($account['name'] ?? ''),
        'email'  => (string) ($account['email'] ?? ''),
        'role'   => function_exists('role_label') ? role_label($account['role'] ?? null) : (string) ($account['role'] ?? ''),
        'kind'   => in_array($method, ['remember', 'impersonation'], true) ? $method : 'password',
        'method' => match ($method) {
            'remember'      => 'Reconnexion automatique (« Se souvenir de moi »)',
            'impersonation' => 'Prise de contrôle du compte par ' . ($by['name'] ?? 'un administrateur'),
            default         => 'Connexion par mot de passe',
        },
        'date'   => $when->format('d/m/Y'),
        'time'   => $when->format('H:i'),
        'ip'     => client_ip(),
        'device' => login_alert_device($ua),
    ] + login_alert_site();
    register_shutdown_function(static function () use ($data): void {
        // Réponse d'abord envoyée au navigateur (PHP-FPM ou LiteSpeed/Hostinger) : l'envoi ne retarde pas la page,
        // et se poursuit même si le navigateur a déjà fermé la connexion
        ignore_user_abort(true);
        @set_time_limit(60);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        login_alert_send($data);
    });
}

/** Adresses du site pour l'e-mail : nom d'hôte, Dashboard (liens), logo (image publique). */
function login_alert_site(): array
{
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?: 'globaltradingcorporation.net';
    $base = defined('GTC_SITE_URL') && GTC_SITE_URL !== '' ? rtrim((string) GTC_SITE_URL, '/') : 'https://' . $host;
    return ['host' => preg_replace('/:\d+$/', '', $host), 'dash' => $base . '/dashboard/', 'logo' => $base . '/assets/img/logo-gtc-436.png'];
}

/** Initiales du compte (pastille de l'e-mail). */
function login_alert_initials(string $name): string
{
    $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));
    $ini = mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '');
    return mb_strtoupper($ini);
}

/**
 * Construit et envoie l'e-mail : version HTML soignée (charte GTC, compatible Gmail, Outlook, téléphones : tableaux
 * et styles en ligne) + version texte. Retourne true si mail() a accepté le message.
 */
function login_alert_send(array $d): bool
{
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    [$os, $browser] = array_pad(explode(' · ', $d['device'], 2), 2, '');
    $badge = [
        'password'      => ['#E6EEF8', '#003F82', 'Mot de passe'],
        'remember'      => ['#E3F4E9', '#0B6B33', 'Reconnexion automatique'],
        'impersonation' => ['#FEF3E2', '#8A5A00', 'Prise de contrôle'],
    ][$d['kind'] ?? 'password'] ?? ['#E6EEF8', '#003F82', 'Connexion'];
    $subject = 'Connexion au Dashboard GTC : ' . $d['name'] . ' — ' . $d['date'] . ' à ' . $d['time'];
    $preheader = $d['name'] . ' (' . $d['role'] . ') s\'est connecté le ' . $d['date'] . ' à ' . $d['time'] . ' depuis ' . $os . ' — IP ' . $d['ip'];
    $font = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";

    // ---------- Version texte ----------
    $rows = [
        'Compte'        => $d['name'] . ($d['email'] !== '' ? ' — ' . $d['email'] : ''),
        'Rôle'          => $d['role'],
        'Méthode'       => $d['method'],
        'Date et heure' => $d['date'] . ' à ' . $d['time'] . ' (heure de Douala)',
        'Adresse IP'    => $d['ip'],
        'Appareil'      => $os,
        'Navigateur'    => $browser,
    ];
    $text = "GLOBAL TRADING CORPORATION — Alerte de sécurité\nNouvelle connexion au Dashboard\n" . str_repeat('-', 56) . "\n";
    foreach ($rows as $k => $v) {
        $text .= str_pad($k, 15) . ': ' . $v . "\n";
    }
    $text .= "\nVous ne reconnaissez pas cette connexion ?\n"
        . "1. Réinitialisez le mot de passe du compte : " . $d['dash'] . "users.php\n"
        . "2. Désactivez le compte si nécessaire, puis consultez le journal d'activité : " . $d['dash'] . "activity.php\n\n"
        . "Alerte automatique de " . $d['host'] . ". Pour changer l'adresse ou désactiver ces alertes : Dashboard → Paramètres → Système.\n";

    // ---------- Version HTML ----------
    $detail = static function (string $label, string $value, bool $mono = false) use ($e, $font): string {
        return '<tr><td style="' . $font . 'padding:11px 0;border-bottom:1px solid #EDF0F4;font-size:13px;color:#6B7689;width:42%;vertical-align:top">' . $e($label) . '</td>'
            . '<td style="' . $font . 'padding:11px 0;border-bottom:1px solid #EDF0F4;font-size:14px;color:#1F2630;font-weight:600;text-align:right;'
            . ($mono ? "font-family:Consolas,'Courier New',monospace;" : '') . '">' . $e($value) . '</td></tr>';
    };
    $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . $e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#EEF1F5;-webkit-text-size-adjust:100%">'
        // Texte d'aperçu (boîte de réception), invisible dans le message
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#EEF1F5">' . $e($preheader) . str_repeat('&#8199;&#65279;', 40) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#EEF1F5"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background:#FFFFFF;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(15,23,42,.08)">'
        // Bandeau aux couleurs de la charte (bleu, vert, magenta)
        . '<tr><td style="padding:0;line-height:0;font-size:0"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="height:5px;background:#0051A5;width:50%"></td><td style="height:5px;background:#00963F;width:30%"></td><td style="height:5px;background:#9C006D;width:20%"></td></tr></table></td></tr>'
        // En-tête : logo + mention
        . '<tr><td style="padding:22px 28px 6px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="vertical-align:middle"><img src="' . $e($d['logo']) . '" width="168" alt="Global Trading Corporation" style="display:block;width:168px;max-width:60%;height:auto;border:0"></td>'
        . '<td align="right" style="vertical-align:middle"><span style="' . $font . 'display:inline-block;padding:5px 11px;border-radius:999px;background:#FDECEC;color:#B42318;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap">Alerte de sécurité</span></td>'
        . '</tr></table></td></tr>'
        // Titre
        . '<tr><td style="' . $font . 'padding:16px 28px 4px"><h1 style="margin:0;font-size:22px;line-height:1.3;color:#1F2630;font-weight:700">Nouvelle connexion au Dashboard</h1>'
        . '<p style="margin:6px 0 0;font-size:14px;color:#6B7689">Le ' . $e($d['date']) . ' à <strong style="color:#1F2630">' . $e($d['time']) . '</strong> · heure de Douala</p></td></tr>'
        // Carte du compte : initiales, nom, e-mail, rôle, méthode
        . '<tr><td style="padding:18px 28px 6px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F7F9FC;border:1px solid #E5EAF1;border-radius:12px"><tr>'
        . '<td width="64" style="padding:16px 0 16px 16px;vertical-align:middle"><div style="' . $font . 'width:48px;height:48px;line-height:48px;border-radius:50%;background:#0051A5;color:#FFFFFF;font-size:17px;font-weight:700;text-align:center">' . $e(login_alert_initials($d['name'])) . '</div></td>'
        . '<td style="' . $font . 'padding:16px 16px 16px 12px;vertical-align:middle">'
        . '<div style="font-size:16px;font-weight:700;color:#1F2630">' . $e($d['name']) . '</div>'
        . ($d['email'] !== '' ? '<div style="font-size:13px;color:#6B7689;margin-top:2px">' . $e($d['email']) . '</div>' : '')
        . '<div style="margin-top:8px"><span style="display:inline-block;padding:3px 9px;border-radius:999px;background:#FFFFFF;border:1px solid #D7DEE8;color:#3D4756;font-size:12px;font-weight:600;margin-right:6px">' . $e($d['role']) . '</span>'
        . '<span style="display:inline-block;padding:3px 9px;border-radius:999px;background:' . $badge[0] . ';color:' . $badge[1] . ';font-size:12px;font-weight:700">' . $e($badge[2]) . '</span></div>'
        . '</td></tr></table></td></tr>'
        // Détails de la connexion
        . '<tr><td style="padding:12px 28px 4px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">'
        . $detail('Méthode', $d['method'])
        . $detail('Date et heure', $d['date'] . ' à ' . $d['time'])
        . $detail('Adresse IP', $d['ip'], true)
        . $detail('Appareil', $os)
        . $detail('Navigateur', $browser)
        . '</table></td></tr>'
        // Que faire si la connexion est inconnue
        . '<tr><td style="padding:20px 28px 6px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#FFF8EB;border:1px solid #F5DFB3;border-radius:12px"><tr>'
        . '<td style="' . $font . 'padding:16px 18px;font-size:13.5px;line-height:1.55;color:#5C4400">'
        . '<strong style="display:block;font-size:14px;color:#3D2E00;margin-bottom:4px">Vous ne reconnaissez pas cette connexion ?</strong>'
        . 'Réinitialisez immédiatement le mot de passe du compte, désactivez-le si nécessaire, puis vérifiez les actions effectuées dans le journal d\'activité.</td></tr></table></td></tr>'
        // Boutons
        . '<tr><td style="padding:18px 28px 26px"><table role="presentation" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="border-radius:9px;background:#0051A5"><a href="' . $e($d['dash'] . 'activity.php') . '" style="' . $font . 'display:inline-block;padding:12px 20px;color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;border-radius:9px">Voir le journal d\'activité</a></td>'
        . '<td width="10"></td>'
        . '<td style="border-radius:9px;border:1px solid #CBD3DD"><a href="' . $e($d['dash'] . 'users.php') . '" style="' . $font . 'display:inline-block;padding:11px 18px;color:#1F2630;font-size:14px;font-weight:600;text-decoration:none;border-radius:9px">Gérer les utilisateurs</a></td>'
        . '</tr></table></td></tr>'
        // Pied
        . '<tr><td style="' . $font . 'padding:18px 28px;background:#F7F9FC;border-top:1px solid #E5EAF1;font-size:12px;line-height:1.6;color:#8A94A6">'
        . '<strong style="color:#3D4756">Global Trading Corporation</strong>' . (defined('GTC_ADDRESS') ? ' · ' . $e((string) GTC_ADDRESS) : '') . (defined('GTC_RCCM') ? ' · RCCM ' . $e((string) GTC_RCCM) : '') . '<br>'
        . 'Alerte automatique envoyée par ' . $e($d['host']) . ' à chaque connexion au Dashboard. Pour changer l\'adresse ou désactiver ces alertes : Dashboard → Paramètres → Système.'
        . '</td></tr></table></td></tr></table></body></html>';

    $boundary = 'gtc-' . bin2hex(random_bytes(12));
    $headers = [
        'From'         => mb_encode_mimeheader('Dashboard GTC', 'UTF-8') . ' <' . mailer_from_address($d['host']) . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer'     => 'PHP/' . PHP_VERSION,
        'X-Priority'   => '1',
    ];
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n--{$boundary}--\r\n";

    // Mode test (GTC_MAIL_LOG_DIR dans db.local.php) : message écrit dans un fichier au lieu d'être envoyé
    if (defined('GTC_MAIL_LOG_DIR') && is_dir((string) GTC_MAIL_LOG_DIR)) {
        $file = rtrim((string) GTC_MAIL_LOG_DIR, '/\\') . '/login-alert-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        file_put_contents($file . '.eml', 'To: ' . $d['to'] . "\r\nSubject: " . $subject . "\r\n"
            . implode("\r\n", array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers)) . "\r\n\r\n" . $body);
        file_put_contents($file . '.html', $html);   // aperçu du rendu
        return true;
    }
    // SMTP authentifié si configuré (Paramètres → Système → Envoi des e-mails), sinon mail() — includes/mailer.php
    return mailer_send($d['to'], $subject, $body, $headers)['ok'];
}
