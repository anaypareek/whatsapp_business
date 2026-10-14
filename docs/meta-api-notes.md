# Meta WhatsApp Business Cloud API – working notes (researched 2026-10-14)

Base: `https://graph.facebook.com/{version}` — latest Graph version seen in Meta's changelog/docs on 2026-10-14: **v26.0**
(configurable via `META_GRAPH_VERSION` in `api/.env`). Auth: `Authorization: Bearer <access token>`
(use a System User permanent token with `whatsapp_business_messaging` + `whatsapp_business_management`).

## Send messages — `POST /{phone-number-id}/messages`
```json
{"messaging_product":"whatsapp","recipient_type":"individual","to":"919876543210","type":"text","text":{"preview_url":true,"body":"Hi"}}
{"messaging_product":"whatsapp","to":"9198…","type":"image","image":{"id":"<media-id>","caption":"…"}}      // or {"link":"https://…"}
{"messaging_product":"whatsapp","to":"9198…","type":"document","document":{"id":"<media-id>","filename":"inv.pdf","caption":"…"}}
```
Response: `{"messaging_product":"whatsapp","contacts":[{"input":"…","wa_id":"…"}],"messages":[{"id":"wamid.…"}]}`.
Free-form (non-template) messages only work inside the 24h customer service window; outside it you must send an approved
**template** (`type: "template"`). Mark read: `{"messaging_product":"whatsapp","status":"read","message_id":"wamid…"}`.

## Media upload — `POST /{phone-number-id}/media` (multipart)
Fields: `file` (binary, with content type), `type` (MIME), `messaging_product=whatsapp` → `{"id":"<media-id>"}`.
Limits: images JPEG/PNG ≤5 MB; documents (pdf, doc(x), xls(x), ppt(x), txt) ≤100 MB; audio ≤16 MB; video mp4/3gp ≤16 MB.
Media IDs are valid ~30 days. Download inbound media: `GET /{media-id}` → short-lived `url`, then GET it with the bearer token.

## Webhooks
- **Verify (GET)**: Meta calls `?hub.mode=subscribe&hub.verify_token=<yours>&hub.challenge=<n>`; respond 200 with the raw
  challenge if the token matches, else 403. (PHP turns `hub.mode` into `hub_mode`.)
- **Events (POST)**: `{"object":"whatsapp_business_account","entry":[{"id":"<WABA>","changes":[{"field":"messages","value":{
  "metadata":{"display_phone_number","phone_number_id"},"contacts":[{"profile":{"name"},"wa_id"}],
  "messages":[{"from","id":"wamid…","timestamp","type":"text","text":{"body"}}],   // inbound
  "statuses":[{"id":"wamid…","status":"sent|delivered|read|failed","timestamp","recipient_id","errors":[…]}]}}]}]}` // outbound status
- **Signature**: header `X-Hub-Signature-256: sha256=<hex HMAC-SHA256 of the raw body using the App Secret>`; compare in constant time
  before parsing. Respond 200 quickly; Meta retries failed deliveries (dedupe by `wamid`).
- Callback URL must be **public HTTPS** with a valid cert; subscribe the app to the `messages` field (App Dashboard → WhatsApp →
  Configuration) and subscribe the app to the WABA (`POST /{waba-id}/subscribed_apps`).

## Phone numbers
- List: `GET /{waba-id}/phone_numbers?fields=id,display_phone_number,verified_name,quality_rating,code_verification_status,status`
- Register for Cloud API: `POST /{phone-number-id}/register` `{"messaging_product":"whatsapp","pin":"123456"}` (6-digit 2-step PIN).
- Deregister: `POST /{phone-number-id}/deregister`. Request/verify code: `/request_code`, `/verify_code`.

## Message templates (WABA `/message_templates`)
- **List**: `GET /{waba-id}/message_templates?fields=id,name,language,status,category,components,rejected_reason,quality_score,previous_category&limit=100` (cursor paging via `paging.next`).
- **Create**: `POST /{waba-id}/message_templates` `{"name":"order_update","language":"en_US","category":"UTILITY","components":[…]}` → `{"id","status":"PENDING","category"}`.
  - `name`: lowercase letters, digits, `_` (≤512). `category`: `MARKETING` | `UTILITY` | `AUTHENTICATION` (Meta may re-categorise; `template_category_update` webhook).
  - `HEADER`: `format` `TEXT` (≤60 chars, max one `{{1}}`, `example.header_text:["…"]`) or `IMAGE`/`DOCUMENT`/`VIDEO`
    (`example.header_handle:["<handle>"]`, the handle comes from the Resumable Upload API: `POST /{app-id}/uploads` then `POST /upload:<session>`).
  - `BODY`: `text` ≤1024 with sequential `{{1}}…{{n}}`; must not start/end with a variable; `example.body_text:[["v1","v2"]]` required when vars exist.
  - `FOOTER`: `text` ≤60, no vars. `BUTTONS`: up to 10 — `QUICK_REPLY {text}`, `URL {text,url[,example:["full url"]]}` (one `{{1}}` suffix),
    `PHONE_NUMBER {text,phone_number}`, plus `COPY_CODE`/`OTP`/`FLOW` (not used here). Button text ≤25.
- **Edit**: `POST /{template-id}` with `components` and/or `category`. Only **APPROVED, REJECTED, PAUSED** templates can be edited; name/language
  are immutable; an APPROVED template's category can't change; APPROVED templates may be edited **once per 24h and up to 10 times per 30 days**.
  Edited templates return to review.
- **Delete**: `DELETE /{waba-id}/message_templates?name=<name>` (all languages) or `&hsm_id=<template-id>` (one language). Name can't be reused for 30 days.
- **Statuses**: `APPROVED`, `PENDING`, `REJECTED` (with `rejected_reason`, e.g. `INVALID_FORMAT`, `INCORRECT_CATEGORY`, `ABUSIVE_CONTENT`,
  `TAG_CONTENT_MISMATCH`, `SCAM`, `PROMOTIONAL`), `PAUSED` (low quality, temporary), `DISABLED`, `IN_APPEAL`, `PENDING_DELETION`, `LIMIT_EXCEEDED`.
  `quality_score.score`: `GREEN`/`YELLOW`/`RED`/`UNKNOWN`.
- **Webhooks** (subscribe app fields): `message_template_status_update` `{event, message_template_id, message_template_name,
  message_template_language, reason, other_info?}`; `message_template_quality_update` `{previous_quality_score,new_quality_score,…}`;
  `template_category_update` `{previous_category,new_category,…}`.
- **Send**: `POST /{phone-number-id}/messages` `{"type":"template","template":{"name","language":{"code":"en_US"},"components":[
  {"type":"header","parameters":[{"type":"text","text":"…"}|{"type":"image","image":{"link":"https://…"}}]},
  {"type":"body","parameters":[{"type":"text","text":"…"},…]},
  {"type":"button","sub_type":"url","index":"0","parameters":[{"type":"text","text":"suffix"}]}]}}`. Templates can be sent outside the 24h window.
