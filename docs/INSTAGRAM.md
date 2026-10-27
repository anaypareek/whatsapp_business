# Instagram module

Connect Instagram professional accounts (Business/Creator), then compose, schedule and publish photos, reels, carousels and stories from the
admin panel (`/wab-control-7x` → **Instagram**) and from the client portal (**Instagram**). Publishing uses the official
**Instagram Platform API with Facebook Login for Business** (Content Publishing API). Nothing is scraped and nothing is posted unless a
connected account's owner scheduled it.

* Admin sees every account, post and quota, can assign accounts to clients and can post for any account.
* A client sees and posts only to the accounts assigned to them (all `/api/client/instagram/*` queries are scoped to the signed-in client).
* Times are entered and shown in **IST**, stored in **UTC**.

---------------------------------------------------------------------------------------------------

## 1. Meta app setup (once)

You can reuse the app you already use for WhatsApp, or create a new one. A WhatsApp-only app must have the **Facebook Login for Business** product added.

1. <https://developers.facebook.com/apps> → *Create app* → use case **Other** → type **Business** (or open the existing app).
2. **App settings → Basic**: note the **App ID** and **App secret**; add a Privacy Policy URL, a Terms URL, an app icon and a category. Link the app to your verified Business (needed for Advanced Access).
3. **Add product → Facebook Login for Business** → *Set up*. Under **Settings**:
   * **Valid OAuth Redirect URIs** → add **exactly** the redirect URI from section 2 (https, no trailing slash).
   * Client OAuth login: on · Web OAuth login: on · Enforce HTTPS: on.
4. *(Recommended)* **Facebook Login for Business → Configurations → Create configuration** → login variation *General*, token type *User access token*, assets: *Pages* and *Instagram accounts*, permissions as in section 3.
   Copy the **Configuration ID** into *Settings → Instagram → Login configuration ID*. When a configuration ID is set the portal sends `config_id`
   (instead of `scope`) — this is what Facebook Login for Business expects. Leave it blank to fall back to classic `scope=` login.
5. The Instagram account must be a **Professional** account (Business or Creator) **linked to a Facebook Page** (Instagram → Settings → *Account type and tools* / *Linked accounts*).
   The person who connects must be able to manage that Page (full control, or Page role *Admin/Editor* with task *Create content*).
6. Enter the credentials in the portal: **Settings → Instagram (Meta Facebook Login for Business)**.
   * *Instagram App ID* / *App secret* — **leave blank to reuse the Meta app id/secret** already saved under *Settings → Meta WhatsApp*.
   * *Login configuration ID* — optional (step 4).
   * *Redirect URI override* — optional, normally blank.
   The secret is stored encrypted and never shown again.

## 2. Redirect URI

```
https://<your-domain>/api/instagram/callback
```

For this deployment: `https://whatsappwork.fineoutput.com/api/instagram/callback`. The exact value (with a copy button) is shown in
*Settings → Instagram → Redirect URI*. It is **separate from the WhatsApp webhook URL**. It is derived from `APP_URL` in `api/.env`; set the
override in Settings only if the app sits behind a different public origin. The redirect URI sent in the login dialog and in the token
exchange must be byte-identical or Meta returns an error.

The callback is a public GET route (Meta redirects the browser to it). It is protected by a single-use `state` bound to the signed-in user
and session, and `return_to` can only point back to the portal (no open redirect).

## 3. Permissions (exact)

| Permission | Why it is needed |
|---|---|
| `instagram_basic` | Read the linked Instagram account's id, username and profile picture. |
| `instagram_content_publish` | Create media containers and publish photos, reels, carousels and stories (`/{ig-user-id}/media`, `/media_publish`, `/content_publishing_limit`). |
| `pages_show_list` | List the Facebook Pages the user manages (`/me/accounts`) to find the Page → Instagram link. |
| `pages_read_engagement` | Read the Page's `instagram_business_account` field and the Page access token (required to reach the linked account). |
| `business_management` | Needed when the Page / Instagram account is owned by a Business Portfolio (the usual case for agencies and clients). |

These are the scopes requested (`instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement,business_management`) unless a
Login configuration ID is set, in which case the same permissions must be ticked in that configuration.

## 4. How connecting works

1. *Connect Instagram* (portal) → `POST …/instagram/connect/start` returns the Facebook dialog URL (with `state`).
2. The user approves on Facebook → Meta redirects to `/api/instagram/callback?code=…&state=…`.
3. The server exchanges `code` → short-lived user token → **long-lived user token** (`grant_type=fb_exchange_token`, ≈60 days).
4. `GET /me/accounts?fields=id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}` lists Pages. **Only Pages with a linked
   `instagram_business_account` are offered**; the user ticks one or more in the picker and (admin only) can assign them to a client.
5. For each chosen account we store: Page id, IG user id, username, profile picture, the **Page access token** and the long-lived user token
   (both with the Laravel `encrypted` cast — never returned by the API) and the expiry (from `debug_token`).
   Page tokens obtained from a long-lived user token do not expire, but they die if the user changes their password, removes the app or loses the Page role.
6. Reconnecting an already-connected account refreshes its tokens in place and keeps its client assignment and posts.

### Validity checks / renewal

`php artisan instagram:check-tokens` runs **daily at 03:30** from the scheduler. It calls `debug_token` for each account, renews the long-lived user
token with `fb_exchange_token` when it is close to expiry, and marks accounts **needs reconnect** if the token is invalid/expired/revoked, the
permission was removed or the IG link was lost (also set immediately when a publish fails with an OAuth error, code 190). Such accounts show a red badge and a
*Reconnect* button, and their scheduled posts are held (not failed) until reconnected.

## 5. Composer and Meta's limits

Formats: **Photo**, **Reel**, **Carousel** (2–10 items, photos and videos mixed) and **Story** (photo or video). Uploads go to the public disk
(`/storage/instagram/…`) because Instagram downloads from a URL, and are validated on upload (server side, with the real file, not the extension):

| | Image | Video |
|---|---|---|
| Photo | JPEG, ≤ 8 MB, aspect 4:5 – 1.91:1 | – |
| Reel | – | MP4/MOV (H.264/AAC), 3 s – 15 min, ≤ 300 MB, width ≤ 1920 (9:16 recommended) |
| Carousel item | JPEG ≤ 8 MB, aspect 4:5 – 1.91:1 | 3 – 60 s, ≤ 100 MB |
| Story | JPEG ≤ 8 MB (9:16 recommended) | 3 – 60 s, ≤ 100 MB |

* Caption ≤ **2,200** characters, ≤ **30 hashtags**, ≤ 20 @-mentions. Stories take no caption.
* The composer shows a live character/hashtag counter and a phone-style preview (carousel arrows, story ring, reel overlay).
* Choose one or more accounts (one post is created per account), then **Post now** (queued for the next cron minute), **Schedule** (IST date-time picker) or **Save draft**.
* Server upload limit: PHP's `upload_max_filesize` and `post_max_size` must be at least as large as your biggest video (reels up to 300 MB). The composer shows the active limit. On cPanel set both in *MultiPHP INI Editor* (e.g. 320M).

## 6. Publishing engine

There is **no queue worker** on shared hosting, so everything runs from the existing cron entry:

```
* * * * * cd /home/<cpuser>/whatsappwork.fineoutput.com/api && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs `instagram:publish` **every minute** with `withoutOverlapping(10)`. Each run claims a small batch of due posts inside a DB
transaction (`lockForUpdate`, plus a `locked_until` lease on every post) so overlapping runs or two servers never publish twice.

Each post moves through non-blocking stages that **resume across cron runs** (nothing sleeps or waits inside a run):

1. **new** → `POST /{ig-user-id}/media` with `image_url` / `video_url`, `media_type` = `REELS` / `STORIES` / `CAROUSEL` (`children=[…]`, each child created with `is_carousel_item=true`); the container ids are saved on the post.
2. **processing** → `GET /{container-id}?fields=status_code,status` is polled once per run until `FINISHED` (images finish immediately; videos typically take 10 s – a few minutes). `IN_PROGRESS` just waits for the next run. `ERROR` / `EXPIRED` fail the post with Meta's reason; a processing timeout of 30 minutes applies.
3. **ready** → `POST /{ig-user-id}/media_publish` with `creation_id`, then `GET /{media-id}?fields=permalink` and the permalink is stored.
4. **published** → status **posted**.

Statuses: `draft` · `scheduled` · `publishing` · `posted` · `failed` (with Meta's error message, also written to **Error Logs**).
Transient errors (HTTP 5xx, and Meta codes 1, 2, 4, 17, 32, 341, 368, 613, 9007) are retried with back-off up to 5 attempts; a container is recreated if it is older than 23 h.

**Retry / edit / cancel:** a failed post can be retried (resets to scheduled); a draft or scheduled post can be edited, rescheduled or cancelled before its publish time
(back to draft); posted items can be removed from the portal (this does *not* delete the Instagram post — Meta's API cannot).

### Publishing limit

Before publishing, the engine reads `GET /{ig-user-id}/content_publishing_limit?fields=quota_usage,config` (cached for a few minutes, refreshed on demand).
If `quota_usage >= quota_total` (100 per rolling 24 h; a carousel counts once) the post is **deferred** with a reason ("Daily Instagram limit reached (100/100) …")
and is retried automatically — it is not failed. The admin *Usage & limits* tab shows usage per account and a *Refresh from Instagram* button.
> Meta's documentation has shown both 100 and 50 posts per 24 h over time; the limit is always taken from the API's `config.quota_total`. `IG_DAILY_LIMIT` (default 100) is only a fallback when the API doesn't answer.

## 7. Environment

Only needed if the defaults are wrong (all in `api/.env`):

| Variable | Default | Meaning |
|---|---|---|
| `APP_URL` | – | Public origin. Redirect URI and media URLs derive from it. Must be `https://…`. |
| `IG_MEDIA_BASE_URL` | `APP_URL` | Origin Instagram will fetch media from, if different from `APP_URL`. |
| `IG_FRONTEND_URL` | `APP_URL` | Where the browser is sent after the callback (only for local dev, where the Vite dev server differs from the API). |
| `IG_DAILY_LIMIT` | `100` | Fallback publishing limit. |
| `META_GRAPH_VERSION` | `v26.0` | Graph API version (shared with WhatsApp). |
| `META_GRAPH_BASE` / `META_DIALOG_BASE` | Meta's hosts | **Dev/test only**: point to the mock server. |

## 8. Deploy (cPanel Git)

1. Push → cPanel **Git™ Version Control → Manage → Update from Remote**, then **Deploy HEAD Commit**. `.cpanel.yml` runs `composer install`, `php artisan migrate --force`
   (creates the `instagram_*` tables), creates the `api/public/storage` symlink if missing, and re-caches config/routes/views. The built frontend is committed (`web/dist`).
2. Confirm the **cron** entry from section 6 exists (it already does if the WhatsApp scheduler runs).
3. In Settings, save the Instagram credentials (or leave blank to reuse the Meta app) and copy the redirect URI into the Meta app.
4. Set PHP `upload_max_filesize` / `post_max_size` as in section 5.
5. Smoke test: *Instagram → Accounts → Connect Instagram*, choose an account, then schedule a photo 2 minutes ahead.

Caveats:
* **Media must be reachable by Meta**: `https://<domain>/storage/instagram/...jpg` must return the file anonymously (no basic auth, no IP allow-list, no hotlink protection, not blocked by Cloudflare "bot fight").
  The root `.htaccess` routes `/storage/*` to `api/public/storage`, which is a **symlink**; the host must allow `FollowSymLinks`/`SymLinksIfOwnerMatch` (cPanel default). If `/storage/...` 404s, check this first.
* Until the app passes **App Review + Business Verification**, only people with a role on the Meta app can connect (section 9).
* Tokens: validity is checked daily; accounts flagged *needs reconnect* hold their posts until reconnected.
* Posts published through the API cannot be edited or deleted through the API afterwards.
* Videos are validated by reading the MP4/MOV container header (duration, size, dimensions). Exotic files that PHP cannot parse are rejected with a clear message rather than sent to Meta.
* Uploaded files are deleted by `instagram:cleanup` (daily 04:00) 7 days after publishing, or when they were never used.

## 9. Testing in development mode (before App Review)

In **Development mode** the Instagram permissions work only for people who have a **role on the app** and only for assets they control:

1. Meta app → **App roles → Roles** → add the person as **Administrator / Developer / Tester** (testers must accept at developers.facebook.com → *Settings → Roles* or via the notification).
2. The Facebook user who connects must manage the Page and the Instagram account must be Professional and linked to it.
3. With the permissions on Standard Access in dev mode no review is needed; publish to a **test Instagram account** you own.
4. For a Business-owned Page, also add the user to the Business Portfolio and the Meta app to the portfolio (*Business settings → Accounts → Apps*).
5. Switch the app to **Live** only after review (section 10) — otherwise non-role users get "App not active" / permission errors.

## 10. App Review (drafts — nothing has been submitted)

Request **Advanced Access** for: `instagram_content_publish`, `instagram_basic`, `pages_show_list`, `pages_read_engagement`, `business_management`.
Review needs: verified Business, Privacy Policy URL (describing Instagram data), a working login for the reviewer, and a screencast per permission.

### Reviewer instructions (paste into "Testing instructions")

> Portal: https://whatsappwork.fineoutput.com (client login: **<test-email> / <password>**). 
> 1. Open **Instagram** in the left menu → **Accounts** → **Connect Instagram**, sign in to Facebook with a user that has a role on this app and manages a Page linked to an Instagram professional account.
> 2. Approve the permissions and select the Instagram account in the list → **Connect accounts**. The account appears with its username and token status.
> 3. Open **Compose**, choose *Photo*, select the account, upload an image, write a caption and click **Post now** (or schedule 2 minutes ahead). Within about a minute the post appears as **Posted** with a *View on Instagram* link.
> 4. Open **Posts & calendar** to see scheduled / posted / failed items; **Usage & limits** shows `content_publishing_limit` usage.

### Screencast script (about 3–4 min, one recording, English UI, show browser address bar)

1. Show the Meta app dashboard with the app name matching the login dialog.
2. In the portal click **Connect Instagram** → show the Facebook Login for Business dialog listing every permission requested → *Continue*.
3. Back in the portal, show the account picker (Page → Instagram username/profile picture) and connect one account. *(pages_show_list, pages_read_engagement, instagram_basic, business_management)*
4. Compose a **photo**: upload, caption with hashtags, preview, *Post now*. Show the status change to *Posted*, click *View on Instagram* and show the post live on instagram.com. *(instagram_content_publish)*
5. Compose a **carousel** and a **story/reel**, schedule them, show the calendar and the published result.
6. Show *Usage & limits* (publishing limit) and *Remove account* to demonstrate the user's control over their data.

### Use-case text per permission (drafts)

* **instagram_content_publish** — "Our customers are small businesses that manage their own Instagram professional accounts through our portal. After they connect their account with Facebook Login for Business, they compose photos, reels, carousels and stories and either publish immediately or schedule them. We call `POST /{ig-user-id}/media` to create the media container, poll the container status, then `POST /{ig-user-id}/media_publish` to publish, and `GET /{ig-user-id}/content_publishing_limit` to respect the 24-hour publishing quota. We only publish content the account owner composed or approved in our portal, never automatically generated content, and never to accounts that did not connect themselves."
* **instagram_basic** — "After the customer connects, we read the account's id, username and profile picture to display which account a post will be published to, and to let them choose between several connected accounts."
* **pages_show_list** — "An Instagram professional account is reached through its linked Facebook Page. We list the Pages the user manages (`/me/accounts`) so they can choose which Page's linked Instagram account to connect."
* **pages_read_engagement** — "Required to read the Page's `instagram_business_account` connection and the Page access token that the Instagram Content Publishing API uses for that Page. We do not read or store Page posts, comments or insights."
* **business_management** — "Many customers own their Pages and Instagram accounts through a Business Portfolio. This permission lets those users' assets appear in `/me/accounts` so they can connect them. We do not manage business settings, ad accounts or other business assets."

Data handling to mention: tokens are encrypted at rest; users can remove an account at any time (deletes our stored tokens); media is deleted after publishing; we offer a data-deletion URL in the app settings.

## 11. Local development with the mock (no real Meta calls)

`tools/mock-meta.php` mocks the Facebook Login for Business dialog, token exchange, `/me/accounts`, `debug_token`, containers, status polling, `media_publish`, permalinks and `content_publishing_limit` (plus the WhatsApp endpoints).

```bash
php -S 127.0.0.1:8099 tools/mock-meta.php        # any free port works
# api/.env
META_GRAPH_BASE=http://127.0.0.1:8099
META_DIALOG_BASE=http://127.0.0.1:8099
APP_URL=http://localhost:8020
IG_FRONTEND_URL=http://localhost:5190            # Vite dev server
# run the API with a big enough upload limit, and the scheduler by hand:
cd api/public && php -d upload_max_filesize=64M -d post_max_size=70M -S 127.0.0.1:8020 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
cd api && php artisan instagram:publish          # once per "cron minute"
```

* Three fake accounts are served (`@oswalsoap`, `@fineoutput.studio`, `@himalayatea`, the last one near its quota) plus a Page without Instagram (filtered out).
* "Connect Instagram" in the portal redirects through the mock and straight back, as if the user approved.
* Magic values: a media URL containing `bad` → error 9004 (media could not be fetched); a *video* URL containing `fail` → container `ERROR`; `GET /__ig/revoke?ig=<id>` → token invalid (needs reconnect); `GET /__ig/reset` → clear state.
* Video containers stay `IN_PROGRESS` for 2 polls, so you can watch a reel resume across several `instagram:publish` runs.
* State is in `tools/mock-meta-ig.json` and the request log in `tools/mock-meta.log` (both gitignored).
* Automated tests use `Http::fake()` only (`api/tests/Feature/InstagramTest.php`); no test reaches the network.

## 12. API summary

Admin (`/api/instagram/*`, Sanctum, admin only) and client (`/api/client/instagram/*`, scoped to the client's accounts) expose the same shapes:
`GET config`, `GET accounts`, `POST connect/start`, `GET|POST connect/{sid}` (account picker), `POST accounts/{id}/check`, `DELETE accounts/{id}`, `POST media` / `DELETE media/{id}` (upload),
`GET|POST posts` (`GET` accepts `from`, `to`, `status`, `format`, `account_id`, `q`; the calendar uses the same call), `GET|PUT|DELETE posts/{id}`, `POST posts/{id}/retry|cancel|publish-now`, `GET usage`.
Admin extras: `PUT accounts/{id}` (assign to a client), `GET clients`, `GET|PUT settings`. Public: `GET /api/instagram/callback`.
