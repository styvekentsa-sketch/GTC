<?php
/**
 * GTC-Vault — Fiche d'un client KYC et consultation sécurisée de ses pièces
 *
 *   client.php?uuid=xxxxxxxx-xxxx-4xxx-xxxx-xxxxxxxxxxxx
 *
 * - Accès : session + rôle KYC (KYC_VIEW_ROLES) ; l'ouverture de la fiche est journalisée (audit_logs).
 * - Pièces : jamais de lien direct vers les fichiers (stockés hors de la racine web). « Consulter » ouvre un lecteur
 *   qui passe par viewer.php avec un lien signé valable 10 minutes dans cette session :
 *     · image : filigrane incrusté côté serveur dans les pixels ;
 *     · PDF   : rendu par PDF.js (hébergé dans assets/pdfjs) dans un canevas, filigrane peint sur chaque page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/vault.php';

$user = vault_guard();   // connexion du Dashboard + rôle VAULT_ROLES

// Page sensible : jamais en cache, jamais dans un cadre d'un autre site
header('Cache-Control: no-store, private, max-age=0');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: same-origin');

/** Page « dossier introuvable » (aucun détail sur l'existence du dossier). */
function client_not_found(): never
{
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="fr"><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Dossier introuvable · GTC-Vault</title>'
       . '<body style="font-family:system-ui,sans-serif;background:#f1f5f9;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
       . '<div style="max-width:420px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px;text-align:center">'
       . '<h1 style="font-size:20px;margin:0 0 8px">Dossier introuvable</h1><p style="color:#475569;margin:0 0 20px">Ce lien ne correspond à aucun dossier client. Il a peut-être été supprimé ou mal copié.</p>'
       . '<a href="clients.php" style="color:#1d4ed8;font-weight:600"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:4px" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>Retour au répertoire</a></div></body></html>';
    exit;
}

$uuid = strtolower(trim((string) ($_GET['uuid'] ?? '')));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
    client_not_found();
}

// ---------- Client et pièces (une requête, jointure) ----------
$st = vault_db()->prepare('SELECT c.id, c.uuid, c.type_client, c.nom_complet, c.telephone, c.email, c.adresse, c.numero_identifiant,
                                  c.created_at, c.updated_at,
                                  d.id AS doc_id, d.type_document, d.nom_original, d.taille_fichier, d.mime_type, d.created_at AS doc_created_at
                           FROM clients c
                           LEFT JOIN client_documents d ON d.client_id = c.id
                           WHERE c.uuid = ?
                           ORDER BY d.created_at ASC, d.id ASC');
$st->execute([$uuid]);
$rows = $st->fetchAll();
if (!$rows) {
    vault_audit('client_not_found', null, null, 'Fiche demandée inexistante : ' . $uuid);
    client_not_found();
}
$client = $rows[0];
$docs = [];
foreach ($rows as $r) {
    if ($r['doc_id'] === null) {
        continue;
    }
    $isPdf = $r['mime_type'] === 'application/pdf';
    $docs[] = [
        'id'    => (int) $r['doc_id'],
        'type'  => $r['type_document'],
        'label' => KYC_DOC_LABELS[$r['type_document']] ?? 'Document',
        'name'  => (string) $r['nom_original'],
        'size'  => vault_size((int) $r['taille_fichier']),
        'date'  => vault_date($r['doc_created_at']),
        'kind'  => $isPdf ? 'pdf' : 'image',
        'src'   => vault_viewer_url((int) $r['doc_id'], $isPdf ? 'pdf' : 'image'),
    ];
}

vault_audit('client_view', $client, null, $user['name'] . ' a ouvert la fiche du client ' . $client['nom_complet'] . ' (' . count($docs) . ' pièce' . (count($docs) > 1 ? 's' : '') . ')');

// Liens signés créés : la session peut être libérée
session_write_close();

// ---------- Complétude du dossier ----------
$have = array_column($docs, 'type');
$has = fn (string $t) => in_array($t, $have, true);
$missing = [];
if ($client['type_client'] === 'entreprise') {
    if (!$has('rccm')) {
        $missing[] = 'Registre du commerce (RCCM)';
    }
    if (!$has('cni_recto') && !$has('passeport')) {
        $missing[] = 'Pièce d\'identité du représentant légal';
    }
} elseif (!$has('passeport')) {
    if (!$has('cni_recto')) {
        $missing[] = $has('cni_verso') ? 'CNI — recto' : 'CNI (recto et verso) ou passeport';
    }
    if ($has('cni_recto') && !$has('cni_verso')) {
        $missing[] = 'CNI — verso';
    }
}

// ---------- Dernières consultations de ce dossier ----------
$journal = [];
try {
    $st = vault_db()->prepare("SELECT created_at, user_name, action, details FROM audit_logs
                               WHERE client_uuid = ? AND action IN ('client_create', 'client_view', 'document_view')
                               ORDER BY id DESC LIMIT 8");
    $st->execute([$uuid]);
    $journal = $st->fetchAll();
} catch (Throwable $e) {
    error_log('[GTC-Vault client] journal : ' . $e->getMessage());
}

$isCompany = $client['type_client'] === 'entreprise';
[$createdDay, $createdTime] = vault_date($client['created_at']);
$initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1),
    array_slice(array_values(array_filter(preg_split('/[\s&\-]+/u', $client['nom_complet']) ?: [],
        fn ($w) => preg_match('/^\p{L}/u', $w) && !in_array(mb_strtoupper($w), ['SARL', 'SA', 'SAS', 'SARLU', 'ETS', 'ET', 'DE', 'DU', 'LA', 'LE'], true)))
    , 0, 2))));

$icon = [
    'image' => '<svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M15 9h3M15 13h3M6 16h7"/></svg>',
    'pdf'   => '<svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>',
];
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($client['nom_complet']) ?> · Dossier KYC · GTC-Vault</title>
    <!-- Tailwind compilé (assets/vault.css, voir assets/README-tailwind.txt) : aucun script externe -->
    <link rel="stylesheet" href="<?= h(vault_css()) ?>">
    <style>
        [hidden] { display: none !important; }
        dialog::backdrop { background: rgb(15 23 42 / .85); backdrop-filter: blur(2px); }
        /* Lecteur : pas de sélection ni de glisser-déposer des pièces ; rien à l'impression */
        #viewer-stage, #viewer-stage * { -webkit-user-select: none; user-select: none; -webkit-user-drag: none; -webkit-touch-callout: none; }
        @media print { #viewer, .no-print { display: none !important; } }
    </style>
</head>
<body class="min-h-full bg-slate-100 font-sans text-slate-900 antialiased">

<?= vault_topbar([['Clients', 'clients.php'], [$client['nom_complet'], null]]) ?>

<main class="mx-auto max-w-6xl px-4 pb-16 pt-6 sm:px-6">
    <a href="clients.php" id="back-link"
       class="no-print mb-5 inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 -ml-2 text-sm font-medium text-slate-600 transition hover:bg-white hover:text-slate-900 focus:outline-none focus:ring-4 focus:ring-blue-100">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        Retour à la liste
    </a>

    <!-- ==================== EN-TÊTE DU DOSSIER ==================== -->
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="client-name">
        <div class="flex flex-col gap-4 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:p-6">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl text-lg font-bold
                         <?= $isCompany ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700' ?>" aria-hidden="true"><?= h($initials ?: '?') ?></span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h1 id="client-name" class="break-words text-2xl font-bold tracking-tight text-slate-900"><?= h($client['nom_complet']) ?></h1>
                    <?= $isCompany
                        ? '<span class="rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">Entreprise</span>'
                        : '<span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Particulier</span>' ?>
                </div>
                <p class="mt-1 text-sm text-slate-500">Dossier créé le <?= h($createdDay) ?> à <?= h($createdTime) ?> · Réf. <span class="font-mono text-xs"><?= h($client['uuid']) ?></span></p>
            </div>
            <?php if ($missing): ?>
                <div class="rounded-xl bg-amber-50 px-3.5 py-2.5 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20 sm:max-w-xs">
                    <p class="font-semibold">Dossier incomplet</p>
                    <p class="mt-0.5 text-amber-800">Manque : <?= h(implode(', ', $missing)) ?></p>
                </div>
            <?php else: ?>
                <span class="inline-flex items-center gap-1.5 self-start rounded-full bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-800 ring-1 ring-inset ring-emerald-600/20 sm:self-center">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>
                    Dossier complet
                </span>
            <?php endif; ?>
        </div>

        <!-- Coordonnées -->
        <dl class="grid gap-x-6 gap-y-5 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Téléphone</dt>
                <dd class="mt-1 text-sm font-medium tabular-nums text-slate-900"><a href="tel:<?= h($client['telephone']) ?>" class="hover:text-blue-700"><?= h(vault_phone($client['telephone'])) ?></a></dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">E-mail</dt>
                <dd class="mt-1 break-all text-sm font-medium text-slate-900">
                    <?= $client['email'] ? '<a href="mailto:' . h($client['email']) . '" class="hover:text-blue-700">' . h($client['email']) . '</a>' : '<span class="font-normal text-slate-400">Non renseigné</span>' ?>
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= $isCompany ? 'N° RCCM' : 'N° CNI / passeport' ?></dt>
                <dd class="mt-1 flex items-center gap-1.5">
                    <span class="break-all font-mono text-sm font-medium text-slate-900" id="client-id-number"><?= $client['numero_identifiant'] !== null ? h($client['numero_identifiant']) : '—' ?></span>
                    <?php if ($client['numero_identifiant'] !== null): ?>
                        <button type="button" data-copy="#client-id-number" class="no-print rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-4 focus:ring-blue-100" aria-label="Copier le numéro" title="Copier">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                        </button>
                    <?php endif; ?>
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Adresse</dt>
                <dd class="mt-1 whitespace-pre-line break-words text-sm font-medium text-slate-900"><?= $client['adresse'] ? h($client['adresse']) : '<span class="font-normal text-slate-400">Non renseignée</span>' ?></dd>
            </div>
        </dl>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <!-- ==================== PIÈCES JUSTIFICATIVES ==================== -->
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 lg:col-span-2" aria-labelledby="docs-title">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="docs-title" class="text-base font-semibold text-slate-900">Pièces Justificatives du Dossier</h2>
                <p class="text-xs text-slate-500"><?= count($docs) ?> pièce<?= count($docs) > 1 ? 's' : '' ?> · consultation filigranée et enregistrée</p>
            </div>

            <?php if (!$docs): ?>
                <div class="mt-5 rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center text-sm text-slate-500">Aucune pièce n'a encore été versée à ce dossier.</div>
            <?php else: ?>
                <ul class="mt-5 grid gap-3 sm:grid-cols-2">
                    <?php foreach ($docs as $d): ?>
                        <li class="flex flex-col rounded-xl border border-slate-200 p-4 transition hover:border-slate-300 hover:shadow-sm">
                            <div class="flex items-start gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg <?= $d['kind'] === 'pdf' ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-600' ?>"><?= $icon[$d['kind']] ?></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-slate-900"><?= h($d['label']) ?></p>
                                    <p class="truncate text-xs text-slate-500" title="<?= h($d['name']) ?>"><?= h($d['name']) ?></p>
                                    <p class="mt-1 text-xs text-slate-500"><?= $d['kind'] === 'pdf' ? 'PDF' : 'Image' ?> · <?= h($d['size']) ?> · ajoutée le <?= h($d['date'][0]) ?></p>
                                </div>
                            </div>
                            <button type="button" class="no-print mt-4 inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700 focus:outline-none focus:ring-4 focus:ring-slate-300"
                                    data-view data-kind="<?= h($d['kind']) ?>" data-src="<?= h($d['src']) ?>"
                                    data-label="<?= h($d['label']) ?>" data-meta="<?= h(($d['kind'] === 'pdf' ? 'PDF' : 'Image') . ' · ' . $d['size'] . ' · ajoutée le ' . $d['date'][0] . ' à ' . $d['date'][1]) ?>">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                Consulter / Prévisualiser
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <!-- ==================== JOURNAL DES CONSULTATIONS ==================== -->
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="audit-title">
            <h2 id="audit-title" class="text-base font-semibold text-slate-900">Dernières consultations</h2>
            <p class="mt-0.5 text-xs text-slate-500">Chaque ouverture de la fiche et de ses pièces est enregistrée.</p>
            <ol class="mt-4 space-y-3">
                <?php foreach ($journal as $j):
                    [$jd, $jt] = vault_date($j['created_at']); ?>
                    <li class="flex gap-3 text-sm">
                        <?php [$dot, $verb] = ['document_view' => ['bg-amber-500', 'a consulté une pièce'], 'client_create' => ['bg-emerald-500', 'a créé le dossier']][$j['action']] ?? ['bg-slate-300', 'a ouvert la fiche']; ?>
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full <?= $dot ?>" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="text-slate-800"><span class="font-medium"><?= h($j['user_name']) ?></span> <?= $verb ?></p>
                            <p class="text-xs text-slate-500"><?= h($jd) ?> à <?= h($jt) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
</main>

<!-- ==================== LECTEUR SÉCURISÉ ==================== -->
<dialog id="viewer" aria-labelledby="viewer-title"
        class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 sm:m-auto sm:h-[92vh] sm:w-[min(1100px,94vw)] sm:rounded-2xl">
    <div class="flex h-full flex-col overflow-hidden bg-slate-900 text-white sm:rounded-2xl">
        <header class="flex items-start gap-3 border-b border-white/10 px-4 py-3 sm:px-5">
            <div class="min-w-0 flex-1">
                <h2 id="viewer-title" class="truncate text-base font-semibold"></h2>
                <p id="viewer-meta" class="truncate text-xs text-slate-400"></p>
            </div>
            <button type="button" data-close class="rounded-lg p-2 text-slate-300 hover:bg-white/10 hover:text-white focus:outline-none focus:ring-4 focus:ring-white/20" aria-label="Fermer le lecteur">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </header>
        <div id="viewer-stage" class="relative flex-1 overflow-auto bg-slate-800/60 p-3 sm:p-5">
            <div id="viewer-loading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm text-slate-300">
                <svg class="h-8 w-8 animate-spin text-white" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                Chargement sécurisé…
            </div>
            <div id="viewer-error" hidden class="absolute inset-0 flex flex-col items-center justify-center gap-4 p-6 text-center">
                <p class="max-w-sm text-sm text-slate-200" data-text></p>
                <button type="button" onclick="location.reload()" class="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-100">Recharger la fiche</button>
            </div>
            <div id="viewer-content" class="mx-auto flex max-w-full flex-col items-center gap-4"></div>
        </div>
        <footer class="flex items-center gap-2 border-t border-white/10 px-4 py-2.5 text-xs text-slate-400 sm:px-5">
            <svg class="h-4 w-4 shrink-0 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.4 8.3 8 9 4.6-.7 8-4 8-9V6l-8-3Z"/></svg>
            <span>Document confidentiel — usage interne. Consultation par <strong class="font-medium text-slate-200"><?= h($user['name']) ?></strong> enregistrée dans le journal d'audit.</span>
        </footer>
    </div>
</dialog>

<script src="assets/pdfjs/pdf.min.js"></script>
<script>
(function () {
  'use strict';

  var AGENT = <?= json_encode($user['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var REF = <?= json_encode(substr($client['uuid'], 0, 8)) ?>;
  var dialog = document.getElementById('viewer');
  var stage = document.getElementById('viewer-stage');
  var content = document.getElementById('viewer-content');
  var loading = document.getElementById('viewer-loading');
  var errorBox = document.getElementById('viewer-error');
  var session = 0, pdfDoc = null, opener = null;

  if (window.pdfjsLib) pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/pdfjs/pdf.worker.min.js';

  /** Texte du filigrane, à l'heure de Douala au moment de la consultation. */
  function watermarkText() {
    var now = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Africa/Douala', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date()).replace(',', '');
    return 'GTC - USAGE INTERNE - ' + AGENT + ' - ' + now;
  }

  /** Filigrane peint dans le canevas (même dessin que viewer.php pour les images). */
  function paintWatermark(canvas, pageInfo) {
    var ctx = canvas.getContext('2d'), W = canvas.width, H = canvas.height, text = watermarkText();
    var size = Math.max(14, Math.round(Math.min(W, H) / 30));
    ctx.save();
    ctx.font = '700 ' + size + 'px Arial, sans-serif';
    var step = ctx.measureText(text).width + size * 4;
    ctx.translate(W / 2, H / 2); ctx.rotate(-Math.PI / 6); ctx.translate(-W / 2, -H / 2);
    for (var y = -H, row = 0; y < H * 2; y += size * 7, row++) {
      for (var x = -W + (row % 2) * step / 2; x < W * 2; x += step) {
        ctx.fillStyle = 'rgba(255,255,255,.25)'; ctx.fillText(text, x + 1, y + 1);
        ctx.fillStyle = 'rgba(15,23,42,.32)'; ctx.fillText(text, x, y);
      }
    }
    ctx.restore();
    var fs = Math.max(11, Math.round(size * 0.7)), band = Math.round(fs * 2.6);
    ctx.fillStyle = 'rgba(15,23,42,.78)'; ctx.fillRect(0, H - band, W, band);
    ctx.fillStyle = '#fff'; ctx.font = '700 ' + fs + 'px Arial, sans-serif';
    ctx.fillText(text + '  ·  Réf. ' + REF + ' · ' + pageInfo + ' · consultation enregistrée', Math.round(fs * 0.9), H - Math.round(band * 0.32));
  }

  function fail(message) {
    loading.hidden = true;
    errorBox.hidden = false;
    errorBox.querySelector('[data-text]').textContent = message;
  }

  function reset() {
    session++;
    if (pdfDoc) { pdfDoc.destroy(); pdfDoc = null; }
    content.innerHTML = ''; errorBox.hidden = true; loading.hidden = false;
  }

  // ---------- Image : déjà filigranée par le serveur ----------
  function showImage(src, label) {
    var img = new Image();
    img.alt = label + ' (aperçu filigrané)';
    img.className = 'max-w-full rounded-lg shadow-2xl';
    img.draggable = false;
    img.onload = function () { loading.hidden = true; };
    img.onerror = function () { fail('L\'aperçu n\'a pas pu être chargé. Le lien de consultation a peut-être expiré (10 minutes) : rechargez la fiche.'); };
    img.src = src;
    content.appendChild(img);
  }

  // ---------- PDF : rendu page par page dans des canevas ----------
  function showPdf(src) {
    var mine = session;
    if (!window.pdfjsLib) { fail('Le lecteur PDF n\'a pas pu être chargé.'); return; }
    fetch(src, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-GTC-Viewer': '1' } })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status === 403 ? 'expired' : 'http');
        return r.arrayBuffer();
      })
      .then(function (data) {
        // isEvalSupported:false : aucun code généré par le document n'est exécuté (protection contre les PDF piégés)
        return pdfjsLib.getDocument({ data: new Uint8Array(data), isEvalSupported: false, enableXfa: false, disableAutoFetch: true }).promise;
      })
      .then(function (pdf) {
        if (mine !== session) { pdf.destroy(); return; }
        pdfDoc = pdf;
        var total = Math.min(pdf.numPages, 30), chain = Promise.resolve();
        var width = Math.min(stage.clientWidth - 24, 1000);
        for (var n = 1; n <= total; n++) {
          (function (n) {
            chain = chain.then(function () {
              if (mine !== session) return;
              return pdf.getPage(n).then(function (page) {
                var base = page.getViewport({ scale: 1 });
                var dpr = Math.min(window.devicePixelRatio || 1, 2);
                var vp = page.getViewport({ scale: (width / base.width) * dpr });
                var canvas = document.createElement('canvas');
                canvas.width = Math.floor(vp.width); canvas.height = Math.floor(vp.height);
                canvas.style.width = Math.floor(vp.width / dpr) + 'px';
                canvas.className = 'max-w-full rounded-lg bg-white shadow-2xl';
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', 'Page ' + n + ' sur ' + pdf.numPages + ' (aperçu filigrané)');
                return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise.then(function () {
                  if (mine !== session) return;
                  paintWatermark(canvas, 'page ' + n + '/' + pdf.numPages);   // filigrane peint AVANT l'affichage
                  content.appendChild(canvas);
                  loading.hidden = true;
                });
              });
            });
          })(n);
        }
        return chain.then(function () {
          if (pdf.numPages > total && mine === session) {
            var p = document.createElement('p'); p.className = 'text-sm text-slate-300';
            p.textContent = total + ' premières pages affichées sur ' + pdf.numPages + '.'; content.appendChild(p);
          }
        });
      })
      .catch(function (e) {
        if (mine !== session) return;
        fail(e.message === 'expired'
          ? 'Le lien de consultation a expiré (10 minutes). Rechargez la fiche pour consulter cette pièce.'
          : 'Le document n\'a pas pu être affiché.');
      });
  }

  document.querySelectorAll('[data-view]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      reset();
      opener = btn;
      document.getElementById('viewer-title').textContent = btn.getAttribute('data-label');
      document.getElementById('viewer-meta').textContent = btn.getAttribute('data-meta');
      dialog.showModal();
      if (btn.getAttribute('data-kind') === 'pdf') showPdf(btn.getAttribute('data-src'));
      else showImage(btn.getAttribute('data-src'), btn.getAttribute('data-label'));
    });
  });
  dialog.querySelector('[data-close]').addEventListener('click', function () { dialog.close(); });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });   // clic sur le fond
  dialog.addEventListener('close', function () { reset(); loading.hidden = true; if (opener) opener.focus(); });

  // Pas de menu « Enregistrer l'image », de glisser-déposer ni de copie depuis le lecteur
  ['contextmenu', 'dragstart', 'copy'].forEach(function (t) { stage.addEventListener(t, function (e) { e.preventDefault(); }); });
  // Impression : le lecteur est masqué (feuille de style @media print) ; on le ferme aussi
  window.addEventListener('beforeprint', function () { if (dialog.open) dialog.close(); });

  // Retour à la liste : on revient à la recherche en cours si l'on vient du répertoire
  document.getElementById('back-link').addEventListener('click', function (e) {
    try {
      var ref = new URL(document.referrer);
      if (ref.origin === location.origin && /\/clients\.php$/.test(ref.pathname) && history.length > 1) { e.preventDefault(); history.back(); }
    } catch (err) { /* pas de page précédente : lien normal */ }
  });

  // Copier le N° d'identifiant
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = document.querySelector(b.getAttribute('data-copy')).textContent.trim();
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
        b.setAttribute('title', 'Copié !'); b.classList.add('text-emerald-600');
        setTimeout(function () { b.setAttribute('title', 'Copier'); b.classList.remove('text-emerald-600'); }, 1500);
      }, function () {});
    });
  });
})();
</script>
</body>
</html>
