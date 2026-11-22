STARR SAVERS - SETUP GUIDE
==========================

PART 1: RUN IT LOCALLY (XAMPP, Windows)
---------------------------------------
1. Put the project in:  C:\xampp\htdocs\starr-savers-local
   (index.php, php, js, css, admin, config, includes, uploads must be
   directly inside that folder, not in another subfolder.)

2. Open XAMPP Control Panel, Config (Apache) -> "Apache (httpd.conf)".
   a) Under the line "Listen 80" add:   Listen 8081
   b) At the very bottom paste:

      <VirtualHost *:8081>
          DocumentRoot "C:/xampp/htdocs/starr-savers-local"
          <Directory "C:/xampp/htdocs/starr-savers-local">
              AllowOverride All
              Require all granted
          </Directory>
      </VirtualHost>

   Save. The site uses paths like /css/theme.css, so it must run from the
   root of its own address (that is why it gets its own port).

3. In XAMPP click Stop then Start on Apache, then Start on MySQL.
   Apache should show ports 80, 443 (and 8081 is now also listening).
   If the ports look strange (random numbers): Stop, Quit XAMPP, reopen it
   as Administrator, Start again. Still odd? Task Manager -> end every
   httpd.exe -> Start Apache again.

4. Database (first time only):
   - Go to http://localhost/phpmyadmin
   - New -> name: starr_savers -> collation utf8mb4_unicode_ci -> Create
   - Select it -> Import -> choose database.sql -> Import
   (config/db.local.php is already set for XAMPP: root, no password.)

5. Open the site:
   Shop:   http://localhost:8081/
   Admin:  http://localhost:8081/account.php?admin=1
   Email:    admin@starrsavers.co.za
   Password: ChangeMe!2026   (change it: Account -> Change password)

6. Edit config/app.local.php and set:
   'APP_URL' => 'http://localhost:8081'

UPDATING AN EXISTING SETUP
   Copy new files over the old ones (say Yes to replace).
   Your database is untouched.

WHAT WORKS LOCALLY
   Browsing, cart, delivery quotes, EFT orders, admin panel.
   (Delivery address lookup needs internet.)

WHAT NEEDS EXTRA SETUP
   Emails: download PHPMailer from github.com/PHPMailer/PHPMailer and upload
   Exception.php, PHPMailer.php, SMTP.php into /php/PHPMailer/src/
   then fill in the SMTP_* values in config/app.local.php.
   Card payments: put your Paystack TEST keys in config/app.local.php.

TROUBLESHOOTING
   Refused to connect  -> Apache not running, or wrong port in the address.
   XAMPP welcome page  -> files are not in the folder used by DocumentRoot.
   Database error      -> MySQL not started, or database.sql not imported,
                          or the database is not named exactly starr_savers.
   Blank white page    -> same as above; check C:\xampp\apache\logs\error.log


PART 2: GO LIVE (InfinityFree)
------------------------------
1. Create a MySQL database in the InfinityFree panel. Note host, name,
   user, password. Import database.sql with phpMyAdmin (no CREATE DATABASE).
2. Upload the project to /htdocs on the host. DO NOT upload the local
   config/db.local.php or config/app.local.php.
3. Create new live versions:
   config/db.local.php  -> your InfinityFree host, name, user, pass
   config/app.local.php -> copy app.local.sample.php and fill in:
      APP_URL (your https address, no trailing slash)
      APP_SECRET (long random string)
      SMTP details, ADMIN_NOTIFY_EMAIL
      Paystack keys (test first, live later)
4. Upload PHPMailer files (see above).
5. Paystack dashboard -> set webhook URL to:
   https://YOUR-DOMAIN/php/paystack-verify.php
6. Log in to admin, change the password, then fill in Settings
   (company details, VAT, bank details, branches, delivery fees)
   and add your real products and photos.
7. Test a full order with Paystack TEST keys, then switch to live keys.

SECURITY CHECKLIST
   - Change the admin password
   - STARR_DEBUG stays false in config/db.php
   - config/ and uploads/pop/ keep their .htaccess files
   - Have legal.php reviewed by a South African attorney
