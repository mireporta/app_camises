# app_camises

Projecte PHP senzill per a control d'estoc.

## Instal·lació ràpida (servidor real, carpeta compartida)

1. Copia la carpeta `app_camises/public` al directori web del teu servidor (per exemple, `/var/www/html/app_camises` o la carpeta compartida que utilitzeu).
2. Ajusta permisos si cal perquè el servidor web pugui llegir els fitxers.
3. Crea la base de dades i dades d'exemple:
   ```bash
   mysql -u hamelin -p inventari_camises < sql/init_db.sql
   ```
   (Introdueix la contrasenya `camises` quan et demani)
   Si prefereixes, pots executar l'script directament sense especificar la BD:
   ```bash
   mysql -u hamelin -p < sql/init_db.sql
   ```

4. Composer (instal·la les dependències, inclòs PhpSpreadsheet):
   - Si no tens Composer instal·lat al servidor, instal·la'l seguint https://getcomposer.org/
   - Executa a la carpeta arrel (`app_camises`):
     ```bash
     composer install
     ```

5. Assegura't que `src/config.php` té aquests valors (ja configurats per defecte):
   ```
   host: localhost
   dbname: inventari_camises
   user: hamelin
   pass: camises
   ```

6. Obre el navegador i accedeix a la ruta del projecte (ex: `http://servidor/app_camises/index.php`).

## Notes
- Les funcionalitats d'importació/exportació d'Excel requereixen PhpSpreadsheet (instal·lada via Composer).
- Si no vols utilitzar Composer, pots instal·lar manualment la biblioteca i ajustar `vendor/autoload.php`.
- És recomanable afegir autenticació abans d'usar l'aplicació en producció.

