<?php
/**
 * Factures commerciales (facture, facture proforma, devis) — tableau personnalisable (colonnes et lignes libres,
 * une colonne des montants), totaux, numérotation,
 * montant en lettres. Utilisé par dashboard/invoices-list.php (saisie, liste) et
 * dashboard/print-commercial-invoice.php (impression avec l'en-tête officiel).
 *
 * Distinct des « Reçus de caisse » (table invoices, dashboard/invoices.php) : opérations de guichet.
 */

/** Types de document : libellé, préfixe de numéro, titre imprimé, phrase du montant en lettres. */
const CINV_TYPES = [
    'invoice'  => ['label' => 'Facture',          'prefix' => 'FAC', 'title' => 'FACTURE',          'stop' => 'Arrêtée la présente facture à la somme de'],
    'proforma' => ['label' => 'Facture proforma', 'prefix' => 'PRO', 'title' => 'FACTURE PROFORMA', 'stop' => 'Arrêtée la présente facture proforma à la somme de'],
    'quote'    => ['label' => 'Devis',            'prefix' => 'DEV', 'title' => 'DEVIS',            'stop' => 'Arrêté le présent devis à la somme de'],
];

const CINV_STATUSES = [
    'issued'    => ['label' => 'Émise',   'tone' => 'info'],
    'paid'      => ['label' => 'Payée',   'tone' => 'success'],
    'cancelled' => ['label' => 'Annulée', 'tone' => 'muted'],
];

const CINV_MAX_LINES = 100;

/** Nom de la devise pour le montant en lettres : [singulier, pluriel]. */
const CINV_CURRENCY_WORDS = [
    'XAF' => ['franc CFA', 'francs CFA'],
    'EUR' => ['euro', 'euros'],
    'USD' => ['dollar américain', 'dollars américains'],
    'CAD' => ['dollar canadien', 'dollars canadiens'],
    'NGN' => ['naira', 'nairas'],
    'AED' => ['dirham', 'dirhams'],
    'CNY' => ['yuan', 'yuans'],
];

/* ==========================================================================
   Montant en lettres (règles traditionnelles : quatre-vingts, deux cents, vingt et un…)
   ========================================================================== */

/** 0 à 99. */
function fr_words_below_100(int $n): string
{
    static $units = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
        'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];
    static $tens = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    if ($n < 17) {
        return $units[$n];
    }
    if ($n < 20) {
        return 'dix-' . $units[$n - 10];
    }
    $t = intdiv($n, 10);
    $u = $n % 10;
    if ($t === 7 || $t === 9) {                       // soixante-dix…, quatre-vingt-dix…
        $base = $t === 7 ? 'soixante' : 'quatre-vingt';
        return $t === 7 && $u === 1 ? 'soixante et onze' : $base . '-' . fr_words_below_100(10 + $u);
    }
    if ($t === 8) {
        return $u === 0 ? 'quatre-vingts' : 'quatre-vingt-' . $units[$u];
    }
    return $u === 0 ? $tens[$t] : ($u === 1 ? $tens[$t] . ' et un' : $tens[$t] . '-' . $units[$u]);
}

/** 0 à 999 ; $final : le nombre termine l'expression (accord de « cents »). */
function fr_words_below_1000(int $n, bool $final = true): string
{
    $h = intdiv($n, 100);
    $r = $n % 100;
    $parts = [];
    if ($h === 1) {
        $parts[] = 'cent';
    } elseif ($h > 1) {
        $parts[] = fr_words_below_100($h) . ($r === 0 && $final ? ' cents' : ' cent');
    }
    if ($r > 0 || $h === 0) {
        $parts[] = fr_words_below_100($r);
    }
    return implode(' ', $parts);
}

/** Nombre entier en toutes lettres (jusqu'à 999 milliards). */
function fr_number_words(int $n): string
{
    if ($n === 0) {
        return 'zéro';
    }
    $words = [];
    foreach ([[1000000000, 'milliard'], [1000000, 'million']] as [$size, $name]) {
        $count = intdiv($n, $size);
        if ($count > 0) {
            // « millions » et « milliards » sont des noms : « quatre-vingts millions », « deux cents millions »
            $words[] = fr_words_below_1000($count) . ' ' . $name . ($count > 1 ? 's' : '');
            $n %= $size;
        }
    }
    $thousands = intdiv($n, 1000);
    if ($thousands > 0) {
        // « mille » est invariable et ne fait pas accorder « vingt » ni « cent » : « quatre-vingt mille », « deux cent mille »
        $words[] = $thousands === 1 ? 'mille' : preg_replace('/(vingt|cent)s$/', '$1', fr_words_below_1000($thousands, false)) . ' mille';
        $n %= 1000;
    }
    if ($n > 0) {
        $words[] = fr_words_below_1000($n);
    }
    return implode(' ', $words);
}

/** Montant en lettres avec la devise : « Cent vingt-cinq mille francs CFA », « Un million de francs CFA ». */
function amount_in_words(float $amount, string $currency): string
{
    [$one, $many] = CINV_CURRENCY_WORDS[$currency] ?? [$currency, $currency];
    $decimals = DASH_CURRENCIES[$currency]['decimals'] ?? 2;
    $amount   = round(abs($amount), $decimals);
    $whole    = (int) floor($amount + 1e-9);
    $cents    = $decimals > 0 ? (int) round(($amount - $whole) * 100) : 0;

    $text = fr_number_words($whole);
    // « un million DE francs », « deux milliards D'euros »
    $of = preg_match('/(million|milliard)s?$/', $text) ? (preg_match('/^[aeiouyéè]/i', $many) ? " d'" : ' de ') : ' ';
    $text .= $of . ($whole > 1 ? $many : $one);
    if ($cents > 0) {
        $text .= ' et ' . fr_number_words($cents) . ' centime' . ($cents > 1 ? 's' : '');
    }
    return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
}

/* ==========================================================================
   Tableau personnalisable : colonnes de la facture
   ========================================================================== */

/** Colonnes d'une nouvelle facture : une colonne texte et la colonne des montants (align : alignement de la colonne). */
const CINV_DEFAULT_COLUMNS = [
    ['key' => 'c1', 'label' => 'Désignation', 'type' => 'text', 'align' => 'left'],
    ['key' => 'amount', 'label' => 'Montant', 'type' => 'amount', 'align' => 'right'],
];
/** Alignements possibles (colonne entière ou cellule). */
const CINV_ALIGNS = ['left', 'center', 'right'];

/** Alignement par défaut : texte à gauche, montants à droite. */
function cinv_default_align(string $type): string
{
    return $type === 'amount' ? 'right' : 'left';
}

/** Alignement d'une cellule : celui choisi pour la cellule, sinon celui de sa colonne. */
function cinv_cell_align(array $row, array $col): string
{
    $own = $row['_align'][$col['key']] ?? null;
    return in_array($own, CINV_ALIGNS, true) ? $own : ($col['align'] ?? cinv_default_align($col['type']));
}
const CINV_MAX_TEXT_COLUMNS = 5;    // + la colonne des montants (largeur d'une page A4)
const CINV_MAX_LABEL = 40;
const CINV_MAX_CELL = 500;

/** Colonnes enregistrées (JSON) → tableau validé, colonnes par défaut si absent ou illisible. */
function cinv_columns(?string $json): array
{
    $cols = $json ? json_decode($json, true) : null;
    if (!is_array($cols)) {
        return CINV_DEFAULT_COLUMNS;
    }
    $valid = array_values(array_filter($cols, fn ($c) => is_array($c) && isset($c['key'], $c['label'], $c['type'])));
    foreach ($valid as &$c) {
        $c['align'] = in_array($c['align'] ?? null, CINV_ALIGNS, true) ? $c['align'] : cinv_default_align($c['type']);
    }
    unset($c);
    return $valid && in_array('amount', array_column($valid, 'key'), true) ? $valid : CINV_DEFAULT_COLUMNS;
}

/* ==========================================================================
   Saisie, calculs, enregistrement
   ========================================================================== */

/** Nombre saisi à la française (« 1 250 000,50 », « -10 000 ») → float, null si invalide. */
function cinv_number(string $value): ?float
{
    $raw = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], trim($value));
    return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
}

/** Arrondi selon la devise (0 décimale pour le FCFA). */
function cinv_round(float $value, string $currency): float
{
    return round($value, DASH_CURRENCIES[$currency]['decimals'] ?? 2);
}

/** Texte reçu du formulaire : chaîne UTF-8 nettoyée (une saisie Windows-1252 est convertie, comme post()). */
function cinv_text($value): string
{
    $v = trim(is_scalar($value) ? (string) $value : '');
    return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
}

/**
 * Lecture et validation du formulaire. Retourne [données, calculs, erreurs].
 * Colonnes : col_key[] / col_label[] (colonnes texte puis la colonne « amount ») ;
 * cellules : cell[<clé>][] alignées par ligne. Les lignes entièrement vides sont ignorées.
 */
function cinv_input(): array
{
    $d = [
        'doc_type'       => post('doc_type', 'invoice'),
        'status'         => post('status', 'issued'),
        'client_name'    => post('client_name'),
        'client_phone'   => post('client_phone'),
        'client_email'   => mb_strtolower(post('client_email')),
        'client_address' => post('client_address'),
        'client_niu'     => strtoupper(post('client_niu')),
        'issue_date'     => post('issue_date', date('Y-m-d')),
        'due_date'       => post('due_date'),
        'currency'       => post('currency', 'XAF'),
        'tax_rate'       => post('tax_rate', '0'),
        'notes'          => post('notes'),
    ];
    $e = [];

    if (!isset(CINV_TYPES[$d['doc_type']])) {
        $e['doc_type'] = 'Type de document invalide.';
    }
    if (!isset(CINV_STATUSES[$d['status']])) {
        $e['status'] = 'Statut invalide.';
    }
    if (mb_strlen($d['client_name']) < 2 || mb_strlen($d['client_name']) > 150) {
        $e['client_name'] = 'Indiquez le nom du client (2 à 150 caractères).';
    }
    if ($d['client_phone'] !== '' && !preg_match('/^[0-9+().\s-]{6,50}$/', $d['client_phone'])) {
        $e['client_phone'] = 'Numéro de téléphone invalide.';
    }
    if ($d['client_email'] !== '' && (!filter_var($d['client_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['client_email']) > 150)) {
        $e['client_email'] = 'Adresse e-mail invalide.';
    }
    if (mb_strlen($d['client_address']) > 255) {
        $e['client_address'] = 'Adresse trop longue (255 caractères au plus).';
    }
    if ($d['client_niu'] !== '' && !preg_match('/^[A-Z0-9]{6,30}$/', $d['client_niu'])) {
        $e['client_niu'] = 'NIU invalide (lettres et chiffres).';
    }
    $issue = DateTimeImmutable::createFromFormat('!Y-m-d', $d['issue_date']);
    if (!$issue || $issue->format('Y-m-d') !== $d['issue_date']) {
        $e['issue_date'] = "Date d'émission invalide.";
    }
    if ($d['due_date'] !== '') {
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $d['due_date']);
        if (!$due || $due->format('Y-m-d') !== $d['due_date']) {
            $e['due_date'] = "Date d'échéance invalide.";
        } elseif ($issue && $due < $issue) {
            $e['due_date'] = "L'échéance ne peut pas précéder la date d'émission.";
        }
    }
    if (!isset(DASH_CURRENCIES[$d['currency']])) {
        $e['currency'] = 'Devise invalide.';
        $d['currency'] = 'XAF';
    }
    $tax = cinv_number($d['tax_rate']);
    if ($tax === null || $tax < 0 || $tax > 100) {
        $e['tax_rate'] = 'Taux de TVA invalide (0 à 100 %).';
        $tax = 0.0;
    }
    $d['tax_rate'] = round($tax, 2);
    if (mb_strlen($d['notes']) > 2000) {
        $e['notes'] = 'Notes trop longues (2 000 caractères au plus).';
    }

    // ---- Colonnes : clés techniques sûres, libellés libres, une seule colonne des montants (en dernier)
    $keys   = array_values((array) ($_POST['col_key'] ?? []));
    $labels = array_values((array) ($_POST['col_label'] ?? []));
    $aligns = array_values((array) ($_POST['col_align'] ?? []));
    $columns = [];
    $seen = [];
    foreach ($keys as $i => $key) {
        $key = cinv_text($key);
        if (!preg_match('/^(c\d{1,3}|amount)$/', $key) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $label = cinv_text($labels[$i] ?? '');
        if ($label === '') {
            $label = $key === 'amount' ? 'Montant' : 'Colonne ' . (count($columns) + 1);
        }
        if (mb_strlen($label) > CINV_MAX_LABEL) {
            $e['items'][] = 'Titre de colonne trop long (' . CINV_MAX_LABEL . ' caractères au plus) : « ' . mb_substr($label, 0, 20) . '… ».';
        }
        $type  = $key === 'amount' ? 'amount' : 'text';
        $align = cinv_text($aligns[$i] ?? '');
        $columns[] = ['key' => $key, 'label' => mb_substr($label, 0, CINV_MAX_LABEL), 'type' => $type,
                      'align' => in_array($align, CINV_ALIGNS, true) ? $align : cinv_default_align($type)];
    }
    $text   = array_values(array_filter($columns, fn ($c) => $c['type'] === 'text'));
    $amount = array_values(array_filter($columns, fn ($c) => $c['type'] === 'amount'))[0] ?? CINV_DEFAULT_COLUMNS[1];
    if (!$text) {
        $text = [CINV_DEFAULT_COLUMNS[0]];
    }
    if (count($text) > CINV_MAX_TEXT_COLUMNS) {
        $e['items'][] = 'Le tableau compte au plus ' . CINV_MAX_TEXT_COLUMNS . ' colonnes de texte (plus la colonne des montants).';
        $text = array_slice($text, 0, CINV_MAX_TEXT_COLUMNS);
    }
    $columns = [...$text, $amount];

    // ---- Lignes
    $cells = (array) ($_POST['cell'] ?? []);
    $cellAligns = (array) ($_POST['cell_align'] ?? []);
    $rowCount = 0;
    foreach ($columns as $c) {
        $rowCount = max($rowCount, count((array) ($cells[$c['key']] ?? [])));
    }
    $rowCount = min($rowCount, CINV_MAX_LINES + 50);
    $items = [];
    for ($i = 0; $i < $rowCount; $i++) {
        $row = [];
        $filled = false;
        foreach ($text as $c) {
            $v = cinv_text(((array) ($cells[$c['key']] ?? []))[$i] ?? '');
            $row[$c['key']] = $v;
            $filled = $filled || $v !== '';
        }
        $rawAmount = cinv_text(((array) ($cells['amount'] ?? []))[$i] ?? '');
        if (!$filled && $rawAmount === '') {
            continue;   // ligne vide
        }
        $line = count($items) + 1;
        foreach ($text as $c) {
            if (mb_strlen($row[$c['key']]) > CINV_MAX_CELL) {
                $e['items'][] = "Ligne {$line}, « {$c['label']} » : " . CINV_MAX_CELL . ' caractères au plus.';
            }
        }
        $value = null;
        if ($rawAmount !== '') {
            $value = cinv_number($rawAmount);
            if ($value === null || abs($value) > 1e12) {
                $e['items'][] = "Ligne {$line} : montant invalide.";
                $value = null;
            } else {
                $value = cinv_round($value, $d['currency']);
            }
        }
        $row['amount'] = $value;   // null : ligne de texte (titre, commentaire) sans montant
        // Alignement choisi pour une cellule (différent de celui de sa colonne)
        foreach ($columns as $c) {
            $a = cinv_text(((array) ($cellAligns[$c['key']] ?? []))[$i] ?? '');
            if (in_array($a, CINV_ALIGNS, true) && $a !== $c['align']) {
                $row['_align'][$c['key']] = $a;
            }
        }
        $items[] = $row;
    }
    if (!$items) {
        $e['items'][] = 'Ajoutez au moins une ligne au tableau.';
    } elseif (count($items) > CINV_MAX_LINES) {
        $e['items'][] = 'Le tableau compte au plus ' . CINV_MAX_LINES . ' lignes.';
    }

    $calc = cinv_totals($items, $d['tax_rate'], $d['currency']);
    if ($items && $calc['subtotal'] < 0) {
        $e['items'][] = 'Le total ne peut pas être négatif (vérifiez les remises).';
    }
    $calc['columns'] = $columns;
    return [$d, $calc, $e];
}

/** Totaux : somme de la colonne des montants (lignes sans montant ignorées), TVA, total. */
function cinv_totals(array $items, float $taxRate, string $currency): array
{
    $subtotal = cinv_round(array_sum(array_map(fn ($r) => (float) ($r['amount'] ?? 0), $items)), $currency);
    $tax      = cinv_round($subtotal * $taxRate / 100, $currency);
    return ['items' => $items, 'subtotal' => $subtotal, 'tax_amount' => $tax, 'total' => cinv_round($subtotal + $tax, $currency)];
}

/** Prochain numéro pour un type et une année : FAC-2026-0001. À appeler dans une transaction. */
function cinv_next_number(string $type, string $issueDate): string
{
    $prefix = CINV_TYPES[$type]['prefix'] . '-' . substr($issueDate, 0, 4) . '-';
    $last   = (string) db_value('SELECT MAX(invoice_number) FROM commercial_invoices WHERE invoice_number LIKE ?', [$prefix . '%']);
    $seq    = $last !== '' ? (int) substr($last, strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Enregistre une facture (création si $id est null) avec ses colonnes et ses lignes. Retourne l'identifiant.
 * Le numéro est attribué à la création et conservé ensuite (même si la date ou le type change).
 */
function cinv_save(array $d, array $calc, array $user, ?int $id = null): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $fields = [
            'doc_type' => $d['doc_type'], 'status' => $d['status'], 'client_name' => $d['client_name'],
            'client_phone' => $d['client_phone'] ?: null, 'client_email' => $d['client_email'] ?: null,
            'client_address' => $d['client_address'] ?: null, 'client_niu' => $d['client_niu'] ?: null,
            'issue_date' => $d['issue_date'], 'due_date' => $d['due_date'] ?: null, 'currency' => $d['currency'],
            'tax_rate' => $d['tax_rate'], 'subtotal' => $calc['subtotal'], 'tax_amount' => $calc['tax_amount'],
            'total' => $calc['total'], 'notes' => $d['notes'] ?: null,
            'columns_json' => json_encode($calc['columns'], JSON_UNESCAPED_UNICODE), 'updated_at' => db_now(),
        ];
        if ($id === null) {
            $fields += ['invoice_number' => cinv_next_number($d['doc_type'], $d['issue_date']),
                        'created_by' => (int) $user['id'], 'created_by_name' => $user['name'], 'created_at' => db_now()];
            $cols = array_keys($fields);
            db_query('INSERT INTO commercial_invoices (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($fields));
            $id = (int) $pdo->lastInsertId();
        } else {
            $set = implode(', ', array_map(fn ($c) => "{$c} = ?", array_keys($fields)));
            db_query("UPDATE commercial_invoices SET {$set} WHERE id = ?", [...array_values($fields), $id]);
            db_query('DELETE FROM commercial_invoice_items WHERE invoice_id = ?', [$id]);
        }
        $firstText = $calc['columns'][0]['key'];
        $insert = $pdo->prepare('INSERT INTO commercial_invoice_items (invoice_id, position, description, line_total, cells_json) VALUES (?, ?, ?, ?, ?)');
        foreach ($calc['items'] as $i => $row) {
            $insert->execute([$id, $i + 1, mb_substr((string) ($row[$firstText] ?? ''), 0, 500), (float) ($row['amount'] ?? 0),
                json_encode($row, JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Facture avec ses colonnes ('columns') et ses lignes ('items' : cellules par clé, 'amount' null ou nombre), ou null. */
function cinv_find(int $id): ?array
{
    $inv = db_one('SELECT * FROM commercial_invoices WHERE id = ?', [$id]);
    if (!$inv) {
        return null;
    }
    $inv['columns'] = cinv_columns($inv['columns_json'] ?? null);
    $inv['items'] = [];
    foreach (db_all('SELECT * FROM commercial_invoice_items WHERE invoice_id = ? ORDER BY position, id', [$id]) as $item) {
        $cells = json_decode((string) ($item['cells_json'] ?? ''), true);
        $inv['items'][] = is_array($cells) ? $cells + ['amount' => null] : ['c1' => $item['description'], 'amount' => (float) $item['line_total']];
    }
    return $inv;
}

/** Supprime une facture et ses lignes. */
function cinv_delete(int $id): void
{
    // Lignes puis document, ensemble ou pas du tout (une erreur annule toute la suppression)
    db()->beginTransaction();
    try {
        db_query('DELETE FROM commercial_invoice_items WHERE invoice_id = ?', [$id]);
        db_query('DELETE FROM commercial_invoices WHERE id = ?', [$id]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/** Montant sans devise, au format français : 1 250 000 / 99,50 / -10 000. */
function cinv_amount(float $value, string $currency): string
{
    return number_format($value, DASH_CURRENCIES[$currency]['decimals'] ?? 2, ',', "\u{202F}");
}
