<?php
/**
 * Export des listes au format CSV, ouvert directement par Excel (version française) et LibreOffice.
 *
 *   dashboard/invoices.php?action=list&export=csv   Reçus de caisse
 *   dashboard/invoices-list.php?export=csv          Factures, proformas et devis
 *
 * L'export reprend exactement les filtres de la liste affichée (recherche, type, statut, période « Du / Au »)
 * et contient toutes les lignes correspondantes, pas seulement la page en cours.
 * Format : UTF-8 avec BOM (accents corrects dans Excel), séparateur « ; », décimales à la virgule, fins de ligne CRLF.
 */

/** Période « Du / Au » des filtres (dates AAAA-MM-JJ valides, sinon ignorées) : [du, au, SQL, paramètres]. */
function list_date_range(string $column): array
{
    $valid = function (string $d): string {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d ? $d : '';
    };
    $from = $valid(query('from'));
    $to   = $valid(query('to'));
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];   // dates inversées : corrigées plutôt que refusées
    }
    $where = [];
    $params = [];
    if ($from !== '') {
        $where[] = "{$column} >= ?";
        $params[] = $from;
    }
    if ($to !== '') {
        // « jusqu'au » inclus, que la colonne contienne une date seule ou une date et une heure
        $where[] = "{$column} < ?";
        $params[] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
    }
    return [$from, $to, $where, $params];
}

/** Champs « Du / Au » des barres de filtres. */
function list_date_inputs(string $from, string $to): string
{
    return '<label class="filters__date"><span>Du</span><input type="date" name="from" value="' . e($from) . '" data-autosubmit></label>'
         . '<label class="filters__date"><span>Au</span><input type="date" name="to" value="' . e($to) . '" data-autosubmit></label>';
}

/** Montant au format Excel français (1234,56), vide si absent. */
function csv_amount(float|string|null $value, int $decimals = 2): string
{
    return $value === null || $value === '' ? '' : number_format((float) $value, $decimals, ',', '');
}

/**
 * Envoie le fichier CSV et termine le script. $rows : itérable de tableaux (une ligne par élément),
 * parcouru au fil de l'eau (une grosse liste n'est jamais chargée entièrement en mémoire).
 */
function csv_send(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fwrite($out, "\u{FEFF}");   // BOM : Excel reconnaît l'UTF-8
    $line = function (array $cells) use ($out): void {
        // Protection contre l'injection de formules : une cellule commençant par = + - @ (ou tabulation / retour)
        // serait exécutée par Excel ; elle est préfixée d'une apostrophe. Les nombres négatifs restent des nombres.
        $cells = array_map(function ($v) {
            $v = (string) $v;
            return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric(str_replace(',', '.', $v)) ? "'" . $v : $v;
        }, $cells);
        fputcsv($out, $cells, ';', '"', '', "\r\n");   // échappement '' : guillemets doublés (norme CSV)
    };
    $line($header);
    foreach ($rows as $row) {
        $line($row);
    }
    fclose($out);
    exit;
}
