<?php
/**
 * PDF natif d'un courrier officiel sur le papier à en-tête de GTC — téléchargement depuis dashboard/pdf.php
 * (ordinateur, Android, iPhone), prêt à être envoyé par e-mail ou WhatsApp, sans dépendance externe.
 *
 * Même présentation que dashboard/print-courrier.php : papier à en-tête répété sur chaque page (cipdf_letterhead),
 * références et date, destinataire à droite, objet, corps en texte enrichi (gras, italique, souligné, listes,
 * alignements ; justifié par défaut), formule de politesse et signature toujours ensemble, numéros de page.
 * Police : Helvetica (chasse identique à Arial, police de la version imprimée).
 */
require_once __DIR__ . '/commercial_invoice_pdf.php';   // GtcPdf, papier à en-tête, marges CIPDF_*
require_once __DIR__ . '/courriers.php';

const LTPDF_SIZE   = 10.5;   // corps du texte (pt)
const LTPDF_LINE   = 5.2;    // interligne (mm)
const LTPDF_INDENT = 7.0;    // retrait des listes (mm)

/** Police GtcPdf d'un fragment de texte. */
function ltpdf_font(array $f): string
{
    return $f['b'] && $f['i'] ? 'bolditalic' : ($f['b'] ? 'bold' : ($f['i'] ? 'italic' : 'regular'));
}

/** Largeur d'un mot (liste de fragments de styles différents). */
function ltpdf_word_width(GtcPdf $pdf, array $word): float
{
    return array_sum(array_map(fn ($f) => $pdf->width($f['t'], LTPDF_SIZE, $f['b']), $word));
}

/**
 * Découpe des « runs » (courrier_blocks) en lignes de largeur $max :
 * [['words' => [[fragment, …], …], 'forced' => bool], …] — forced : fin de bloc ou retour à la ligne voulu
 * (ligne non justifiée).
 */
function ltpdf_lines(GtcPdf $pdf, array $runs, float $max): array
{
    // 1. Mots (un mot peut mêler plusieurs styles : « <b>Bon</b>jour ») et retours à la ligne
    $tokens = [];
    $word = [];
    foreach ($runs as $r) {
        if (isset($r['br'])) {
            if ($word) { $tokens[] = $word; $word = []; }
            $tokens[] = 'BR';
            continue;
        }
        foreach (preg_split('/( +)/u', $r['t'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
            if (trim($piece) === '') {
                if ($word) { $tokens[] = $word; $word = []; }
            } else {
                $word[] = ['t' => $piece, 'b' => $r['b'], 'i' => $r['i'], 'u' => $r['u']];
            }
        }
    }
    if ($word) {
        $tokens[] = $word;
    }

    // 2. Lignes
    $space = $pdf->width(' ', LTPDF_SIZE);
    $lines = [];
    $line = [];
    $width = 0.0;
    foreach ($tokens as $tok) {
        if ($tok === 'BR') {
            $lines[] = ['words' => $line, 'forced' => true];
            $line = [];
            $width = 0.0;
            continue;
        }
        $w = ltpdf_word_width($pdf, $tok);
        if ($w > $max) {
            // Mot plus large que la ligne (lien, référence) : coupé caractère par caractère
            if ($line) { $lines[] = ['words' => $line, 'forced' => false]; $line = []; $width = 0.0; }
            $chunk = [];
            foreach ($tok as $f) {
                foreach (mb_str_split($f['t']) as $ch) {
                    $try = array_merge($chunk, [['t' => $ch] + $f]);
                    if ($chunk && ltpdf_word_width($pdf, $try) > $max) {
                        $lines[] = ['words' => [$chunk], 'forced' => false];
                        $chunk = [['t' => $ch] + $f];
                    } else {
                        $chunk = $try;
                    }
                }
            }
            $line = [$chunk];
            $width = ltpdf_word_width($pdf, $chunk);
            continue;
        }
        if ($line && $width + $space + $w > $max) {
            $lines[] = ['words' => $line, 'forced' => false];
            $line = [];
            $width = 0.0;
        }
        $width += ($line ? $space : 0) + $w;
        $line[] = $tok;
    }
    if ($line || !$lines) {
        $lines[] = ['words' => $line, 'forced' => true];
    } else {
        $lines[count($lines) - 1]['forced'] = true;
    }
    return $lines;
}

/** Dessine une ligne : alignement (justifié par défaut, sauf dernière ligne / retour à la ligne), styles, soulignement. */
function ltpdf_draw_line(GtcPdf $pdf, array $line, float $x, float $y, float $max, string $align): void
{
    $words = $line['words'];
    if (!$words) {
        return;
    }
    $space  = $pdf->width(' ', LTPDF_SIZE);
    $widths = array_map(fn ($w) => ltpdf_word_width($pdf, $w), $words);
    $used   = array_sum($widths) + $space * (count($words) - 1);
    $gap    = $space;
    if ($align === 'justify' && !$line['forced'] && count($words) > 1) {
        $gap = $space + ($max - $used) / (count($words) - 1);
    } elseif ($align === 'center') {
        $x += ($max - $used) / 2;
    } elseif ($align === 'right') {
        $x += $max - $used;
    }
    foreach ($words as $k => $word) {
        foreach ($word as $f) {
            $fw = $pdf->width($f['t'], LTPDF_SIZE, $f['b']);
            $pdf->textColor($x, $y, $f['t'], LTPDF_SIZE, ltpdf_font($f), 'left', CIPDF_INK);
            if ($f['u']) {
                $pdf->lineColor($x, $y + 0.9, $x + $fw, $y + 0.9, 0.22, CIPDF_INK);
            }
            $x += $fw;
        }
        // Espace souligné entre deux mots soulignés
        $next = $words[$k + 1] ?? null;
        if ($next && end($word)['u'] && $next[0]['u']) {
            $pdf->lineColor($x, $y + 0.9, $x + $gap, $y + 0.9, 0.22, CIPDF_INK);
        }
        $x += $gap;
    }
}

/** Texte brut (appel, formule de politesse) → runs, retours à la ligne conservés. */
function ltpdf_plain_runs(string $text): array
{
    $runs = [];
    foreach (preg_split('/\n/u', trim($text)) as $i => $line) {
        if ($i > 0) {
            $runs[] = ['br' => true];
        }
        $runs[] = ['t' => trim($line), 'b' => false, 'i' => false, 'u' => false];
    }
    return $runs;
}

/** PDF complet du courrier. */
function courrier_pdf(array $c, string $companyName): string
{
    $pdf = new GtcPdf(210, 297);
    $dir = __DIR__ . '/../assets/img/letterhead/';
    $img = ['header' => GtcPdf::jpegFile($dir . 'pdf-header.jpg'), 'logo' => GtcPdf::jpegFile($dir . 'pdf-logo.jpg'), 'stripes' => GtcPdf::jpegFile($dir . 'pdf-footer-stripes.jpg')];
    $L   = CIPDF_LEFT;
    $W   = CIPDF_WIDTH;
    $R   = $L + $W;

    // Variables de publipostage remplacées dans l'objet, l'appel, le corps et la formule de politesse
    $subject    = courrier_merge($c['subject'], $c, false);
    $salutation = courrier_merge($c['salutation'], $c, false);
    $closing    = courrier_merge($c['closing'], $c, false);
    $blocks     = courrier_blocks(courrier_merge(courrier_body_html($c['body']), $c, true));

    cipdf_letterhead($pdf, $img);
    $y = CIPDF_TOP;
    // Saut de page si $need mm ne tiennent plus au-dessus du pied de page
    $room = function (float $need) use ($pdf, $img, &$y): void {
        if ($y + $need > CIPDF_BOTTOM) {
            $pdf->addPage();
            cipdf_letterhead($pdf, $img);
            $y = CIPDF_TOP;
        }
    };

    /* ---------- Références (gauche) · lieu et date (droite) ---------- */
    $y += 5;
    $pdf->textColor($L, $y, 'N/Réf. :', 9.5, 'regular', 'left', CIPDF_MUTED);
    $pdf->textColor($L + $pdf->width('N/Réf. : ', 9.5), $y, $c['reference'], 9.5, 'bold', 'left', [11, 47, 107]);
    $pdf->textColor($R, $y, $c['place'] . ', le ' . courrier_long_date($c['letter_date']), LTPDF_SIZE, 'regular', 'right', CIPDF_INK);

    /* ---------- Destinataire (à droite) ---------- */
    $y += 11;
    $toX = $R - 82;
    foreach (courrier_recipient_lines($c) as $i => $line) {
        foreach ($pdf->wrap($line, LTPDF_SIZE, $i === 0, 82) as $part) {
            $pdf->textColor($toX, $y, $part, LTPDF_SIZE, $i === 0 ? 'bold' : 'regular', 'left', CIPDF_INK);
            $y += 5;
        }
    }

    /* ---------- Objet et pièces jointes ---------- */
    $y += 7;
    $label = 'Objet :';
    $lw    = $pdf->width($label . ' ', LTPDF_SIZE, true);
    $pdf->textColor($L, $y, $label, LTPDF_SIZE, 'bold', 'left', CIPDF_INK);
    $pdf->lineColor($L, $y + 0.9, $L + $pdf->width($label, LTPDF_SIZE, true), $y + 0.9, 0.25, CIPDF_INK);
    foreach ($pdf->wrap($subject, LTPDF_SIZE, true, $W - $lw) as $part) {
        $pdf->textColor($L + $lw, $y, $part, LTPDF_SIZE, 'bold', 'left', CIPDF_INK);
        $y += LTPDF_LINE;
    }
    if ($c['attachments'] !== '') {
        $pj = 'P.J. : ';
        foreach ($pdf->wrap($c['attachments'], LTPDF_SIZE, false, $W - $pdf->width($pj, LTPDF_SIZE)) as $i => $part) {
            if ($i === 0) {
                $pdf->textColor($L, $y, $pj, LTPDF_SIZE, 'regular', 'left', CIPDF_INK);
            }
            $pdf->textColor($L + $pdf->width($pj, LTPDF_SIZE), $y, $part, LTPDF_SIZE, 'regular', 'left', CIPDF_INK);
            $y += LTPDF_LINE;
        }
    }

    /* ---------- Appel ---------- */
    if ($salutation !== '') {
        $y += 6;
        $room(LTPDF_LINE * 3);   // l'appel reste avec le début du corps
        $pdf->textColor($L, $y, $salutation, LTPDF_SIZE, 'regular', 'left', CIPDF_INK);
        $y += LTPDF_LINE;
    }

    /* ---------- Corps en texte enrichi ---------- */
    $y += 3;
    $number = 0;
    foreach ($blocks as $b => $block) {
        $inList = $block['list'] !== null;
        $number = $inList && ($b > 0 && $blocks[$b - 1]['list'] === $block['list']) ? $number + 1 : 1;
        $x      = $L + ($inList ? LTPDF_INDENT : 0);
        $max    = $W - ($inList ? LTPDF_INDENT : 0);
        $lines  = ltpdf_lines($pdf, $block['runs'], $max);
        foreach ($lines as $i => $line) {
            // Jamais une ligne isolée en bas de page : 2 lignes au moins (ou le bloc entier s'il est court)
            $room($i === 0 ? LTPDF_LINE * min(2, count($lines)) : LTPDF_LINE);
            if ($i === 0 && $inList) {
                $marker = $block['list'] === 'ol' ? $number . '.' : '•';
                $pdf->textColor($x - 2, $y, $marker, LTPDF_SIZE, 'regular', 'right', CIPDF_INK);
            }
            ltpdf_draw_line($pdf, $line, $x, $y, $max, $block['align'] ?? 'justify');
            $y += LTPDF_LINE;
        }
        $nextInSameList = isset($blocks[$b + 1]) && $inList && $blocks[$b + 1]['list'] === $block['list'];
        $y += $nextInSameList ? 1.2 : 3.2;
    }

    /* ---------- Fin de lettre : formule de politesse + signature, toujours sur la même page ---------- */
    $closingLines = $closing !== '' ? ltpdf_lines($pdf, ltpdf_plain_runs($closing), $W) : [];
    $room(1 + count($closingLines) * LTPDF_LINE + 42);
    if ($closingLines) {
        $y += 1;
        foreach ($closingLines as $line) {
            ltpdf_draw_line($pdf, $line, $L, $y, $W, 'justify');
            $y += LTPDF_LINE;
        }
    }

    /* ---------- Signature (à droite) ---------- */
    $cx = $R - 38;
    $y += 5;
    $pdf->textColor($cx, $y, 'Pour ' . $companyName . ',', 9.5, 'regular', 'center', CIPDF_MUTED);
    if ($c['signatory_title'] !== '') {
        $y += 5.5;
        $pdf->textColor($cx, $y, $c['signatory_title'], LTPDF_SIZE, 'bold', 'center', CIPDF_INK);
    }
    $y += 25;   // cachet et signature
    $pdf->textColor($cx, $y, $c['signatory_name'], LTPDF_SIZE, 'bold', 'center', CIPDF_INK);
    $nw = $pdf->width($c['signatory_name'], LTPDF_SIZE, true);
    $pdf->lineColor($cx - $nw / 2, $y + 0.9, $cx + $nw / 2, $y + 0.9, 0.25, CIPDF_INK);

    // Numéros de page (courrier de plusieurs pages)
    $pages = $pdf->pageCount();
    if ($pages > 1) {
        for ($p = 0; $p < $pages; $p++) {
            $pdf->setPage($p);
            $pdf->textColor($R, CIPDF_BOTTOM + 3, $c['reference'] . ' — page ' . ($p + 1) . '/' . $pages, 8, 'regular', 'right', CIPDF_MUTED);
        }
    }

    return $pdf->output('Courrier ' . $c['reference'] . ' — ' . $subject);
}
