GTC-Vault — bibliothèques hébergées
===================================

Les pages de GTC-Vault s'affichent dans la mise en page du Dashboard (vault_header(), includes/vault.php) avec
les styles du Dashboard (assets/css/dashboard.css) et les composants propres au module (assets/css/vault.css,
minifiée par « php tools/build.php » comme les autres feuilles de style). Aucune ressource n'est chargée depuis
un autre site.

PDF.js (pdfjs/) : version 3.11.174 de Mozilla (licence Apache 2.0). Le lecteur de la fiche client l'utilise avec
isEvalSupported:false : aucun code venant d'un PDF n'est exécuté.

QR code (qrcode/qrcode.min.js) : qrcode-generator 1.4.4 de Kazuhiko Arase (licence MIT). Il dessine sur le poste
de l'agent le QR code de capture par téléphone (clients-nouveau.php → capture.php).
