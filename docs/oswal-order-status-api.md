# Oswal Order Status API — contract for the WhatsApp AI assistant

The WhatsApp Business panel's AI assistant uses this **read-only** endpoint to answer
"where is my order?" questions. Build it on the Oswal order portal. The panel is configured under
**Admin → Settings → AI Replies → Orders API base URL / Orders API key**.

## Endpoint

```
GET {base}/orders/status?phone={E164 digits}[&order_id={id}]
X-Api-Key: {secret key}
Accept: application/json
```

| Param      | Required | Notes |
|------------|----------|-------|
| `phone`    | yes      | Customer's WhatsApp number in E.164 **digits only**, no `+` (e.g. `919811000001`). The panel always sends the number the message came from, so the AI can never ask about anyone else's phone. |
| `order_id` | no       | If given, return that order **only if it belongs to `phone`**. Otherwise return 404 or an empty list. |

## Security requirements

1. **Read-only.** GET only. No other methods on this path. No side effects.
2. **Key-protected.** Requests without the correct `X-Api-Key` get `401`. Use a long random key (32+ chars) that is only for this integration, and compare it in constant time. The key can be rotated: change it on the portal and save the new one in the panel.
3. **Phone-scoped.** Always filter by `phone`, matching the customer's stored mobile normalised to digits (also try it with the country code removed, e.g. `91`). When `order_id` is given, filter by **both** phone and order_id. Never return orders for a different phone.
4. **Rate-limited.** For example, 60 requests/min per key and 10/min per phone. Return `429` with `Retry-After` when the limit is exceeded.
5. **HTTPS only.** Do not log the key. Logging the phone and order id is fine.
6. **Minimal data.** Return only the fields below. No addresses, emails, payment details or other customers' data.
7. Respond within **8 seconds**, which is the panel's timeout.

## Responses

`200 OK`

```json
{
  "orders": [
    {
      "order_id": "OS-1001",
      "status": "Shipped",
      "items": [{ "name": "Toor dal 1kg", "qty": 2 }],
      "total": 290,
      "placed_at": "2026-10-30",
      "expected_delivery": "2026-11-04"
    }
  ]
}
```

- Sort newest first. The panel shows at most 3.
- `items` may also be a plain string (`"Toor dal x2, Rice 5kg"`).
- `status` is a short human label (Placed / Packed / Shipped / Out for delivery / Delivered / Cancelled).
- Optionally include `"phone"` on each order. If it is present and doesn't match the request, the panel drops that order.

| Status | Meaning | Panel behaviour |
|--------|---------|-----------------|
| 200 with `orders: []` or 404 | No matching order | Polite hand-off to a human (`needs_human`) |
| 401 / 403 | Bad key | Hand-off; admin should check the key |
| 429 / 5xx / timeout | Temporary problem | Hand-off |

## Example

```
curl -H "X-Api-Key: $KEY" "https://portal.example.com/api/orders/status?phone=919811000001&order_id=OS-1001"
```
