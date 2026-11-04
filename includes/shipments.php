<?php
/**
 * Suivi des colis et conteneurs (transit et fret international) — règles communes au Dashboard
 * (dashboard/shipments.php) et à la page publique (suivi.php). Tables : includes/db.php → db_install_shipments().
 *
 * Code de suivi : GTC-XXXX-XXXX, tiré au hasard (31^8 ≈ 850 milliards de combinaisons), donc impossible à deviner
 * à partir d'un autre dossier. C'est lui qu'on communique au client. Le numéro du transporteur (B/L, LTA,
 * n° de conteneur) est aussi accepté par la recherche publique.
 * Page publique : nom du client abrégé (« Jean M. »), téléphone jamais affiché, notes internes inexistantes
 * (la note d'une étape est visible par le client), recherches infructueuses limitées par adresse IP.
 */

/** Étapes, dans l'ordre du trajet. 'mode' : libellé propre au mode de transport (sinon 'label'). */
const SHIPMENT_STATUSES = [
    'loading'   => ['label' => "En cours d'embarquement", 'tone' => 'muted',   'icon' => 'forklift',
                    'text'  => "La marchandise est prise en charge et en cours de chargement au départ."],
    'transit'   => ['label' => 'En mer / En vol',         'tone' => 'info',    'icon' => 'ship',
                    'mode'  => ['sea' => 'En mer', 'air' => 'En vol', 'road' => 'En route'],
                    'text'  => "La marchandise a quitté le point de départ et fait route vers Douala."],
    'arrived'   => ['label' => 'Arrivé au port de Douala', 'tone' => 'info',   'icon' => 'anchor',
                    'mode'  => ['sea' => 'Arrivé au port de Douala', 'air' => "Arrivé à l'aéroport de Douala", 'road' => 'Arrivé à Douala'],
                    'text'  => "La marchandise est arrivée à Douala et attend son passage en douane."],
    'customs'   => ['label' => 'En douane',               'tone' => 'warning', 'icon' => 'shield',
                    'text'  => "Formalités de dédouanement en cours."],
    'available' => ['label' => 'Disponible en agence',    'tone' => 'success', 'icon' => 'store',
                    'text'  => "Dédouanée : la marchandise peut être retirée en agence (pièce d'identité et code de suivi)."],
    'delivered' => ['label' => 'Livré au client',         'tone' => 'success', 'icon' => 'check',
                    'text'  => "La marchandise a été remise au client. Dossier terminé."],
];

const SHIPMENT_MODES = [
    'sea'  => 'Maritime',
    'air'  => 'Aérien',
    'road' => 'Routier',
];

/** Longueur maximale de chaque champ saisi (identique aux colonnes de la base). */
const SHIPMENT_LIMITS = [
    'carrier_reference' => 60,
    'client_name'       => 150,
    'client_phone'      => 40,
    'description'       => 500,
    'origin'            => 120,
    'destination'       => 120,
    'location'          => 120,
    'note'              => 500,
    'label'             => 150,
    'package_type'      => 40,
    'pickup_location'   => 200,
];

/** Lieu de retrait proposé pour un nouveau dossier (modifiable dossier par dossier). */
const SHIPMENT_DEFAULT_PICKUP = 'Agence Global Trading Corporation, ' . GTC_ADDRESS;

/** Mesures du colis : champ => [libellé, maximum, décimales]. */
const SHIPMENT_MEASURES = [
    'weight_kg'  => ['Poids (kg)', 1000000, 2],
    'volume_cbm' => ['Volume (m³)', 100000, 3],
    'packages'   => ['Nombre de colis', 1000000, 0],
];

/** Nombre au format français : 1 250,5 (espace insécable, zéros inutiles retirés). */
function shipment_number(float|int|string|null $value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $text = number_format((float) $value, $decimals, ',', "\u{202F}");
    return $decimals > 0 ? rtrim(rtrim($text, '0'), ',') : $text;
}

/**
 * Détails logistiques renseignés, prêts à afficher : [libellé => valeur].
 * Ex. ['Poids' => '1 250,5 kg', 'Volume' => '12,4 m³ (CBM)', 'Colis' => '42 cartons'].
 */
function shipment_measures_text(array $s): array
{
    $out = [];
    if ($s['weight_kg'] !== null && $s['weight_kg'] !== '') {
        $out['Poids'] = shipment_number($s['weight_kg'], 2) . ' kg';
    }
    if ($s['volume_cbm'] !== null && $s['volume_cbm'] !== '') {
        $out['Volume'] = shipment_number($s['volume_cbm'], 3) . ' m³ (CBM)';
    }
    if ($s['packages'] !== null && $s['packages'] !== '') {
        $n = (int) $s['packages'];
        $out['Colis'] = shipment_number($n, 0) . ' ' . ($s['package_type'] ?: 'colis');
    }
    return $out;
}

/** Libellé affiché d'une étape : son libellé libre, à défaut celui du statut. */
function shipment_event_title(array $event, string $mode = ''): string
{
    return (string) ($event['label'] ?? '') !== '' ? $event['label'] : shipment_status_label($event['status'], $mode);
}

/** Position du statut dans le trajet : [numéro de l'étape (1…), nombre d'étapes]. */
function shipment_progress(string $status): array
{
    $keys = array_keys(SHIPMENT_STATUSES);
    $i = array_search($status, $keys, true);
    return [$i === false ? 0 : $i + 1, count($keys)];
}

/** Recherches publiques infructueuses autorisées par adresse IP sur la période (au-delà : attente). */
const TRACKING_MAX_MISSES  = 15;
const TRACKING_WINDOW_MINS = 10;

/** Libellé d'un statut, adapté au mode de transport (« En mer », « En vol »…). */
function shipment_status_label(string $status, string $mode = ''): string
{
    $st = SHIPMENT_STATUSES[$status] ?? null;
    return $st ? ($st['mode'][$mode] ?? $st['label']) : $status;
}

/** Pastille de statut (Dashboard). */
function shipment_badge(string $status, string $mode = ''): string
{
    $tone = SHIPMENT_STATUSES[$status]['tone'] ?? 'muted';
    return '<span class="badge badge--' . e($tone) . '">' . e(shipment_status_label($status, $mode)) . '</span>';
}

/** Code de suivi sans tirets ni espaces, en majuscules : « gtc 7k4p-q9xm » → « GTC7K4PQ9XM ». */
function shipment_normalize(string $code): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $code));
}

/** Nouveau code de suivi unique GTC-XXXX-XXXX (sans 0/O, 1/I/L : lisible au téléphone). */
function shipment_new_tracking_number(): string
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    do {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = 'GTC-' . substr($chars, 0, 4) . '-' . substr($chars, 4);
    } while (db_value('SELECT 1 FROM shipments WHERE tracking_number = ?', [$code]));
    return $code;
}

function shipment_find(int $id): ?array
{
    return db_one('SELECT * FROM shipments WHERE id = ?', [$id]);
}

/** Étapes d'un dossier, de la plus ancienne à la plus récente. */
function shipment_events(int $shipmentId): array
{
    return db_all('SELECT * FROM shipment_events WHERE shipment_id = ? ORDER BY event_date ASC, id ASC', [$shipmentId]);
}

/** Expression SQL : colonne sans tirets ni espaces, en majuscules (même règle que shipment_normalize()). */
function shipment_sql_normalized(string $column): string
{
    return "REPLACE(REPLACE(REPLACE(UPPER({$column}), '-', ''), ' ', ''), '/', '')";
}

/** Dossier correspondant à un code de suivi GTC ou à un numéro du transporteur (B/L, LTA, conteneur). */
function shipment_lookup(string $code): ?array
{
    $key = shipment_normalize($code);
    if (strlen($key) < 4) {
        return null;
    }
    return db_one('SELECT * FROM shipments WHERE ' . shipment_sql_normalized('tracking_number') . ' = ? OR '
        . shipment_sql_normalized('carrier_reference') . ' = ? ORDER BY id DESC LIMIT 1', [$key, $key]);
}

/** Trop de recherches infructueuses récentes depuis cette adresse IP ? */
function tracking_throttled(string $ip): bool
{
    $since = (new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->modify('-' . TRACKING_WINDOW_MINS . ' minutes')->format('Y-m-d H:i:s');
    return (int) db_value('SELECT COUNT(*) FROM tracking_lookups WHERE ip = ? AND created_at >= ?', [$ip, $since]) >= TRACKING_MAX_MISSES;
}

/** Note une recherche infructueuse (et purge celles de plus d'un jour). */
function tracking_record_miss(string $ip): void
{
    db_query('INSERT INTO tracking_lookups (ip, created_at) VALUES (?, ?)', [$ip, db_now()]);
    if (random_int(1, 20) === 1) {
        $old = (new DateTimeImmutable('now', new DateTimeZone('Africa/Douala')))->modify('-1 day')->format('Y-m-d H:i:s');
        db_query('DELETE FROM tracking_lookups WHERE created_at < ?', [$old]);
    }
}

/** Nom du client abrégé pour la page publique : « Jean Mbarga » → « Jean M. ». */
function shipment_public_name(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) {
        return '';
    }
    $first = array_shift($words);
    $initials = '';
    foreach ($words as $w) {
        if (preg_match('/[\p{L}\p{N}]/u', $w, $m)) {   // première lettre (ignore « ( », « - »…)
            $initials .= ' ' . mb_strtoupper($m[0]) . '.';
        }
    }
    return $first . $initials;
}

/**
 * Saisie du formulaire « dossier » validée : [données, erreurs].
 * Nouveau dossier : statut initial et lieu de départ de la première étape en plus.
 */
function shipment_input(bool $isNew): array
{
    $data = [];
    foreach (['carrier_reference', 'client_name', 'client_phone', 'description', 'origin', 'destination', 'package_type', 'pickup_location'] as $field) {
        $data[$field] = post($field);
    }
    foreach (SHIPMENT_MEASURES as $field => $_) {
        $data[$field] = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], post($field));   // « 1 250,5 » → 1250.5
    }
    $data['transport_mode'] = post('transport_mode', 'sea');
    $data['eta']            = post('eta');
    $errors = [];

    foreach (['client_name' => 'Le nom du client est obligatoire.', 'description' => 'Décrivez la marchandise.'] as $field => $message) {
        if ($data[$field] === '') {
            $errors[$field] = $message;
        }
    }
    foreach ($data as $field => $value) {
        if (isset(SHIPMENT_LIMITS[$field]) && mb_strlen($value) > SHIPMENT_LIMITS[$field]) {
            $errors[$field] = SHIPMENT_LIMITS[$field] . ' caractères au maximum.';
        }
    }
    foreach (SHIPMENT_MEASURES as $field => [$label, $max, $decimals]) {
        if ($data[$field] === '') {
            continue;
        }
        $pattern = $decimals > 0 ? '/^\d+(\.\d{1,' . $decimals . '})?$/' : '/^\d+$/';
        if (!preg_match($pattern, $data[$field])) {
            $errors[$field] = $decimals > 0 ? "Nombre attendu (au plus {$decimals} décimales)." : 'Nombre entier attendu.';
        } elseif ((float) $data[$field] <= 0 || (float) $data[$field] > $max) {
            $errors[$field] = 'Valeur entre 0 et ' . shipment_number($max, 0) . '.';
        }
    }
    if (!isset(SHIPMENT_MODES[$data['transport_mode']])) {
        $errors['transport_mode'] = 'Mode de transport inconnu.';
    }
    if ($data['eta'] !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data['eta']);
        if (!$d || $d->format('Y-m-d') !== $data['eta']) {
            $errors['eta'] = 'Date invalide.';
        }
    }
    if ($data['carrier_reference'] !== '' && strlen(shipment_normalize($data['carrier_reference'])) < 4) {
        $errors['carrier_reference'] = 'Numéro trop court (4 caractères au moins).';
    }
    if ($isNew) {
        $data['status'] = post('status', 'loading');
        if (!isset(SHIPMENT_STATUSES[$data['status']])) {
            $errors['status'] = 'Statut inconnu.';
        }
    }
    return [$data, $errors];
}

/** Saisie du formulaire « nouvelle étape » validée : [données, erreurs]. */
function shipment_event_input(): array
{
    $data = [
        'status'     => post('status'),
        'label'      => post('label'),
        'location'   => post('location'),
        'note'       => post('note'),
        'event_date' => str_replace('T', ' ', post('event_date')),
    ];
    $errors = [];
    if (!isset(SHIPMENT_STATUSES[$data['status']])) {
        $errors['status'] = 'Choisissez un statut.';
    }
    foreach (['label', 'location', 'note'] as $field) {
        if (mb_strlen($data[$field]) > SHIPMENT_LIMITS[$field]) {
            $errors[$field] = SHIPMENT_LIMITS[$field] . ' caractères au maximum.';
        }
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $data['event_date']);
    if (!$d || $d->format('Y-m-d H:i') !== $data['event_date']) {
        $errors['event_date'] = 'Date et heure invalides.';
    } elseif ($d > new DateTimeImmutable('+1 day')) {
        $errors['event_date'] = 'La date ne peut pas être dans le futur.';
    } else {
        $data['event_date'] = $d->format('Y-m-d H:i:s');
    }
    return [$data, $errors];
}

/** Enregistre un dossier (création : code de suivi + première étape). Retourne son identifiant. */
function shipment_save(array $data, array $user, ?int $id = null): int
{
    $now    = db_now();
    $fields = [
        'carrier_reference' => $data['carrier_reference'] !== '' ? $data['carrier_reference'] : null,
        'client_name'       => $data['client_name'],
        'client_phone'      => $data['client_phone'] !== '' ? $data['client_phone'] : null,
        'description'       => $data['description'],
        'transport_mode'    => $data['transport_mode'],
        'origin'            => $data['origin'] !== '' ? $data['origin'] : null,
        'destination'       => $data['destination'] !== '' ? $data['destination'] : null,
        'eta'               => $data['eta'] !== '' ? $data['eta'] : null,
        'weight_kg'         => $data['weight_kg'] !== '' ? $data['weight_kg'] : null,
        'volume_cbm'        => $data['volume_cbm'] !== '' ? $data['volume_cbm'] : null,
        'packages'          => $data['packages'] !== '' ? (int) $data['packages'] : null,
        'package_type'      => $data['package_type'] !== '' ? $data['package_type'] : null,
        'pickup_location'   => $data['pickup_location'] !== '' ? $data['pickup_location'] : null,
    ];
    if ($id !== null) {
        $set = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($fields)));
        db_query("UPDATE shipments SET {$set}, updated_at = ? WHERE id = ?", [...array_values($fields), $now, $id]);
        return $id;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $fields += [
            'tracking_number' => shipment_new_tracking_number(),
            'status'          => $data['status'],
            'created_by'      => (int) $user['id'],
            'created_by_name' => $user['name'],
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $cols = implode(', ', array_keys($fields));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        db_query("INSERT INTO shipments ({$cols}) VALUES ({$marks})", array_values($fields));
        $newId = (int) $pdo->lastInsertId();
        // Date à la minute, comme le formulaire des étapes : une étape saisie dans la même minute passe après celle-ci
        shipment_add_event($newId, ['status' => $data['status'], 'label' => '', 'location' => $data['origin'], 'note' => '', 'event_date' => substr($now, 0, 16) . ':00'], $user);
        $pdo->commit();
        return $newId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Ajoute une étape et recalcule le statut actuel du dossier. */
function shipment_add_event(int $shipmentId, array $event, array $user): int
{
    db_query('INSERT INTO shipment_events (shipment_id, status, label, location, note, event_date, created_by, created_by_name, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $shipmentId, $event['status'],
        ($event['label'] ?? '') !== '' ? $event['label'] : null,
        $event['location'] !== '' ? $event['location'] : null,
        $event['note'] !== '' ? $event['note'] : null,
        $event['event_date'], (int) $user['id'], $user['name'], db_now(),
    ]);
    $eventId = (int) db()->lastInsertId();
    shipment_refresh_status($shipmentId);
    return $eventId;
}

/**
 * Statut actuel = celui de l'étape la plus récente (date de l'étape, puis ordre de saisie) : une étape saisie après
 * coup avec une date antérieure ne fait pas reculer le dossier. Sans étape, le statut reste inchangé.
 */
function shipment_refresh_status(int $shipmentId): void
{
    $last = db_value('SELECT status FROM shipment_events WHERE shipment_id = ? ORDER BY event_date DESC, id DESC LIMIT 1', [$shipmentId]);
    if ($last) {
        db_query('UPDATE shipments SET status = ?, updated_at = ? WHERE id = ?', [$last, db_now(), $shipmentId]);
    }
}

/**
 * Frise chronologique : une entrée par statut, dans l'ordre du trajet, avec l'étape la plus récente de ce statut.
 * 'state' : done (franchie), current (statut actuel), todo (à venir).
 */
function shipment_timeline(array $shipment, array $events): array
{
    $keys    = array_keys(SHIPMENT_STATUSES);
    $current = array_search($shipment['status'], $keys, true);
    $latest  = [];
    foreach ($events as $ev) {
        $latest[$ev['status']] = $ev;   // événements triés par date : le dernier l'emporte
    }
    $steps = [];
    foreach ($keys as $i => $key) {
        $steps[] = [
            'status' => $key,
            'label'  => shipment_status_label($key, $shipment['transport_mode']),
            'icon'   => SHIPMENT_STATUSES[$key]['icon'],
            'text'   => SHIPMENT_STATUSES[$key]['text'],
            'state'  => $current === false ? 'todo' : ($i < $current ? 'done' : ($i === $current ? 'current' : 'todo')),
            'event'  => $i <= (int) $current ? ($latest[$key] ?? null) : null,
        ];
    }
    return $steps;
}

/** Lien de suivi à envoyer au client (adresse publique du site). */
function shipment_public_url(array $shipment): string
{
    return seo_url('suivi.php?code=' . rawurlencode($shipment['tracking_number']));
}
