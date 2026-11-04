<?php
/**
 * Suivi des colis et conteneurs (transit et fret international).
 *
 *   shipments.php                        Liste (recherche, statut, pagination)
 *   shipments.php?action=new             Nouveau dossier (code de suivi attribué à l'enregistrement)
 *   shipments.php?action=edit&id=ID      Modification des informations du dossier
 *   shipments.php?id=ID                  Fiche : frise, mise à jour du statut, lien de suivi à envoyer au client
 *   ../suivi.php?code=GTC-XXXX-XXXX      Page publique consultée par le client
 *
 * Règles : includes/shipments.php. Accès : comptes des opérations ; suppressions (dossier, étape) réservées aux
 * administrateurs. Toutes les actions sont inscrites au journal d'activité.
 */
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/shipments.php';
require_once __DIR__ . '/../includes/messages.php';   // message_whatsapp() : lien WhatsApp vers le client

$user   = require_login();
require_roles(OPERATION_ROLES);

$action = query('action', 'list');
$id     = (int) query('id');
$form   = null;
$errors = [];
$eventForm   = null;
$eventErrors = [];

/* ==========================================================================
   Actions (POST + CSRF)
   ========================================================================== */

if (is_post()) {
    csrf_verify();
    $op = post('op');

    if ($op === 'save') {
        $editId = (int) post('id') ?: null;
        $before = $editId !== null ? shipment_find($editId) : null;
        if ($editId !== null && !$before) {
            flash('error', 'Dossier introuvable (supprimé entre-temps ?).');
            redirect('shipments.php');
        }
        [$form, $errors] = shipment_input($editId === null);
        if (!$errors) {
            $savedId = shipment_save($form, $user, $editId);
            $code    = (string) db_value('SELECT tracking_number FROM shipments WHERE id = ?', [$savedId]);
            activity_log($editId ? 'update' : 'create', 'shipment', $savedId, $code, [
                'client' => $form['client_name'], 'marchandise' => mb_strimwidth($form['description'], 0, 120, '…'),
            ]);
            flash('success', "Dossier {$code} " . ($editId ? 'modifié.' : 'créé : communiquez ce code de suivi au client.'));
            redirect("shipments.php?id={$savedId}");
        }
        $action = $editId ? 'edit' : 'new';
        $id     = (int) $editId;
    }

    if ($op === 'event') {
        $shipment = shipment_find((int) post('id'));
        if (!$shipment) {
            flash('error', 'Dossier introuvable.');
            redirect('shipments.php');
        }
        [$eventForm, $eventErrors] = shipment_event_input();
        if (!$eventErrors) {
            shipment_add_event((int) $shipment['id'], $eventForm, $user);
            $now = (string) db_value('SELECT status FROM shipments WHERE id = ?', [(int) $shipment['id']]);
            activity_log('status', 'shipment', (int) $shipment['id'], $shipment['tracking_number'], [
                'étape'  => shipment_status_label($eventForm['status'], $shipment['transport_mode']),
                'statut' => shipment_status_label($shipment['status'], $shipment['transport_mode']) . ' → ' . shipment_status_label($now, $shipment['transport_mode']),
                'lieu'   => $eventForm['location'],
            ]);
            flash('success', 'Étape ajoutée : ' . shipment_status_label($eventForm['status'], $shipment['transport_mode']) . '.');
            redirect('shipments.php?id=' . (int) $shipment['id']);
        }
        $action = 'view';
        $id     = (int) $shipment['id'];
    }

    if ($op === 'delete_event') {
        require_role('admin');
        $event = db_one('SELECT e.*, s.tracking_number, s.transport_mode FROM shipment_events e JOIN shipments s ON s.id = e.shipment_id WHERE e.id = ?', [(int) post('event_id')]);
        if ($event) {
            $count = (int) db_value('SELECT COUNT(*) FROM shipment_events WHERE shipment_id = ?', [(int) $event['shipment_id']]);
            if ($count <= 1) {
                flash('error', "La seule étape du dossier ne peut pas être supprimée : ajoutez d'abord l'étape correcte.");
            } else {
                db_query('DELETE FROM shipment_events WHERE id = ?', [(int) $event['id']]);
                shipment_refresh_status((int) $event['shipment_id']);
                activity_log('delete', 'shipment', (int) $event['shipment_id'], $event['tracking_number'], [
                    'étape supprimée' => shipment_status_label($event['status'], $event['transport_mode']) . ' du ' . fdate($event['event_date'], true),
                ]);
                flash('success', 'Étape supprimée.');
            }
            redirect('shipments.php?id=' . (int) $event['shipment_id']);
        }
        redirect('shipments.php');
    }

    if ($op === 'delete') {
        require_role('admin');
        $deleted = shipment_find((int) post('id'));
        if ($deleted) {
            db_query('DELETE FROM shipment_events WHERE shipment_id = ?', [(int) $deleted['id']]);
            db_query('UPDATE invoices SET shipment_id = NULL WHERE shipment_id = ?', [(int) $deleted['id']]);   // reçus conservés (pièces comptables)
            db_query('DELETE FROM shipments WHERE id = ?', [(int) $deleted['id']]);
            activity_log('delete', 'shipment', (int) $deleted['id'], $deleted['tracking_number'], [
                'client' => $deleted['client_name'], 'marchandise' => mb_strimwidth($deleted['description'], 0, 120, '…'),
            ]);
            flash('success', "Dossier {$deleted['tracking_number']} supprimé.");
        }
        $back = safe_back(post('back'), 'shipments.php', 'shipments.php');
        redirect(preg_match('/[?&]id=/', $back) ? 'shipments.php' : $back);   // fiche du dossier supprimé : retour à la liste
    }
}

/* ==========================================================================
   Vue : nouveau dossier / modification
   ========================================================================== */

if ($action === 'new' || $action === 'edit') {
    $existing = $action === 'edit' ? shipment_find($id) : null;
    if ($action === 'edit' && !$existing) {
        flash('error', 'Dossier introuvable.');
        redirect('shipments.php');
    }
    $form ??= $existing ?? ['transport_mode' => 'sea', 'destination' => 'Douala', 'status' => 'loading', 'pickup_location' => SHIPMENT_DEFAULT_PICKUP];
    $err = fn (string $k) => isset($errors[$k]) ? '<p class="field__error">' . e($errors[$k]) . '</p>' : '';
    $inv = fn (string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
    $val = fn (string $k) => e((string) ($form[$k] ?? ''));
    $max = fn (string $k) => ' maxlength="' . SHIPMENT_LIMITS[$k] . '"';

    dash_header($existing ? 'Modifier ' . $existing['tracking_number'] : 'Nouveau dossier de suivi', 'shipments');
    ?>
    <div class="toolbar">
        <a href="<?= $existing ? 'shipments.php?id=' . (int) $existing['id'] : 'shipments.php' ?>" class="btn btn--ghost"><?= icon('arrow-left') ?> <?= $existing ? 'Retour au dossier' : 'Tous les dossiers' ?></a>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>Merci de corriger les champs indiqués.</p></div>
    <?php endif; ?>

    <form method="post" class="panel form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <?php if ($existing): ?><input type="hidden" name="id" value="<?= (int) $existing['id'] ?>"><?php endif; ?>

        <header class="panel__head">
            <h2 class="panel__title"><?= icon('ship') ?> <?= $existing ? 'Dossier ' . e($existing['tracking_number']) : 'Nouveau dossier' ?></h2>
            <span class="cell-sub"><?= $existing
                ? 'Créé par ' . e($existing['created_by_name'] ?? '—') . ' le ' . e(fdate($existing['created_at'], true)) . ' · le statut se met à jour depuis la fiche du dossier'
                : 'Code de suivi GTC-XXXX-XXXX attribué automatiquement à l\'enregistrement' ?></span>
        </header>

        <fieldset class="form-section">
            <legend>Client et marchandise</legend>
            <div class="form-grid">
                <div class="field">
                    <label for="client_name">Nom du client *</label>
                    <input id="client_name" name="client_name" required value="<?= $val('client_name') ?>"<?= $max('client_name') . $inv('client_name') ?> placeholder="Ex. Jean Mbarga ou SARL Bois du Littoral">
                    <small class="field__hint">Sur la page publique, seul le prénom suivi des initiales apparaît (« Jean M. »).</small>
                    <?= $err('client_name') ?>
                </div>
                <div class="field">
                    <label for="client_phone">Téléphone du client <small>(facultatif)</small></label>
                    <input id="client_phone" name="client_phone" type="tel" value="<?= $val('client_phone') ?>"<?= $max('client_phone') . $inv('client_phone') ?> placeholder="Ex. 6 77 00 00 00">
                    <small class="field__hint">Usage interne (envoi du lien par WhatsApp), jamais affiché sur le site.</small>
                    <?= $err('client_phone') ?>
                </div>
                <div class="field field--full">
                    <label for="description">Description de la marchandise *</label>
                    <textarea id="description" name="description" rows="3" required<?= $max('description') . $inv('description') ?> placeholder="Ex. 1 conteneur 40' — pièces détachées automobiles, 420 colis"><?= $val('description') ?></textarea>
                    <small class="field__hint">Visible par le client sur la page de suivi.</small>
                    <?= $err('description') ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="form-section">
            <legend>Colis et retrait</legend>
            <div class="form-grid">
                <div class="field">
                    <label for="weight_kg">Poids total (kg) <small>(facultatif)</small></label>
                    <input id="weight_kg" name="weight_kg" inputmode="decimal" value="<?= $val('weight_kg') ?>"<?= $inv('weight_kg') ?> placeholder="Ex. 1250,5">
                    <?= $err('weight_kg') ?>
                </div>
                <div class="field">
                    <label for="volume_cbm">Volume (m³ / CBM) <small>(facultatif)</small></label>
                    <input id="volume_cbm" name="volume_cbm" inputmode="decimal" value="<?= $val('volume_cbm') ?>"<?= $inv('volume_cbm') ?> placeholder="Ex. 12,4">
                    <?= $err('volume_cbm') ?>
                </div>
                <div class="field">
                    <label for="packages">Nombre de colis <small>(facultatif)</small></label>
                    <input id="packages" name="packages" inputmode="numeric" value="<?= $val('packages') ?>"<?= $inv('packages') ?> placeholder="Ex. 42">
                    <?= $err('packages') ?>
                </div>
                <div class="field">
                    <label for="package_type">Nature des colis <small>(facultatif)</small></label>
                    <input id="package_type" name="package_type" list="package-types" value="<?= $val('package_type') ?>"<?= $max('package_type') . $inv('package_type') ?> placeholder="Ex. cartons">
                    <datalist id="package-types"><option value="cartons"><option value="palettes"><option value="sacs"><option value="fûts"><option value="caisses"><option value="pièces"><option value="véhicule"></datalist>
                    <?= $err('package_type') ?>
                </div>
                <div class="field field--full">
                    <label for="pickup_location">Lieu de retrait à Douala <small>(affiché au client)</small></label>
                    <input id="pickup_location" name="pickup_location" value="<?= $val('pickup_location') ?>"<?= $max('pickup_location') . $inv('pickup_location') ?> placeholder="Ex. Magasin du port, Terminal à conteneurs, porte 3">
                    <?= $err('pickup_location') ?>
                </div>
            </div>
        </fieldset>

        <fieldset class="form-section">
            <legend>Transport</legend>
            <div class="form-grid">
                <div class="field">
                    <label for="transport_mode">Mode de transport</label>
                    <select id="transport_mode" name="transport_mode"<?= $inv('transport_mode') ?>>
                        <?php foreach (SHIPMENT_MODES as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $key === ($form['transport_mode'] ?? 'sea') ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('transport_mode') ?>
                </div>
                <div class="field">
                    <label for="carrier_reference">N° du transporteur <small>(facultatif)</small></label>
                    <input id="carrier_reference" name="carrier_reference" value="<?= $val('carrier_reference') ?>"<?= $max('carrier_reference') . $inv('carrier_reference') ?> placeholder="B/L, LTA ou n° de conteneur">
                    <small class="field__hint">Le client peut aussi rechercher son dossier avec ce numéro.</small>
                    <?= $err('carrier_reference') ?>
                </div>
                <div class="field">
                    <label for="origin">Départ</label>
                    <input id="origin" name="origin" value="<?= $val('origin') ?>"<?= $max('origin') . $inv('origin') ?> placeholder="Ex. Port d'Anvers, Belgique">
                    <?= $err('origin') ?>
                </div>
                <div class="field">
                    <label for="destination">Arrivée</label>
                    <input id="destination" name="destination" value="<?= $val('destination') ?>"<?= $max('destination') . $inv('destination') ?>>
                    <?= $err('destination') ?>
                </div>
                <div class="field">
                    <label for="eta">Arrivée prévue <small>(facultatif)</small></label>
                    <input id="eta" name="eta" type="date" value="<?= $val('eta') ?>"<?= $inv('eta') ?>>
                    <?= $err('eta') ?>
                </div>
                <?php if (!$existing): ?>
                    <div class="field">
                        <label for="status">Statut de départ</label>
                        <select id="status" name="status"<?= $inv('status') ?>>
                            <?php foreach (SHIPMENT_STATUSES as $key => $st): ?>
                                <option value="<?= e($key) ?>" <?= $key === ($form['status'] ?? 'loading') ? 'selected' : '' ?>><?= e($st['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $err('status') ?>
                    </div>
                <?php endif; ?>
            </div>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= icon('save') ?> <?= $existing ? 'Enregistrer' : 'Créer le dossier' ?></button>
        </div>
    </form>
    <?php
    dash_footer();
    exit;
}

/* ==========================================================================
   Vue : fiche d'un dossier
   ========================================================================== */

if ($id && ($action === 'list' || $action === 'view')) {
    $s = shipment_find($id);
    if (!$s) {
        flash('error', 'Dossier introuvable.');
        redirect('shipments.php');
    }
    $events   = shipment_events($id);
    $timeline = shipment_timeline($s, $events);
    $payments = db_all('SELECT * FROM invoices WHERE shipment_id = ? ORDER BY id DESC', [$id]);
    $paidTotals = [];   // devise => total des reçus payés (annulés et brouillons exclus)
    foreach ($payments as $p) {
        if ($p['status'] === 'paid') {
            $paidTotals[$p['target_currency']] = ($paidTotals[$p['target_currency']] ?? 0) + (float) $p['amount_received'];
        }
    }
    $mode     = $s['transport_mode'];
    $link     = shipment_public_url($s);
    $whatsapp = message_whatsapp($s['client_phone']);
    $waText   = 'Bonjour ' . $s['client_name'] . ', voici le suivi de votre marchandise (code ' . $s['tracking_number'] . ') : ' . $link . ' — ' . GTC_NAME;
    $eventForm ??= ['status' => array_keys(SHIPMENT_STATUSES)[min(array_search($s['status'], array_keys(SHIPMENT_STATUSES), true) + 1, count(SHIPMENT_STATUSES) - 1)],
                    'location' => '', 'note' => '', 'event_date' => date('Y-m-d H:i')];
    $err = fn (string $k) => isset($eventErrors[$k]) ? '<p class="field__error">' . e($eventErrors[$k]) . '</p>' : '';
    $inv = fn (string $k) => isset($eventErrors[$k]) ? ' aria-invalid="true"' : '';

    dash_header('Dossier ' . $s['tracking_number'], 'shipments');
    ?>
    <div class="toolbar">
        <a href="shipments.php" class="btn btn--ghost"><?= icon('arrow-left') ?> Tous les dossiers</a>
        <div class="toolbar__actions">
            <a href="shipments.php?action=edit&amp;id=<?= $id ?>" class="btn btn--ghost"><?= icon('edit') ?> Modifier</a>
            <a href="<?= e(site_root()) ?>suivi.php?code=<?= e(rawurlencode($s['tracking_number'])) ?>" class="btn btn--ghost" target="_blank" rel="noopener"><?= icon('eye') ?> Voir comme le client</a>
            <?= delete_button($id, 'Supprimer définitivement le dossier ' . $s['tracking_number'] . ' et ses ' . count($events) . ' étape(s) ? Le client ne pourra plus le suivre.', 'Supprimer') ?>
        </div>
    </div>

    <?php if ($eventErrors): ?>
        <div class="alert alert--error" role="alert"><?= icon('alert') ?><p>L'étape n'a pas été ajoutée : merci de corriger les champs indiqués.</p></div>
    <?php endif; ?>

    <article class="panel">
        <header class="detail-head">
            <div>
                <p class="detail-head__label">Code de suivi</p>
                <h2 class="detail-head__number"><?= e($s['tracking_number']) ?></h2>
                <p class="cell-sub"><?= e(SHIPMENT_MODES[$mode] ?? $mode) ?><?= $s['carrier_reference'] ? ' · N° transporteur ' . e($s['carrier_reference']) : '' ?></p>
            </div>
            <?= shipment_badge($s['status'], $mode) ?>
        </header>

        <div class="detail-grid">
            <dl class="detail-list">
                <div><dt>Client</dt><dd><?= e($s['client_name']) ?></dd></div>
                <div><dt>Téléphone</dt><dd><?= $s['client_phone'] ? e($s['client_phone']) : '—' ?></dd></div>
                <div><dt>Marchandise</dt><dd><?= nl2br(e($s['description'])) ?></dd></div>
                <?php foreach (['Poids', 'Volume', 'Colis'] as $k): ?>
                    <div><dt><?= $k ?></dt><dd><?= e(shipment_measures_text($s)[$k] ?? '—') ?></dd></div>
                <?php endforeach; ?>
                <div><dt>Lieu de retrait</dt><dd><?= $s['pickup_location'] ? e($s['pickup_location']) : '—' ?></dd></div>
            </dl>
            <dl class="detail-list">
                <div><dt>Départ</dt><dd><?= $s['origin'] ? e($s['origin']) : '—' ?></dd></div>
                <div><dt>Arrivée</dt><dd><?= $s['destination'] ? e($s['destination']) : '—' ?></dd></div>
                <div><dt>Arrivée prévue</dt><dd><?= $s['eta'] ? e(fdate($s['eta'])) : '—' ?></dd></div>
                <div><dt>Dernière mise à jour</dt><dd><?= e(fdate($s['updated_at'], true)) ?></dd></div>
            </dl>
        </div>

        <!-- Lien à envoyer au client -->
        <div class="track-share">
            <label for="track-link"><?= icon('share') ?> Lien de suivi à envoyer au client</label>
            <div class="track-share__row">
                <input id="track-link" type="text" readonly value="<?= e($link) ?>" onfocus="this.select()">
                <?php if ($whatsapp): ?>
                    <a href="<?= e($whatsapp . '?text=' . rawurlencode($waText)) ?>" class="btn btn--whatsapp btn--sm" target="_blank" rel="noopener noreferrer"><?= icon('whatsapp') ?> Envoyer par WhatsApp</a>
                <?php else: ?>
                    <a href="https://wa.me/?text=<?= e(rawurlencode($waText)) ?>" class="btn btn--ghost btn--sm" target="_blank" rel="noopener noreferrer"><?= icon('whatsapp') ?> Partager sur WhatsApp</a>
                <?php endif; ?>
            </div>
            <small class="field__hint">Le client peut aussi saisir le code <strong><?= e($s['tracking_number']) ?></strong><?= $s['carrier_reference'] ? ' ou le n° ' . e($s['carrier_reference']) : '' ?> sur la page « Suivi » du site.</small>
        </div>
    </article>

    <!-- ==================== PAIEMENTS (reçus de caisse « Fret / Transit » rattachés au dossier) ==================== -->
    <section class="panel">
        <header class="panel__head">
            <div>
                <h2 class="panel__title"><?= icon('receipt') ?> Paiements</h2>
                <?php if ($payments): ?>
                    <span class="cell-sub">Total réglé : <?= e(implode(' · ', array_map(fn ($c, $t) => money($t, $c), array_keys($paidTotals), $paidTotals))) ?></span>
                <?php endif; ?>
            </div>
            <a href="invoices.php?shipment=<?= $id ?>" class="btn btn--primary btn--sm"><?= icon('plus') ?> Encaisser un paiement</a>
        </header>
        <?php if (!$payments): ?>
            <p class="empty">Aucun paiement enregistré pour ce dossier. « Encaisser un paiement » ouvre un reçu de caisse « Fret / Transit » prérempli.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead><tr><th>Reçu</th><th>Date</th><th class="num">Montant réglé</th><th>Statut</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td data-label="Reçu"><a href="invoices.php?action=view&amp;id=<?= (int) $p['id'] ?>" class="link-strong"><?= e($p['invoice_number']) ?></a></td>
                            <td data-label="Date"><?= e(fdate($p['created_at'] ?: $p['issue_date'], true)) ?></td>
                            <td data-label="Montant réglé" class="num"><?= e(money($p['amount_received'], $p['target_currency'])) ?></td>
                            <td data-label="Statut"><?= badge($p['status'], INVOICE_STATUSES) ?></td>
                            <td class="actions">
                                <?= action_menu([
                                    ['link', 'Voir le reçu', 'invoices.php?action=view&id=' . (int) $p['id'], 'eye'],
                                    ['link', 'Imprimer le reçu', 'print-invoice.php?id=' . (int) $p['id'] . '&autoprint=1', 'printer', ['target' => '_blank']],
                                    ['link', 'Télécharger le reçu PDF', 'pdf.php?type=receipt&id=' . (int) $p['id'], 'download', ['download' => '']],
                                ], 'Actions du reçu ' . $p['invoice_number']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <div class="track-layout">
        <!-- ==================== MISE À JOUR DU STATUT ==================== -->
        <form method="post" class="panel form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="event">
            <input type="hidden" name="id" value="<?= $id ?>">
            <header class="panel__head"><h2 class="panel__title"><?= icon('refresh') ?> Mettre à jour le statut</h2></header>
            <div class="field">
                <label for="ev-status">Nouvelle étape *</label>
                <select id="ev-status" name="status"<?= $inv('status') ?>>
                    <?php foreach (SHIPMENT_STATUSES as $key => $st): ?>
                        <option value="<?= e($key) ?>" <?= $key === $eventForm['status'] ? 'selected' : '' ?>><?= e(shipment_status_label($key, $mode)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $err('status') ?>
            </div>
            <div class="field">
                <label for="ev-label">Libellé de l'événement <small>(facultatif)</small></label>
                <input id="ev-label" name="label" maxlength="<?= SHIPMENT_LIMITS['label'] ?>" value="<?= e((string) ($eventForm['label'] ?? '')) ?>"<?= $inv('label') ?> placeholder="Ex. Transbordement à Algésiras">
                <small class="field__hint">Étape intermédiaire : vide, le libellé du statut est affiché.</small>
                <?= $err('label') ?>
            </div>
            <div class="field">
                <label for="ev-date">Date et heure *</label>
                <input id="ev-date" name="event_date" type="datetime-local" required value="<?= e(str_replace(' ', 'T', substr((string) $eventForm['event_date'], 0, 16))) ?>"<?= $inv('event_date') ?>>
                <?= $err('event_date') ?>
            </div>
            <div class="field">
                <label for="ev-location">Lieu <small>(facultatif)</small></label>
                <input id="ev-location" name="location" maxlength="<?= SHIPMENT_LIMITS['location'] ?>" value="<?= e((string) $eventForm['location']) ?>"<?= $inv('location') ?> placeholder="Ex. Port de Douala, terminal à conteneurs">
                <?= $err('location') ?>
            </div>
            <div class="field">
                <label for="ev-note">Message au client <small>(facultatif)</small></label>
                <textarea id="ev-note" name="note" rows="3" maxlength="<?= SHIPMENT_LIMITS['note'] ?>"<?= $inv('note') ?> placeholder="Ex. Navire MSC Aurora, arrivée prévue le 12/11"><?= e((string) $eventForm['note']) ?></textarea>
                <small class="field__hint">Visible sur la page de suivi publique : n'y mettez rien de confidentiel.</small>
                <?= $err('note') ?>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= icon('check') ?> Ajouter l'étape</button>
            </div>
        </form>

        <!-- ==================== HISTORIQUE ==================== -->
        <section class="panel">
            <header class="panel__head"><h2 class="panel__title"><?= icon('history') ?> Frise du dossier</h2></header>
            <ol class="track-steps">
                <?php foreach ($timeline as $step): ?>
                    <li class="track-steps__item is-<?= e($step['state']) ?>">
                        <span class="track-steps__dot"><?= icon($step['state'] === 'done' ? 'check' : $step['icon']) ?></span>
                        <div>
                            <strong><?= e($step['label']) ?></strong>
                            <?php if ($step['event']): ?>
                                <small class="cell-sub"><?= e(fdate($step['event']['event_date'], true)) ?><?= $step['event']['location'] ? ' · ' . e($step['event']['location']) : '' ?></small>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>

            <h3 class="track-history__title">Toutes les étapes saisies (<?= count($events) ?>)</h3>
            <ul class="track-history">
                <?php foreach (array_reverse($events) as $ev): ?>
                    <li>
                        <div class="track-history__head">
                            <?= shipment_badge($ev['status'], $mode) ?>
                            <?php if ((string) $ev['label'] !== ''): ?><strong><?= e($ev['label']) ?></strong><?php endif; ?>
                            <span class="cell-sub"><?= e(fdate($ev['event_date'], true)) ?><?= $ev['location'] ? ' · ' . e($ev['location']) : '' ?></span>
                            <?php if (is_admin() && count($events) > 1): ?>
                                <form method="post" class="inline-form track-history__delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="delete_event">
                                    <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Supprimer cette étape" data-confirm="Supprimer l'étape « <?= e(shipment_status_label($ev['status'], $mode)) ?> » du <?= e(fdate($ev['event_date'], true)) ?> ?"><?= icon('trash') ?><span class="visually-hidden">Supprimer cette étape</span></button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($ev['note']): ?><p class="track-history__note"><?= nl2br(e($ev['note'])) ?></p><?php endif; ?>
                        <small class="cell-sub">Saisie par <?= e($ev['created_by_name'] ?? '—') ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
    <?php
    dash_footer();
    exit;
}

/* ==========================================================================
   Vue : liste des dossiers
   ========================================================================== */

$q       = mb_substr(query('q'), 0, 100);
$status  = isset(SHIPMENT_STATUSES[query('status')]) ? query('status') : '';
$page    = max(1, (int) query('page', '1'));
$perPage = 20;

$where  = [];
$params = [];
if ($q !== '') {
    $norm = shipment_normalize($q);
    $where[] = '(client_name LIKE ? OR description LIKE ? OR origin LIKE ? OR destination LIKE ?'
             . ($norm !== '' ? ' OR ' . shipment_sql_normalized('tracking_number') . ' LIKE ? OR ' . shipment_sql_normalized('carrier_reference') . ' LIKE ?' : '') . ')';
    array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
    if ($norm !== '') {
        array_push($params, '%' . $norm . '%', '%' . $norm . '%');
    }
}
if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total    = (int) db_value("SELECT COUNT(*) FROM shipments {$whereSql}", $params);
$page     = min($page, max(1, (int) ceil($total / $perPage)));
$offset   = ($page - 1) * $perPage;
$list     = db_all("SELECT * FROM shipments {$whereSql} ORDER BY updated_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}", $params);
$byStatus = array_column(db_all('SELECT status, COUNT(*) AS n FROM shipments GROUP BY status'), 'n', 'status');

dash_header('Suivi des colis', 'shipments');
?>
<div class="toolbar">
    <form method="get" class="filters" role="search">
        <div class="search">
            <?= icon('search') ?>
            <label class="visually-hidden" for="q">Rechercher</label>
            <input id="q" name="q" type="search" placeholder="Code, B/L, client, marchandise…" value="<?= e($q) ?>">
        </div>
        <label class="visually-hidden" for="f-status">Statut</label>
        <select id="f-status" name="status" data-autosubmit>
            <option value="">Tous les statuts</option>
            <?php foreach (SHIPMENT_STATUSES as $key => $st): ?>
                <option value="<?= e($key) ?>" <?= $key === $status ? 'selected' : '' ?>><?= e($st['label']) ?> (<?= (int) ($byStatus[$key] ?? 0) ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php if ($q !== '' || $status !== ''): ?><a href="shipments.php" class="btn btn--ghost btn--sm">Réinitialiser</a><?php endif; ?>
    </form>
    <div class="toolbar__actions">
        <a href="shipments.php?action=new" class="btn btn--primary"><?= icon('plus') ?> Nouveau dossier</a>
    </div>
</div>

<section class="panel">
    <?php if (!$list): ?>
        <p class="empty"><?= $where ? 'Aucun dossier ne correspond à ces critères.' : 'Aucun dossier de suivi pour le moment. <a href="shipments.php?action=new">Créer le premier dossier</a>' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--hover table--stack">
                <thead><tr><th>Code de suivi</th><th>Client</th><th>Marchandise</th><th>Trajet</th><th>Statut</th><th>Mis à jour</th><th class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($list as $s): $sid = (int) $s['id']; ?>
                    <tr>
                        <td data-label="Code de suivi"><span class="cell-stack">
                            <a href="shipments.php?id=<?= $sid ?>" class="link-strong"><?= e($s['tracking_number']) ?></a>
                            <?php if ($s['carrier_reference']): ?><small class="cell-sub"><?= e($s['carrier_reference']) ?></small><?php endif; ?>
                        </span></td>
                        <td data-label="Client"><?= e($s['client_name']) ?></td>
                        <td data-label="Marchandise"><span class="cell-stack"><span class="track-desc"><?= e(mb_strimwidth($s['description'], 0, 90, '…')) ?></span>
                            <?php if ($measures = shipment_measures_text($s)): ?><small class="cell-sub"><?= e(implode(' · ', $measures)) ?></small><?php endif; ?></span></td>
                        <td data-label="Trajet"><span class="cell-stack"><?= e(SHIPMENT_MODES[$s['transport_mode']] ?? $s['transport_mode']) ?>
                            <?php if ($s['origin'] || $s['destination']): ?><small class="cell-sub"><?= e(($s['origin'] ?: '?') . ' → ' . ($s['destination'] ?: '?')) ?></small><?php endif; ?></span></td>
                        <td data-label="Statut"><?= shipment_badge($s['status'], $s['transport_mode']) ?></td>
                        <td data-label="Mis à jour"><?= e(fdate($s['updated_at'], true)) ?></td>
                        <td class="actions">
                            <?= action_menu(array_filter([
                                ['link', 'Ouvrir / mettre à jour le statut', 'shipments.php?id=' . $sid, 'eye'],
                                ['link', 'Modifier le dossier', 'shipments.php?action=edit&id=' . $sid, 'edit'],
                                ['link', 'Voir comme le client', site_root() . 'suivi.php?code=' . rawurlencode($s['tracking_number']), 'external', ['target' => '_blank']],
                                is_admin() ? ['sep'] : [],
                                delete_menu_item($sid, 'Supprimer définitivement le dossier ' . $s['tracking_number'] . ' ? Le client ne pourra plus le suivre.'),
                            ]), 'Actions du dossier ' . $s['tracking_number']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination($total, $page, $perPage, array_filter(['q' => $q, 'status' => $status])) ?>
    <?php endif; ?>
</section>

<?php dash_footer(); ?>
