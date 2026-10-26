<?php
/**
 * Courriers officiels (lettres aux entreprises et administrations) sur le papier à en-tête de GTC.
 *
 *   dashboard/courriers.php        Liste (recherche, statut), rédaction, modification
 *   dashboard/print-courrier.php   Impression A4 sur le papier à en-tête officiel
 *   dashboard/pdf.php?type=courrier&id=X   PDF (includes/courrier_pdf.php)
 *
 * Numérotation annuelle : GTC-COURRIER-2026-001 (attribuée à la création, conservée ensuite).
 * Présentation (normes administratives françaises et camerounaises) : expéditeur dans l'en-tête, lieu et date
 * puis destinataire à droite, références et objet à gauche, appel, corps justifié, formule de politesse,
 * signature à droite (nom et qualité du signataire, place du cachet).
 */

const COURRIER_PREFIX = 'GTC-COURRIER-';

const COURRIER_STATUSES = [
    'draft' => ['label' => 'Brouillon', 'tone' => 'muted'],
    'sent'  => ['label' => 'Envoyé',    'tone' => 'success'],
];

/** Valeurs proposées pour un nouveau courrier. */
const COURRIER_DEFAULTS = [
    'place'      => 'Douala',
    'country'    => 'Cameroun',
    'salutation' => 'Madame, Monsieur,',
    'closing'    => "Dans l'attente de votre retour, nous vous prions d'agréer, Madame, Monsieur, l'expression de nos salutations distinguées.",
];

/** Longueur maximale de chaque champ (caractères). */
const COURRIER_LIMITS = [
    'recipient_company' => 150, 'recipient_name' => 150, 'recipient_role' => 150, 'recipient_address' => 255,
    'recipient_city' => 100, 'recipient_country' => 100, 'place' => 100, 'subject' => 255, 'salutation' => 150,
    'body' => 60000, 'closing' => 500,   // corps : HTML nettoyé (courrier_sanitize_html) 'signatory_name' => 100, 'signatory_title' => 100, 'attachments' => 255,
];

/** Date au format « 26 octobre 2026 » (ou « 1er octobre 2026 »). */
function courrier_long_date(string $date): string
{
    static $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($date);
    if (!$t) {
        return $date;
    }
    $day = (int) date('j', $t);
    return ($day === 1 ? '1er' : $day) . ' ' . $months[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}

/* ==========================================================================
   Variables de publipostage : balises remplacées à l'impression et dans le PDF (pas à l'enregistrement,
   la référence n'étant connue qu'après la création)
   ========================================================================== */

/** Balise => [libellé du bouton, valeur tirée du courrier]. */
function courrier_variables(array $c): array
{
    return [
        "[Nom de l'entreprise]" => ['Entreprise destinataire', trim((string) ($c['recipient_company'] ?? ''))],
        '[Nom du destinataire]' => ['Destinataire',            trim((string) ($c['recipient_name'] ?? ''))],
        '[Date du jour]'        => ['Date du courrier',        courrier_long_date((string) ($c['letter_date'] ?? date('Y-m-d')))],
        '[Nom du signataire]'   => ['Signataire',              trim((string) ($c['signatory_name'] ?? ''))],
        '[Référence]'           => ['Référence',               (string) ($c['reference'] ?? '')],
    ];
}

/** Remplace les balises connues (les autres crochets, ex. « [Nom du poste] », restent tels quels). */
function courrier_merge(string $text, array $c, bool $html): string
{
    $esc = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $map = [];
    foreach (courrier_variables($c) as $tag => [, $value]) {
        if ($value === '') {
            continue;   // valeur inconnue : la balise reste visible pour être complétée
        }
        $map[$tag] = $html ? $esc($value) : $value;
        if ($html) {
            $map[$esc($tag)] = $esc($value);   // balise dont l'apostrophe a été échappée dans le HTML (&#039;)
        }
    }
    return strtr($text, $map);
}

/* ==========================================================================
   Modèles de courrier (pré-remplissage de l'objet et du corps dans le formulaire)
   ========================================================================== */

const COURRIER_TEMPLATES = [
    'standard' => [
        'label'   => 'Courrier libre / Standard',
        'subject' => '',
        'body'    => '<p>Nous avons l\'honneur de porter à votre connaissance que…</p><p>[Développez ici l\'objet de votre courrier.]</p><p>Nous restons à votre entière disposition pour tout complément d\'information.</p>',
    ],
    'services' => [
        'label'   => 'Offre de services / Proposition commerciale',
        'subject' => 'Offre de services de Global Trading Corporation',
        'body'    => '<p>Nous avons l\'honneur de vous présenter <strong>Global Trading Corporation</strong>, société basée à Douala et active dans le commerce international et la logistique.</p>'
                   . '<p>Afin d\'accompagner [Nom de l\'entreprise] dans ses opérations, nous mettons à votre disposition les services suivants :</p>'
                   . '<ul><li><strong>Transit et dédouanement</strong> de vos marchandises ;</li><li><strong>Consignation et agence maritime</strong> au Port Autonome de Douala ;</li><li><strong>Import / export et négoce</strong>, avec paiement de vos fournisseurs à l\'étranger ;</li><li><strong>Transfert d\'argent et change de devises</strong>.</li></ul>'
                   . '<p>Nous serions honorés de vous adresser une proposition adaptée à vos besoins et restons disponibles pour une rencontre à votre convenance.</p>',
    ],
    'cotation' => [
        'label'   => 'Demande de cotation / Partenariat',
        'subject' => 'Demande de cotation et proposition de partenariat',
        'body'    => '<p>Dans le cadre du développement de nos activités, nous souhaitons recevoir votre meilleure cotation pour la prestation suivante :</p>'
                   . '<ul><li>Nature de la prestation ou des marchandises : [à préciser] ;</li><li>Quantités et caractéristiques : [à préciser] ;</li><li>Délais souhaités : [à préciser].</li></ul>'
                   . '<p>Au-delà de cette demande, nous serions intéressés par l\'établissement d\'un <strong>partenariat durable</strong> entre [Nom de l\'entreprise] et Global Trading Corporation.</p>'
                   . '<p>Nous vous remercions de bien vouloir rappeler notre référence <strong>[Référence]</strong> dans votre réponse.</p>',
    ],
    'candidature' => [
        'label'   => 'Candidature / Demande de stage',
        'subject' => 'Demande de stage',
        'body'    => '<p>Nous avons l\'honneur de solliciter auprès de [Nom de l\'entreprise] un stage au profit de [Nom et prénom du candidat], [formation / niveau d\'études], pour une durée de [durée] à compter du [date de début].</p>'
                   . '<p>Ce stage lui permettrait de compléter sa formation dans un environnement professionnel reconnu et de mettre en pratique ses compétences en [domaine].</p>'
                   . '<p>Vous trouverez ci-joint son curriculum vitae. Nous restons à votre disposition pour tout entretien que vous jugeriez utile.</p>',
    ],
];

/* ==========================================================================
   Corps du courrier en texte enrichi (éditeur du formulaire) — HTML strictement filtré
   Conservés : paragraphes, retours à la ligne, gras, italique, souligné, listes à puces / numérotées,
   alignement (gauche, centré, droite, justifié). Tout le reste (scripts, styles, liens, polices Word…) est retiré.
   ========================================================================== */

const COURRIER_ALIGNS = ['left', 'center', 'right', 'justify'];

/** Corps enregistré → HTML : un ancien corps en texte brut devient des paragraphes (ligne vide = nouveau paragraphe). */
function courrier_body_html(string $body): string
{
    if (preg_match('/<(p|div|br|ul|ol|li|strong|b|em|i|u|span)\b/i', $body)) {
        return $body;
    }
    $paras = preg_split('/\R\s*\R/u', trim(str_replace("\r", '', $body)));
    $html = '';
    foreach ($paras as $p) {
        if (trim($p) !== '') {
            $html .= '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
        }
    }
    return $html;
}

/**
 * Analyse un HTML quelconque en blocs : [['list' => null|'ul'|'ol', 'align' => null|'left'…, 'runs' => [
 *   ['t' => texte, 'b' => bool, 'i' => bool, 'u' => bool] | ['br' => true], …]], …].
 * Base commune du nettoyage (courrier_sanitize_html) et du PDF (includes/courrier_pdf.php).
 */
function courrier_blocks(string $html): array
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $blocks = [];
    $current = null;   // bloc en cours de remplissage
    $flush = function () use (&$blocks, &$current): void {
        if ($current !== null && $current['runs']) {   // conteneur sans contenu propre (ex. <div> autour de <p>) : ignoré
            $blocks[] = $current;
        }
        $current = null;
    };
    $open = function (?string $list, ?string $align) use (&$current, $flush): void {
        $flush();
        $current = ['list' => $list, 'align' => $align, 'runs' => []];
    };
    $alignOf = function (DOMElement $el): ?string {
        $a = strtolower(trim($el->getAttribute('align')));
        if (preg_match('/text-align\s*:\s*(left|center|right|justify|start|end)/i', $el->getAttribute('style'), $m)) {
            $a = strtolower($m[1]);
        }
        $a = ['start' => 'left', 'end' => 'right'][$a] ?? $a;
        return in_array($a, COURRIER_ALIGNS, true) ? $a : null;
    };
    // $inLi : à l'intérieur d'un élément de liste, un paragraphe imbriqué devient un retour à la ligne du même élément
    $walk = function (DOMNode $node, array $style, ?string $align, ?string $list, bool $inLi = false) use (&$walk, &$current, $open, $flush, $alignOf): void {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = preg_replace('/[ \t\r\n]+/u', ' ', $child->nodeValue);
                if ($text === '') {
                    continue;
                }
                if ($current === null) {
                    if (trim($text) === '') {
                        continue;   // espaces entre deux blocs
                    }
                    $open($list ? $list : null, $align);
                }
                $current['runs'][] = ['t' => $text] + $style;
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['script', 'style', 'head', 'title', 'meta', 'link', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript', 'template', 'img', 'video', 'audio', 'input', 'button', 'select', 'textarea'], true)) {
                continue;
            }
            $s = $style;
            switch ($tag) {
                case 'br':
                    if ($current === null) {
                        $open($list, $align);
                    }
                    $current['runs'][] = ['br' => true];
                    break;
                case 'b': case 'strong':
                    $s['b'] = true;
                    $walk($child, $s, $align, $list, $inLi);
                    break;
                case 'i': case 'em':
                    $s['i'] = true;
                    $walk($child, $s, $align, $list, $inLi);
                    break;
                case 'u': case 'ins':
                    $s['u'] = true;
                    $walk($child, $s, $align, $list, $inLi);
                    break;
                case 'ul': case 'ol':
                    $flush();
                    $walk($child, $style, $alignOf($child) ?? $align, $tag);
                    $flush();
                    break;
                case 'li':
                    $open($list ?? 'ul', $alignOf($child) ?? $align);
                    $walk($child, $style, $alignOf($child) ?? $align, $list ?? 'ul', true);
                    $flush();
                    break;
                case 'p': case 'div': case 'blockquote': case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                    if ($inLi) {
                        if ($current !== null && $current['runs']) {
                            $current['runs'][] = ['br' => true];
                        }
                        $walk($child, $style, $align, $list, true);
                        break;
                    }
                    $open($list, $alignOf($child) ?? $align);
                    $walk($child, $style, $alignOf($child) ?? $align, $list);
                    $flush();
                    break;
                default:   // span, font, a… : seul le contenu est gardé (gras / italique / souligné écrits en style CSS compris)
                    $css = strtolower($child->getAttribute('style'));
                    if (preg_match('/font-weight\s*:\s*(bold|[6-9]00)/', $css)) {
                        $s['b'] = true;
                    }
                    if (str_contains($css, 'italic')) {
                        $s['i'] = true;
                    }
                    if (str_contains($css, 'underline')) {
                        $s['u'] = true;
                    }
                    $walk($child, $s, $align, $list, $inLi);
            }
        }
    };
    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body) {
        $walk($body, ['b' => false, 'i' => false, 'u' => false], null, null);
    }
    $flush();

    // Nettoyage : espaces en début / fin de bloc, <br> final, blocs vides en tête et en fin de document
    foreach ($blocks as &$block) {
        while ($block['runs'] && isset(end($block['runs'])['br']) && count($block['runs']) > 1) {
            array_pop($block['runs']);
        }
        foreach ([0, count($block['runs']) - 1] as $edge) {
            if (isset($block['runs'][$edge]['t'])) {
                $block['runs'][$edge]['t'] = $edge === 0 ? ltrim($block['runs'][$edge]['t']) : rtrim($block['runs'][$edge]['t']);
            }
        }
        // Pas d'espace en fin de ligne avant un retour à la ligne, ni en début de la ligne suivante
        foreach ($block['runs'] as $k => $r) {
            if (isset($r['br'])) {
                if (isset($block['runs'][$k - 1]['t'])) {
                    $block['runs'][$k - 1]['t'] = rtrim($block['runs'][$k - 1]['t']);
                }
                if (isset($block['runs'][$k + 1]['t'])) {
                    $block['runs'][$k + 1]['t'] = ltrim($block['runs'][$k + 1]['t']);
                }
            }
        }
    }
    unset($block);
    $isEmpty = fn ($b) => trim(implode('', array_map(fn ($r) => $r['t'] ?? '', $b['runs']))) === '';
    while ($blocks && $isEmpty($blocks[0])) {
        array_shift($blocks);
    }
    while ($blocks && $isEmpty(end($blocks))) {
        array_pop($blocks);
    }
    return $blocks;
}

/** HTML propre et sûr à partir de n'importe quel HTML (enregistrement et affichage). */
function courrier_sanitize_html(string $html): string
{
    $out = '';
    $openList = null;
    foreach (courrier_blocks($html) as $block) {
        if ($block['list'] !== $openList) {
            $out .= $openList ? "</{$openList}>" : '';
            $out .= $block['list'] ? "<{$block['list']}>" : '';
            $openList = $block['list'];
        }
        $inner = '';
        foreach ($block['runs'] as $r) {
            if (isset($r['br'])) {
                $inner .= '<br>';
                continue;
            }
            $t = htmlspecialchars($r['t'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($r['u']) $t = "<u>{$t}</u>";
            if ($r['i']) $t = "<em>{$t}</em>";
            if ($r['b']) $t = "<strong>{$t}</strong>";
            $inner .= $t;
        }
        $tag = $block['list'] ? 'li' : 'p';
        $style = $block['align'] ? ' style="text-align: ' . $block['align'] . '"' : '';
        $out .= "<{$tag}{$style}>" . ($inner === '' ? '<br>' : $inner) . "</{$tag}>";
    }
    return $out . ($openList ? "</{$openList}>" : '');
}

/** Texte brut du corps (contrôle « corps vide », recherche). */
function courrier_body_text(string $html): string
{
    return trim(implode("\n", array_map(fn ($b) => implode('', array_map(fn ($r) => $r['t'] ?? "\n", $b['runs'])), courrier_blocks($html))));
}

/** Bloc destinataire, ligne par ligne (lignes vides retirées). */
function courrier_recipient_lines(array $c): array
{
    $cityLine = trim(implode(' — ', array_filter([trim((string) $c['recipient_city']), trim((string) $c['recipient_country'])])));
    return array_values(array_filter([
        trim((string) $c['recipient_name']) !== '' ? trim((string) $c['recipient_name']) : null,
        trim((string) $c['recipient_role']) !== '' ? trim((string) $c['recipient_role']) : null,
        trim((string) $c['recipient_company']) !== '' ? trim((string) $c['recipient_company']) : null,
        trim((string) $c['recipient_address']) !== '' ? trim((string) $c['recipient_address']) : null,
        $cityLine !== '' ? $cityLine : null,
    ]));
}

/** Prochaine référence de l'année : GTC-COURRIER-2026-001. À appeler dans une transaction. */
function courrier_next_reference(string $date): string
{
    $prefix = COURRIER_PREFIX . substr($date, 0, 4) . '-';
    $max = 0;
    foreach (db_all('SELECT reference FROM courriers WHERE reference LIKE ?', [$prefix . '%']) as $row) {
        $max = max($max, (int) substr($row['reference'], strlen($prefix)));
    }
    return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

/** Signataire du dernier courrier (pré-remplissage), à défaut le compte connecté. */
function courrier_last_signatory(array $user): array
{
    $last = db_one('SELECT signatory_name, signatory_title FROM courriers ORDER BY id DESC LIMIT 1');
    return $last ?: ['signatory_name' => $user['name'], 'signatory_title' => ''];
}

/** Lecture et contrôle du formulaire : [données, erreurs]. */
function courrier_input(): array
{
    $d = [];
    foreach (COURRIER_LIMITS as $key => $max) {
        // UTF-8 garanti AVANT tout traitement (une expression /u sur un texte Windows-1252 renverrait null)
        $value = (string) ($_POST[$key] ?? '');
        $value = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        $d[$key] = $key === 'body' || $key === 'closing'
            ? trim(str_replace("\r", '', $value))
            : trim((string) preg_replace('/\s+/u', ' ', $value));
    }
    $d['letter_date'] = (string) ($_POST['letter_date'] ?? '');
    $d['status']      = isset(COURRIER_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'draft';
    // Corps : HTML de l'éditeur (ou texte brut sans JavaScript) réduit aux mises en forme autorisées
    $d['body'] = courrier_body_text(courrier_body_html($d['body'])) === '' ? '' : courrier_sanitize_html(courrier_body_html($d['body']));

    $errors = [];
    foreach (COURRIER_LIMITS as $key => $max) {
        if (mb_strlen($d[$key]) > $max) {
            $errors[$key] = "{$max} caractères au maximum.";
        }
    }
    if ($d['recipient_company'] === '' && $d['recipient_name'] === '') {
        $errors['recipient_company'] = "Indiquez l'entreprise ou le nom du destinataire.";
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d['letter_date']);
    if (!$dt || $dt->format('Y-m-d') !== $d['letter_date']) {
        $errors['letter_date'] = 'Date invalide.';
    }
    foreach (['place' => 'Indiquez le lieu d\'émission.', 'subject' => "Indiquez l'objet du courrier.",
              'body' => 'Rédigez le contenu de la lettre.', 'signatory_name' => 'Indiquez le nom du signataire.'] as $key => $msg) {
        if ($d[$key] === '' && !isset($errors[$key])) {
            $errors[$key] = $msg;
        }
    }
    return [$d, $errors];
}

/** Enregistre un courrier (création si $id est null). Retourne son identifiant. */
function courrier_save(array $d, array $user, ?int $id = null): int
{
    $fields = [];
    foreach (array_keys(COURRIER_LIMITS) as $key) {
        $fields[$key] = $d[$key] === '' ? null : $d[$key];
    }
    $fields['letter_date'] = $d['letter_date'];
    $fields['status']      = $d['status'];
    $fields['updated_at']  = db_now();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id === null) {
            $fields += ['reference' => courrier_next_reference($d['letter_date']), 'created_by' => (int) $user['id'],
                        'created_by_name' => $user['name'], 'created_at' => db_now()];
            $cols = array_keys($fields);
            db_query('INSERT INTO courriers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($fields));
            $id = (int) $pdo->lastInsertId();
        } else {
            $set = implode(', ', array_map(fn ($c) => "{$c} = ?", array_keys($fields)));
            db_query("UPDATE courriers SET {$set} WHERE id = ?", [...array_values($fields), $id]);
        }
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Courrier (champs vides en chaîne vide), ou null. */
function courrier_find(int $id): ?array
{
    $c = db_one('SELECT * FROM courriers WHERE id = ?', [$id]);
    if (!$c) {
        return null;
    }
    foreach (array_keys(COURRIER_LIMITS) as $key) {
        $c[$key] = (string) ($c[$key] ?? '');
    }
    return $c;
}

/** Destinataire en une ligne pour la liste : « Société — Nom ». */
function courrier_recipient_label(array $c): string
{
    return implode(' — ', array_filter([trim((string) $c['recipient_company']), trim((string) $c['recipient_name'])])) ?: '—';
}
