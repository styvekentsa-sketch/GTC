<?php
/**
 * Export des listes vers Excel : fichier Excel natif (.xlsx, recommandé) ou CSV.
 *
 *   dashboard/invoices.php?action=list&export=xlsx|csv   Reçus de caisse
 *   dashboard/invoices-list.php?export=xlsx|csv          Factures, proformas et devis
 *   dashboard/activity.php?export=xlsx|csv               Journal d'activité
 *
 * L'export reprend exactement les filtres de la liste affichée (recherche, type, statut, période « Du / Au »)
 * et contient toutes les lignes correspondantes, pas seulement la page en cours.
 *
 * Pourquoi deux formats : le .xlsx est le plus fiable (vrais nombres et dates) ; le CSV est conçu pour s'ouvrir lui aussi
 * directement dans Excel en colonnes distinctes (ligne « sep=; », encodage Windows-1252 : voir csv_send()).
 * Le .xlsx s'ouvre correctement partout : colonnes, accents, montants (vrais nombres, séparateurs de milliers selon
 * le poste, sommes possibles) et dates.
 *
 * Colonnes : [['label' => 'Montant remis', 'type' => 'amount', 'decimals' => 2, 'width' => 16], …]
 *   type : text (défaut), amount (nombre à séparateurs de milliers), int, date (AAAA-MM-JJ), time, datetime.
 * Lignes : valeurs brutes dans l'ordre des colonnes (nombres, dates « AAAA-MM-JJ[ HH:MM:SS] », texte) ;
 *   un montant peut porter ses propres décimales : [montant, décimales] (ex. FCFA sans décimales).
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

/** Boutons d'export des barres d'outils (filtres de la liste conservés). */
function export_buttons(string $page, array $filters, int $total, string $what): string
{
    if ($total === 0) {
        return '';
    }
    $url = fn (string $format) => e($page . '?' . http_build_query($filters + ['export' => $format]));
    $title = "{$total} {$what} correspondant aux filtres";
    return '<a href="' . $url('xlsx') . '" class="btn btn--ghost" title="Fichier Excel : ' . e($title) . '">' . icon('download') . ' Exporter (Excel)</a>'
         . '<a href="' . $url('csv') . '" class="btn btn--ghost btn--sm export-csv" title="Fichier CSV (séparateur « ; ») : ' . e($title) . '">CSV</a>';
}

/** Format demandé par ?export=… (xlsx, csv) ou null. */
function export_format(): ?string
{
    return in_array(query('export'), ['xlsx', 'csv'], true) ? query('export') : null;
}

/* ==========================================================================
   Nettoyage des valeurs
   ========================================================================== */

/** Texte sur une ligne : retours à la ligne, tabulations et espaces multiples remplacés par une espace. */
function export_clean_text(mixed $value): string
{
    $text = (string) ($value ?? '');
    $text = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);                 // caractères de contrôle
    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));                 // espaces, retours, insécables
}

/** Nombre à partir d'un montant éventuellement saisi avec espaces (« 1 250 000,50 »), ou null. */
function export_number(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    $s = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string) $value));
    $s = str_contains($s, ',') && !str_contains($s, '.') ? str_replace(',', '.', $s) : str_replace(',', '', $s);
    return is_numeric($s) ? (float) $s : null;
}

/** Montant accompagné de ses décimales propres ([1500, 0] : FCFA sans décimales) : [valeur, colonne ajustée]. */
function export_cell_decimals(mixed $value, array $col): array
{
    if (is_array($value)) {
        $col['decimals'] = (int) ($value[1] ?? $col['decimals'] ?? 2);
        $value = $value[0] ?? null;
    }
    return [$value, $col];
}

/**
 * CSV : protection contre l'injection de formules — apostrophe devant un texte qu'Excel exécuterait (vérifié avec Excel 16) :
 * tout ce qui commence par = ou @, et par + ou - sauf un numéro / nombre (« +237 677 79 42 69 » reste du texte).
 * Inutile dans le .xlsx, où une cellule texte ne peut jamais devenir une formule.
 */
function export_safe_text(string $text): string
{
    if ($text === '') {
        return $text;
    }
    $risky = $text[0] === '=' || $text[0] === '@'
        || (($text[0] === '+' || $text[0] === '-') && !preg_match('#^[+-][\d\s().,/-]*$#', $text));
    return $risky ? "'" . $text : $text;
}

/* ==========================================================================
   Envoi
   ========================================================================== */

/** Envoie l'export ($format : xlsx ou csv) et termine le script. */
function export_send(string $format, string $basename, string $sheetTitle, array $columns, iterable $rows): never
{
    $stamp = date('Ymd-His');
    if ($format === 'xlsx') {
        $file = xlsx_build($sheetTitle, $columns, $rows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $basename . '-' . $stamp . '.xlsx"');
        header('Content-Length: ' . strlen($file));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $file;
        exit;
    }
    csv_send($basename . '-' . $stamp . '.csv', $columns, $rows);
}

/**
 * CSV prêt pour Excel en ouverture directe (double-clic), quels que soient les réglages régionaux du poste :
 *   1re ligne « sep=; » : Excel découpe les colonnes au point-virgule même sur un poste réglé en anglais
 *                         (où le séparateur par défaut est la virgule) ; la ligne n'apparaît pas dans la feuille.
 *   encodage Windows-1252 : en présence de « sep=; », Excel IGNORE le BOM UTF-8 et lit le fichier dans l'encodage
 *                         Windows — un fichier UTF-8 afficherait « RÃ´le », « HÃ©lÃ¨ne » (vérifié avec Excel 16).
 *                         Windows-1252 contient tous les caractères français (é è à ç ô œ « » — € °…) ; les rares
 *                         caractères absents sont remplacés (→ devient ->, sinon « ? »).
 * Puis : séparateur « ; », CHAQUE champ entre guillemets (guillemets internes doublés), fins de ligne CRLF, texte
 * sur une seule ligne. Montants en chiffres seuls avec virgule décimale (1234567,50) : avec des espaces de milliers,
 * Excel les lirait comme du texte et ne pourrait plus les additionner — la présentation à séparateurs de milliers
 * est celle du fichier Excel (.xlsx). Dates JJ/MM/AAAA, heures HH:MM.
 */
function csv_send(string $filename, array $columns, iterable $rows): never
{
    header('Content-Type: text/csv; charset=windows-1252');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fwrite($out, "sep=;\r\n");
    $line = function (array $cells) use ($out): void {
        fwrite($out, csv_windows1252(implode(';', array_map(fn ($c) => '"' . str_replace('"', '""', $c) . '"', $cells))) . "\r\n");
    };
    $line(array_map(fn ($c) => export_clean_text($c['label']), $columns));
    foreach ($rows as $row) {
        $cells = [];
        foreach (array_values($columns) as $i => $col) {
            $cells[] = csv_cell($row[$i] ?? null, $col);
        }
        $line($cells);
    }
    fclose($out);
    exit;
}

/** Texte UTF-8 → Windows-1252 (caractères absents de cet encodage : équivalent proche, sinon « ? »). */
function csv_windows1252(string $text): string
{
    $text = strtr($text, ['→' => '->', '←' => '<-', '↔' => '<->', '⇒' => '=>', '≤' => '<=', '≥' => '>=', '≠' => '<>', '✓' => 'v',
                          "\u{202F}" => "\u{00A0}", "\u{2011}" => '-', "\u{2212}" => '-', "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2009}" => ' ']);
    $previous = mb_substitute_character();
    mb_substitute_character(0x3F);   // « ? »
    $converted = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    mb_substitute_character($previous);
    return $converted;
}

/** Valeur d'une cellule CSV selon le type de colonne. */
function csv_cell(mixed $value, array $col): string
{
    [$value, $col] = export_cell_decimals($value, $col);
    $type = $col['type'] ?? 'text';
    if (in_array($type, ['amount', 'int'], true)) {
        $n = export_number($value);
        return $n === null ? '' : number_format($n, $type === 'int' ? 0 : ($col['decimals'] ?? 2), ',', '');
    }
    if (in_array($type, ['date', 'time', 'datetime'], true)) {
        $t = $value ? strtotime((string) $value) : false;
        return $t ? date(['date' => 'd/m/Y', 'time' => 'H:i', 'datetime' => 'd/m/Y H:i'][$type], $t) : '';
    }
    return export_safe_text(export_clean_text($value));
}

/* ==========================================================================
   Fichier Excel natif (.xlsx) — Office Open XML minimal, sans extension PHP « zip »
   Une feuille : ligne d'en-tête figée, filtres automatiques, largeurs de colonnes, formats de nombres et de dates.
   ========================================================================== */

/** Styles (index cellXfs) : 0 texte, 1 en-tête, 2..6 montants 0 à 6 décimales… voir xlsx_styles(). */
const XLSX_STYLE = ['text' => 0, 'header' => 1, 'int' => 2, 'amount0' => 2, 'amount2' => 3, 'amount3' => 4, 'amount6' => 5,
                    'date' => 6, 'time' => 7, 'datetime' => 8];

function xlsx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        // Séparateur de milliers affiché selon le poste (espace en français, virgule en anglais)
        . '<numFmts count="5"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.000"/>'
        . '<numFmt numFmtId="166" formatCode="#,##0.000000"/><numFmt numFmtId="167" formatCode="dd/mm/yyyy"/>'
        . '<numFmt numFmtId="168" formatCode="dd/mm/yyyy hh:mm"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0051A5"/><bgColor indexed="64"/></patternFill></fill></fills>'   // bleu de la charte
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left/><right/><top/><bottom style="thin"><color rgb="FFD9DDE3"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'                                            // 0 texte
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'   // 1 en-tête
        . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                    // 2 #,##0
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                  // 3 #,##0.00
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                  // 4 #,##0.000
        . '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'                  // 5 taux
        . '<xf numFmtId="167" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>'   // 6 date
        . '<xf numFmtId="20" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>'    // 7 heure
        . '<xf numFmtId="168" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>'   // 8 date et heure
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

/** Référence de colonne Excel (0 → A, 26 → AA). */
function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** Cellule XML ; null si vide. */
function xlsx_cell(string $ref, mixed $value, array $col): ?string
{
    [$value, $col] = export_cell_decimals($value, $col);
    $type = $col['type'] ?? 'text';
    if (in_array($type, ['amount', 'int'], true)) {
        $n = export_number($value);
        if ($n === null) {
            return null;
        }
        $style = $type === 'int' ? XLSX_STYLE['int'] : (XLSX_STYLE['amount' . min(6, max(0, (int) ($col['decimals'] ?? 2)))] ?? XLSX_STYLE['amount2']);
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . rtrim(rtrim(sprintf('%.6F', $n), '0'), '.') . '</v></c>';
    }
    if (in_array($type, ['date', 'time', 'datetime'], true)) {
        $t = $value ? date_create_immutable((string) $value, new DateTimeZone('UTC')) : false;
        if (!$t) {
            return null;
        }
        // Numéro de série Excel : jours depuis le 30/12/1899 (heure locale enregistrée, sans conversion de fuseau)
        $serial = ($t->getTimestamp() / 86400) + 25569;
        $serial = $type === 'date' ? floor($serial) : ($type === 'time' ? $serial - floor($serial) : $serial);
        return '<c r="' . $ref . '" s="' . XLSX_STYLE[$type] . '"><v>' . round($serial, 8) . '</v></c>';
    }
    $text = export_clean_text($value);   // texte inline : jamais interprété comme une formule
    if ($text === '') {
        return null;
    }
    return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
}

/** Classeur .xlsx complet (contenu binaire). */
function xlsx_build(string $sheetTitle, array $columns, iterable $rows): string
{
    $columns = array_values($columns);
    $last    = xlsx_col(count($columns) - 1);
    $sheet   = fopen('php://temp/maxmemory:8388608', 'w+');   // au-delà de 8 Mo : fichier temporaire
    $n       = 1;
    $cols = '';
    foreach ($columns as $i => $c) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) ($c['width'] ?? 16) . '" customWidth="1"/>';
    }
    fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/><cols>' . $cols . '</cols><sheetData>');
    $head = '<row r="1" ht="30" customHeight="1">';
    foreach ($columns as $i => $c) {
        $head .= '<c r="' . xlsx_col($i) . '1" s="1" t="inlineStr"><is><t>' . htmlspecialchars($c['label'], ENT_XML1, 'UTF-8') . '</t></is></c>';
    }
    fwrite($sheet, $head . '</row>');
    foreach ($rows as $row) {
        $n++;
        $xml = '<row r="' . $n . '">';
        foreach ($columns as $i => $c) {
            $xml .= xlsx_cell(xlsx_col($i) . $n, $row[$i] ?? null, $c) ?? '';
        }
        fwrite($sheet, $xml . '</row>');
    }
    fwrite($sheet, '</sheetData><autoFilter ref="A1:' . $last . $n . '"/>'
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>');
    rewind($sheet);
    $sheetXml = stream_get_contents($sheet);
    fclose($sheet);

    $title = mb_substr(preg_replace('#[\\\\/?*\[\]:]#u', ' ', $sheetTitle), 0, 31);
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . htmlspecialchars($sheetTitle, ENT_XML1, 'UTF-8') . '</dc:title><dc:creator>' . htmlspecialchars(GTC_NAME, ENT_XML1, 'UTF-8') . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", $title) . '\'!$A$1:$' . $last . '$' . $n . '</definedName>'
            . '<definedName name="_xlnm.Print_Titles" localSheetId="0">\'' . str_replace("'", "''", $title) . '\'!$1:$1</definedName></definedNames></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => xlsx_styles(),
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ];
    return zip_build($files);
}

/** Archive ZIP (compression « deflate ») construite en mémoire, sans l'extension PHP zip. */
function zip_build(array $files): string
{
    $data = '';
    $central = '';
    $time = (int) date('G') << 11 | (int) date('i') << 5 | intdiv((int) date('s'), 2);   // date et heure au format MS-DOS
    $day  = ((int) date('Y') - 1980) << 9 | (int) date('n') << 5 | (int) date('j');
    foreach ($files as $name => $content) {
        $crc  = crc32($content);
        $comp = gzdeflate($content, 6);
        $head = pack('vvvvvVVVvv', 20, 0x0800, 8, $time, $day, $crc, strlen($comp), strlen($content), strlen($name), 0);   // 0x0800 : noms en UTF-8
        $central .= pack('V', 0x02014b50) . pack('v', 20) . $head . pack('vvvVV', 0, 0, 0, 0, strlen($data)) . $name;
        $data .= pack('V', 0x04034b50) . $head . $name . $comp;
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
}
