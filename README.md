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
cd api && php artisan test          # 45 feature tests, Http::fake for Meta, MySQL db whatsapp_business_test
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

## Clients and client portal
- **Admin → Clients** (`/wab-control-7x/clients`): create clients (name, company, login email, password with show/hide and generate, active/inactive status). You can also edit them, reset a password (you set it, or one is generated and shown once; this logs the client out), and deactivate or activate them. Deactivating revokes the client's tokens. The table shows each client's numbers and template count.
- **Number Master** has a searchable Client dropdown on add/edit (or *No client*), a Client column and a client filter.
- **Template Master** detail panel lets you assign a template to a client. This assignment is local only and is not sent to Meta.
- Staff tokens and client tokens are kept apart (`EnsureStaff` / `EnsureClient` middleware). A client token gets 403 on every admin endpoint.

## Admin path
The admin panel is served at **`/wab-control-7x`** (login: `/wab-control-7x/login`), not `/admin`. It is set in one place,
`VITE_ADMIN_BASE` (see `web/.env.example`, read by `web/src/config.js` and `web/vite.config.js`). To change it:
1. `VITE_ADMIN_BASE=/your-path npm run build` in `web/`.
2. Replace `wab-control-7x` in the root `.htaccess` (`deploy/hostgator-root.htaccess`) with the same path.

`/` is the **client portal** (`web/index.html`, its own Vite entry, so its bundle has no admin path and no link to it). Clients log in at `/login` and see only the numbers assigned to them: dashboard, chats and sending, numbers, and templates. The admin panel is a separate entry (`web/admin.html`). `/admin` and every other unknown path return
`web/site/404.html` with HTTP 404. `/api/*`, `/storage/*` and `/up` still go to Laravel.

## Client requests and stats
- **Number requests**: clients use *Number requests* in the portal (phone number, display name, business name, category, notes) and see each request's status and any rejection reason.
  Admin → **Number Requests** (`/wab-control-7x/number-requests`) approves with the Meta *Phone number ID* and the 6-digit PIN, or rejects with a reason.
  Approving creates the number assigned to that client and calls `WhatsAppService::registerNumber`. The outcome (`registered`/`failed`) and any error are stored on
  the request. A failed registration can be retried from the same page.
- **Template requests**: clients use *Template requests → Request new template*, which opens the same builder and live preview as Template Master. Admin → **Template Requests**
  lets you view, edit, approve (calls Meta `POST /{waba}/message_templates`, creates the template linked to the client) or reject with a reason. Meta's PENDING/APPROVED/REJECTED
  status then arrives through the existing webhook and *Sync from Meta*. Clients only see **APPROVED** templates assigned to them under *My templates*.
- **Client dashboard**: total sent, phone numbers, sent today and this month, a 7-day chart, and per-template sent/delivered/read/failed/last sent. Click a template
  for `/template-stats/{id}`, which lists every send (recipient, sender number, time, status and error), paginated and searchable. Everything is scoped to the client's own numbers.
  Template sends store `messages.message_template_id` (migration backfills older rows from `template_name`).
- Admin sidebar and dashboard show pending counts for both request types.

API: client `GET|POST /api/client/number-requests`, `GET|POST /api/client/template-requests`, `GET /api/client/stats`, `GET /api/client/stats/templates/{id}?q=&status=&page=&per_page=`;
admin `GET /api/number-requests`, `POST /api/number-requests/{id}/approve|reject|register`, `GET /api/template-requests`, `GET|PUT /api/template-requests/{id}`,
`POST /api/template-requests/{id}/approve|reject`, `GET /api/requests/pending-counts`.

## Frontend build is committed
`web/dist` is in git because the server has no Node. After changing anything in `web/src`, run `cd web && npm run build` and commit `web/dist` with your change.

## Automation Flows (Wati-style flow builder)
Admin → **Automation Flows** (every number; pick a client's number first) and client portal → **Automation flows** (only the client's own numbers).
1. Pick a phone number, then create flows for it. A flow belongs to that number and to the number's client.
2. **Trigger**: keywords (contains as a whole word or phrase, is exactly, or starts with), several keywords, case-insensitive.
3. **Steps** (max **10** per flow, enforced in the UI and the API), on a React Flow canvas (`@xyflow/react`) with drag, connect, zoom and a minimap:
   - **Send a message** (green): text, optional image or document by URL.
   - **Ask a question** (yellow): up to 3 options are sent as interactive **reply buttons** (title ≤20), and 4–10 as a **list message** (title ≤24). Each option has its own branch.
   - **Set a condition** (blue): keyword match on the contact's latest reply, with *Matched* and *No match* branches.
   - **Webhook** (pink): POSTs `{event, flow_id, session_id, phone_number, contact, last_input, at}` to an http(s) URL. Private and loopback addresses are blocked. The flow then continues.
4. **Runtime** (`app/Services/FlowEngine.php` with the pure interpreter `FlowRunner.php`): each inbound webhook message is checked against the number's active flows.
   A per-contact session in `flow_sessions` (MySQL) stores the question it is waiting on and expires after **24h** without a reply.
   Button and list replies are matched by id (`f{flow}.{node}.{option}`). Typed text is matched by option number, title, the option's extra keywords, or a distinctive word of one title.
   An unrecognised reply re-asks the question, and a trigger keyword restarts the flow. **Flows take priority over the simple auto-replies**, which still run when no flow handles the message.
5. **Test flow** simulator: a WhatsApp phone preview that runs the *unsaved* draft through the same interpreter (`POST /api/flows-simulate`). Nothing is sent.
6. Save, activate/deactivate (activation needs keywords and a connected trigger), duplicate (the copy starts inactive) and delete.
   Analytics per flow: triggered, completed and live sessions.

API (admin `/api/...`, client `/api/client/...`, same shape): `GET flow-numbers`, `GET|POST flows`, `GET|PUT|DELETE flows/{id}`, `POST flows/{id}/duplicate`,
`GET flows/{id}/stats`, `POST flows-simulate`. Client requests for another client's flow return 404, and the admin API returns 403 for client tokens.
`tools/mock-meta.php` validates interactive payloads the way Meta does (1–3 buttons with titles ≤20, list of 1–10 rows with titles ≤24, list button text ≤20, body ≤1024).
