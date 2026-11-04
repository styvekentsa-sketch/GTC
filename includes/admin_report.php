<?php
/**
 * Rapport administratif PDF (Dashboard → Sauvegardes → « Générer un rapport PDF », administrateurs).
 *
 * Bilan synthétique aux couleurs de l'entreprise, horodaté : base de données et sauvegardes, caisse (reçus, montants
 * encaissés par devise, mois en cours et total), factures, courriers, suivi des colis, messages, utilisateurs,
 * dernières actions du journal. Généré par GtcPdf (includes/print-direct.php), sans bibliothèque externe.
 * Aucune donnée personnelle de client n'y figure : uniquement des totaux (et les noms des comptes du Dashboard).
 */
require_once __DIR__ . '/print-direct.php';            // GtcPdf, print_logo_jpeg()
require_once __DIR__ . '/commercial_invoices.php';     // CINV_TYPES
require_once __DIR__ . '/shipments.php';               // SHIPMENT_STATUSES
require_once __DIR__ . '/activity.php';                // ACTIVITY_ACTIONS, ACTIVITY_ENTITIES
require_once __DIR__ . '/backup.php';

/** Requête de comptage tolérante : une table absente (module jamais ouvert) compte pour 0. */
function report_value(string $sql, array $params = []): float
{
    try {
        return (float) db_value($sql, $params);
    } catch (Throwable) {
        return 0;
    }
}

function report_rows(string $sql, array $params = []): array
{
    try {
        return db_all($sql, $params);
    } catch (Throwable) {
        return [];
    }
}

/** Données du rapport (sections → lignes [libellé, valeur]). */
function admin_report_data(): array
{
    $tz       = new DateTimeZone('Africa/Douala');
    $now      = new DateTimeImmutable('now', $tz);
    $month    = $now->format('Y-m-01');
    $nextM    = $now->modify('first day of next month')->format('Y-m-01');
    $today    = $now->format('Y-m-d');
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $when     = 'COALESCE(created_at, issue_date)';
    $n        = fn (float $v) => number_format($v, 0, ',', "\u{202F}");
    $months   = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $monthLabel = $months[(int) $now->format('n') - 1] . ' ' . $now->format('Y');

    // Base et sauvegardes
    $mysql  = DB_DRIVER === 'mysql';
    $tables = $mysql
        ? report_rows('SHOW TABLES')
        : report_rows("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
    $size = $mysql
        ? report_value('SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = ?', [DB_NAME])
        : (is_file(DB_SQLITE_PATH) ? (float) filesize(DB_SQLITE_PATH) : 0);
    $history  = backup_list(null, array_keys(BACKUP_KINDS));
    $lastAuto = array_values(array_filter($history, fn ($b) => $b['kind'] === BACKUP_AUTO_PREFIX))[0] ?? null;
    $kb       = fn (float $b) => $b >= 1048576 ? number_format($b / 1048576, 1, ',', "\u{202F}") . ' Mo' : number_format($b / 1024, 1, ',', "\u{202F}") . ' Ko';

    $sections = [];
    $sections['Base de données et sauvegardes'] = [
        ['Moteur', $mysql ? 'MySQL · ' . DB_NAME : 'SQLite (fichier local)'],
        ['Tables', $n(count($tables))],
        ['Taille de la base', $kb($size)],
        ['Sauvegardes sur le serveur', $n(count($history)) . ' fichier' . (count($history) > 1 ? 's' : '') . ' · ' . $kb(array_sum(array_column($history, 'size')))],
        ['Dernière sauvegarde', $history ? date('d/m/Y à H:i', $history[0]['time']) : 'aucune'],
        ['Sauvegarde automatique', $lastAuto
            ? ($lastAuto['time'] < time() - 2 * 86400 ? 'EN RETARD — ' : 'active — ') . 'dernière le ' . date('d/m/Y à H:i', $lastAuto['time'])
            : 'non programmée'],
    ];

    // Caisse
    $sections['Reçus de caisse'] = [
        ['Reçus enregistrés (total)', $n(report_value('SELECT COUNT(*) FROM invoices'))],
        ['dont payés', $n(report_value("SELECT COUNT(*) FROM invoices WHERE status = 'paid'"))],
        ['dont annulés / en attente / brouillons', $n(report_value("SELECT COUNT(*) FROM invoices WHERE status <> 'paid'"))],
        ["Transactions aujourd'hui", $n(report_value("SELECT COUNT(*) FROM invoices WHERE status = 'paid' AND {$when} >= ? AND {$when} < ?", [$today, $tomorrow]))],
        ['Transactions en ' . $monthLabel, $n(report_value("SELECT COUNT(*) FROM invoices WHERE status = 'paid' AND {$when} >= ? AND {$when} < ?", [$month, $nextM]))],
        ['Dernière clôture de caisse validée', ($c = report_rows('SELECT closing_date FROM cash_closings ORDER BY closing_date DESC LIMIT 1')) ? date('d/m/Y', strtotime($c[0]['closing_date'])) : 'aucune'],
    ];

    // Montants encaissés (reçus payés, montant remis par le client) : mois en cours et total, par devise
    $cur = [];
    foreach (report_rows("SELECT source_currency AS c, COUNT(*) AS n, SUM(amount_given) AS total,
                                 SUM(CASE WHEN {$when} >= ? AND {$when} < ? THEN amount_given ELSE 0 END) AS month
                          FROM invoices WHERE status = 'paid' GROUP BY source_currency ORDER BY SUM(amount_given) DESC", [$month, $nextM]) as $r) {
        $cur[] = [(string) $r['c'], money((float) $r['month'], (string) $r['c']), money((float) $r['total'], (string) $r['c']), $n((float) $r['n'])];
    }

    // Autres modules
    $docs = [];
    foreach (['invoice' => 'factures', 'proforma' => 'proformas', 'quote' => 'devis'] as $key => $plural) {
        if (isset(CINV_TYPES[$key])) {
            $docs[] = $n(report_value('SELECT COUNT(*) FROM commercial_invoices WHERE doc_type = ?', [$key])) . ' ' . $plural;
        }
    }
    $ship = [];
    foreach (report_rows('SELECT status, COUNT(*) AS n FROM shipments GROUP BY status') as $r) {
        $ship[$r['status']] = (int) $r['n'];
    }
    $shipText = [];
    foreach (SHIPMENT_STATUSES as $key => $st) {
        if (!empty($ship[$key])) {
            $shipText[] = $ship[$key] . ' ' . mb_strtolower($st['label']);
        }
    }
    $sections['Activité des modules'] = [
        ['Factures commerciales', implode(' · ', $docs)],
        ['Courriers', $n(report_value('SELECT COUNT(*) FROM courriers')) . ' (dont ' . $n(report_value("SELECT COUNT(*) FROM courriers WHERE status = 'sent'")) . ' envoyés)'],
        ['Dossiers de suivi', $n(array_sum($ship)) . ($shipText ? ' — ' . implode(', ', $shipText) : '')],
        ['Messages du site', $n(report_value('SELECT COUNT(*) FROM messages')) . ' (dont ' . $n(report_value("SELECT COUNT(*) FROM messages WHERE status = 'unread'")) . ' non lus)'],
    ];

    // Utilisateurs
    $users = [];
    foreach (report_rows('SELECT name, role, status, last_login_at FROM users ORDER BY status, role, name') as $u) {
        $users[] = [$u['name'], USER_ROLES[$u['role']] ?? $u['role'], $u['status'] === 'actif' ? 'Actif' : 'Désactivé',
                    $u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : 'jamais'];
    }

    // Journal
    $log = [];
    foreach (report_rows('SELECT created_at, user_name, action, entity, label FROM activity_log ORDER BY id DESC LIMIT 12') as $a) {
        $log[] = [date('d/m H:i', strtotime($a['created_at'])), (string) ($a['user_name'] ?? '—'),
                  (ACTIVITY_ACTIONS[$a['action']]['label'] ?? $a['action']) . ' · ' . (ACTIVITY_ENTITIES[$a['entity']] ?? $a['entity']) . ($a['label'] ? ' ' . $a['label'] : '')];
    }

    return [
        'sections'   => $sections,
        'currencies' => $cur,
        'monthLabel' => $monthLabel,
        'users'      => $users,
        'active'     => count(array_filter($users, fn ($u) => $u[2] === 'Actif')),
        'log'        => $log,
        'generated'  => $now,
    ];
}

/** PDF A4 du rapport. */
function admin_report_pdf(array $user): string
{
    $d       = admin_report_data();
    $company = settings_all()['company_name'] ?: GTC_NAME;
    $blue = [0, 81, 165]; $green = [0, 150, 71]; $magenta = [184, 0, 128]; $ink = [31, 38, 48]; $muted = [95, 108, 128]; $soft = [241, 245, 249];
    $pdf  = new GtcPdf(210, 297);
    $x = 15; $w = 180; $bottom = 280;
    $y = 0;

    $header = function (bool $first) use ($pdf, $x, $w, $company, $blue, $green, $magenta, $ink, $muted, &$y, $d, $user): void {
        // Bandeau tricolore de la charte (bleu, vert, magenta)
        $pdf->rectColor(0, 0, 70, 3, $blue);
        $pdf->rectColor(70, 0, 70, 3, $green);
        $pdf->rectColor(140, 0, 70, 3, $magenta);
        if (!$first) {
            $pdf->text($x, 12, $company . ' — Rapport administratif (suite)', 8, true, 'left', 0, $muted);
            $y = 20;
            return;
        }
        $logo = print_logo_jpeg();
        $tx = $x;
        if ($logo) {
            $ratio = $logo[1] / $logo[2];
            $lw = min(18 * $ratio, 50);
            $pdf->image($logo, $x, 10, $lw, $lw / $ratio);
            $tx = $x + $lw + 5;
        }
        $cx = $tx;
        foreach (preg_split('/\s+/', trim($company)) as $i => $word) {   // nom aux couleurs du logo
            $pdf->text($cx, 16, $word, 13, true, 'left', 0, $i === 1 ? $green : $blue);
            $cx += $pdf->width($word . ' ', 13, true);
        }
        $pdf->text($tx, 22, 'Rapport administratif — bilan des données', 10, true, 'left', 0, $ink);
        $pdf->text($tx, 27, 'Généré le ' . $d['generated']->format('d/m/Y à H:i') . ' (heure de Douala) par ' . $user['name'], 8, false, 'left', 0, $muted);
        $pdf->text($x + $w, 16, 'CONFIDENTIEL', 8, true, 'right', 0, $magenta);
        $pdf->lineColor($x, 33, $x + $w, 33, 0.6, $ink);
        $y = 40;
    };
    $ensure = function (float $need) use ($pdf, $header, $bottom, &$y): void {
        if ($y + $need > $bottom) {
            $pdf->addPage();
            $header(false);
        }
    };
    $title = function (string $text) use ($pdf, $x, $w, $blue, $ensure, &$y): void {
        $ensure(22);
        $pdf->rectColor($x, $y, $w, 7.5, $blue);
        $pdf->text($x + 3, $y + 5.1, mb_strtoupper($text), 8.5, true, 'left', 0, [255, 255, 255]);
        $y += 11;
    };
    $kv = function (array $rows) use ($pdf, $x, $w, $ink, $muted, $soft, $ensure, &$y): void {
        foreach ($rows as $i => [$k, $v]) {
            $lines = $pdf->wrap((string) $v, 9, true, $w * 0.58, 3);
            $h = max(6.5, 2.5 + count($lines) * 4.2);
            $ensure($h);
            if ($i % 2 === 0) {
                $pdf->rectColor($x, $y, $w, $h, $soft);
            }
            $pdf->text($x + 3, $y + 4.4, $k, 9, false, 'left', 0, $muted);
            foreach ($lines as $j => $line) {
                $pdf->text($x + $w - 3, $y + 4.4 + $j * 4.2, $line, 9, true, 'right', 0, $ink);
            }
            $y += $h;
        }
        $y += 5;
    };
    // Tableau : $cols = [[titre, largeur, alignement], …]
    $table = function (array $cols, array $rows, string $empty) use ($pdf, $x, $w, $ink, $muted, $soft, $ensure, $header, $bottom, &$y): void {
        $head = function () use ($pdf, $x, $w, $cols, $ink, $muted, &$y): void {
            $cx = $x;
            $pdf->lineColor($x, $y + 6, $x + $w, $y + 6, 0.4, $ink);
            foreach ($cols as [$label, $cw, $align]) {
                $pdf->text($align === 'right' ? $cx + $cw - 2 : $cx + 2, $y + 4.2, mb_strtoupper($label), 7, true, $align, 0, $muted);
                $cx += $cw;
            }
            $y += 7;
        };
        $ensure(14);
        $head();
        if (!$rows) {
            $pdf->text($x + 2, $y + 4.5, $empty, 8.5, false, 'left', 0, $muted);
            $y += 11;
            return;
        }
        foreach ($rows as $i => $row) {
            if ($y + 6.5 > $bottom) {   // suite du tableau sur une nouvelle page : titres des colonnes répétés
                $pdf->addPage();
                $header(false);
                $head();
            }
            if ($i % 2 === 1) {
                $pdf->rectColor($x, $y, $w, 6.2, $soft);
            }
            $cx = $x;
            foreach ($cols as $k => [, $cw, $align]) {
                $text = $pdf->wrap((string) $row[$k], 8.5, $k === 0, $cw - 4, 1)[0] ?? '';
                $pdf->text($align === 'right' ? $cx + $cw - 2 : $cx + 2, $y + 4.3, $text, 8.5, $k === 0, $align, 0, $ink);
                $cx += $cw;
            }
            $y += 6.2;
        }
        $y += 6;
    };

    $header(true);

    // Chiffres clés
    $tiles = [
        ['Reçus payés', (string) $d['sections']['Reçus de caisse'][1][1], $blue],
        ['Transactions ' . explode(' ', $d['monthLabel'])[0], (string) $d['sections']['Reçus de caisse'][4][1], $green],
        ['Utilisateurs actifs', (string) $d['active'], $magenta],
        ['Sauvegardes', explode(' ', (string) $d['sections']['Base de données et sauvegardes'][3][1])[0], $ink],
    ];
    $tw = ($w - 3 * 4) / 4;
    foreach ($tiles as $i => [$label, $value, $color]) {
        $tx = $x + $i * ($tw + 4);
        $pdf->rectColor($tx, $y, $tw, 20, [248, 250, 252], [226, 232, 240], 0.3);
        $pdf->rectColor($tx, $y, 1.4, 20, $color);
        $pdf->text($tx + 5, $y + 11, $value, 15, true, 'left', 0, $color);
        $pdf->text($tx + 5, $y + 16.5, $label, 7.5, false, 'left', 0, $muted);
    }
    $y += 28;

    $title('Base de données et sauvegardes');
    $kv($d['sections']['Base de données et sauvegardes']);
    $title('Reçus de caisse');
    $kv($d['sections']['Reçus de caisse']);
    $title('Montants encaissés par devise (reçus payés)');
    $table([['Devise', 25, 'left'], [ucfirst($d['monthLabel']), 60, 'right'], ['Total depuis le début', 65, 'right'], ['Reçus', 30, 'right']],
        $d['currencies'], 'Aucun reçu payé.');
    $title('Activité des modules');
    $kv($d['sections']['Activité des modules']);
    $title('Utilisateurs du Dashboard');
    $table([['Nom', 62, 'left'], ['Rôle', 48, 'left'], ['Statut', 30, 'left'], ['Dernière connexion', 40, 'right']], $d['users'], 'Aucun compte.');
    $title("Dernières actions (journal d'activité)");
    $table([['Date', 24, 'left'], ['Par', 44, 'left'], ['Action', 112, 'left']], $d['log'], 'Journal vide.');

    // Pied de page : numéro de page sur chaque page
    $pages = $pdf->pageCount();
    for ($p = 0; $p < $pages; $p++) {
        $pdf->setPage($p);
        $pdf->lineColor($x, 287, $x + $w, 287, 0.2, [203, 213, 225]);
        $pdf->text($x, 291.5, $company . ' — rapport du ' . $d['generated']->format('d/m/Y H:i') . ' — document confidentiel', 7, false, 'left', 0, $muted);
        $pdf->text($x + $w, 291.5, 'Page ' . ($p + 1) . ' / ' . $pages, 7, false, 'right', 0, $muted);
    }
    return $pdf->output('Rapport administratif ' . $company);
}
