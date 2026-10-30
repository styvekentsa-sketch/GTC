@echo off
rem ==========================================================================
rem  Global Trading Corporation : serveur de developpement local
rem  Double-cliquer sur ce fichier, puis ouvrir :
rem    Site public : http://localhost:8000/
rem    Dashboard   : http://localhost:8000/dashboard/
rem  Le serveur a pour racine le dossier du site (et non dashboard\) : sinon le
rem  Dashboard s'affiche sans mise en page ni images (dossier assets\ hors d'atteinte).
rem  Fermer cette fenetre arrete le serveur.
rem ==========================================================================
cd /d "%~dp0.."
echo Serveur GTC : http://localhost:8000/dashboard/
echo (fermer cette fenetre pour l'arreter)
php -S localhost:8000 -t "%cd%"
pause
