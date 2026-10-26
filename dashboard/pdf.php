<?php
/**
 * Téléchargement PDF des reçus de caisse et des factures commerciales (ordinateur, Android, iPhone).
 *
 *   pdf.php?type=receipt&id=12[&format=a4|a5]   Reçu de caisse : A4 (exemplaire client + caisse) ou A5 (un exemplaire)
 *   pdf.php?type=invoice&id=7                   Facture / proforma / devis sur le papier à en-tête officiel
 *   pdf.php?type=courrier&id=3                  Courrier officiel sur le papier à en-tête (includes/courrier_pdf.php)
 *   &inline=1                                   affichage dans le navigateur au lieu du téléchargement (partage)
 *
 * PDF généré à la volée par GtcPdf (includes/print-direct.php), sans dépendance externe : le fichier est
 * téléchargé directement, même là où l'impression depuis le navigateur est limitée (Chrome Android, Safari iOS).
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/print-direct.php';
require_once __DIR__ . '/../includes/commercial_invoice_pdf.php';
require_once __DIR__ . '/../includes/courrier_pdf.php';

$user = require_login();
require_roles(OPERATION_ROLES);
session_write_close();

/** Nom de fichier sûr (lettres, chiffres, tirets) : « FAC-2026-0001-Styve-Kentsa ». */
function pdf_filename(string ...$parts): string
{
    $name = implode('-', array_filter($parts, fn ($p) => $p !== ''));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    return trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $ascii), '-') . '.pdf';
}

/** Envoie le PDF : téléchargement (par défaut) ou affichage. */
function pdf_send(string $pdf, string $file, bool $inline): never
{
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $file . '"; filename*=UTF-8\'\'' . rawurlencode($file));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

$type   = query('type');
$id     = (int) query('id');
$inline = query('inline') === '1';

if ($type === 'receipt') {
    $inv = db_one('SELECT * FROM invoices WHERE id = ?', [$id]);
    if (!$inv) {
        http_response_code(404);
        exit('Reçu introuvable.');
    }
    $format = query('format') === 'a5' ? 'a5' : 'a4';
    pdf_send(invoice_pdf(print_invoice_data($inv, $user), $format), pdf_filename('Recu', $inv['invoice_number'], $inv['client_name']), $inline);
}

if ($type === 'invoice') {
    $inv = cinv_find($id);
    if (!$inv) {
        http_response_code(404);
        exit('Facture introuvable.');
    }
    $label = ['invoice' => 'Facture', 'proforma' => 'Proforma', 'quote' => 'Devis'][$inv['doc_type']] ?? 'Facture';
    pdf_send(cinv_pdf($inv, $user['name']), pdf_filename($label, $inv['invoice_number'], $inv['client_name']), $inline);
}

if ($type === 'courrier') {
    $c = courrier_find($id);
    if (!$c) {
        http_response_code(404);
        exit('Courrier introuvable.');
    }
    $company = settings_all()['company_name'] ?: GTC_NAME;
    pdf_send(courrier_pdf($c, $company), pdf_filename('Courrier', $c['reference'], $c['recipient_company'] ?: $c['recipient_name']), $inline);
}

http_response_code(400);
exit('Document inconnu.');
