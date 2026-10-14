#!/usr/bin/env bash
# End-to-end smoke test against the running servers (via the Vite proxy on :5173 by default).
set -euo pipefail
B=${BASE:-http://localhost:5190}/api
CRED=$(dirname "$0")/../secrets/admin-credentials.txt
EMAIL=$(grep ADMIN_EMAIL "$CRED" | cut -d= -f2); PASS=$(grep ADMIN_PASSWORD "$CRED" | cut -d= -f2)
J='-H Accept:application/json -H Content-Type:application/json'
ok(){ echo "✔ $*"; }
TOKEN=$(curl -sf $J -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}" $B/auth/login | python3 -c 'import json,sys;print(json.load(sys.stdin)["token"])'); ok login
A="-H Authorization:Bearer\ $TOKEN"
auth(){ curl -sf -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" "$@"; }
VT=verify-$RANDOM; SECRET=demo-app-secret
auth -X PUT -H Content-Type:application/json -d "{\"app_id\":\"123\",\"app_secret\":\"$SECRET\",\"access_token\":\"EAADEMO\",\"waba_id\":\"999\",\"verify_token\":\"$VT\"}" $B/settings >/dev/null; ok settings saved
NID=$(auth -H Content-Type:application/json -d "{\"phone_number_id\":\"E2E$RANDOM\",\"display_number\":\"+91 90000 11111\",\"name\":\"E2E\"}" $B/phone-numbers | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])'); ok "number created id=$NID"
auth -X PUT -H Content-Type:application/json -d '{"name":"E2E renamed"}' $B/phone-numbers/$NID | grep -q renamed && ok number updated
auth -X POST $B/phone-numbers-sync | grep -q '"synced":1' && ok "sync from (mock) Meta"
auth -H Content-Type:application/json -d "{\"phone_number_id\":$NID,\"to\":\"919812345678\",\"text\":\"Hello from portal\"}" $B/messages/send | grep -q '"status":"sent"' && ok "text sent"
echo "demo invoice" > /tmp/e2e-invoice.pdf
auth -F phone_number_id=$NID -F to=919812345678 -F text=Invoice -F file=@/tmp/e2e-invoice.pdf $B/messages/send | grep -q '"type":"document"' && ok "document uploaded+sent"
CH=$(curl -sf "$B/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=$VT&hub.challenge=4242"); [ "$CH" = 4242 ] && ok "webhook verify returns challenge"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=bad&hub.challenge=1")" = 403 ] && ok "bad verify token -> 403"
PNID=$(auth $B/phone-numbers/$NID | python3 -c 'import json,sys;print(json.load(sys.stdin)["phone_number_id"])')
RID=$(auth -H Content-Type:application/json -d "{\"match_type\":\"keyword\",\"trigger\":\"order,status\",\"reply\":\"Thanks! We will share your order status shortly.\",\"phone_number_id\":$NID}" $B/auto-replies | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])'); ok "auto-reply rule $RID"
BODY="{\"object\":\"whatsapp_business_account\",\"entry\":[{\"id\":\"999\",\"changes\":[{\"field\":\"messages\",\"value\":{\"messaging_product\":\"whatsapp\",\"metadata\":{\"display_phone_number\":\"919000011111\",\"phone_number_id\":\"$PNID\"},\"contacts\":[{\"profile\":{\"name\":\"Ravi\"},\"wa_id\":\"919812345678\"}],\"messages\":[{\"from\":\"919812345678\",\"id\":\"wamid.E2E$RANDOM$RANDOM\",\"timestamp\":\"$(date +%s)\",\"type\":\"text\",\"text\":{\"body\":\"What is my order status?\"}}]}}]}]}"
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')
[ "$(curl -s -o /dev/null -w '%{http_code}' -H Content-Type:application/json -H 'X-Hub-Signature-256: sha256=bad' -d "$BODY" $B/webhook/whatsapp)" = 401 ] && ok "bad signature -> 401"
R=$(curl -sf -H Content-Type:application/json -H "X-Hub-Signature-256: sha256=$SIG" -d "$BODY" $B/webhook/whatsapp); echo "  $R"
echo "$R" | grep -q '"auto_replies":1' && ok "inbound stored + auto-reply fired"
auth "$B/messages/thread?phone_number_id=$NID&contact=919812345678" | grep -q 'order status shortly' && ok "auto-reply in thread"
KEY=$(auth -H Content-Type:application/json -d '{"name":"Oswal Soap orders (e2e)"}' $B/api-keys | python3 -c 'import json,sys;print(json.load(sys.stdin)["plain_key"])'); ok "api key generated"
[ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer nope' -H Accept:application/json -d from=$PNID -d to=919812345678 -d text=x $B/v1/messages/send)" = 401 ] && ok "bad api key -> 401"
curl -sf -H "Authorization: Bearer $KEY" -H Content-Type:application/json -H Accept:application/json -d "{\"from\":\"$PNID\",\"to\":\"919812345678\",\"text\":\"New order #OS-1001 received: 12 x Oswal Neem Soap\",\"media_url\":\"https://example.com/order-1001.pdf\"}" $B/v1/messages/send | grep -q '"success":true' && ok "API-key endpoint send (with media_url)"
curl -sf -H "Authorization: Bearer $KEY" -H Accept:application/json -F from=$PNID -F to=919812345678 -F text=Invoice -F file=@/tmp/e2e-invoice.pdf $B/v1/messages/send | grep -q '"success":true' && ok "API-key endpoint send (multipart upload)"
# ---- Template Master ----
rm -f "$(dirname "$0")/mock-meta-templates.json"
auth -X POST $B/templates-sync | grep -q '"synced":2' && ok "templates synced from (mock) Meta"
TID=$(auth "$B/templates?status=APPROVED&q=order_confirmation" | python3 -c 'import json,sys;print(json.load(sys.stdin)[0]["id"])')
TN=e2e_tpl_$RANDOM
NEWID=$(auth -H Content-Type:application/json -d "{\"name\":\"$TN\",\"language\":\"en_US\",\"category\":\"UTILITY\",\"body\":{\"text\":\"Hi {{1}}, your order {{2}} has shipped.\",\"examples\":[\"Ravi\",\"OS-1\"]},\"footer\":\"Oswal Soap\",\"buttons\":[{\"type\":\"QUICK_REPLY\",\"text\":\"Thanks\"}]}" $B/templates | python3 -c 'import json,sys;d=json.load(sys.stdin);assert d["status"]=="PENDING";print(d["id"])'); ok "template created (PENDING)"
auth "$B/templates/$NEWID" | grep -q '"allowed":false' && ok "PENDING template edit blocked"
TMETA=$(auth "$B/templates/$NEWID" | python3 -c 'import json,sys;print(json.load(sys.stdin)["meta_id"])')
WB="{\"object\":\"whatsapp_business_account\",\"entry\":[{\"id\":\"999\",\"time\":$(date +%s),\"changes\":[{\"field\":\"message_template_status_update\",\"value\":{\"event\":\"REJECTED\",\"message_template_id\":\"$TMETA\",\"message_template_name\":\"$TN\",\"message_template_language\":\"en_US\",\"reason\":\"INVALID_FORMAT\"}}]}]}"
SIG=$(printf '%s' "$WB" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')
curl -sf -H Content-Type:application/json -H "X-Hub-Signature-256: sha256=$SIG" -d "$WB" $B/webhook/whatsapp | grep -q '"template_events":1' && ok "template status webhook accepted"
auth "$B/templates/$NEWID" | grep -q '"rejected_reason":"INVALID_FORMAT"' && ok "template now REJECTED with reason"
auth -X PUT -H Content-Type:application/json -d '{"category":"UTILITY","body":{"text":"Hi {{1}}, order {{2}} is on the way.","examples":["Ravi","OS-1"]}}' $B/templates/$NEWID | grep -q '"status":"PENDING"' && ok "rejected template edited -> PENDING"
auth -X DELETE $B/templates/$NEWID -o /dev/null -w '%{http_code}' | grep -q 204 && ok "template deleted"
auth -H Content-Type:application/json -d "{\"phone_number_id\":$NID,\"to\":\"919812345678\",\"template_id\":$TID,\"params\":[\"Ravi\",\"OS-1001\",\"₹1,440\"],\"header_param\":\"OS-1001\",\"button_params\":[\"OS-1001\"]}" $B/messages/send-template | grep -q '"type":"template"' && ok "template sent from portal"
curl -sf -H "Authorization: Bearer $KEY" -H Content-Type:application/json -H Accept:application/json -d "{\"from\":\"$PNID\",\"to\":\"919812345678\",\"template\":\"order_confirmation\",\"language\":\"en_US\",\"params\":[\"Ravi\",\"OS-1002\",\"₹960\"],\"header_param\":\"OS-1002\",\"button_params\":[\"OS-1002\"]}" $B/v1/messages/send | grep -q '"success":true' && ok "template sent via API key"
[ "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $KEY" -H Content-Type:application/json -H Accept:application/json -d "{\"from\":\"$PNID\",\"to\":\"919812345678\",\"template\":\"diwali_offer\"}" $B/v1/messages/send)" = 422 ] && ok "rejected template send -> 422"
grep -q '"type":"template"' "$(dirname "$0")/mock-meta.log" && ok "mock Meta received template payload"
echo "ALL E2E CHECKS PASSED"
