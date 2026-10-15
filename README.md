# WhatsApp Business Portal (Laravel + MySQL + React/Vite)

Owner: Vibhav Pareek · timezone Asia/Kolkata. Built on Meta WhatsApp Cloud API (Graph v26.0, see `docs/meta-api-notes.md`).

```
api/      Laravel 13 API (Sanctum, MySQL/MariaDB)     → http://localhost:8020
web/      React + Vite admin panel (proxies /api)      → http://localhost:5190/wab-control-7x/
docs/     meta-api-notes.md, API.md (curl examples)
secrets/  admin-credentials.txt (chmod 600)
tools/    mock-meta.php (local fake Graph API), e2e-curl.sh (end-to-end smoke test)
```
Ports 8000/5173 were already used by other projects on this box, hence 8020/5190.

## Modules
1. **Login / Users** – Sanctum token login; admin creates/lists/deletes users. Seeded admin from `secrets/admin-credentials.txt`.
2. **Number Master** – CRUD of phone numbers (phone_number_id, display number, name, WABA); *Sync from Meta*
   (`GET /{waba}/phone_numbers`); *Register* (`POST /{id}/register` with PIN).
3. **Send Message** – sender, recipient, text, optional image/file (uploaded to `/{id}/media`, then sent by media id).
4. **Chat Master** – WhatsApp-style UI, conversations left/thread right, polling every 3s, status ticks, manual reply,
   auto-reply rules (keyword / contains / exact, per number or all, enabled toggle, priority). All inbound/outbound
   messages in `messages`, every status callback in `message_statuses`.
5. **Settings** – app ID, app secret, access token, WABA ID, verify token; stored with Laravel `encrypted` cast (APP_KEY).
6. **Template Master** – list/filter (status, category, language, name) with status and quality badges, create/edit
   form with live WhatsApp-style preview, rejection reasons, webhook status history, edit-limit enforcement, sync from Meta,
   delete; send approved templates with variables from Send Message and the API-key endpoint.
7. **API keys + `POST /api/v1/messages/send`** – for Oswal Soap new-order alerts. Keys stored as SHA-256 hashes.

All Meta calls go through `app/Services/WhatsAppService.php`; `MessagingService` sends+persists; `WebhookProcessor`
handles inbound payloads, statuses and auto-replies.

## Run
```bash
sudo service mariadb start
cd api && php artisan serve --host=0.0.0.0 --port=8020 &
cd web && npm run dev &            # http://localhost:5190
```
Fresh setup: `composer install && cp .env.example .env && php artisan key:generate`, set DB_* /ADMIN_*,
`php artisan migrate --seed && php artisan storage:link`; `cd web && npm install`.

## Tests
```bash
cd api && php artisan test          # 19 feature tests, Http::fake for Meta, MySQL db whatsapp_business_test
tools/e2e-curl.sh                   # curl smoke test against the running servers
```

## Mock vs real Meta
For the local demo `api/.env` has `META_GRAPH_BASE=http://127.0.0.1:8099`, served by
`php -S 127.0.0.1:8099 tools/mock-meta.php` (logs to tools/mock-meta.log). **To go live:** delete that line (default is
`https://graph.facebook.com`), `php artisan config:clear`, and enter real values in Settings.

## Going live with Meta
1. In Meta App Dashboard → WhatsApp → API Setup: get the WABA ID, phone number IDs; create a System User token with
   `whatsapp_business_messaging`, `whatsapp_business_management`. App secret: App settings → Basic.
2. Enter them in **Settings**, pick any verify token.
3. Expose the API over **public HTTPS** (Meta rejects http/localhost/self-signed):
   - `cloudflared tunnel --url http://localhost:8020` (quick, free trycloudflare.com URL; named tunnel for a stable one)
   - `ngrok http 8020` (stable domain on free tier)
   - or deploy behind nginx + Let's Encrypt on a real domain (recommended for production).
4. WhatsApp → Configuration → Webhook: Callback URL `https://<host>/api/webhook/whatsapp`, Verify token = the one in Settings,
   then subscribe to the **messages**, **message_template_status_update**, **message_template_quality_update** and **template_category_update** fields. Set `APP_URL` to the public URL.
5. Free-form messages only reach users who messaged you within 24h; use an approved template for order alerts.

## Not yet implemented
- Media-header sample upload (Resumable Upload API): paste a header_handle manually for IMAGE/DOCUMENT/VIDEO headers.
- AUTHENTICATION templates (OTP/COPY_CODE buttons), carousel, LTO and Flow buttons are not in the form (sync shows them).
- Downloading inbound media from Meta (inbound media is shown as a placeholder with its media id).
- Queued/async sending and auto-retry; real-time push (uses polling).

## Admin path
The admin panel is served at **`/wab-control-7x`** (login: `/wab-control-7x/login`), not `/admin`. It is set in one place,
`VITE_ADMIN_BASE` (see `web/.env.example`, read by `web/src/config.js` and `web/vite.config.js`). To change it:
1. `VITE_ADMIN_BASE=/your-path npm run build` in `web/`.
2. Replace `wab-control-7x` in the root `.htaccess` (`deploy/hostgator-root.htaccess`) with the same path.

`/` serves a neutral public page (`web/site/index.html`) with no admin link. `/admin` and every other unknown path return
`web/site/404.html` with HTTP 404. `/api/*`, `/storage/*` and `/up` still go to Laravel.
