<?php
// Tiny local stand-in for graph.facebook.com, for curl demos without real credentials.
// Run: php -S 127.0.0.1:8099 tools/mock-meta.php   and set META_GRAPH_BASE=http://127.0.0.1:8099
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(__DIR__.'/mock-meta.log', date('c')." {$_SERVER['REQUEST_METHOD']} $path ".file_get_contents('php://input')."\n", FILE_APPEND);
header('Content-Type: application/json');

// ============ Instagram Graph API + Facebook Login for Business mock (docs/INSTAGRAM.md) ============
// Browser flow: dialog/oauth → redirects straight back to redirect_uri?code=…&state=… (as if the user approved). Containers, status polling
// (video containers stay IN_PROGRESS for 2 polls, so cron runs must resume them), media_publish, content_publishing_limit, permalinks.
// Magic values: a media URL containing "bad" → error 9004; a video URL containing "fail" → container ERROR; 'revoke' via /__ig/revoke?ig=ID.
// State lives in tools/mock-meta-ig.json (gitignored). Reset: GET /__ig/reset.
(function () use ($path) {
    $ver = '#^/v\d+\.\d+#';
    $p = preg_replace($ver, '', $path);
    $isIg = preg_match('#^/(dialog/oauth|oauth/access_token|me/accounts|debug_token|__ig/.*|__avatar/\d+\.svg|17841\d{12}(/.*)?|179\d{15,16}(/.*)?|IGC[0-9a-f]+)$#', $p);
    if (! $isIg) return;
    $file = __DIR__.'/mock-meta-ig.json';
    $st = is_file($file) ? json_decode(file_get_contents($file), true) : ['containers' => [], 'published' => [], 'revoked' => [], 'seq' => 0];
    $save = function () use (&$st, $file) { file_put_contents($file, json_encode($st, JSON_PRETTY_PRINT)); };
    $raw = file_get_contents('php://input');
    $in = array_merge($_GET, $_POST, json_decode($raw, true) ?: []);
    $accounts = [ // IG user id => [page id, page name, username, name, preset quota usage]
        '17841400000000001' => ['1100000000000001', 'Oswal Soap', 'oswalsoap', 'Oswal Soap', 6],
        '17841400000000002' => ['1100000000000002', 'Fineoutput Studio', 'fineoutput.studio', 'Fineoutput Studio', 1],
        '17841400000000003' => ['1100000000000004', 'Himalaya Tea Co', 'himalayatea', 'Himalaya Tea Co', 98],
    ];
    $pages = $accounts; // + one Page without an Instagram account (must be filtered out)
    $json = function ($d, int $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($d); exit; };
    $err = function (string $msg, int $code, ?int $sub = null, int $http = 400, array $x = []) use ($json) {
        $json(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $code] + ($sub ? ['error_subcode' => $sub] : []) + $x + ['fbtrace_id' => 'MOCK'.bin2hex(random_bytes(4))]], $http);
    };
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = preg_match('/^Bearer\s+(.+)$/i', $hdr, $m) ? $m[1] : ($in['access_token'] ?? '');
    $isRevoked = function (string $t) use (&$st) { return str_starts_with($t, 'REVOKED') || (preg_match('/^MOCKPAGE(\d+)$/', $t, $m) && in_array($m[1], $st['revoked'], true)); };
    $avatar = fn ($ig) => 'http://'.($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8099').'/__avatar/'.(array_search($ig, array_keys($accounts)) + 1).'.svg';
    $quota = function (string $ig) use (&$st, $accounts) {
        $n = (int) ($accounts[$ig][4] ?? 0); $cut = time() - 86400;
        foreach ($st['published'] as $pb) if ($pb['ig'] === $ig && $pb['t'] > $cut) $n++;
        return $n;
    };

    if (preg_match('#^/__ig/reset$#', $p)) { @unlink($file); $json(['reset' => true]); }
    if (preg_match('#^/__ig/revoke$#', $p)) { $pg = $accounts[$in['ig'] ?? ''][0] ?? null; if ($pg) { $st['revoked'][] = $pg; $save(); } $json(['revoked' => $pg]); }
    if (preg_match('#^/__ig/state$#', $p)) $json($st);
    if (preg_match('#^/__avatar/(\d+)\.svg$#', $p, $m)) {
        $names = array_values($accounts); $n = $names[(int) $m[1] - 1][2] ?? '?'; header('Content-Type: image/svg+xml');
        $c = ['#e8590c', '#f08c00', '#d9480f'][((int) $m[1] - 1) % 3];
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="'.$c.'"/><text x="100" y="128" font-family="Arial" font-size="96" font-weight="700" fill="#fff" text-anchor="middle">'.strtoupper($n[0]).'</text></svg>'; exit;
    }
    if (preg_match('#^/dialog/oauth$#', $p)) { // the "consent screen": approve immediately
        $ru = $in['redirect_uri'] ?? ''; if (! $ru) $err('Missing redirect_uri', 100);
        if (($in['client_id'] ?? '') === '') { http_response_code(400); echo 'mock: client_id missing'; exit; }
        if (empty($in['scope']) && empty($in['config_id'])) { http_response_code(400); echo 'mock: scope or config_id required'; exit; }
        file_put_contents(__DIR__.'/mock-meta.log', date('c')." DIALOG scope=".($in['scope'] ?? '')." config_id=".($in['config_id'] ?? '')."\n", FILE_APPEND);
        header('Location: '.$ru.(str_contains($ru, '?') ? '&' : '?').http_build_query(['code' => 'MOCKCODE'.bin2hex(random_bytes(4)), 'state' => $in['state'] ?? ''])); http_response_code(302); exit;
    }
    if (preg_match('#^/oauth/access_token$#', $p)) {
        if (($in['grant_type'] ?? '') === 'fb_exchange_token') {
            if (empty($in['fb_exchange_token']) || str_starts_with($in['fb_exchange_token'], 'REVOKED')) $err('Error validating access token: Session has expired', 190, 463, 400);
            $json(['access_token' => 'MOCKLONG'.bin2hex(random_bytes(10)), 'token_type' => 'bearer', 'expires_in' => 5183944]);
        }
        if (empty($in['code']) || ! str_starts_with($in['code'], 'MOCKCODE')) $err('This authorization code has been used or is invalid', 100, null, 400);
        $json(['access_token' => 'MOCKSHORT'.bin2hex(random_bytes(10)), 'token_type' => 'bearer', 'expires_in' => 3600]);
    }
    if (preg_match('#^/debug_token$#', $p)) {
        $t = $in['input_token'] ?? '';
        $valid = $t !== '' && ! $isRevoked($t);
        $json(['data' => ['app_id' => 'MOCKAPP', 'type' => 'PAGE', 'is_valid' => $valid, 'scopes' => ['instagram_basic', 'instagram_content_publish', 'pages_show_list', 'pages_read_engagement', 'business_management'],
            'data_access_expires_at' => time() + 90 * 86400, 'expires_at' => 0] + ($valid ? [] : ['error' => ['code' => 190, 'message' => 'Error validating access token: The session has been invalidated because the user changed their password or Facebook has changed the session for security reasons.', 'subcode' => 460]])]);
    }
    if (preg_match('#^/me/accounts$#', $p)) {
        if ($token === '' || $isRevoked($token)) $err('Invalid OAuth access token.', 190, null, 401);
        $data = [];
        foreach ($pages as $ig => [$pid, $pname, $user, $name]) {
            $data[] = ['id' => $pid, 'name' => $pname, 'access_token' => 'MOCKPAGE'.$pid, 'instagram_business_account' => ['id' => (string) $ig, 'username' => $user, 'name' => $name, 'profile_picture_url' => $avatar($ig)]];
            if ($pid === '1100000000000002') $data[] = ['id' => '1100000000000003', 'name' => 'Fineoutput Café (no Instagram)', 'access_token' => 'MOCKPAGE1100000000000003'];
        }
        $json(['data' => $data, 'paging' => ['cursors' => ['before' => 'a', 'after' => 'b']]]);
    }
    // ---- /{ig-user-id}/… ----
    if (preg_match('#^/(17841\d{12})(?:/(media|media_publish|content_publishing_limit))?$#', $p, $m)) {
        $ig = $m[1]; $sub = $m[2] ?? ''; $acc = $accounts[$ig] ?? null;
        if (! $acc) $err('Unsupported get request. Object with ID \''.$ig.'\' does not exist, cannot be loaded due to missing permissions, or does not support this operation.', 100, 33, 400);
        if ($isRevoked($token)) $err('Error validating access token: The user has not authorized application MOCKAPP.', 190, 458, 400);
        $method = $_SERVER['REQUEST_METHOD'];
        if ($sub === '' && $method === 'GET') $json(['id' => $ig, 'username' => $acc[2], 'name' => $acc[3], 'profile_picture_url' => $avatar($ig)]);
        if ($sub === 'content_publishing_limit') $json(['data' => [['quota_usage' => $quota($ig), 'config' => ['quota_total' => (int) (getenv('IG_MOCK_LIMIT') ?: 100), 'quota_duration' => 86400]]]]);
        if ($sub === 'media' && $method === 'POST') {
            $type = strtoupper($in['media_type'] ?? ''); $img = $in['image_url'] ?? null; $vid = $in['video_url'] ?? null; $kids = $in['children'] ?? null;
            if (is_array($kids)) $kids = implode(',', $kids);
            if (mb_strlen($in['caption'] ?? '') > 2200) $err('Invalid parameter', 100, 2207004, 400, ['error_user_title' => 'Caption too long', 'error_user_msg' => 'The caption is longer than 2200 characters.']);
            if ($type === 'CAROUSEL') {
                $ids = array_filter(explode(',', (string) $kids));
                if (count($ids) < 2 || count($ids) > 10) $err('Invalid parameter', 100, 2207004, 400, ['error_user_title' => 'Invalid carousel', 'error_user_msg' => 'A carousel needs between 2 and 10 children.']);
                foreach ($ids as $c) if (empty($st['containers'][$c]['carousel_item'])) $err('Invalid parameter', 100, 2207004, 400, ['error_user_msg' => "Child container $c is not a carousel item."]);
            } else {
                if (! $img && ! $vid) $err('Invalid parameter', 100, 2207001, 400, ['error_user_title' => 'Media URL missing', 'error_user_msg' => 'Provide image_url or video_url.']);
                $url = $img ?: $vid;
                if (str_contains($url, 'bad')) $err('Only photo or video can be accepted as media type.', 9004, 2207052, 400, ['error_user_title' => 'Media download has failed', 'error_user_msg' => 'The media could not be fetched from the URI you provided.']);
                if ($vid && ! in_array($type, ['REELS', 'VIDEO', 'STORIES'], true)) $err('Invalid parameter', 100, 2207023, 400, ['error_user_msg' => 'video_url requires media_type REELS, VIDEO or STORIES.']);
                if ($type === 'STORIES' && ! empty($in['caption'])) $err('Invalid parameter', 100, 2207004, 400, ['error_user_msg' => 'Stories do not support captions.']);
            }
            $id = 'IGC'.(++$st['seq']).bin2hex(random_bytes(3));
            $st['containers'][$id] = ['ig' => $ig, 'type' => $type ?: 'IMAGE', 'video' => (bool) $vid, 'fail' => $vid && str_contains($vid, 'fail'), 'carousel_item' => ! empty($in['is_carousel_item']) && $in['is_carousel_item'] !== 'false',
                'children' => $kids, 'polls' => 0, 'created' => time(), 'published' => false];
            $save(); $json(['id' => $id]);
        }
        if ($sub === 'media_publish' && $method === 'POST') {
            $cid = $in['creation_id'] ?? ''; $c = $st['containers'][$cid] ?? null;
            if (! $c || $c['ig'] !== $ig) $err('Invalid parameter', 100, 2207008, 400, ['error_user_msg' => 'The media container does not exist.']);
            if ($c['published']) $err('Invalid parameter', 100, 2207006, 400, ['error_user_msg' => 'This media container was already published.']);
            if ($c['video'] && $c['polls'] < 2) $err('Media ID is not available', 9007, 2207027, 400, ['error_user_title' => 'Media not ready', 'error_user_msg' => 'The media is not ready for publishing, please wait for a moment.']);
            if ($quota($ig) >= (int) (getenv('IG_MOCK_LIMIT') ?: 100)) $err('The user has reached the maximum number of posts per 24 hours.', 4, 2207042, 400, ['error_user_title' => 'Publishing limit reached', 'error_user_msg' => 'You have reached the maximum number of posts you can publish in 24 hours.']);
            $mid = '179'.str_pad((string) random_int(1, 999999999999999), 15, '0', STR_PAD_LEFT);
            $st['containers'][$cid]['published'] = true; $st['published'][] = ['ig' => $ig, 'media' => $mid, 't' => time(), 'type' => $c['type']];
            $save(); $json(['id' => $mid]);
        }
        $err('mock: unsupported IG edge', 100, null, 404);
    }
    // ---- container status ----
    if (preg_match('#^/(IGC[0-9a-f]+)$#', $p, $m)) {
        $c = $st['containers'][$m[1]] ?? null;
        if (! $c) $err('Unsupported get request. Object does not exist.', 100, 33, 400);
        if ($c['published']) $json(['id' => $m[1], 'status_code' => 'PUBLISHED', 'status' => 'Published']);
        $st['containers'][$m[1]]['polls']++; $polls = $st['containers'][$m[1]]['polls']; $save();
        if ($c['fail']) $json(['id' => $m[1], 'status_code' => $polls < 2 ? 'IN_PROGRESS' : 'ERROR', 'status' => $polls < 2 ? 'Processing' : 'Error: Video format not supported. Use H.264 MP4 (2207026)']);
        $done = ! $c['video'] || $polls >= 2;
        $json(['id' => $m[1], 'status_code' => $done ? 'FINISHED' : 'IN_PROGRESS', 'status' => $done ? 'Finished' : 'In progress']);
    }
    // ---- published media (permalink) ----
    if (preg_match('#^/(179\d{15,16})$#', $p, $m)) {
        $short = substr(strtr(base64_encode(md5($m[1], true)), '+/', '-_'), 0, 11);
        $type = 'IMAGE'; foreach ($st['published'] as $pb) if ($pb['media'] === $m[1]) $type = $pb['type'];
        $json(['id' => $m[1], 'permalink' => 'https://www.instagram.com/'.($type === 'REELS' ? 'reel' : 'p').'/'.$short.'/']);
    }
    $err('mock: unknown Instagram path', 100, null, 404);
})();
// ============ end Instagram mock ============
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
