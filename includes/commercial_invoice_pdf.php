<?php
/**
 * PDF natif d'une facture commerciale (facture, proforma, devis) sur le papier à en-tête officiel —
 * téléchargement depuis dashboard/pdf.php (ordinateur, Android, iPhone), sans dépendance externe.
 *
 * Même présentation que dashboard/print-commercial-invoice.php : papier à en-tête répété sur chaque page,
 * tableau façon Excel (colonnes, contenus et alignements du mini-tableur), totaux, montant en lettres, signatures.
 * Moteur : GtcPdf (includes/print-direct.php) ; images JPEG : assets/img/letterhead/pdf-*.jpg.
 */
require_once __DIR__ . '/print-direct.php';
require_once __DIR__ . '/commercial_invoices.php';

const CIPDF_LEFT   = 18.0;    // marges de la zone d'écriture (mm)
const CIPDF_WIDTH  = 174.0;
const CIPDF_TOP    = 54.0;    // sous le papier à en-tête
const CIPDF_BOTTOM = 248.0;   // au-dessus des rayures du pied de page

const CIPDF_NAVY   = [15, 44, 89];
const CIPDF_INK    = [17, 24, 39];
const CIPDF_MUTED  = [91, 101, 117];
const CIPDF_GRID   = [203, 213, 225];

/** Papier à en-tête officiel : bandeau, secteurs d'activité, logo, pied de page (cotes du PDF officiel). */
function cipdf_letterhead(GtcPdf $pdf, array $img): void
{
    if ($img['header']) {
        $pdf->image($img['header'], 0, 0, 210, 22);
    }
    $pdf->lineColor(21.8, 22.7, 21.8, 47.8, 0.35, [0, 150, 71]);
    foreach (array_chunk(GTC_ACTIVITIES, 2) as $i => $pair) {
        $y = 30.2 + $i * 4.55;
        $x = 25.4;
        foreach ($pair as $j => $label) {
            if ($j > 0) {
                $pdf->textColor($x + 1.4, $y, '·', 9, 'bold', 'left', [0, 150, 71]);
                $x += 1.4 + $pdf->width('·', 9, true) + 1.4;
            }
            $pdf->textColor($x, $y, $label, 8.5, 'regular', 'left', [58, 74, 99]);
            $x += $pdf->width($label, 8.5);
        }
    }
    if ($img['logo']) {
        $pdf->image($img['logo'], 110.7, 25.5, 74, 74 * $img['logo'][2] / $img['logo'][1]);
    }
    if ($img['stripes']) {
        $pdf->image($img['stripes'], 0, 252.7, 210, 12.2);
    }
    $pdf->rectColor(0, 264.9, 210, 27, [16, 52, 76]);
    $lines = [
        'N° de contribuable : ' . GTC_NIU . '   |   RCCM : ' . GTC_RCCM . '   |   N° matricule CNPS : ' . GTC_CNPS,
        'Banque : ' . GTC_BANK['name'] . '   |   Code banque : ' . GTC_BANK['bank_code'] . '   |   Code guichet : ' . GTC_BANK['branch_code'] . '   |   N° compte : ' . GTC_BANK['account'],
        GTC_PO_BOX . '   |   Mob. : ' . GTC_PHONE_2 . '   |   Bur. : ' . GTC_PHONE,
        'Site web : ' . GTC_OFFICIAL_WEBSITE . '   |   Email : ' . GTC_EMAIL,
    ];
    foreach ($lines as $i => $line) {
        $pdf->textColor(105, 272.6 + $i * 4.55, $line, 7.5, 'regular', 'center', [255, 255, 255]);
    }
    $pdf->rectColor(0, 291.9, 210, 5.1, [0, 81, 165]);
}

/** Lignes d'un texte de cellule (retours à la ligne conservés, découpe à la largeur). */
function cipdf_lines(GtcPdf $pdf, string $text, float $size, bool $bold, float $max): array
{
    $out = [];
    foreach (preg_split('/\R/u', trim($text)) as $para) {
        $out = array_merge($out, $para === '' ? [''] : $pdf->wrap($para, $size, $bold, $max));
    }
    return $out ?: [''];
}

/** Abscisse d'un texte aligné dans une cellule. */
function cipdf_x(float $x, float $w, string $align, float $pad = 2.8): float
{
    return $align === 'center' ? $x + $w / 2 : ($align === 'right' ? $x + $w - $pad : $x + $pad);
}

/** PDF complet de la facture. $printedBy : nom affiché dans « Document établi par ». */
function cinv_pdf(array $inv, string $printedBy): string
{
    $pdf  = new GtcPdf(210, 297);
    $dir  = __DIR__ . '/../assets/img/letterhead/';
    $img  = ['header' => GtcPdf::jpegFile($dir . 'pdf-header.jpg'), 'logo' => GtcPdf::jpegFile($dir . 'pdf-logo.jpg'), 'stripes' => GtcPdf::jpegFile($dir . 'pdf-footer-stripes.jpg')];
    $type = CINV_TYPES[$inv['doc_type']] ?? CINV_TYPES['invoice'];
    $cur  = $inv['currency'];
    $curL = currency_label($cur);
    $L    = CIPDF_LEFT;
    $R    = CIPDF_LEFT + CIPDF_WIDTH;

    cipdf_letterhead($pdf, $img);
    $newPage = function () use ($pdf, $img): float {
        $pdf->addPage();
        cipdf_letterhead($pdf, $img);
        return CIPDF_TOP;
    };

    /* ---------- Titre, références, client ---------- */
    $y = CIPDF_TOP;
    $pdf->textColor($L, $y + 7, $type['title'], 20, 'bold', 'left', [11, 47, 107]);
    $meta = [['N°', $inv['invoice_number']], ['Date', fdate($inv['issue_date'])]];
    if ($inv['due_date']) {
        $meta[] = [$inv['doc_type'] === 'quote' ? "Valable jusqu'au" : 'Échéance', fdate($inv['due_date'])];
    }
    $meta[] = ['Devise', $curL];
    foreach ($meta as $i => [$k, $v]) {
        $pdf->textColor($L, $y + 15.5 + $i * 5.4, $k, 9.5, 'regular', 'left', CIPDF_MUTED);
        $pdf->textColor($L + 30, $y + 15.5 + $i * 5.4, $v, 9.5, 'bold', 'left', CIPDF_INK);
    }
    if ($inv['status'] !== 'issued') {
        $stamp = mb_strtoupper(CINV_STATUSES[$inv['status']]['label']);
        $color = $inv['status'] === 'paid' ? [4, 120, 87] : [185, 28, 28];
        $sw = $pdf->width($stamp, 11, true) + 6;
        $pdf->rectColor($L + 64, $y + 13, $sw, 8, null, $color, 0.6);
        $pdf->textColor($L + 64 + $sw / 2, $y + 18.6, $stamp, 11, 'bold', 'center', $color);
    }
    $metaH = 15.5 + count($meta) * 5.4;

    // « Facturé à » : cadre avec filet doré à gauche
    $cx = 110;
    $cw = $R - $cx;
    $clientLines = [];
    foreach ([$inv['client_address'], $inv['client_phone'] ? 'Tél. : ' . $inv['client_phone'] : '', $inv['client_email'], $inv['client_niu'] ? 'NIU : ' . $inv['client_niu'] : ''] as $line) {
        if ((string) $line !== '') {
            $clientLines = array_merge($clientLines, $pdf->wrap((string) $line, 9, false, $cw - 8));
        }
    }
    $nameLines = $pdf->wrap($inv['client_name'], 11, true, $cw - 8, 2);
    $boxH = max(30, 10 + count($nameLines) * 5 + count($clientLines) * 4.5 + 4);
    $pdf->rectColor($cx, $y, $cw, $boxH, null, [214, 220, 228], 0.3);
    $pdf->rectColor($cx, $y, 0.9, $boxH, [184, 134, 11]);
    $pdf->textColor($cx + 4.5, $y + 6, $inv['doc_type'] === 'quote' ? 'DEVIS ÉTABLI POUR' : 'FACTURÉ À', 7.5, 'bold', 'left', CIPDF_MUTED);
    $ly = $y + 12;
    foreach ($nameLines as $line) {
        $pdf->textColor($cx + 4.5, $ly, $line, 11, 'bold', 'left', CIPDF_INK);
        $ly += 5;
    }
    foreach ($clientLines as $line) {
        $pdf->textColor($cx + 4.5, $ly, $line, 9, 'regular', 'left', CIPDF_INK);
        $ly += 4.5;
    }
    $y += max($metaH, $boxH) + 7;

    /* ---------- Tableau façon Excel ---------- */
    $columns = $inv['columns'];
    $nW = 12;
    $aW = 34;
    $text = array_values(array_filter($columns, fn ($c) => $c['type'] !== 'amount'));
    // Largeur des colonnes de texte selon leur contenu (au moins 22 mm)
    $weights = [];
    foreach ($text as $c) {
        $len = mb_strlen($c['label']);
        foreach ($inv['items'] as $row) {
            $len = max($len, min(60, mb_strlen((string) ($row[$c['key']] ?? ''))));
        }
        $weights[$c['key']] = max(8, $len);
    }
    $free = CIPDF_WIDTH - $nW - $aW;
    $widths = [];
    foreach ($text as $c) {
        $widths[$c['key']] = max(22, $free * $weights[$c['key']] / array_sum($weights));
    }
    $scale = $free / array_sum($widths);
    foreach ($widths as $k => $w) {
        $widths[$k] = $w * $scale;
    }
    $widths['amount'] = $aW;

    $drawHead = function (float $y) use ($pdf, $columns, $widths, $nW, $L, $curL): float {
        $h = 9;
        $pdf->rectColor($L, $y, CIPDF_WIDTH, $h, CIPDF_NAVY);
        $pdf->textColor($L + $nW / 2, $y + 5.9, 'N°', 8, 'bold', 'center', [255, 255, 255]);
        $x = $L + $nW;
        foreach ($columns as $c) {
            $w = $widths[$c['key']];
            $label = mb_strtoupper($c['label']) . ($c['type'] === 'amount' ? ' (' . $curL . ')' : '');
            $label = $pdf->wrap($label, 8, true, $w - 5, 1)[0];
            $pdf->textColor(cipdf_x($x, $w, $c['align']), $y + 5.9, $label, 8, 'bold', $c['align'], [255, 255, 255]);
            $x += $w;
        }
        $pdf->lineColor($L, $y + $h, $L + CIPDF_WIDTH, $y + $h, 0.5, [0, 0, 0]);
        return $y + $h;
    };

    $y = $drawHead($y);
    foreach ($inv['items'] as $i => $row) {
        $note = $row['amount'] === null;
        $cells = [];
        $max = 1;
        foreach ($columns as $c) {
            if ($c['type'] === 'amount') {
                $cells[$c['key']] = [$row['amount'] === null ? '' : cinv_amount((float) $row['amount'], $cur)];
            } else {
                $cells[$c['key']] = cipdf_lines($pdf, (string) ($row[$c['key']] ?? ''), 9, $note, $widths[$c['key']] - 5.6);
            }
            $max = max($max, count($cells[$c['key']]));
        }
        $h = $max * 4.3 + 4.4;
        if ($y + $h > CIPDF_BOTTOM) {
            $y = $drawHead($newPage());
        }
        $fill = $note ? [238, 243, 250] : ($i % 2 === 1 ? [248, 250, 252] : null);
        if ($fill) {
            $pdf->rectColor($L, $y, CIPDF_WIDTH, $h, $fill);
        }
        $pdf->rectColor($L, $y, $nW, $h, null, CIPDF_GRID, 0.25);
        $pdf->textColor($L + $nW / 2, $y + 5.6, (string) ($i + 1), 9, $note ? 'bold' : 'regular', 'center', $note ? CIPDF_NAVY : CIPDF_MUTED);
        $x = $L + $nW;
        foreach ($columns as $c) {
            $w = $widths[$c['key']];
            $pdf->rectColor($x, $y, $w, $h, null, CIPDF_GRID, 0.25);
            $align = cinv_cell_align($row, $c);
            $bold = $note || $c['type'] === 'amount';
            foreach ($cells[$c['key']] as $k => $line) {
                $pdf->textColor(cipdf_x($x, $w, $align), $y + 5.6 + $k * 4.3, $line, 9, $bold ? 'bold' : 'regular', $align, $note ? CIPDF_NAVY : CIPDF_INK);
            }
            $x += $w;
        }
        $y += $h;
    }

    /* ---------- Totaux (sous la colonne des montants) et montant en lettres ---------- */
    $rows = (float) $inv['tax_rate'] > 0
        ? [['Total HT (' . $curL . ')', (float) $inv['subtotal'], false],
           ['TVA ' . str_replace('.', ',', rtrim(rtrim(number_format((float) $inv['tax_rate'], 2, '.', ''), '0'), '.')) . ' %', (float) $inv['tax_amount'], false],
           ['Total TTC (' . $curL . ')', (float) $inv['total'], true]]
        : [['Total (' . $curL . ')', (float) $inv['total'], true]];
    $words = amount_in_words((float) $inv['total'], $cur);
    $wordsLines = $pdf->wrap($words, 9.5, true, 92);
    $notesLines = $inv['notes'] ? cipdf_lines($pdf, 'Notes : ' . $inv['notes'], 8, false, 92) : [];
    $y += 3;   // espace entre le tableau et les totaux (double encadrement du total)
    $sumH = max(count($rows) * 8.6 + 2, 9 + count($wordsLines) * 4.6 + ($notesLines ? 3 + count($notesLines) * 3.8 : 0));
    if ($y + $sumH > CIPDF_BOTTOM) {
        $y = $newPage();
    }
    $tx = $R - $aW - 40;
    foreach ($rows as $k => [$label, $value, $grand]) {
        $ry = $y + $k * 8.6;
        if ($grand) {
            $pdf->rectColor($tx - 0.9, $ry - 0.9, 74 + 1.8, 8.6 + 1.8, null, CIPDF_NAVY, 0.35);   // double encadrement
            $pdf->rectColor($tx, $ry, 74, 8.6, CIPDF_NAVY);
            $pdf->textColor($tx + 3, $ry + 5.7, $label, 10, 'bold', 'left', [255, 255, 255]);
            $pdf->textColor($R - 2.8, $ry + 5.7, cinv_amount($value, $cur), 10.5, 'bold', 'right', [255, 255, 255]);
        } else {
            $pdf->rectColor($tx, $ry, 40, 8.6, [241, 245, 249], CIPDF_GRID, 0.25);
            $pdf->rectColor($tx + 40, $ry, $aW, 8.6, null, CIPDF_GRID, 0.25);
            $pdf->textColor($tx + 3, $ry + 5.6, $label, 9, 'bold', 'left', CIPDF_INK);
            $pdf->textColor($R - 2.8, $ry + 5.6, cinv_amount($value, $cur), 9, 'bold', 'right', CIPDF_INK);
        }
    }
    $pdf->textColor($L, $y + 5, $type['stop'] . ' :', 8, 'regular', 'left', CIPDF_MUTED);
    $wy = $y + 10;
    foreach ($wordsLines as $line) {
        $pdf->textColor($L, $wy, $line, 9.5, 'bolditalic', 'left', CIPDF_INK);
        $wy += 4.6;
    }
    $wy += 2;
    foreach ($notesLines as $line) {
        $pdf->textColor($L, $wy, $line, 8, 'regular', 'left', CIPDF_MUTED);
        $wy += 3.8;
    }
    $y += $sumH + 9;

    /* ---------- Signatures ---------- */
    if ($y + 40 > CIPDF_BOTTOM) {
        $y = $newPage();
    }
    $half = (CIPDF_WIDTH - 10) / 2;
    $company = mb_strtoupper(setting('company_name', GTC_NAME));
    $pdf->textColor($L, $y + 3, 'LE CLIENT', 8, 'bold', 'left', CIPDF_MUTED);
    $pdf->lineColor($L, $y + 28, $L + $half, $y + 28, 0.3, [154, 164, 178], [1.2, 1]);
    $sx = $L + $half + 10;
    $pdf->textColor($sx, $y + 3, $pdf->wrap('POUR ' . $company . ' — LA DIRECTION', 8, true, $half, 1)[0], 8, 'bold', 'left', CIPDF_MUTED);
    $pdf->rectColor($sx, $y + 6, $half, 23, null, [154, 164, 178], 0.3, [1.2, 1]);
    $pdf->textColor($sx + $half / 2, $y + 26.5, 'Cachet et signature', 7, 'regular', 'center', [154, 164, 178]);
    $pdf->textColor($R, $y + 36, 'Document établi le ' . fdate($inv['created_at'] ?: $inv['issue_date']) . ' par ' . ($inv['created_by_name'] ?: $printedBy), 7, 'regular', 'right', CIPDF_MUTED);

    // Numéros de page (documents de plusieurs pages)
    $total = $pdf->pageCount();
    if ($total > 1) {
        for ($p = 0; $p < $total; $p++) {
            $pdf->setPage($p);
            $pdf->textColor($R, CIPDF_BOTTOM + 3.5, 'Page ' . ($p + 1) . ' / ' . $total, 7.5, 'regular', 'right', CIPDF_MUTED);
        }
    }
    return $pdf->output($type['label'] . ' ' . $inv['invoice_number'] . ' — ' . $inv['client_name']);
}
