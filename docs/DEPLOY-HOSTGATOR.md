# Deploying on HostGator

Layout used below: **API** at `https://api.yourdomain.com` (Laravel, `api/public` as document root) and
**frontend** at `https://wa.yourdomain.com` (static React build). One domain also works (see “Single domain”).

## Requirements & shared-hosting caveats
- **Laravel 13 needs PHP ≥ 8.3** (8.4 recommended) with `pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, fileinfo, curl`.
  Older HostGator shared servers may not offer 8.3+; check *cPanel → Select PHP Version / MultiPHP Manager* before buying.
- **SSH access** is needed for `composer`/`artisan` (Hatchling/Baby plans: enable SSH in cPanel or ask support). Without SSH you must run
  `composer install --no-dev` locally and upload `vendor/` (works, but slow and fragile).
- No Node on shared hosting: build the React app locally and upload `web/dist`.
- No long-running processes: no queue workers or websockets (this app doesn't need them; the UI polls).
- Meta requires a **public HTTPS** webhook. AutoSSL covers this on cPanel.
- Uploaded media lives in `api/storage/app/public`, so it counts toward your disk/inode quota.

---
## A. Shared / cPanel hosting

### 1. PHP version
cPanel → **MultiPHP Manager**: set PHP 8.3/8.4 for both subdomains. In **Select PHP Version → Extensions**, enable the extensions listed above.
For CLI over SSH, use `php -v`. If it's older, call the versioned binary, e.g. `/opt/cpanel/ea-php84/root/usr/bin/php` (alias it in `~/.bashrc`).

### 2. MySQL database
cPanel → **MySQL Databases**: create a DB (e.g. `cpuser_wab`) and a user with a strong password, then **Add User to Database → ALL PRIVILEGES**.
Host is `localhost`. Names get your cPanel prefix (`cpuser_`).

### 3. Get the code onto the server
Keep the project **outside** `public_html`, e.g. `/home/cpuser/whatsapp-business`.
- **cPanel → Git™ Version Control → Create**: clone URL `https://github.com/anaypareek/whatsapp_business.git`, path `/home/cpuser/whatsapp-business`.
  For a private repo, use a deploy key (cPanel → SSH Access → generate key, add the public key to GitHub *Deploy keys*) with the SSH URL.
- or over SSH: `cd ~ && git clone git@github.com:anaypareek/whatsapp_business.git whatsapp-business`
- or upload a zip through File Manager and extract it.

### 4. Install and configure Laravel (SSH)
```bash
cd ~/whatsapp-business/api
# composer: use the server's composer, or: curl -sS https://getcomposer.org/installer | php && alias composer="php ~/whatsapp-business/api/composer.phar"
composer install --no-dev --optimize-autoloader
cp .env.example .env
nano .env        # APP_ENV=production, APP_DEBUG=false, APP_URL=https://api.yourdomain.com,
                 # DB_DATABASE/DB_USERNAME/DB_PASSWORD from step 2, ADMIN_EMAIL=you@yourdomain.com
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force            # creates the admin. If ADMIN_PASSWORD is empty, a password is written to
                                       # ~/whatsapp-business/secrets/admin-credentials.txt (chmod 600). Read it, then delete the file.
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R 775 storage bootstrap/cache
```
Re-run `php artisan config:cache` after every `.env` change.

### 5. Point the API subdomain at `api/public`
cPanel → **Domains** (or Subdomains) → create `api.yourdomain.com` with **Document Root** `/home/cpuser/whatsapp-business/api/public`.
Laravel's `public/.htaccess` already handles routing and passes the `Authorization` header.

*If you can't change the document root* (for example the main domain is stuck on `public_html`): put the project outside `public_html`, then either
symlink `ln -s ~/whatsapp-business/api/public ~/public_html/api` (and use `https://yourdomain.com/api/api/...`, or set the subdomain to that folder),
or add this `public_html/.htaccess` to rewrite everything into `public/`:
```apache
RewriteEngine On
RewriteRule ^(.*)$ whatsapp-business/api/public/$1 [L]
```
(Only if the project sits inside `public_html`. Then make sure `.env` isn't web-readable: Laravel's root has no index, but add `Deny from all` in `api/.htaccess`.)

### Updating the live site after `git pull` (clients + client portal release)
Run these on the server over SSH, from the domain root (`~/whatsappwork.fineoutput.com`):
```bash
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
cd ~/whatsappwork.fineoutput.com
git pull origin main
cd api
$PHP ~/composer.phar install --no-dev --optimize-autoloader
$PHP artisan migrate --force          # adds the clients table and client_id on phone_numbers/message_templates
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
cd ..
cp deploy/hostgator-root.htaccess .htaccess   # new routing: portal at /, admin at /wab-control-7x/
rm -f web/site/index.html                     # old landing page, now replaced by the client portal
```
Then build the frontend **on your machine** (HostGator shared hosting has no Node) and upload it:
```bash
cd web && npm ci && VITE_ADMIN_BASE=/wab-control-7x npm run build
rsync -av --delete -e 'ssh -p 2222' dist/ fineo1l1@103.53.42.51:whatsappwork.fineoutput.com/web/dist/
```
Check: `https://whatsappwork.fineoutput.com/login` (client login), `/wab-control-7x/login` (admin), and `/admin` should return 404.
Then create clients under **Admin → Clients** and assign numbers to them in **Number Master**.

### 6. Frontend (build locally, upload dist)
> **Live layout (whatsappwork.fineoutput.com):** the repo sits in the domain's document root. The root `.htaccess` is
> `deploy/hostgator-root.htaccess`. It sends `/api`, `/storage` and `/up` to Laravel, serves the admin SPA from `web/dist` at
> **`/wab-control-7x/`** (build with `VITE_ADMIN_BASE=/wab-control-7x npm run build`), serves the **client portal** (`web/dist/index.html`) at `/`, `/login`, `/dashboard`, `/chats`, `/numbers` and `/templates`, serves hashed bundles at `/assets/*`, and returns
> `web/site/404.html` (HTTP 404) for everything else, including `/admin`. It also blocks dotfiles, `docs/`, `tools/`, `README.md` and `secrets/`.
> If you change the admin path, change it in both `VITE_ADMIN_BASE` and the `.htaccess`. Never link the admin path from public pages.

The frontend calls relative `/api/...`. In production either serve it from the same host as the API (see *Single domain*) or set an API
base URL. To use a separate host, change `fetch(\`/api${path}\`` in `web/src/api.js` to
`fetch(\`${import.meta.env.VITE_API_BASE || ''}/api${path}\``, then:
```bash
cd web && npm ci && VITE_API_BASE=https://api.yourdomain.com npm run build     # outputs web/dist
```
Create `wa.yourdomain.com` (doc root e.g. `/home/cpuser/wa`), upload the *contents* of `web/dist/`, and add `.htaccess` for SPA routing:
```apache
RewriteEngine On
RewriteBase /
RewriteRule ^index\.html$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.html [L]
```
**CORS** for a separate host: in `api/config/cors.php` set `'allowed_origins' => ['https://wa.yourdomain.com']` (`php artisan config:publish cors` if
the file is missing), then `php artisan config:cache`. Media links (`/storage/...`) are relative, so either serve the SPA from the API host or prefix them.

**Single domain (simplest, no CORS):** copy the contents of `web/dist/` into `api/public/` (keep Laravel's `index.php` and `.htaccess`). Then
add the SPA fallback to `routes/web.php`:
```php
Route::get('/{any}', fn () => file_get_contents(public_path('index.html')))->where('any', '^(?!api|storage|up).*$');
```
The UI and API then share `https://wa.yourdomain.com`.

### 7. SSL
cPanel → **SSL/TLS Status → Run AutoSSL** for both subdomains (free, auto-renewing). Force HTTPS by adding this at the top of `api/public/.htaccess`:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 8. Cron
cPanel → **Cron Jobs**, every minute:
```
* * * * * cd /home/cpuser/whatsapp-business/api && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```
(No scheduled tasks yet. This is in place for future ones, such as periodic template sync.)

### 9. Meta configuration
1. Log in to the portal → **Settings**: App ID, App Secret, permanent System User access token, WABA ID, and a random verify token.
2. Meta App Dashboard → WhatsApp → Configuration → Webhook:
   - Callback URL: `https://api.yourdomain.com/api/webhook/whatsapp`
   - Verify token: the same as in Settings → **Verify and save**
   - Subscribe fields: `messages`, `message_template_status_update`, `message_template_quality_update`, `template_category_update`
3. Number Master → **Sync from Meta**, Template Master → **Sync from Meta**.
4. API Keys → generate a key for the Oswal Soap store, which posts to `https://api.yourdomain.com/api/v1/messages/send`.
Some shared hosts' ModSecurity rules block webhook POSTs. If Meta reports delivery failures, check *cPanel → Errors* and ask support to whitelist the path.

### 10. Updating
```bash
cd ~/whatsapp-business && git pull     # or cPanel Git → Update from Remote
cd api && composer install --no-dev -o && php artisan migrate --force && php artisan config:cache && php artisan route:cache
# rebuild web locally and re-upload dist
```

### Creating more admins
Portal → Users (admin only), or over SSH:
`php artisan tinker --execute="App\Models\User::create(['name'=>'X','email'=>'x@y.com','password'=>'Str0ngPass!','is_admin'=>true]);"`

---
## B. HostGator VPS / Dedicated (recommended for production)
Root access, any PHP version, real Node, and no ModSecurity surprises.
1. Choose AlmaLinux/Ubuntu (or the cPanel VPS image, which works like section A with WHM → *EasyApache 4* for PHP 8.4).
2. Without cPanel (Ubuntu):
   ```bash
   apt install nginx mariadb-server php8.4-fpm php8.4-{mysql,mbstring,xml,curl,zip,bcmath,intl} composer nodejs npm certbot python3-certbot-nginx
   mysql -e "CREATE DATABASE whatsapp_business; CREATE USER 'wab'@'localhost' IDENTIFIED BY '<strong>'; GRANT ALL ON whatsapp_business.* TO 'wab'@'localhost';"
   git clone https://github.com/anaypareek/whatsapp_business.git /var/www/wab && cd /var/www/wab/api
   composer install --no-dev -o && cp .env.example .env && php artisan key:generate && php artisan migrate --force --seed && php artisan storage:link && php artisan config:cache
   chown -R www-data:www-data storage bootstrap/cache
   cd ../web && npm ci && npm run build
   ```
3. nginx: one server block for `wa.yourdomain.com` with `root /var/www/wab/web/dist; try_files $uri /index.html;`, and
   `location ~ ^/(api|storage|up)` passed to Laravel (`root /var/www/wab/api/public; try_files $uri /index.php?$query_string;` plus `fastcgi_pass unix:/run/php/php8.4-fpm.sock`).
   The relative `/api` calls then work with no CORS. Set `client_max_body_size 20M;` for uploads.
4. `certbot --nginx -d wa.yourdomain.com` for free SSL. Add the crontab line `* * * * * cd /var/www/wab/api && php artisan schedule:run`.
5. Firewall: `ufw allow 22,80,443/tcp`. Keep MySQL local-only.

## Checklist
- [ ] PHP ≥ 8.3, SSH works, `composer install` ran
- [ ] `.env` set (APP_DEBUG=false, APP_URL https), `key:generate`, `migrate --force`, admin seeded, credentials file removed
- [ ] Document root points to `api/public`; `/up` returns 200 over HTTPS
- [ ] Frontend loads, login works, `/api` reachable (CORS or same domain)
- [ ] AutoSSL/certbot active; cron added
- [ ] Meta Settings saved, webhook verified, fields subscribed, numbers and templates synced
- [ ] API key created for the Oswal Soap store
