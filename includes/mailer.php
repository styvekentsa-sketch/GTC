<?php
/**
 * Envoi des e-mails du Dashboard (alertes de connexion…).
 *
 * Deux modes :
 *  1. SMTP authentifié (recommandé) : une vraie boîte mail de l'hébergement (ex. no-reply@globaltradingcorporation.net
 *     créée dans hPanel → E-mails). Réglages : Paramètres → Système → « Envoi des e-mails », ou constantes
 *     GTC_SMTP_HOST / GTC_SMTP_PORT / GTC_SMTP_USER / GTC_SMTP_PASS dans includes/db.local.php (prioritaires).
 *     Message signé par l'hébergeur (SPF/DKIM) : il arrive en boîte de réception au lieu d'être rejeté.
 *  2. À défaut : fonction mail() de PHP. Chez Hostinger, elle n'est fiable que si l'expéditeur est une boîte
 *     existante du domaine ; sinon le message est souvent écarté sans erreur.
 *
 * Chaque envoi est consigné (paramètre mail_last_status) et visible dans Paramètres → Système.
 */

require_once __DIR__ . '/settings.php';

/** Résultat du dernier envoi de cette requête (null si aucun). */
function mailer_last(): ?array
{
    return $GLOBALS['gtc_mailer_last'] ?? null;
}

/** Configuration SMTP active (constantes de db.local.php, sinon Paramètres), ou null si aucune. */
function mailer_smtp_config(): ?array
{
    $get = static fn (string $const, string $key, string $def = ''): string
        => defined($const) && (string) constant($const) !== '' ? (string) constant($const) : setting($key, $def);
    $cfg = [
        'host' => trim($get('GTC_SMTP_HOST', 'smtp_host', 'smtp.hostinger.com')),
        'port' => (int) $get('GTC_SMTP_PORT', 'smtp_port', '465'),
        'user' => trim($get('GTC_SMTP_USER', 'smtp_user')),
        'pass' => $get('GTC_SMTP_PASS', 'smtp_pass'),
    ];
    if (preg_match('/@(gmail|googlemail)\.com$/i', $cfg['user']) && stripos($cfg['host'], 'hostinger') !== false) {
        $cfg['host'] = 'smtp.gmail.com';   // une boîte Gmail ne s'authentifie que chez Google
    }
    return $cfg['user'] !== '' && $cfg['pass'] !== '' && $cfg['host'] !== '' ? $cfg : null;
}

/** Adresse d'expédition : la boîte SMTP si configurée, sinon no-reply@domaine. */
function mailer_from_address(string $host): string
{
    $cfg = mailer_smtp_config();
    if ($cfg && filter_var($cfg['user'], FILTER_VALIDATE_EMAIL)) {
        return $cfg['user'];
    }
    return 'no-reply@' . preg_replace('/^www\./i', '', $host);
}

/**
 * Envoie un message déjà construit (corps MIME + en-têtes, sans To ni Subject).
 * Retourne ['ok' => bool, 'via' => 'smtp'|'mail', 'error' => string].
 */
function mailer_send(string $to, string $subject, string $body, array $headers): array
{
    $cfg = mailer_smtp_config();
    $res = $cfg ? mailer_smtp($cfg, $to, $subject, $body, $headers) : mailer_php_mail($to, $subject, $body, $headers);
    if ($cfg && !$res['ok'] && preg_match('/^(connexion|accueil)/', $res['error'])) {
        sleep(2);   // serveur momentanément indisponible ou accueil retardé : une seconde tentative
        $res = mailer_smtp($cfg, $to, $subject, $body, $headers);
    }
    $GLOBALS['gtc_mailer_last'] = $res;
    if (!$res['ok']) {
        error_log('[GTC mail] envoi impossible à ' . $to . ' (' . $res['via'] . ') : ' . $res['error']);
    }
    try {
        settings_save(['mail_last_status' => json_encode([
            'at' => (new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->format('d/m/Y H:i'),
            'to' => $to, 'ok' => $res['ok'], 'via' => $res['via'], 'error' => mb_substr($res['error'], 0, 300),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        // journal facultatif : n'empêche jamais l'envoi
    }
    return $res;
}

/**
 * Message complet texte + HTML (multipart/alternative), expédié par « Dashboard GTC ».
 * Mode test (GTC_MAIL_LOG_DIR dans db.local.php) : écrit dans un fichier (.eml + aperçu .html) au lieu d'être envoyé.
 */
function mailer_send_html(string $to, string $subject, string $text, string $html, string $tag = 'mail', string $host = ''): bool
{
    $host = $host !== '' ? $host : (preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?: 'globaltradingcorporation.net');
    $boundary = 'gtc-' . bin2hex(random_bytes(12));
    $headers = [
        'From'         => mb_encode_mimeheader('Dashboard GTC', 'UTF-8') . ' <' . mailer_from_address($host) . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer'     => 'PHP/' . PHP_VERSION,
    ];
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n--{$boundary}--\r\n";
    if (defined('GTC_MAIL_LOG_DIR') && is_dir((string) GTC_MAIL_LOG_DIR)) {
        $file = rtrim((string) GTC_MAIL_LOG_DIR, '/\\') . '/' . $tag . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        file_put_contents($file . '.eml', 'To: ' . $to . "\r\nSubject: " . $subject . "\r\n"
            . implode("\r\n", array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers)) . "\r\n\r\n" . $body);
        file_put_contents($file . '.html', $html);
        return true;
    }
    return mailer_send($to, $subject, $body, $headers)['ok'];
}

function mailer_php_mail(string $to, string $subject, string $body, array $headers): array
{
    $from = '';
    if (preg_match('/<([^>]+)>/', (string) ($headers['From'] ?? ''), $m)) {
        $from = $m[1];
    }
    // -f : expéditeur d'enveloppe (Return-Path) aligné sur le domaine, exigé par de nombreux serveurs
    $params = $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) ? '-f' . $from : '';
    $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers, $params);
    $err = $ok ? '' : ((error_get_last()['message'] ?? '') ?: 'la fonction mail() du serveur a refusé le message');
    return ['ok' => $ok, 'via' => 'mail', 'error' => $err];
}

/** Client SMTP minimal : SSL direct (465) ou STARTTLS (587), AUTH LOGIN. */
function mailer_smtp(array $cfg, string $to, string $subject, string $body, array $headers): array
{
    $fail = static fn (string $msg): array => ['ok' => false, 'via' => 'smtp', 'error' => $msg];
    $ssl = $cfg['port'] === 465;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return $fail('connexion à ' . $cfg['host'] . ':' . $cfg['port'] . ' impossible (' . trim($errstr) . ')');
    }
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int) substr($data, 0, 3), trim($data)];
    };
    $cmd = static function (string $c, array $expect) use ($fp, $read): ?string {
        fwrite($fp, $c . "\r\n");
        [$code, $msg] = $read();
        return in_array($code, $expect, true) ? null : ($msg !== '' ? $msg : 'pas de réponse du serveur');
    };
    $helo = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    try {
        [$code, $msg] = $read();
        if ($code !== 220) {
            return $fail('accueil du serveur : ' . $msg);
        }
        if ($e = $cmd('EHLO ' . $helo, [250])) {
            return $fail('EHLO : ' . $e);
        }
        if (!$ssl) {
            if ($e = $cmd('STARTTLS', [220])) {
                return $fail('STARTTLS : ' . $e);
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return $fail('chiffrement TLS impossible');
            }
            if ($e = $cmd('EHLO ' . $helo, [250])) {
                return $fail('EHLO : ' . $e);
            }
        }
        if (($e = $cmd('AUTH LOGIN', [334])) || ($e = $cmd(base64_encode($cfg['user']), [334])) || ($e = $cmd(base64_encode($cfg['pass']), [235]))) {
            return $fail('identifiants refusés (adresse ou mot de passe de la boîte mail) : ' . $e);
        }
        $from = filter_var($cfg['user'], FILTER_VALIDATE_EMAIL) ? $cfg['user'] : mailer_from_address($helo);
        if ($e = $cmd('MAIL FROM:<' . $from . '>', [250])) {
            return $fail('expéditeur refusé : ' . $e);
        }
        if ($e = $cmd('RCPT TO:<' . $to . '>', [250, 251])) {
            return $fail('destinataire refusé : ' . $e);
        }
        if ($e = $cmd('DATA', [354])) {
            return $fail('DATA : ' . $e);
        }
        $head = ['Date' => date('r'), 'To' => $to, 'Subject' => mb_encode_mimeheader($subject, 'UTF-8'),
                 'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . '>'] + $headers;
        $raw = implode("\r\n", array_map(static fn ($k, $v) => "$k: $v", array_keys($head), $head)) . "\r\n\r\n"
            . preg_replace('/\r?\n/', "\r\n", $body);
        $raw = preg_replace('/^\./m', '..', $raw);   // « dot-stuffing » : une ligne commençant par « . » est doublée
        if ($e = $cmd($raw . "\r\n.", [250])) {
            return $fail('message refusé : ' . $e);
        }
        $cmd('QUIT', [221]);
        return ['ok' => true, 'via' => 'smtp', 'error' => ''];
    } finally {
        fclose($fp);
    }
}
