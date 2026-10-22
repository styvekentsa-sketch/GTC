<?php
/**
 * Configuration globale — Global Trading Corporation
 *
 * À inclure en tout premier dans chaque page, AVANT includes/header.php.
 * header.php ne redéfinit pas ces constantes si elles existent déjà
 * (garde `if (!defined('GTC_NAME'))`), ce fichier est donc la source de vérité.
 */

define('GTC_NAME', 'Global Trading Corporation');

// Téléphones
define('GTC_PHONE', '+237 233 43 13 22');
define('GTC_PHONE_LINK', '+237233431322');
define('GTC_PHONE_2', '+237 673 66 00 00');      // ligne du secrétariat (page Contact du site d'origine)
define('GTC_PHONE_2_LINK', '+237673660000');

// WhatsApp : format international, sans "+" ni espaces (utilisé par tous les boutons WhatsApp du site).
define('GTC_WHATSAPP', '237673660000');
define('GTC_WHATSAPP_DISPLAY', '+237 673 66 00 00');

// E-mail (affichage + destinataire du formulaire de contact)
define('GTC_EMAIL', 'gtc8181@gmail.com');

// Adresse
define('GTC_ADDRESS', '1.229 Paul Soppo Priso, Douala');
define('GTC_ADDRESS_SHORT', 'Douala - Rue Soppo Priso, Cameroun');
define('GTC_MAPS_QUERY', 'Douala-cameroun, Bonapriso, carrefour ancien chococho');

// Réseaux sociaux : renseignez l'adresse complète de chaque page (ex. https://www.facebook.com/votrepage).
// Un réseau sans adresse n'est pas affiché. Les adresses renseignées sont aussi publiées
// dans les données structurées Google (sameAs, includes/seo.php).
// Pied de page et page Contact : tous les réseaux renseignés · Barre de navigation : Facebook et LinkedIn.
const GTC_SOCIAL = [
    'facebook'  => 'https://www.facebook.com/GlobalTradingCorporationNew',
    'linkedin'  => '',
    'instagram' => 'https://www.instagram.com/global_trading_corporation344',
    'youtube'   => '',
    'twitter'   => 'https://x.com/GLOBALTRADINGC9',
];

// Site web (affiché sur les factures)
define('GTC_WEBSITE', 'globaltradingcorporation.net');

// Référencement (includes/seo.php) : adresse canonique du site, sans barre finale.
define('GTC_SITE_URL', 'https://globaltradingcorporation.net');
// Coordonnées GPS de l'agence (données structurées Google). Laissées vides, elles ne sont pas publiées.
// À relever sur Google Maps (clic droit sur l'agence → coordonnées), ex. '4.0350' et '9.6950'.
define('GTC_GEO_LAT', '');
define('GTC_GEO_LNG', '');

// Mentions légales des factures : à compléter avec les numéros officiels de l'entreprise.
// Laissés vides, ils ne sont pas affichés.
define('GTC_RCCM', 'RC/DLA/2019/B/2990');    // Registre du Commerce et du Crédit Mobilier
define('GTC_NIU', 'M061912787291Z');         // Numéro d'Identifiant Unique / N° de contribuable (impôts)
define('GTC_CNPS', '356-0014221-000-E');     // N° matricule CNPS (employeur)

// En-tête officiel des factures commerciales (dashboard/print-commercial-invoice.php), d'après les documents de la société
const GTC_ACTIVITIES = [
    "Transfert d'Argent", 'Transit', "Centrale d'achat", 'Exchange',
    'Import/Export', 'Consignation', 'Négoce', 'Agent Maritime',
];
const GTC_BANK = [
    'name'        => 'Afriland First Bank',
    'bank_code'   => '10005',
    'branch_code' => '00061',
    'account'     => '07275701001-27',
];
define('GTC_PO_BOX', 'BP : 1575 Douala-Bonapriso');
// Site indiqué sur les documents officiels (le site actuel est GTC_WEBSITE : vérifier lequel imprimer)
define('GTC_OFFICIAL_WEBSITE', 'globaltradingcorporation.com');

// Pages légales (dossier legal/) : à compléter. Une information laissée vide n'est pas affichée.
define('GTC_LEGAL_FORM', '');            // forme juridique et capital, ex. « SARL au capital de 1 000 000 FCFA »
define('GTC_PUBLICATION_DIRECTOR', '');  // directeur de la publication (vide : « le Directeur Général »)
define('GTC_HOST_NAME', '');             // hébergeur du site : raison sociale
define('GTC_HOST_ADDRESS', '');          // hébergeur : adresse
define('GTC_HOST_CONTACT', '');          // hébergeur : site web ou téléphone

date_default_timezone_set('Africa/Douala');

/**
 * Session du site public (formulaire de contact : jeton anti-falsification, saisie à réafficher).
 * Cookie distinct de celui du Dashboard (GTC_ADMIN) : les deux sessions ne se mélangent jamais.
 * Cookie inaccessible au JavaScript (HttpOnly), SameSite=Lax, sécurisé en HTTPS, identifiants imposés refusés.
 */
function gtc_public_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_start([
        'name'             => 'GTC_SITE',
        'cookie_httponly'  => true,
        'cookie_samesite'  => 'Lax',
        'cookie_secure'    => $https,
        'cookie_path'      => '/',
        'use_strict_mode'  => true,
        'use_only_cookies' => true,
    ]);
}

/**
 * Indique si l'agence est ouverte (heure de Douala).
 * Lundi-Vendredi : 08h-17h30 · Samedi : 09h-14h
 */
function gtc_is_open(?DateTimeInterface $now = null): bool
{
    $now     = $now ?? new DateTimeImmutable('now', new DateTimeZone('Africa/Douala'));
    $day     = (int) $now->format('N'); // 1 = lundi … 7 = dimanche
    $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');

    return ($day <= 5 && $minutes >= 8 * 60 && $minutes < 17 * 60 + 30)
        || ($day === 6 && $minutes >= 9 * 60 && $minutes < 14 * 60);
}
