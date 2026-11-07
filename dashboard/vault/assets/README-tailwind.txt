GTC-Vault — feuille de style vault.css
=====================================

vault.css est générée par Tailwind CSS 3.4.17 à partir des classes utilisées dans dashboard/vault/*.php et
includes/vault.php (aucun script Tailwind n'est chargé dans le navigateur). Après avoir ajouté ou modifié des
classes Tailwind dans ces fichiers, régénérer la feuille depuis la racine du site :

  1. Créer (hors du site) un fichier tailwind.config.js :

       module.exports = {
         content: ['dashboard/vault/*.php', 'includes/vault.php'],
         theme: { extend: { fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Roboto', 'sans-serif'] } } },
       };

  2. Créer un fichier input.css contenant :

       @tailwind base;
       @tailwind components;
       @tailwind utilities;

  3. Lancer (Node.js requis) :

       npx tailwindcss@3.4.17 -c tailwind.config.js -i input.css -o dashboard/vault/assets/vault.css --minify

PDF.js (assets/pdfjs/) : version 3.11.174 de Mozilla, hébergée ici (aucune requête vers un CDN).
Le lecteur l'utilise avec isEvalSupported:false (aucun code venant d'un PDF n'est exécuté).

QR code (assets/qrcode/qrcode.min.js) : qrcode-generator 1.4.4 de Kazuhiko Arase (licence MIT), hébergé ici.
Il dessine sur le poste de l'agent le QR code de capture par téléphone (clients-nouveau.php → capture.php).
