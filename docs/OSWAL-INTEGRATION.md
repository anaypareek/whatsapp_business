# Oswal website → WhatsApp order alerts (server-side integration)

Send an approved WhatsApp template (e.g. `order_confirmation`) from the Oswal website **backend** whenever a new order is placed.

> ⚠️ **Server-side only.** The API key can send WhatsApp messages as you. Keep it in the server's environment
> (`.env`, hosting panel env vars). Never put it in browser JavaScript, a mobile app, HTML, or a git repo.
> If it leaks, revoke it in the portal (**API keys → Revoke**) and generate a new one.

## 1. Create a key
1. Client portal → **API keys** → *Generate key* (admins: Admin panel → API Keys, choose the client).
2. Label it (e.g. `oswal.com orders`). Optionally restrict:
   - **IP allowlist** – the web server's outbound IP(s) or CIDR (recommended).
   - **Origin/Referer domains** – only if your server sends an `Origin`/`Referer` header. **When a domain list is set, a request with no Origin/Referer is rejected (403 ORIGIN_NOT_ALLOWED).** Normal server-to-server calls (cURL/Guzzle) do not send these headers, so prefer the IP allowlist.
   - **Rate limit** – default 60 requests/minute per key.
3. Copy the key — it is shown **once** and stored only as a hash.
4. Put it in the server env:
   ```
   WAB_API_KEY=wab_xxxxxxxx...
   WAB_FROM_NUMBER_ID=1234567890      # from "Use via API" on the template
   ```

## 2. The endpoint
```
POST https://whatsappwork.fineoutput.com/api/v1/templates/send
X-API-Key: <key>            (or Authorization: Bearer <key>)
Content-Type: application/json
Accept: application/json

{
  "from_number_id": "1234567890",
  "to": "919812345678",
  "template": "order_confirmation",
  "language": "en_US",
  "variables": { "body": ["Ravi", "#1001", "₹1,250"], "header": "#1001", "buttons": ["1001"] }
}
```
- `variables.body` — one string per `{{n}}` in the body, in order (count is validated).
- `variables.header` — the text `{{1}}` value, or a public media URL for IMAGE/VIDEO/DOCUMENT headers.
- `variables.buttons` — values for dynamic URL / OTP buttons by button index (`null` for static ones).
- Open a template → **Use via API** for prefilled cURL / PHP / Node code.
- HTTPS only; a key in the query string (`?api_key=`) is rejected (400).
- The legacy `POST /api/v1/messages/send` still works unchanged.

### Responses
Errors return `{ "message": "...", "error_code": "..." }`.

| Status | Meaning | Retry? |
|---|---|---|
| 201 | Accepted by Meta: `{success, id, wamid, status}` | — |
| 400 `KEY_IN_QUERY` | Key sent in URL — move it to the header | No |
| 403 `HTTPS_REQUIRED` | Use https:// | No |
| 401 `UNAUTHENTICATED` / `KEY_REVOKED` | Bad or revoked key | No — alert ops |
| 403 `IP_NOT_ALLOWED` / `ORIGIN_NOT_ALLOWED` / `NUMBER_NOT_ALLOWED` | Allowlist / scope | No |
| 422 `UNKNOWN_NUMBER` / `UNKNOWN_TEMPLATE` / `VARIABLE_COUNT` | Bad input (`expected` given) | No |
| 429 | Rate limited — honour `Retry-After` | Yes |
| 502 | Meta rejected / unreachable (`message` has the reason) | Yes, with backoff (unless it's a permanent Meta error such as an invalid number) |
| 5xx / network error | Transient | Yes, with backoff |

Every call is visible under **API keys → Request logs** (time, key, status, latency, template, recipient, IP, error).

## 3. Laravel (recommended: queued job with retries)
```php
// config/services.php
'wab' => ['key' => env('WAB_API_KEY'), 'from' => env('WAB_FROM_NUMBER_ID'),
          'url' => 'https://whatsappwork.fineoutput.com/api/v1/templates/send'],

// app/Jobs/SendOrderWhatsApp.php
class SendOrderWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 5;
    public function backoff(): array { return [30, 120, 600, 1800]; }
    public function __construct(public Order $order) {}

    public function handle(): void
    {
        $res = Http::timeout(15)->acceptJson()
            ->withHeaders(['X-API-Key' => config('services.wab.key')])
            ->post(config('services.wab.url'), [
                'from_number_id' => config('services.wab.from'),
                'to'       => preg_replace('/\D/', '', $this->order->phone), // with country code, e.g. 91...
                'template' => 'order_confirmation',
                'language' => 'en_US',
                'variables'=> ['body' => [$this->order->customer_name, '#'.$this->order->number, '₹'.number_format($this->order->total)]],
            ]);

        if ($res->status() === 201) return;
        if ($res->status() === 429) { $this->release((int) $res->header('Retry-After', 60)); return; }
        if ($res->status() >= 500) throw new \RuntimeException('WAB '.$res->status().': '.$res->json('message'));
        // 4xx: permanent — log, do not retry
        Log::error('WhatsApp order alert rejected', ['order' => $this->order->id, 'status' => $res->status(), 'body' => $res->json()]);
        $this->fail(new \RuntimeException($res->json('error_code') ?? 'rejected'));
    }
}

// Where the order is created (controller, observer or event listener):
SendOrderWhatsApp::dispatch($order)->afterCommit();
```
Never block checkout on WhatsApp — always dispatch to the queue (or wrap in try/catch).

## 4. Plain PHP / WooCommerce
```php
// wp-config.php (or server env):  define('WAB_API_KEY', getenv('WAB_API_KEY'));
add_action('woocommerce_thankyou', 'oswal_whatsapp_order_alert', 10, 1);
function oswal_whatsapp_order_alert($order_id) {
    if (!$order_id || get_post_meta($order_id, '_wab_sent', true)) return;   // idempotent
    $order = wc_get_order($order_id);
    $res = wp_remote_post('https://whatsappwork.fineoutput.com/api/v1/templates/send', [
        'timeout' => 15,
        'headers' => ['X-API-Key' => WAB_API_KEY, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body'    => wp_json_encode([
            'from_number_id' => getenv('WAB_FROM_NUMBER_ID'),
            'to'       => preg_replace('/\D/', '', $order->get_billing_phone()),
            'template' => 'order_confirmation',
            'language' => 'en_US',
            'variables'=> ['body' => [$order->get_billing_first_name(), '#'.$order->get_order_number(), html_entity_decode(strip_tags(wc_price($order->get_total())))]],
        ]),
    ]);
    $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
    if ($code === 201) { update_post_meta($order_id, '_wab_sent', 1); return; }
    error_log('WhatsApp alert failed for order '.$order_id.': '.(is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res)));
    if ($code === 0 || $code === 429 || $code >= 500) {   // transient → retry in 5 min via Action Scheduler / WP-Cron
        wp_schedule_single_event(time() + 300, 'oswal_whatsapp_order_alert_retry', [$order_id]);
    }
}
add_action('oswal_whatsapp_order_alert_retry', 'oswal_whatsapp_order_alert');
```
(Cap retries, e.g. store an attempt counter in order meta and stop after 5.)

## 5. Checklist
- [ ] Template approved by Meta and assigned to the client
- [ ] Key in server env, IP allowlist set to the web server's IP
- [ ] Phone numbers sent with country code, digits only
- [ ] Calls made from a queue/background job with retry on 429/5xx only
- [ ] Watch **Request logs** after go-live
