# Manual chat takeover

Per conversation (`phone_number_id` + `contact_wa_id`) staff or the owning client can **Take over** a chat.

While `ai_contact_states.taken_over` is true:

- **All automatic replies are skipped** for that chat: AI (including trained / KB answers and smart-continue during handoff), keyword auto-replies, greetings / off-hours, flows, and template button follow-ups.
- Push notifications for inbound messages still fire so the human sees them.
- A manual outbound message still clears `needs_human` only — it does **not** clear `taken_over`.

**Give to AI** (`release`) sets `taken_over=false` and clears `needs_human` / `handoff_at` / `reason` so the AI resumes normally on the next inbound.

## API

| Who | Take over | Give to AI |
|-----|-----------|------------|
| Admin portal | `POST /api/conversations/takeover` | `POST /api/conversations/release` |
| Client portal | `POST /api/client/conversations/takeover` | `POST /api/client/conversations/release` |
| Mobile (client) | `POST /api/client/chat/takeover` | `POST /api/client/chat/release` |
| Mobile (admin) | `POST /api/admin/chat/takeover` | `POST /api/admin/chat/release` |

Body: `{ "phone_number_id": 1, "contact": "9198..." }`.

Conversation list and thread payloads include `taken_over` (and `needs_human`). Clients may only act on their own numbers.
