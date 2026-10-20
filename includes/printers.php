<?php
/**
 * Imprimantes réseau de l'agence (table printers).
 *
 * Gérées par l'administrateur dans Paramètres → Système & sauvegardes ;
 * choisies à la création d'une facture (« Imprimante de sortie ») puis affichées sur print-invoice.php.
 *
 * Détection (dashboard/printers-api.php) :
 *   - état en temps réel des imprimantes enregistrées : connexion TCP vers leur adresse IP ;
 *   - découverte des appareils du réseau local : balayage d'un sous-réseau PRIVÉ choisi par
 *     l'administrateur (ex. 192.168.1.0/24), ports d'impression uniquement (9100, 631, 515).
 * Ces vérifications partent du SERVEUR : elles n'aboutissent que si le serveur du dashboard est
 * sur le réseau de l'agence (serveur au bureau ou VPN). Un navigateur ne peut pas, par sécurité,
 * lister les imprimantes installées sur l'ordinateur de l'agent.
 */
require_once __DIR__ . '/db.php';

/** Format de papier associé à chaque imprimante (appliqué automatiquement à l'impression). */
const PRINTER_FORMATS = ['a4' => 'Feuille A4', 'a5' => 'Feuille A5', 'ticket' => 'Ticket thermique 80 mm'];

/** Ports autorisés pour le test de connexion. */
const PRINTER_PORTS = [9100 => 'RAW / JetDirect (9100)', 631 => 'IPP (631)', 515 => 'LPD (515)'];

/** Ports permettant l'envoi direct d'un document (includes/print-direct.php). */
const PRINTER_DIRECT_PORTS = [9100, 631];

/** Ports interrogés lors de la découverte du réseau local. */
const PRINTER_SCAN_PORTS = [9100, 631, 515];

/** Résultat de la découverte réseau conservé 2 minutes (évite de balayer le réseau à chaque clic). */
const PRINTER_SCAN_CACHE = __DIR__ . '/../data/printer-scan.json';
const PRINTER_SCAN_TTL   = 120;

/** Toutes les imprimantes (ou seulement les actives) : celle par défaut en premier. */
function printers_list(bool $activeOnly = false): array
{
    return db_all('SELECT * FROM printers' . ($activeOnly ? ' WHERE is_active = 1' : '')
        . ' ORDER BY is_default DESC, is_active DESC, printer_name');
}

/** Une imprimante par son identifiant (active uniquement par défaut). */
function printer_find(int $id, bool $activeOnly = true): ?array
{
    if ($id <= 0) {
        return null;
    }
    return db_one('SELECT * FROM printers WHERE id = ?' . ($activeOnly ? ' AND is_active = 1' : ''), [$id]);
}

/** Imprimante par défaut de l'agence (sinon la première imprimante active). */
function printer_default(): ?array
{
    return db_one('SELECT * FROM printers WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
}

/** Définit l'imprimante par défaut (une seule à la fois). */
function printer_set_default(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_query('UPDATE printers SET is_default = 0 WHERE is_default <> 0');
        db_query('UPDATE printers SET is_default = 1 WHERE id = ?', [$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** « Imprimante Guichet Caisse — Guichet principal » */
function printer_label(array $printer): string
{
    return $printer['printer_name'] . ($printer['printer_location'] !== '' ? ' — ' . $printer['printer_location'] : '');
}

/** IPv4, IPv6 ou nom d'hôte valide. */
function printer_valid_host(string $host): bool
{
    return filter_var($host, FILTER_VALIDATE_IP) !== false
        || (bool) preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host);
}

/** L'imprimante peut-elle recevoir directement le document (adresse IP + RAW ou IPP + option activée) ? */
function printer_is_direct(?array $printer): bool
{
    return $printer
        && (int) ($printer['direct_print'] ?? 0) === 1
        && (string) $printer['host'] !== ''
        && in_array((int) $printer['port'], PRINTER_DIRECT_PORTS, true);
}

/* ==========================================================================
   Connexions TCP en parallèle (état des imprimantes, découverte)
   ========================================================================== */

/**
 * Teste en parallèle une liste de cibles [clé => [hôte, port]].
 * Retourne [clé => true|false] (true : le port accepte la connexion).
 * Par lots de 64 connexions (limite de select() sous Windows).
 */
function printer_tcp_check(array $targets, float $timeout = 1.0): array
{
    $result = array_fill_keys(array_keys($targets), false);
    foreach (array_chunk($targets, 64, true) as $batch) {
        $pending = [];
        foreach ($batch as $key => [$host, $port]) {
            $address = (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host) . ':' . (int) $port;
            $socket = @stream_socket_client('tcp://' . $address, $errno, $errstr, $timeout,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
            if ($socket) {
                $pending[(int) $socket] = [$key, $socket];
            }
        }
        $deadline = microtime(true) + $timeout;
        while ($pending && ($left = $deadline - microtime(true)) > 0) {
            $write = $except = array_column($pending, 1);
            $read = null;
            $ready = @stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1e6));
            if (!$ready) {
                break;
            }
            foreach ($write as $socket) {
                [$key] = $pending[(int) $socket];
                // Connexion réellement établie (un échec peut aussi rendre le socket « prêt »)
                $result[$key] = @stream_socket_get_name($socket, true) !== false;
                fclose($socket);
                unset($pending[(int) $socket]);
            }
            foreach ($except as $socket) {
                if (isset($pending[(int) $socket])) {
                    fclose($socket);
                    unset($pending[(int) $socket]);
                }
            }
        }
        foreach ($pending as [, $socket]) {
            fclose($socket);
        }
    }
    return $result;
}

/**
 * État en temps réel des imprimantes enregistrées ayant une adresse IP.
 * Met à jour last_status / last_checked_at et retourne [id => 'online'|'offline'].
 */
function printers_probe(array $printers, float $timeout = 1.0): array
{
    $targets = [];
    foreach ($printers as $p) {
        if ((string) $p['host'] !== '') {
            $targets[(int) $p['id']] = [$p['host'], (int) $p['port']];
        }
    }
    $status = [];
    foreach (printer_tcp_check($targets, $timeout) as $id => $online) {
        $status[$id] = $online ? 'online' : 'offline';
        db_query('UPDATE printers SET last_status = ?, last_checked_at = ? WHERE id = ?', [$status[$id], db_now(), $id]);
    }
    return $status;
}

/* ==========================================================================
   Découverte du réseau local
   ========================================================================== */

/**
 * Adresses d'un sous-réseau PRIVÉ (10/8, 172.16/12, 192.168/16, ou 127/8 pour le poste local),
 * de /24 à /32 (256 adresses au plus). Retourne null si la plage est refusée.
 */
function printer_scan_targets(string $cidr): ?array
{
    if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})(?:/(\d{1,2}))?$#', trim($cidr), $m)
        || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return null;
    }
    $prefix = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 32;
    if ($prefix < 24 || $prefix > 32) {
        return null;
    }
    $ip = ip2long($m[1]);
    $private = [['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['127.0.0.0', 8]];
    $allowed = false;
    foreach ($private as [$net, $bits]) {
        $mask = -1 << (32 - $bits);
        if (($ip & $mask) === (ip2long($net) & $mask)) {
            $allowed = true;
        }
    }
    if (!$allowed) {
        return null;
    }
    $mask  = $prefix === 32 ? -1 : (-1 << (32 - $prefix));
    $first = $ip & $mask;
    $count = 1 << (32 - $prefix);
    $hosts = [];
    for ($i = 0; $i < $count; $i++) {
        $addr = $first + $i;
        // Adresses réseau et diffusion exclues (sauf /31 et /32)
        if ($count > 2 && ($i === 0 || $i === $count - 1)) {
            continue;
        }
        $hosts[] = long2ip($addr);
    }
    return $hosts;
}

/**
 * Appareils d'impression détectés sur le sous-réseau configuré (Paramètres → Système),
 * hors imprimantes déjà enregistrées. Résultat mis en cache PRINTER_SCAN_TTL secondes.
 * Retourne ['enabled' => bool, 'subnet' => string, 'scanned_at' => int|null, 'devices' => [[host, port]...]].
 */
function printers_discover(string $subnet, bool $force = false): array
{
    $info = ['enabled' => false, 'subnet' => $subnet, 'scanned_at' => null, 'devices' => []];
    $hosts = $subnet !== '' ? printer_scan_targets($subnet) : null;
    if ($hosts === null) {
        return $info;
    }
    $info['enabled'] = true;

    $cache = is_file(PRINTER_SCAN_CACHE) ? json_decode((string) @file_get_contents(PRINTER_SCAN_CACHE), true) : null;
    if (!$force && is_array($cache) && ($cache['subnet'] ?? '') === $subnet && time() - (int) ($cache['at'] ?? 0) < PRINTER_SCAN_TTL) {
        $found = $cache['found'] ?? [];
        $info['scanned_at'] = (int) $cache['at'];
    } else {
        $targets = [];
        foreach ($hosts as $host) {
            foreach (PRINTER_SCAN_PORTS as $port) {
                $targets[$host . ':' . $port] = [$host, $port];
            }
        }
        $found = [];
        foreach (printer_tcp_check($targets, 0.6) as $key => $open) {
            if ($open) {
                $found[] = $targets[$key];
            }
        }
        $info['scanned_at'] = time();
        @file_put_contents(PRINTER_SCAN_CACHE, json_encode(['subnet' => $subnet, 'at' => $info['scanned_at'], 'found' => $found]), LOCK_EX);
    }

    // Un appareil répondant sur plusieurs ports n'apparaît qu'une fois (RAW > IPP > LPD) ; imprimantes connues exclues
    $known = [];
    foreach (db_all("SELECT host FROM printers WHERE host IS NOT NULL AND host <> ''") as $row) {
        $known[mb_strtolower($row['host'])] = true;
    }
    $devices = [];
    foreach ($found as [$host, $port]) {
        if (!isset($known[$host]) && (!isset($devices[$host]) || array_search($port, PRINTER_SCAN_PORTS, true) < array_search($devices[$host][1], PRINTER_SCAN_PORTS, true))) {
            $devices[$host] = [$host, (int) $port];
        }
    }
    uksort($devices, fn ($a, $b) => ip2long($a) <=> ip2long($b));
    $info['devices'] = array_values($devices);
    return $info;
}
