<?php
// Tiny local stand-in for graph.facebook.com, for curl demos without real credentials.
// Run: php -S 127.0.0.1:8099 tools/mock-meta.php   and set META_GRAPH_BASE=http://127.0.0.1:8099
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(__DIR__.'/mock-meta.log', date('c')." {$_SERVER['REQUEST_METHOD']} $path ".file_get_contents('php://input')."\n", FILE_APPEND);
header('Content-Type: application/json');
$store = __DIR__.'/mock-meta-templates.json';
$tpls = is_file($store) ? json_decode(file_get_contents($store), true) : [
    ['id' => 'MOCKTPL1', 'name' => 'order_confirmation', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY', 'quality_score' => ['score' => 'GREEN'],
     'components' => [['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order {{1}}', 'example' => ['header_text' => ['OS-1001']]],
        ['type' => 'BODY', 'text' => 'Hi {{1}}, your Oswal Soap order {{2}} of {{3}} is confirmed.', 'example' => ['body_text' => [['Ravi', 'OS-1001', '₹1,440']]]],
        ['type' => 'FOOTER', 'text' => 'Oswal Soap'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Track order', 'url' => 'https://oswal.example/track/{{1}}', 'example' => ['https://oswal.example/track/OS-1001']]]]]],
    ['id' => 'MOCKTPL2', 'name' => 'diwali_offer', 'language' => 'hi', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'INVALID_FORMAT',
     'components' => [['type' => 'BODY', 'text' => 'शुभ दीपावली! सभी साबुन पर 20% छूट।']]],
];
$save = fn () => file_put_contents($store, json_encode($tpls, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (preg_match('#/message_templates$#', $path)) {
    $m = $_SERVER['REQUEST_METHOD'];
    if ($m === 'GET') { echo json_encode(['data' => $tpls, 'paging' => ['cursors' => ['before' => 'x', 'after' => 'y']]]); return; }
    if ($m === 'POST') { $id = 'MOCKTPL'.rand(100, 99999); $tpls[] = ['id' => $id, 'status' => 'PENDING'] + $in; $save(); echo json_encode(['id' => $id, 'status' => 'PENDING', 'category' => $in['category'] ?? 'UTILITY']); return; }
    if ($m === 'DELETE' && str_starts_with($_GET['name'] ?? '', 'sample_')) { http_response_code(400); echo json_encode(['error' => ['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100, 'error_subcode' => 2593002, 'is_transient' => false,
        'error_user_title' => 'Sample template cannot be deleted', 'error_user_msg' => 'Sample templates provided by Meta can not be deleted.', 'fbtrace_id' => 'MOCK'.bin2hex(random_bytes(4))]]); return; }
    if ($m === 'DELETE') { $tpls = array_values(array_filter($tpls, fn ($t) => ! ($t['name'] === ($_GET['name'] ?? '') && (empty($_GET['hsm_id']) || $t['id'] === $_GET['hsm_id'])))); $save(); echo json_encode(['success' => true]); return; }
}
if (preg_match('#/(MOCKTPL\d+)$#', $path, $mm) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($tpls as &$t) if ($t['id'] === $mm[1]) { $t = array_merge($t, $in, ['status' => 'PENDING']); } unset($t); $save(); echo json_encode(['success' => true]); return;
}
if (preg_match('#/messages$#', $path) && ($in['type'] ?? '') === 'interactive') {
    // mirror Meta's interactive limits: <=3 reply buttons (title <=20), list <=10 rows (title <=24), body <=1024
    $i = $in['interactive'] ?? []; $bad = null;
    if (mb_strlen($i['body']['text'] ?? '') > 1024 || ($i['body']['text'] ?? '') === '') $bad = 'body.text must be 1-1024 chars';
    if (($i['type'] ?? '') === 'button') { $b = $i['action']['buttons'] ?? []; if (! $b || count($b) > 3) $bad = 'buttons must be 1-3'; foreach ($b as $x) if (mb_strlen($x['reply']['title'] ?? '') > 20) $bad = 'button title > 20'; }
    elseif (($i['type'] ?? '') === 'list') { $rows = array_merge(...array_map(fn ($s) => $s['rows'] ?? [], $i['action']['sections'] ?? [[]])); if (! $rows || count($rows) > 10) $bad = 'list rows must be 1-10'; foreach ($rows as $x) if (mb_strlen($x['title'] ?? '') > 24) $bad = 'row title > 24'; if (mb_strlen($i['action']['button'] ?? '') > 20) $bad = 'list button > 20'; }
    else $bad = 'unsupported interactive type';
    if ($bad) { http_response_code(400); echo json_encode(['error' => ['message' => "(#131009) Parameter value is not valid: $bad", 'code' => 131009]]); return; }
}
// Phone numbers: 100000000000001 is registered; 100000000000002 stays PENDING until POST /register (PIN 000000 = wrong PIN).
$regFile = __DIR__.'/mock-meta-registered.json';
$registered = is_file($regFile) ? json_decode(file_get_contents($regFile), true) : ['100000000000001' => true];
$phones = ['100000000000001' => ['display_phone_number' => '+91 98765 43210', 'verified_name' => 'Fineoutput Demo'], '100000000000002' => ['display_phone_number' => '+91 88249 30146', 'verified_name' => 'Fineoutput Support']];
$phoneInfo = fn ($id) => ['id' => $id] + $phones[$id] + (! empty($registered[$id])
    ? ['quality_rating' => 'GREEN', 'status' => 'CONNECTED', 'platform_type' => 'CLOUD_API', 'name_status' => 'APPROVED', 'code_verification_status' => 'VERIFIED', 'account_mode' => 'LIVE', 'messaging_limit_tier' => 'TIER_1K']
    : ['quality_rating' => 'UNKNOWN', 'status' => 'PENDING', 'platform_type' => 'NOT_APPLICABLE', 'name_status' => 'APPROVED', 'code_verification_status' => 'VERIFIED', 'account_mode' => 'LIVE']);
if (preg_match('#/(\d{15})/messages$#', $path, $pm) && isset($phones[$pm[1]]) && empty($registered[$pm[1]])) {
    http_response_code(400); echo json_encode(['error' => ['message' => '(#133010) Account not registered', 'type' => 'OAuthException', 'code' => 133010, 'fbtrace_id' => 'MOCK']]); return;
}
if (preg_match('#/(\d{15})/register$#', $path, $pm)) {
    $pin = (json_decode(file_get_contents('php://input'), true) ?: [])['pin'] ?? '';
    if ($pin === '000000') { http_response_code(400); echo json_encode(['error' => ['message' => '(#133005) Two step verification PIN Mismatch', 'type' => 'OAuthException', 'code' => 133005, 'fbtrace_id' => 'MOCK']]); return; }
    $registered[$pm[1]] = true; file_put_contents($regFile, json_encode($registered)); echo json_encode(['success' => true]); return;
}
if (preg_match('#/(\d{15})$#', $path, $pm) && isset($phones[$pm[1]]) && $_SERVER['REQUEST_METHOD'] === 'GET') { echo json_encode($phoneInfo($pm[1])); return; }
if (preg_match('#/messages$#', $path)) { $b = json_decode(file_get_contents('php://input'), true) ?: []; echo json_encode(['messaging_product' => 'whatsapp', 'contacts' => [['input' => $b['to'] ?? '', 'wa_id' => $b['to'] ?? '']], 'messages' => [['id' => 'wamid.MOCK'.bin2hex(random_bytes(6))]]]); }
elseif (preg_match('#/media$#', $path)) echo json_encode(['id' => 'MOCKMEDIA'.rand(1000, 9999)]);
elseif (preg_match('#/phone_numbers$#', $path)) echo json_encode(['data' => array_map($phoneInfo, array_keys($phones))]);
elseif (preg_match('#/register$#', $path)) echo json_encode(['success' => true]);
else { http_response_code(404); echo json_encode(['error' => ['message' => 'mock: unknown path']]); }
