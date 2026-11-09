<?php
/**
 * GTC-Vault — dossier KYC imprimé (A4, papier à en-tête officiel de Global Trading Corporation)
 *
 *   GET  print.php?uuid=…&auto=1   document d'impression (auto=1 : impression lancée dès que les pièces sont prêtes ;
 *                                  ouvert par la fiche client dans un cadre invisible, ou seul dans un onglet)
 *   POST print.php  (csrf_token, uuid, log=shortcut)  impression de la fiche par Ctrl+P : journalisation seule (204)
 *
 * Contenu : en-tête officiel (includes/letterhead.php), fiche d'identité complète (avec l'agent créateur), pièces
 * justificatives intégrées et filigranées « GTC - USAGE INTERNE - agent - date » (images : filigrane incrusté par
 * viewer.php en mode « print » ; PDF : filigrane peint sur chaque page par PDF.js), mention de confidentialité.
 * Traçabilité : chaque impression du dossier (client_print) et de chaque pièce (document_print) est enregistrée dans
 * audit_logs, avec l'agent et l'heure de Douala.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/vault.php';
require_once __DIR__ . '/../../includes/letterhead.php';

$user = vault_guard(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'json' : false);

// Document confidentiel : jamais en cache ; intégrable seulement par les pages du site (cadre d'impression de la fiche)
header('Cache-Control: no-store, private, max-age=0');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Referrer-Policy: same-origin');

$uuid   = strtolower(trim((string) ($_GET['uuid'] ?? $_POST['uuid'] ?? '')));
$client = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)
    ? db_one('SELECT c.*, u.name AS created_by_name FROM clients c LEFT JOIN users u ON u.id = c.created_by WHERE c.uuid = ?', [$uuid])
    : null;

// ---------- Impression de la fiche par Ctrl+P (journalisation seule) ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? '')) || !$client) {
        http_response_code(403);
        exit;
    }
    session_write_close();
    vault_audit('client_print', $client, null, $user['name'] . ' a imprimé la fiche de ' . $client['nom_complet'] . ' (impression de la page, sans les pièces)');
    http_response_code(204);
    exit;
}

if (!$client) {
    http_response_code(404);
    echo '<!DOCTYPE html><meta charset="UTF-8"><title>Dossier introuvable</title><p style="font-family:sans-serif;padding:2rem">Dossier introuvable.</p>';
    exit;
}

// ---------- Pièces du dossier : liens signés propres à l'impression ----------
$docs = [];
foreach (db_all('SELECT id, type_document, mime_type FROM client_documents WHERE client_id = ? ORDER BY created_at, id', [(int) $client['id']]) as $d) {
    $isPdf  = $d['mime_type'] === 'application/pdf';
    $docs[] = [
        'id'    => (int) $d['id'],
        'type'  => $d['type_document'],
        'label' => KYC_DOC_LABELS[$d['type_document']] ?? 'Document',
        'kind'  => $isPdf ? 'pdf' : 'image',
        'src'   => vault_viewer_url((int) $d['id'], $isPdf ? 'pdf' : 'print'),
    ];
}
session_write_close();   // liens signés créés : la session est libérée pour le chargement des pièces

vault_audit('client_print', $client, null, $user['name'] . ' a imprimé le dossier complet de ' . $client['nom_complet']
    . ' (' . count($docs) . ' pièce' . (count($docs) > 1 ? 's' : '') . ')');

// ---------- Données affichées ----------
$isCompany = $client['type_client'] === 'entreprise';
[$printDay, $printTime] = vault_date(date('Y-m-d H:i:s'));
[$createdDay, $createdTime] = vault_date($client['created_at']);
$have = array_column($docs, 'type');
$has = fn (string $t) => in_array($t, $have, true);
$complete = $isCompany ? ($has('rccm') && ($has('cni_recto') || $has('passeport'))) : ($has('passeport') || ($has('cni_recto') && $has('cni_verso')));
$avatar = avatar_url($client['uuid'], $client['avatar_path'] ?? null);
$auto = ($_GET['auto'] ?? '') === '1';
$root = site_root();

// Pièce d'identité imprimée d'un seul tenant : CNI recto + verso côte à côte (ou passeport seul), jamais coupée
// entre deux pages ; les autres pièces (RCCM, statuts, pièces en PDF) suivent
$idOrder  = ['cni_recto' => 0, 'cni_verso' => 1, 'passeport' => 2];
$idDocs   = array_values(array_filter($docs, fn ($d) => $d['kind'] === 'image' && isset($idOrder[$d['type']])));
usort($idDocs, fn ($a, $b) => $idOrder[$a['type']] <=> $idOrder[$b['type']]);
$otherDocs = array_values(array_filter($docs, fn ($d) => !in_array($d, $idDocs, true)));
$trace = 'Imprimé par ' . $user['name'] . ' le ' . $printDay . ' à ' . $printTime;
$fields = [
    [$isCompany ? 'Raison sociale' : 'Nom complet', $client['nom_complet']],
    ['Type de client', $isCompany ? 'Entreprise (personne morale)' : 'Particulier (personne physique)'],
    ['Téléphone', vault_phone($client['telephone'])],
    ['E-mail', $client['email'] ?: 'Non renseigné'],
    [$isCompany ? 'N° RCCM' : 'N° CNI / passeport', $client['numero_identifiant'] ?? '—'],
    ['Adresse', $client['adresse'] ?: 'Non renseignée'],
    ['Dossier créé le', $createdDay . ' à ' . $createdTime],
    ['Agent créateur', $client['created_by_name'] ?: 'Compte supprimé (n° ' . (int) $client['created_by'] . ')'],
    ['Pièces versées', count($docs) . ($complete ? ' · dossier complet' : ' · dossier incomplet')],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <base href="<?= h($root) ?>dashboard/">
    <title>Dossier KYC — <?= h($client['nom_complet']) ?> | GTC-Vault</title>
    <link rel="icon" href="<?= h($root) ?>assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/fonts.css', $root)) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/commercial-invoice-print.css', $root)) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/vault-print.css', $root)) ?>">
</head>
<body>

<nav class="ci-toolbar" aria-label="Actions">
    <a href="vault/client.php?uuid=<?= h($client['uuid']) ?>" class="ci-btn"><?= icon('arrow-left') ?> Retour à la fiche</a>
    <button type="button" class="ci-btn ci-btn--primary" data-print><?= icon('printer') ?> Imprimer</button>
    <p class="kp-hint" data-state>Préparation des pièces…</p>
</nav>

<!-- Traçabilité répétée en bas de CHAQUE page imprimée (position fixe), au-dessus du pied de page officiel -->
<p class="kp-trace-page" data-trace-page><?= h($trace) ?> · Dossier KYC réf. <?= h(substr($client['uuid'], 0, 8)) ?> · Document confidentiel — usage interne</p>

<main class="ci-sheet">
    <?= letterhead_html(GTC_NAME) ?>

    <table class="ci-page" role="presentation">
        <thead><tr><td><div class="ci-page__top"></div></td></tr></thead>
        <tfoot><tr><td><div class="ci-page__bottom"></div></td></tr></tfoot>
        <tbody><tr><td class="ci-page__body">

            <!-- En-tête du dossier -->
            <header class="kp-head">
                <div>
                    <p class="kp-overline">GTC-Vault · Coffre-fort KYC</p>
                    <h1 class="ci-doc__title">DOSSIER KYC</h1>
                    <p class="kp-sub">Fiche d'identification client · Réf. <span class="kp-mono"><?= h($client['uuid']) ?></span></p>
                </div>
                <div class="kp-head__side">
                    <span class="kp-stamp">Confidentiel</span>
                    <p>Imprimé le <?= h($printDay) ?> à <?= h($printTime) ?><br>par <strong><?= h($user['name']) ?></strong></p>
                </div>
            </header>

            <!-- Fiche d'identité -->
            <section class="kp-identity">
                <div class="kp-photo">
                    <?php if ($avatar): ?>
                        <img src="<?= h($avatar) ?>" alt="Photo de profil" data-wait>
                    <?php else: ?>
                        <span><?= h(mb_strtoupper(mb_substr(trim($client['nom_complet']), 0, 1))) ?></span>
                    <?php endif; ?>
                    <small><?= $isCompany ? 'Entreprise' : 'Particulier' ?></small>
                </div>
                <dl class="kp-fields">
                    <?php foreach ($fields as [$label, $value]): ?>
                        <div<?= in_array($label, ['Nom complet', 'Raison sociale', 'Adresse'], true) ? ' class="kp-fields__wide"' : '' ?>>
                            <dt><?= h($label) ?></dt>
                            <dd<?= str_starts_with($label, 'N°') ? ' class="kp-mono"' : '' ?>><?= nl2br(h((string) $value)) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>

            <!-- Pièces justificatives -->
            <h2 class="kp-title">Pièces justificatives (<?= count($docs) ?>)</h2>
            <?php if (!$docs): ?>
                <p class="kp-empty">Aucune pièce n'a été versée à ce dossier.</p>
            <?php endif; ?>
            <?php if ($idDocs): ?>
                <!-- Pièce d'identité : recto et verso ensemble, sans légende technique (fichier, taille, date), bloc jamais coupé.
                     La carte est détourée côté serveur (viewer.php, mode print) et imprimée au plus près de sa taille réelle -->
                <section class="kp-id-pair<?= count($idDocs) === 1 ? ' kp-id-pair--single' : '' ?>" aria-label="Pièce d'identité">
                    <?php foreach ($idDocs as $d): ?>
                        <figure class="kp-piece kp-piece--image kp-id" data-piece data-kind="image" data-src="<?= h($d['src']) ?>" data-label="<?= h($d['label']) ?>">
                            <div class="kp-piece__media">
                                <img src="<?= h($d['src']) ?>" alt="<?= h($d['label']) ?> (filigranée)" data-wait>
                            </div>
                        </figure>
                    <?php endforeach; ?>
                    <p class="kp-trace" data-trace>Pièces filigranées « GTC - USAGE INTERNE » · <?= h($trace) ?> · Réf. <?= h(substr($client['uuid'], 0, 8)) ?></p>
                </section>
            <?php endif; ?>
            <?php foreach ($otherDocs as $d): ?>
                <figure class="kp-piece kp-piece--<?= h($d['kind']) ?>" data-piece data-kind="<?= h($d['kind']) ?>" data-src="<?= h($d['src']) ?>" data-label="<?= h($d['label']) ?>">
                    <figcaption><strong><?= h($d['label']) ?></strong></figcaption>
                    <div class="kp-piece__media">
                        <?php if ($d['kind'] === 'image'): ?>
                            <img src="<?= h($d['src']) ?>" alt="<?= h($d['label']) ?> (filigranée)" data-wait>
                        <?php else: ?>
                            <p class="kp-loading">Chargement du PDF…</p>
                        <?php endif; ?>
                    </div>
                </figure>
            <?php endforeach; ?>

            <p class="kp-confidential">
                Document confidentiel — usage interne de <?= h(GTC_NAME) ?>. Les pièces d'identité sont reproduites avec le filigrane
                « GTC - USAGE INTERNE » à seule fin de vérification KYC ; toute autre utilisation, reproduction ou diffusion est interdite.
                Cette impression est enregistrée dans le journal de sécurité de GTC-Vault (agent, date et heure).
            </p>
        </td></tr></tbody>
    </table>
</main>

<?php if (in_array('pdf', array_column($docs, 'kind'), true)): ?>
<script src="vault/assets/pdfjs/pdf.min.js"></script>
<?php endif; ?>
<script>
(function () {
  'use strict';
  var AUTO = <?= $auto ? 'true' : 'false' ?>;
  var AGENT = <?= json_encode($user['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var REF = <?= json_encode(substr($client['uuid'], 0, 8)) ?>;
  var state = document.querySelector('[data-state]');
  var framed = window.parent !== window;
  function tell(type) { if (framed) { try { window.parent.postMessage({ source: 'gtc-vault-print', type: type }, location.origin); } catch (e) {} } }

  /** Filigrane peint sur chaque page PDF (même texte que les images filigranées par viewer.php). */
  function paintWatermark(canvas, pageInfo) {
    var now = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Africa/Douala', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date()).replace(',', '');
    var text = 'GTC - USAGE INTERNE - ' + AGENT + ' - ' + now;
    var ctx = canvas.getContext('2d'), W = canvas.width, H = canvas.height, size = Math.max(14, Math.round(Math.min(W, H) / 30));
    ctx.save(); ctx.font = '700 ' + size + 'px Arial, sans-serif';
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
    ctx.fillText(text + '  ·  Réf. ' + REF + ' · ' + pageInfo + ' · impression enregistrée', Math.round(fs * 0.9), H - Math.round(band * 0.32));
  }

  /** Pièce PDF : 4 premières pages rendues (largeur d'impression) avec le filigrane. */
  function renderPdf(fig) {
    var box = fig.querySelector('.kp-piece__media');
    if (!window.pdfjsLib) { box.innerHTML = '<p class="kp-loading">Lecteur PDF indisponible.</p>'; return Promise.resolve(); }
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'vault/assets/pdfjs/pdf.worker.min.js';
    return fetch(fig.getAttribute('data-src'), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-GTC-Viewer': '1', 'X-GTC-Print': '1' } })
      .then(function (r) { if (!r.ok) throw new Error('http'); return r.arrayBuffer(); })
      .then(function (data) { return pdfjsLib.getDocument({ data: new Uint8Array(data), isEvalSupported: false, enableXfa: false }).promise; })
      .then(function (pdf) {
        box.innerHTML = '';
        var total = Math.min(pdf.numPages, 4), chain = Promise.resolve();
        for (var n = 1; n <= total; n++) {
          (function (n) {
            chain = chain.then(function () {
              return pdf.getPage(n).then(function (page) {
                var vp = page.getViewport({ scale: 1600 / page.getViewport({ scale: 1 }).width });
                var canvas = document.createElement('canvas');
                canvas.width = Math.floor(vp.width); canvas.height = Math.floor(vp.height);
                return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise.then(function () {
                  paintWatermark(canvas, 'page ' + n + '/' + pdf.numPages);
                  box.appendChild(canvas);
                });
              });
            });
          })(n);
        }
        return chain.then(function () {
          if (pdf.numPages > total) { var p = document.createElement('p'); p.className = 'kp-loading'; p.textContent = total + ' premières pages imprimées sur ' + pdf.numPages + '.'; box.appendChild(p); }
        });
      })
      .catch(function () { box.innerHTML = '<p class="kp-loading">Ce PDF n\'a pas pu être chargé (lien expiré) : rouvrez la fiche puis relancez l\'impression.</p>'; });
  }

  // Toutes les images (photo de profil, pièces filigranées) et les PDF prêts avant l'impression
  var waits = Array.prototype.map.call(document.querySelectorAll('img[data-wait]'), function (img) {
    return new Promise(function (resolve) {
      if (img.complete && img.naturalWidth) return resolve();
      img.addEventListener('load', resolve);
      img.addEventListener('error', function () {
        var fig = img.closest('[data-piece]');
        if (fig) fig.querySelector('.kp-piece__media').innerHTML = '<p class="kp-loading">Pièce indisponible (lien expiré) : rouvrez la fiche puis relancez l\'impression.</p>';
        else img.remove();
        resolve();
      });
    });
  });
  document.querySelectorAll('[data-piece][data-kind="pdf"]').forEach(function (fig) { waits.push(renderPdf(fig)); });

  var ready = Promise.all(waits).then(function () {
    document.documentElement.setAttribute('data-ready', '1');
    state.textContent = 'Dossier prêt à imprimer · A4, marges « Aucune », graphiques d\'arrière-plan cochés.';
  });
  document.querySelector('[data-print]').addEventListener('click', function () { ready.then(function () { window.print(); }); });
  window.addEventListener('afterprint', function () { tell('done'); });

  if (AUTO) {
    ready.then(function () { tell('printing'); setTimeout(function () { window.print(); }, 150); });
  }
})();
</script>
</body>
</html>
