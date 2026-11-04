<?php
/**
 * Export de tous les documents PDF du site dans une archive .zip (Dashboard → Sauvegardes, administrateurs).
 *
 *   recus-de-caisse/      un PDF par reçu de caisse (A4, exemplaires client + caisse, comme « Télécharger le PDF »)
 *   factures/             factures, proformas et devis (papier à en-tête)
 *   courriers/            courriers officiels
 *   LISEZ-MOI.txt         période, nombre de documents par dossier
 *
 * Période facultative (du … au …) : date du reçu, date d'émission de la facture, date du courrier.
 * Les PDF sont produits un à un dans des fichiers temporaires (mémoire stable, même avec des milliers de documents),
 * puis supprimés une fois l'archive écrite. Mêmes générateurs que les boutons PDF du Dashboard (sans dépendance).
 */
require_once __DIR__ . '/print-direct.php';            // invoice_pdf(), print_invoice_data()
require_once __DIR__ . '/commercial_invoices.php';
require_once __DIR__ . '/commercial_invoice_pdf.php';  // cinv_pdf()
require_once __DIR__ . '/courriers.php';
require_once __DIR__ . '/courrier_pdf.php';            // courrier_pdf()

/** Nom de fichier sûr (lettres, chiffres, tirets) : « Recu-GTC-20261104-0001-Paul-Ekambi.pdf ». */
function pdf_export_name(string ...$parts): string
{
    $name  = implode('-', array_filter($parts, fn ($p) => $p !== ''));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    return mb_substr(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $ascii), '-'), 0, 120) . '.pdf';
}

/**
 * Crée l'archive et retourne [chemin du fichier temporaire, nombre de documents par dossier].
 * $from / $to : AAAA-MM-JJ ou null (sans limite). @throws RuntimeException
 */
function pdf_export_zip(?string $from, ?string $to, array $user): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('extension PHP « zip » non activée sur ce serveur');
    }
    @set_time_limit(900);
    $company = settings_all()['company_name'] ?: GTC_NAME;
    $zipPath = tempnam(sys_get_temp_dir(), 'gtcpdf');
    $zip     = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        throw new RuntimeException('création de l\'archive impossible (dossier temporaire)');
    }

    // Filtre de période sur une colonne de date (bornes incluses)
    $period = function (string $column) use ($from, $to): array {
        $where = [];
        $params = [];
        if ($from !== null) {
            $where[] = "{$column} >= ?";
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = "{$column} < ?";
            $params[] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    };

    $temps  = [];
    $counts = ['recus-de-caisse' => 0, 'factures' => 0, 'courriers' => 0];
    $used   = [];   // noms déjà pris dans l'archive
    $add = function (string $folder, string $name, string $pdf) use ($zip, &$temps, &$counts, &$used): void {
        $path = $folder . '/' . $name;
        for ($i = 2; isset($used[$path]); $i++) {
            $path = $folder . '/' . preg_replace('/\.pdf$/', "-{$i}.pdf", $name);
        }
        $used[$path] = true;
        $tmp = tempnam(sys_get_temp_dir(), 'gtcdoc');
        file_put_contents($tmp, $pdf);
        $temps[] = $tmp;
        $zip->addFile($tmp, $path);
        $counts[$folder]++;
    };

    try {
        // 1. Reçus de caisse
        [$w, $p] = $period('COALESCE(created_at, issue_date)');
        $stmt = db()->prepare("SELECT * FROM invoices {$w} ORDER BY id");
        $stmt->execute($p);
        while ($inv = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $add('recus-de-caisse', pdf_export_name('Recu', (string) $inv['invoice_number'], (string) $inv['client_name']),
                invoice_pdf(print_invoice_data($inv, $user), 'a4'));
        }

        // 2. Factures, proformas, devis
        [$w, $p] = $period('issue_date');
        foreach (db_all("SELECT id FROM commercial_invoices {$w} ORDER BY id", $p) as $row) {
            if ($inv = cinv_find((int) $row['id'])) {
                $label = ['invoice' => 'Facture', 'proforma' => 'Proforma', 'quote' => 'Devis'][$inv['doc_type']] ?? 'Facture';
                $add('factures', pdf_export_name($label, (string) $inv['invoice_number'], (string) $inv['client_name']), cinv_pdf($inv, $user['name']));
            }
        }

        // 3. Courriers (sans la référence interne si elle est masquée sur la lettre, comme le bouton PDF)
        [$w, $p] = $period('letter_date');
        foreach (db_all("SELECT id FROM courriers {$w} ORDER BY id", $p) as $row) {
            if ($c = courrier_find((int) $row['id'])) {
                $add('courriers', pdf_export_name('Courrier', courrier_shows_reference($c) ? $c['reference'] : date('Y-m-d', strtotime($c['letter_date'])),
                    (string) ($c['recipient_company'] ?: $c['recipient_name'])), courrier_pdf($c, $company));
            }
        }

        $periodText = $from === null && $to === null ? 'tous les documents'
            : 'du ' . ($from !== null ? date('d/m/Y', strtotime($from)) : 'début') . ' au ' . ($to !== null ? date('d/m/Y', strtotime($to)) : 'aujourd\'hui');
        $zip->addFromString('LISEZ-MOI.txt', implode("\r\n", [
            'Documents PDF — ' . $company,
            'Exportés le ' . date('d/m/Y à H:i') . ' par ' . $user['name'] . ' — période : ' . $periodText,
            '',
            '  recus-de-caisse/   ' . $counts['recus-de-caisse'] . ' reçu(s) de caisse',
            '  factures/          ' . $counts['factures'] . ' facture(s), proforma(s) et devis',
            '  courriers/         ' . $counts['courriers'] . ' courrier(s)',
            '',
            'CONFIDENTIEL : noms des clients et montants. À conserver en lieu sûr.',
        ]));
        if (!$zip->close()) {
            throw new RuntimeException('écriture de l\'archive interrompue (espace disque ?)');
        }
    } catch (Throwable $e) {
        @$zip->close();
        @unlink($zipPath);
        throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
    } finally {
        foreach ($temps as $tmp) {
            @unlink($tmp);
        }
    }
    return [$zipPath, $counts];
}
