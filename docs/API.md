# API reference

Base URL (local): `http://localhost:8020/api` (also proxied through the frontend at `http://localhost:5190/api`).
Portal endpoints use a Sanctum bearer token from `/auth/login`. Admin-only: users, settings, api-keys.

```bash
B=http://localhost:8020/api
TOKEN=$(curl -s -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"admin@oswal.local","password":"<from secrets/admin-credentials.txt>"}' $B/auth/login | jq -r .token)
H=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json')
```

## Auth
| Method | Path | |
|---|---|---|
| POST | /auth/login | `{email,password}` → `{token,user}` |
| GET | /auth/me | current user |
| POST | /auth/logout | revoke token |

## Users (admin)
```bash
curl "${H[@]}" $B/users
curl "${H[@]}" -H 'Content-Type: application/json' -d '{"name":"Op","email":"op@x.com","password":"password123","is_admin":false}' $B/users
curl "${H[@]}" -X DELETE $B/users/2
```

## Clients (admin)
```bash
curl "${H[@]}" "$B/clients?q=oswal&status=active"      # withCount numbers/templates + phone_numbers list
curl "${H[@]}" -H 'Content-Type: application/json' -d '{"name":"Ravi","company":"Oswal Retail","email":"ravi@x.com","password":"secret123","is_active":true}' $B/clients
curl "${H[@]}" -X PUT -H 'Content-Type: application/json' -d '{"is_active":false}' $B/clients/1   # deactivate (revokes tokens)
curl "${H[@]}" -X POST -H 'Content-Type: application/json' -d '{}' $B/clients/1/reset-password     # {} = generate; returns {password} once
curl "${H[@]}" -X DELETE $B/clients/1                   # numbers/templates are unassigned, not deleted
curl "${H[@]}" $B/clients-list                          # id/name/company for dropdowns
curl "${H[@]}" -X PUT -H 'Content-Type: application/json' -d '{"client_id":1}' $B/templates/5/client
```

## Client portal (`/api/client/*`, client token)
`POST /client/auth/login {email,password}` → `{token, client}` (inactive → 403). With `Authorization: Bearer <client token>`:
`GET /client/auth/me`, `POST /client/auth/logout`, `GET /client/dashboard`, `GET /client/numbers`, `GET /client/templates`,
`GET /client/conversations`, `GET /client/messages/thread`, `GET /client/messages/poll` (same query params as the admin chat endpoints),
`POST /client/messages/send` (same body as the portal send). Everything is limited to the client's own numbers; any other number gives 404.

## Number Master
```bash
curl "${H[@]}" "$B/phone-numbers?client_id=1"           # or client_id=none; each row includes client
curl "${H[@]}" $B/phone-numbers
curl "${H[@]}" -H 'Content-Type: application/json' -d '{"phone_number_id":"1234567890","display_number":"+91 98765 43210","name":"Oswal Soap"}' $B/phone-numbers
curl "${H[@]}" -X PUT -H 'Content-Type: application/json' -d '{"name":"Sales"}' $B/phone-numbers/1
curl "${H[@]}" -X DELETE $B/phone-numbers/1
curl "${H[@]}" -X POST $B/phone-numbers-sync                 # pull from GET /{waba}/phone_numbers
curl "${H[@]}" -H 'Content-Type: application/json' -d '{"pin":"123456"}' $B/phone-numbers/1/register
```

## Send (portal)
`phone_number_id` here is the **local id** from Number Master.
```bash
curl "${H[@]}" -H 'Content-Type: application/json' -d '{"phone_number_id":1,"to":"919812345678","text":"Hello"}' $B/messages/send
curl "${H[@]}" -F phone_number_id=1 -F to=919812345678 -F text="Our catalogue" -F file=@catalogue.pdf $B/messages/send
```

## Chat
```bash
curl "${H[@]}" "$B/conversations?phone_number_id=1"
curl "${H[@]}" "$B/messages/thread?phone_number_id=1&contact=919812345678"   # optional &after_id=123
curl "${H[@]}" $B/messages/poll          # {latest_id, updated_at, unread}
```

## Auto-reply rules
`match_type`: `keyword` (whole word, comma-separated list) | `contains` | `exact`; `phone_number_id` null = all numbers.
```bash
curl "${H[@]}" -H 'Content-Type: application/json' -d '{"match_type":"contains","trigger":"price","reply":"Price list: …","phone_number_id":null,"enabled":true,"priority":10}' $B/auto-replies
curl "${H[@]}" -X PUT -H 'Content-Type: application/json' -d '{"enabled":false}' $B/auto-replies/1
curl "${H[@]}" $B/auto-replies ; curl "${H[@]}" -X DELETE $B/auto-replies/1
```

## Settings (admin, stored encrypted; secrets returned masked)
```bash
curl "${H[@]}" $B/settings
curl "${H[@]}" -X PUT -H 'Content-Type: application/json' -d '{"app_id":"…","app_secret":"…","access_token":"EAA…","waba_id":"…","verify_token":"some-random-string"}' $B/settings
```

## API keys (admin)
```bash
curl "${H[@]}" -H 'Content-Type: application/json' -d '{"name":"Oswal Soap orders"}' $B/api-keys   # plain_key shown once
curl "${H[@]}" $B/api-keys ; curl "${H[@]}" -X DELETE $B/api-keys/1
```

## Template Master
Templates are stored in `message_templates`; each row includes `edit_check {allowed, reason}`, `body_variable_count`,
`header_format`, `header_variable_count`. Status changes arrive via the webhook (see below) and are logged in `template_events`.
```bash
curl "${H[@]}" "$B/templates?status=APPROVED&category=UTILITY&language=en_US&q=order"
curl "${H[@]}" $B/templates/1                        # includes last 20 status/quality/category events
curl "${H[@]}" -X POST $B/templates-sync              # pull all from GET /{waba}/message_templates
curl "${H[@]}" -H 'Content-Type: application/json' -d '{
  "name":"order_update","language":"en_US","category":"UTILITY",
  "header":{"format":"TEXT","text":"Order {{1}}","example":"OS-1001"},
  "body":{"text":"Hi {{1}}, your order {{2}} of {{3}} is confirmed.","examples":["Ravi","OS-1001","₹1,440"]},
  "footer":"Oswal Soap",
  "buttons":[{"type":"URL","text":"Track","url":"https://oswal.example/t/{{1}}","example":"https://oswal.example/t/OS-1001"},
             {"type":"QUICK_REPLY","text":"Thanks"}]}' $B/templates
# header formats: NONE|TEXT|IMAGE|DOCUMENT|VIDEO (media headers: "example" = header_handle from Meta's Resumable Upload API)
curl "${H[@]}" -X PUT -H 'Content-Type: application/json' -d '{"body":{"text":"Hi {{1}}, order {{2}} shipped.","examples":["Ravi","OS-1"]}}' $B/templates/1
curl "${H[@]}" -X DELETE $B/templates/1               # DELETE /{waba}/message_templates?name=…&hsm_id=…
```
Edits return `422` with a reason when not allowed: status not APPROVED/REJECTED/PAUSED, name/language change, category change
on an APPROVED template, or an APPROVED template already edited in the last 24h / 10 times in 30 days (counted locally).

Send an approved template from the portal (`params` = body `{{1}}…`, `header_param` = header text var or media URL/id,
`button_params` = `{index: url-suffix}` for dynamic URL buttons):
```bash
curl "${H[@]}" -H 'Content-Type: application/json' -d '{"phone_number_id":1,"to":"919812345678","template_id":1,
  "params":["Ravi","OS-1001","₹1,440"],"header_param":"OS-1001","button_params":["OS-1001"]}' $B/messages/send-template
```

## External send — `POST /api/v1/messages/send` (API key)
Auth: `Authorization: Bearer <api key>` (or `X-API-Key`). Fields: `from` (aliases `from_number`, `from-number`: the Meta
phone_number_id **or** the display number, e.g. `+91 98765 43210`), `to`, `text`, optional `media_url` or multipart `file`.
```bash
KEY=wab_xxxxxxxx
curl -H "Authorization: Bearer $KEY" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"from":"+91 98765 43210","to":"919812345678","text":"New order #OS-1001: 12 x Neem Soap, ₹1,440"}' \
  http://localhost:8020/api/v1/messages/send
curl -H "Authorization: Bearer $KEY" -H 'Accept: application/json' \
  -F from=1234567890 -F to=919812345678 -F text="Invoice for #OS-1001" -F file=@invoice.pdf \
  http://localhost:8020/api/v1/messages/send
```
Template send (works outside the 24h window; only APPROVED templates). `template` + `language` + `params`, optional
`header_param` and `button_params`. `template` can also be an object `{name, language, params, header_param, button_params}`;
with multipart/form data, `params` may be a JSON array string or pipe-separated (`Ravi|OS-1001|₹1,440`). `language` is required
only if the name exists in several languages.
```bash
curl -H "Authorization: Bearer $KEY" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"from":"+91 98765 43210","to":"919812345678","template":"order_update","language":"en_US",
       "params":["Ravi","OS-1001","₹1,440"],"header_param":"OS-1001","button_params":["OS-1001"]}' \
  http://localhost:8020/api/v1/messages/send
```
Response `201 {"success":true,"id":12,"wamid":"wamid…","status":"sent","error":null}`; `401` bad key, `422` validation/unknown
sender, `502` Meta rejected (error text in `error`). Media URLs ending .jpg/.png go as image, .mp4 as video, else document.

## Webhook (called by Meta)
Handled fields: `messages` (inbound + statuses), `message_template_status_update`, `message_template_quality_update`,
`template_category_update`. Subscribe to all four in the App Dashboard.
```bash
curl "$B/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=<verify_token>&hub.challenge=4242"   # → 4242
BODY='{"object":"whatsapp_business_account","entry":[{"id":"WABA","changes":[{"field":"messages","value":{"metadata":{"display_phone_number":"919876543210","phone_number_id":"1234567890"},"contacts":[{"profile":{"name":"Ravi"},"wa_id":"919812345678"}],"messages":[{"from":"919812345678","id":"wamid.TEST1","timestamp":"1760000000","type":"text","text":{"body":"price?"}}]}}]}]}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "<app_secret>" | awk '{print $NF}')
curl -H 'Content-Type: application/json' -H "X-Hub-Signature-256: sha256=$SIG" -d "$BODY" $B/webhook/whatsapp
```
