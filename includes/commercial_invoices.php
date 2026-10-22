<?php
/**
 * Factures commerciales (facture, facture proforma, devis) — lignes d'articles, totaux, numérotation,
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
   Saisie, calculs, enregistrement
   ========================================================================== */

/** Nombre saisi à la française (« 1 250 000,50 ») → float, null si invalide. */
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

/**
 * Lecture et validation du formulaire. Retourne [données, lignes, erreurs].
 * Les lignes entièrement vides sont ignorées ; les montants sont recalculés ici.
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

    // Lignes d'articles
    $items = [];
    $desc  = (array) ($_POST['item_description'] ?? []);
    $qty   = (array) ($_POST['item_quantity'] ?? []);
    $price = (array) ($_POST['item_price'] ?? []);
    $count = min(count($desc), CINV_MAX_LINES + 50);
    for ($i = 0; $i < $count; $i++) {
        $label = trim((string) ($desc[$i] ?? ''));
        $q     = trim((string) ($qty[$i] ?? ''));
        $p     = trim((string) ($price[$i] ?? ''));
        if ($label === '' && ($q === '' || $q === '1') && $p === '') {
            continue;   // ligne vide
        }
        $line = count($items) + 1;
        $qn = cinv_number($q === '' ? '1' : $q);
        $pn = cinv_number($p === '' ? '0' : $p);
        if ($label === '' || mb_strlen($label) > 500) {
            $e['items'][] = "Ligne {$line} : indiquez la désignation (500 caractères au plus).";
        }
        if ($qn === null || $qn <= 0 || $qn > 1e9) {
            $e['items'][] = "Ligne {$line} : quantité invalide.";
            $qn = 0.0;
        }
        if ($pn === null || $pn < 0 || $pn > 1e12) {
            $e['items'][] = "Ligne {$line} : prix unitaire invalide.";
            $pn = 0.0;
        }
        $items[] = ['description' => $label, 'quantity' => round($qn, 3), 'unit_price' => cinv_round($pn, $d['currency'])];
    }
    if (!$items) {
        $e['items'][] = 'Ajoutez au moins une ligne (désignation, quantité, prix unitaire).';
    } elseif (count($items) > CINV_MAX_LINES) {
        $e['items'][] = 'Une facture compte au plus ' . CINV_MAX_LINES . ' lignes.';
    }

    return [$d, cinv_totals($items, $d['tax_rate'], $d['currency']), $e];
}

/** Montants des lignes et totaux : ['items' => …, 'subtotal', 'tax_amount', 'total']. */
function cinv_totals(array $items, float $taxRate, string $currency): array
{
    $subtotal = 0.0;
    foreach ($items as &$item) {
        $item['line_total'] = cinv_round($item['quantity'] * $item['unit_price'], $currency);
        $subtotal += $item['line_total'];
    }
    unset($item);
    $subtotal = cinv_round($subtotal, $currency);
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
 * Enregistre une facture (création si $id est null) avec ses lignes. Retourne l'identifiant.
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
            'total' => $calc['total'], 'notes' => $d['notes'] ?: null, 'updated_at' => db_now(),
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
        $insert = $pdo->prepare('INSERT INTO commercial_invoice_items (invoice_id, position, description, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($calc['items'] as $i => $item) {
            $insert->execute([$id, $i + 1, $item['description'], $item['quantity'], $item['unit_price'], $item['line_total']]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Facture et ses lignes, ou null. */
function cinv_find(int $id): ?array
{
    $inv = db_one('SELECT * FROM commercial_invoices WHERE id = ?', [$id]);
    if (!$inv) {
        return null;
    }
    $inv['items'] = db_all('SELECT * FROM commercial_invoice_items WHERE invoice_id = ? ORDER BY position, id', [$id]);
    return $inv;
}

/** Supprime une facture et ses lignes. */
function cinv_delete(int $id): void
{
    db()->beginTransaction();
    db_query('DELETE FROM commercial_invoice_items WHERE invoice_id = ?', [$id]);
    db_query('DELETE FROM commercial_invoices WHERE id = ?', [$id]);
    db()->commit();
}

/** Quantité lisible : 3 / 2,5 / 0,125. */
function cinv_qty(float $q): string
{
    return str_replace('.', ',', rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.'));
}

/** Montant sans devise, au format français : 1 250 000 / 99,50. */
function cinv_amount(float $value, string $currency): string
{
    return number_format($value, DASH_CURRENCIES[$currency]['decimals'] ?? 2, ',', "\u{202F}");
}
