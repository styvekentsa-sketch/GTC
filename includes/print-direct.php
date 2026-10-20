<?php
/**
 * Impression directe d'une facture sur une imprimante réseau (sans boîte de dialogue du navigateur).
 *
 * Le SERVEUR prépare le document et l'envoie au périphérique choisi :
 *   - imprimante ticket 80 mm   → commandes ESC/POS (2 exemplaires, ligne de découpe, coupe automatique)
 *                                 par le port RAW 9100 ;
 *   - imprimante A4 / A5        → PDF (A4 : exemplaire client + exemplaire caisse séparés par la ligne
 *                                 de découpe ; A5 : exemplaire unique) par RAW 9100 ou IPP 631.
 * Les imprimantes de bureau doivent accepter le PDF en impression directe (cas de la plupart des
 * laser récentes). Le serveur doit pouvoir joindre l'imprimante (serveur à l'agence ou VPN).
 * Aucune bibliothèque externe : générateur PDF et protocole IPP minimalistes ci-dessous.
 */
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/printers.php';

const PRINT_LOGO_FALLBACK = __DIR__ . '/../assets/img/logo-gtc-print.jpg';

/* ==========================================================================
   Données de la facture (communes au PDF et au ticket)
   ========================================================================== */

function print_invoice_data(array $inv, array $user): array
{
    $company = settings_all();
    $legal   = array_filter([GTC_RCCM ? 'RCCM : ' . GTC_RCCM : '']);
    $niu     = company_niu();
    $rows = [
        "Type d'opération"        => OPERATION_TYPES[$inv['operation_type']] ?? $inv['operation_type'],
        'Mode de paiement'        => PAYMENT_METHODS[$inv['payment_method']] ?? $inv['payment_method'],
        'Motif'                   => invoice_reason_text($inv),
    ];

    return [
        'company' => $company['company_name'],
        'lines'   => array_values(array_filter([
            $company['company_address'],
            'Tél. ' . $company['company_phone'] . ($company['company_phone2'] !== '' ? ' · ' . $company['company_phone2'] : ''),
            $niu !== '' ? 'NIU : ' . $niu : '',
            implode(' | ', array_filter([$company['company_email'], $company['company_website']])),
        ])),
        'number'  => $inv['invoice_number'],
        'issued'  => fdate($inv['created_at'] ?: $inv['issue_date'], true),
        'agent'   => $inv['agent_name'],
        'client'  => $inv['client_name'],
        'phone'   => (string) $inv['client_phone'],
        'rows'    => $rows,
        'total'   => money($inv['amount_received'], $inv['target_currency']),
        'legal'   => "Ce document atteste la transaction réalisée à la date indiquée au taux confirmé lors de l'opération. "
                   . $company['company_name'] . ' — ' . $company['company_address'] . '.'
                   . ($legal ? ' ' . implode(' · ', $legal) . '.' : ''),
        'printed' => 'Imprimé le ' . date('d/m/Y à H:i') . ' par ' . $user['name'],
    ];
}

/** Exemplaires à imprimer selon le format : double souche (A4, ticket) ou exemplaire unique (A5). */
function print_copies(string $format): array
{
    return $format === 'a5'
        ? ['' => null]
        : ['client' => 'Exemplaire client', 'caisse' => 'Exemplaire caisse / archive'];
}

function print_thanks(string $copy): string
{
    return $copy === 'caisse'
        ? 'Exemplaire caisse — à classer avec les pièces comptables du jour.'
        : 'Merci pour votre confiance. Gardez ce bordereau comme justificatif.';
}

/* ==========================================================================
   Générateur PDF minimal (polices standard Helvetica, une page, logo JPEG)
   ========================================================================== */

final class GtcPdf
{
    private const K = 72 / 25.4;   // mm → points
    private const W_REGULAR = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584';
    private const W_BOLD    = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584';
    private const SPECIAL = ['—' => 1000, '–' => 556, '·' => 278, '×' => 584, '÷' => 584, '€' => 556, '«' => 556, '»' => 556, '’' => 222, 'œ' => 944, 'Œ' => 1000, 'æ' => 889, 'Æ' => 1000];
    private const BASE = ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ù' => 'u', 'ú' => 'u',
        'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E',
        'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ÿ' => 'Y'];

    private array $ops = [];
    private ?array $image = null;
    private static array $widths = [];

    public function __construct(private float $wMm, private float $hMm)
    {
        if (!self::$widths) {
            self::$widths = [0 => array_map('intval', explode(",", self::W_REGULAR)), 1 => array_map('intval', explode(',', self::W_BOLD))];
        }
    }

    /** Texte UTF-8 → chaîne PDF (Windows-1252) échappée. */
    private static function encode(string $s): string
    {
        $s = str_replace(["\u{202F}", "\u{00A0}", "\u{2009}", "\r", "\n"], ' ', $s);
        $c = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        if ($c === false) {
            $c = preg_replace('/[^\x20-\x7E]/', '?', $s);
        }
        return strtr($c, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function f(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }

    /** Largeur d'un texte en mm. */
    public function width(string $s, float $size, bool $bold = false): float
    {
        $units = 0;
        foreach (mb_str_split(str_replace(["\u{202F}", "\u{00A0}"], ' ', $s)) as $ch) {
            $o = mb_ord($ch);
            if ($o >= 32 && $o <= 126) {
                $units += self::$widths[(int) $bold][$o - 32];
            } elseif (isset(self::SPECIAL[$ch])) {
                $units += self::SPECIAL[$ch];
            } elseif (isset(self::BASE[$ch])) {
                $units += self::$widths[(int) $bold][mb_ord(self::BASE[$ch]) - 32];
            } else {
                $units += 556;
            }
        }
        return $units * $size / 1000 / self::K;
    }

    /** Découpe un texte en lignes de largeur maximale $max (mm). */
    public function wrap(string $s, float $size, bool $bold, float $max, int $maxLines = 99): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($s)) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->width($try, $size, $bold) > $max) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = &$lines[$maxLines - 1];
            while ($last !== '' && $this->width($last . '…', $size, $bold) > $max) {
                $last = mb_substr($last, 0, -1);
            }
            $last .= '…';
        }
        return $lines;
    }

    /** Texte sur la ligne de base $y (mm depuis le haut). $gray : 0 noir → 1 blanc. */
    public function text(float $x, float $y, string $s, float $size, bool $bold = false, string $align = 'left', float $gray = 0): void
    {
        if ($align !== 'left') {
            $w = $this->width($s, $size, $bold);
            $x -= $align === 'right' ? $w : $w / 2;
        }
        $this->ops[] = sprintf('BT /%s %s Tf %s g %s %s Td (%s) Tj ET', $bold ? 'F2' : 'F1', self::f($size), self::f($gray),
            self::f($x * self::K), self::f(($this->hMm - $y) * self::K), self::encode($s));
    }

    /** Ciseaux (police ZapfDingbats, caractère a2). */
    public function scissors(float $x, float $y, float $size, float $gray = 0): void
    {
        $this->ops[] = sprintf('BT /F3 %s Tf %s g %s %s Td (") Tj ET', self::f($size), self::f($gray), self::f($x * self::K), self::f(($this->hMm - $y) * self::K));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $lw = 0.2, float $gray = 0, ?array $dash = null): void
    {
        $this->ops[] = sprintf('q %s G %s w [%s] 0 d %s %s m %s %s l S Q', self::f($gray), self::f($lw * self::K),
            $dash ? implode(' ', array_map(fn ($d) => self::f($d * self::K), $dash)) : '',
            self::f($x1 * self::K), self::f(($this->hMm - $y1) * self::K), self::f($x2 * self::K), self::f(($this->hMm - $y2) * self::K));
    }

    /** Rectangle (haut-gauche en mm) : remplissage et/ou contour en niveaux de gris. */
    public function rect(float $x, float $y, float $w, float $h, ?float $fill = null, ?float $stroke = null, float $lw = 0.2, ?array $dash = null): void
    {
        $op = $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->ops[] = sprintf('q %s%s%s w [%s] 0 d %s %s %s %s re %s Q',
            $fill !== null ? self::f($fill) . ' g ' : '', $stroke !== null ? self::f($stroke) . ' G ' : '', self::f($lw * self::K),
            $dash ? implode(' ', array_map(fn ($d) => self::f($d * self::K), $dash)) : '',
            self::f($x * self::K), self::f(($this->hMm - $y - $h) * self::K), self::f($w * self::K), self::f($h * self::K), $op);
    }

    /** Logo JPEG (le même image peut être placé plusieurs fois). */
    public function image(array $jpeg, float $x, float $y, float $w, float $h): void
    {
        $this->image = $jpeg;
        $this->ops[] = sprintf('q %s 0 0 %s %s %s cm /Im1 Do Q', self::f($w * self::K), self::f($h * self::K),
            self::f($x * self::K), self::f(($this->hMm - $y - $h) * self::K));
    }

    public function output(string $title): string
    {
        $content = gzcompress(implode("\n", $this->ops));
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >>%s >> /Contents 7 0 R >>',
                self::f($this->wMm * self::K), self::f($this->hMm * self::K), $this->image ? ' /XObject << /Im1 9 0 R >>' : ''),
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /ZapfDingbats >>',
            7 => "<< /Length " . strlen($content) . " /Filter /FlateDecode >>\nstream\n{$content}\nendstream",
            8 => '<< /Title (' . self::encode($title) . ') /Producer (GTC Dashboard) >>',
        ];
        if ($this->image) {
            [$data, $w, $h, $channels] = $this->image;
            $objects[9] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8%s /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $w, $h, $channels === 4 ? 'DeviceCMYK' : ($channels === 1 ? 'DeviceGray' : 'DeviceRGB'),
                $channels === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : '', strlen($data), $data);
        }
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($n = 1; $n < $count; $n++) {
            $pdf .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n";
        }
        return $pdf . "trailer\n<< /Size {$count} /Root 1 0 R /Info 8 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}

/** Logo des factures en JPEG : logo téléversé (JPG, ou PNG converti si GD est disponible), sinon logo GTC. */
function print_logo_jpeg(): ?array
{
    $custom = setting('company_logo');
    $file = PRINT_LOGO_FALLBACK;
    if ($custom !== '' && is_file(BRAND_DIR . '/' . basename($custom))) {
        $path = BRAND_DIR . '/' . basename($custom);
        $info = @getimagesize($path);
        if ($info && $info[2] === IMAGETYPE_JPEG) {
            $file = $path;
        } elseif ($info && $info[2] === IMAGETYPE_PNG && function_exists('imagecreatefrompng') && ($src = @imagecreatefrompng($path))) {
            $out = imagecreatetruecolor($info[0], $info[1]);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
            imagecopy($out, $src, 0, 0, 0, 0, $info[0], $info[1]);
            ob_start();
            imagejpeg($out, null, 90);
            return [ob_get_clean(), $info[0], $info[1], 3];
        }
    }
    $info = @getimagesize($file);
    return $info ? [file_get_contents($file), $info[0], $info[1], $info['channels'] ?? 3] : null;
}

/** Dessine un exemplaire dans la zone ($x, $top, $w, $h) en mm. */
function pdf_draw_receipt(GtcPdf $pdf, array $d, ?array $logo, float $x, float $top, float $w, float $h, string $copy, ?string $label): void
{
    $y = $top;
    // En-tête : logo, coordonnées, mention d'exemplaire
    $textX = $x;
    if ($logo) {
        $lh = 15;
        $lw = $lh * $logo[1] / $logo[2];
        $pdf->image($logo, $x, $y, $lw, $lh);
        $textX = $x + $lw + 4;
    }
    $badgeW = $label ? $pdf->width(mb_strtoupper($label), 7, true) + 6 : 0;
    $pdf->text($textX, $y + 4.5, $d['company'], 12, true);
    foreach ($d['lines'] as $i => $line) {
        $pdf->text($textX, $y + 8.6 + $i * 3.4, $line, 7.5, false, 'left', 0.3);
    }
    if ($label) {
        $dark = $copy === 'caisse';
        $pdf->rect($x + $w - $badgeW, $y, $badgeW, 6.2, $dark ? 0 : null, 0, 0.4);
        $pdf->text($x + $w - $badgeW / 2, $y + 4.2, mb_strtoupper($label), 7, true, 'center', $dark ? 1 : 0);
    }
    // Hauteur selon le nombre de lignes de coordonnées (adresse, téléphone, NIU, e-mail)
    $y += max(19, 8.6 + (count($d['lines']) - 1) * 3.4 + 3);
    $pdf->line($x, $y, $x + $w, $y, 0.6);

    // Référence, date, agent
    $y += 5;
    $pdf->text($x, $y, 'FACTURE — BORDEREAU DE TRANSACTION', 6.5, true, 'left', 0.35);
    $pdf->text($x, $y + 5.5, $d['number'], 13, true);
    foreach ([['Émise le', $d['issued']], ['Agent responsable', $d['agent']]] as $i => [$k, $v]) {
        $by = $y + $i * 4.5;
        $pdf->text($x + $w, $by, $v, 8, true, 'right');
        $pdf->text($x + $w - $pdf->width($v, 8, true) - 2.5, $by, $k, 8, false, 'right', 0.35);
    }

    // Client
    $y += 12;
    $pdf->text($x, $y, 'CLIENT', 6.5, true, 'left', 0.35);
    $last = 0;
    foreach ($pdf->wrap($d['client'], 10, true, $w - 13, 2) as $i => $line) {
        $pdf->text($x + 13, $y + $i * 4.5, $line, 10, true);
        $last = $i;
    }
    if ($d['phone'] !== '') {
        $y += ($last + 1) * 4.5;
        $pdf->text($x + 13, $y - 0.3, 'Tél. / WhatsApp : ' . $d['phone'], 8, false, 'left', 0.3);
    } else {
        $y += $last * 4.5;
    }

    // Tableau
    $y += 2;
    // Hauteur de ligne adaptée au nombre de lignes (7 avec commission, motif et émetteur)
    $rowH = count($d['rows']) > 6 ? 5.4 : 6;
    foreach ($d['rows'] as $k => $v) {
        $y += $rowH;
        $pdf->text($x, $y - 1.6, $k, 8.5, false, 'left', 0.3);
        // Valeur limitée à la place disponible (points de suspension au-delà)
        $room = $w - $pdf->width($k, 8.5) - 6;
        $pdf->text($x + $w, $y - 1.6, $pdf->wrap($v, 8.5, true, $room, 1)[0] ?? '', 8.5, true, 'right');
        $pdf->line($x, $y, $x + $w, $y, 0.2, 0.7);
    }
    $pdf->rect($x, $y + 0.6, $w, 8.6, 0.92);
    $pdf->line($x, $y + 0.6, $x + $w, $y + 0.6, 0.6);
    $pdf->line($x, $y + 9.2, $x + $w, $y + 9.2, 0.6);
    $pdf->text($x + 3, $y + 6.4, 'Montant net à remettre', 10.5, true);
    $pdf->text($x + $w - 3, $y + 6.4, $d['total'], 11, true, 'right');

    // Signatures
    $y += 13.5;
    $half = $w / 2;
    $pdf->text($x, $y, 'SIGNATURE DU CLIENT', 6.5, true, 'left', 0.35);
    $pdf->text($x + $half + 3, $y, 'CACHET / SIGNATURE AGENT', 6.5, true, 'left', 0.35);
    $pdf->line($x, $y + 10, $x + $half - 3, $y + 10, 0.25, 0.4, [1, 0.8]);
    $pdf->rect($x + $half + 3, $y + 1.8, $half - 3, 11, null, 0.4, 0.25, [1, 0.8]);

    // Pied de page, ancré en bas de la zone
    $fy = $top + $h;
    $pdf->line($x, $fy - 15.5, $x + $w, $fy - 15.5, 0.2, 0.7);
    $pdf->text($x + $half, $fy - 11.5, print_thanks($copy), 8, true, 'center');
    foreach ($pdf->wrap($d['legal'], 6.3, false, $w, 2) as $i => $line) {
        $pdf->text($x + $half, $fy - 8 + $i * 2.7, $line, 6.3, false, 'center', 0.35);
    }
    $pdf->text($x + $half, $fy - 1.6, $d['printed'], 6, false, 'center', 0.35);
}

/** PDF de la facture : A4 double souche (client + caisse) ou A5 exemplaire unique. */
function invoice_pdf(array $d, string $format): string
{
    $logo = print_logo_jpeg();
    if ($format === 'a5') {
        $pdf = new GtcPdf(148, 210);
        pdf_draw_receipt($pdf, $d, $logo, 10, 10, 128, 190, '', null);
        return $pdf->output($d['number']);
    }
    $pdf = new GtcPdf(210, 297);
    $x = 14;
    $w = 182;
    pdf_draw_receipt($pdf, $d, $logo, $x, 10, $w, 133, 'client', 'Exemplaire client');
    // Ligne de découpe au milieu exact de la feuille (148,5 mm)
    $label = 'LIGNE DE DÉCOUPE';
    $lw = $pdf->width($label, 6.5, true) + 7;
    $mid = $x + $w / 2;
    $pdf->line($x, 148.5, $mid - $lw / 2 - 3, 148.5, 0.4, 0.45, [2, 1.5]);
    $pdf->line($mid + $lw / 2 + 3, 148.5, $x + $w, 148.5, 0.4, 0.45, [2, 1.5]);
    $pdf->scissors($mid - $lw / 2, 149.6, 8, 0.3);
    $pdf->text($mid - $lw / 2 + 4.5, 149.4, $label, 6.5, true, 'left', 0.3);
    pdf_draw_receipt($pdf, $d, $logo, $x, 154, $w, 133, 'caisse', 'Exemplaire caisse / archive');
    return $pdf->output($d['number']);
}

/* ==========================================================================
   Ticket ESC/POS (imprimante thermique 80 mm, 48 colonnes)
   ========================================================================== */

function invoice_escpos(array $d): string
{
    $cols = 48;
    $enc = function (string $s): string {
        $s = str_replace(["\u{202F}", "\u{00A0}"], ' ', $s);
        $c = @iconv('UTF-8', 'CP858//TRANSLIT', $s);
        return $c === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $c;
    };
    // Découpage en caractères (et non en octets, à cause des accents)
    $wrapU = function (string $s, int $width): array {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim(str_replace(["\u{202F}", "\u{00A0}"], ' ', $s))) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && mb_strlen($try) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
            while (mb_strlen($line) > $width) {
                $lines[] = mb_substr($line, 0, $width);
                $line = mb_substr($line, $width);
            }
        }
        return $line === '' ? $lines : [...$lines, $line];
    };
    // « Libellé ......... valeur » sur 48 colonnes (valeur longue : renvoyée à la ligne, alignée à droite)
    $kv = function (string $k, string $v) use ($cols, $wrapU): string {
        $v = str_replace(["\u{202F}", "\u{00A0}"], ' ', $v);
        if (mb_strlen($k) + mb_strlen($v) + 1 <= $cols) {
            return $k . str_repeat(' ', $cols - mb_strlen($k) - mb_strlen($v)) . $v . "\n";
        }
        $out = $k . "\n";
        foreach ($wrapU($v, $cols) as $line) {
            $out .= str_repeat(' ', $cols - mb_strlen($line)) . $line . "\n";
        }
        return $out;
    };

    $ESC = "\x1B";
    $GS  = "\x1D";
    $center = $ESC . 'a' . "\x01";
    $left   = $ESC . 'a' . "\x00";
    $boldOn = $ESC . 'E' . "\x01";
    $boldOff = $ESC . 'E' . "\x00";
    $tallOn = $GS . '!' . "\x01";    // double hauteur
    $normal = $GS . '!' . "\x00";

    $out = $ESC . '@' . $ESC . 't' . "\x13";   // initialisation, page de code 858 (accents + €)
    $copies = print_copies('ticket');
    $n = 0;
    foreach ($copies as $copy => $label) {
        $t = $center;
        $t .= $copy === 'caisse'
            ? $GS . 'B' . "\x01" . $boldOn . ' ' . $enc(mb_strtoupper($label)) . ' ' . $boldOff . $GS . 'B' . "\x00" . "\n"
            : $boldOn . $enc('[ ' . mb_strtoupper($label) . ' ]') . $boldOff . "\n";
        $t .= "\n" . $boldOn . $tallOn . $enc($d['company']) . $normal . $boldOff . "\n";
        foreach ($d['lines'] as $line) {
            foreach ($wrapU($line, $cols) as $l) {
                $t .= $enc($l) . "\n";
            }
        }
        $t .= $left . str_repeat('=', $cols) . "\n";
        $t .= $enc('FACTURE - BORDEREAU DE TRANSACTION') . "\n";
        $t .= $boldOn . $tallOn . $enc($d['number']) . $normal . $boldOff . "\n";
        $t .= $enc($kv('Émise le', $d['issued']) . $kv('Agent', $d['agent']));
        $t .= str_repeat('-', $cols) . "\n";
        $t .= $enc('CLIENT') . "\n" . $boldOn;
        foreach ($wrapU($d['client'], $cols) as $l) {
            $t .= $enc($l) . "\n";
        }
        $t .= $boldOff . ($d['phone'] !== '' ? $enc('Tél. / WhatsApp : ' . $d['phone']) . "\n" : '');
        $t .= str_repeat('-', $cols) . "\n";
        foreach ($d['rows'] as $k => $v) {
            $t .= $enc($kv($k, $v));
        }
        $t .= str_repeat('=', $cols) . "\n";
        $t .= $boldOn . $tallOn . $enc($kv('NET A REMETTRE', $d['total'])) . $normal . $boldOff;
        $t .= str_repeat('=', $cols) . "\n\n";
        $t .= $enc('Signature du client :') . "\n\n\n" . str_repeat('.', 30) . "\n\n";
        $t .= $enc('Cachet / signature agent :') . "\n\n\n\n" . str_repeat('.', 30) . "\n\n";
        $t .= $center . $boldOn;
        foreach ($wrapU(print_thanks($copy), $cols) as $l) {
            $t .= $enc($l) . "\n";
        }
        $t .= $boldOff . $ESC . 'M' . "\x01";   // petite police (64 colonnes)
        foreach ($wrapU($d['legal'], 64) as $l) {
            $t .= $enc($l) . "\n";
        }
        $t .= $enc($d['printed']) . "\n" . $ESC . 'M' . "\x00" . $left;
        if (++$n < count($copies)) {
            // Ligne de découpe entre les deux exemplaires
            $cut = ' 8< LIGNE DE DÉCOUPE 8< ';
            $side = intdiv($cols - mb_strlen($cut), 2);
            $t .= "\n" . $enc(str_repeat('-', $side) . $cut . str_repeat('-', $cols - $side - mb_strlen($cut))) . "\n";
        }
        $t .= $ESC . 'd' . "\x04" . $GS . 'V' . "\x42\x00";   // avance + coupe partielle
        $out .= $t;
    }
    return $out;
}

/* ==========================================================================
   Envoi au périphérique
   ========================================================================== */

/** Envoi brut (RAW / JetDirect, port 9100). Retourne null si tout est parti, sinon le message d'erreur. */
function printer_send_raw(string $host, int $port, string $data): ?string
{
    $socket = @fsockopen($host, $port, $errno, $errstr, 4);
    if (!$socket) {
        return "connexion impossible à {$host}:{$port}" . ($errstr ? " ({$errstr})" : '');
    }
    stream_set_timeout($socket, 15);
    for ($sent = 0, $len = strlen($data); $sent < $len; $sent += $w) {
        $w = @fwrite($socket, substr($data, $sent, 65536));
        if (!$w) {
            fclose($socket);
            return "envoi interrompu vers {$host}:{$port}";
        }
    }
    fclose($socket);
    return null;
}

/** Envoi IPP (port 631, opération Print-Job, document PDF). */
function printer_send_ipp(string $host, int $port, string $pdf, string $jobName, string $userName): ?string
{
    if (!function_exists('curl_init')) {
        return "l'extension cURL de PHP est nécessaire pour l'impression IPP";
    }
    $attr = fn (int $tag, string $name, string $value) => chr($tag) . pack('n', strlen($name)) . $name . pack('n', strlen($value)) . $value;
    $uri = 'ipp://' . $host . ':' . $port . '/ipp/print';
    $body = pack('nnN', 0x0101, 0x0002, random_int(1, 0x7FFFFFFF)) . "\x01"
        . $attr(0x47, 'attributes-charset', 'utf-8')
        . $attr(0x48, 'attributes-natural-language', 'fr')
        . $attr(0x45, 'printer-uri', $uri)
        . $attr(0x42, 'requesting-user-name', mb_substr($userName, 0, 60))
        . $attr(0x42, 'job-name', $jobName)
        . $attr(0x49, 'document-format', 'application/pdf')
        . "\x03" . $pdf;

    $ch = curl_init('http://' . $host . ':' . $port . '/ipp/print');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/ipp'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    if ($response === false) {
        return "connexion impossible à {$host}:{$port}" . ($error ? " ({$error})" : '');
    }
    if ($http !== 200 || strlen($response) < 4) {
        return "l'imprimante a refusé la requête IPP (HTTP {$http})";
    }
    $status = unpack('n', substr($response, 2, 2))[1];
    return $status <= 0x00FF ? null : sprintf("l'imprimante a refusé le travail (code IPP 0x%04X)", $status);
}

/**
 * Prépare et envoie la facture à l'imprimante. Retourne [succès, message, nombre d'exemplaires].
 */
function print_invoice_direct(array $inv, array $printer, array $user): array
{
    if (!printer_is_direct($printer)) {
        return [false, "L'impression directe n'est pas activée pour cette imprimante.", 0];
    }
    $format = isset(PRINTER_FORMATS[$printer['paper_format']]) ? $printer['paper_format'] : 'a4';
    $copies = count(print_copies($format));
    $data = print_invoice_data($inv, $user);
    $host = (string) $printer['host'];
    $port = (int) $printer['port'];

    if ($format === 'ticket') {
        if ($port !== 9100) {
            return [false, 'Une imprimante ticket reçoit les commandes ESC/POS sur le port RAW 9100 : modifiez son protocole dans les Paramètres.', 0];
        }
        $error = printer_send_raw($host, $port, invoice_escpos($data));
    } else {
        $pdf = invoice_pdf($data, $format);
        $error = $port === 631
            ? printer_send_ipp($host, $port, $pdf, $inv['invoice_number'], $user['name'])
            : printer_send_raw($host, $port, $pdf);
    }

    db_query('UPDATE printers SET last_status = ?, last_checked_at = ? WHERE id = ?', [$error ? 'offline' : 'online', db_now(), $printer['id']]);
    return $error
        ? [false, 'Échec de l\'envoi : ' . $error . '.', $copies]
        : [true, $copies > 1
            ? "Facture envoyée sur « {$printer['printer_name']} » : exemplaire client + exemplaire caisse."
            : "Facture envoyée sur « {$printer['printer_name']} ».", $copies];
}
