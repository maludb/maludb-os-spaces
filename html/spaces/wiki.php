<?php
declare(strict_types=1);
/** GET: the wiki's status (screen `wiki-view`). POST: action `space_wiki_set` (log `space.wiki_set`; confirm; agent approval `other`): an owner makes a space a wiki (every page gets its creator as wiki owner where none) or stops. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    // GET /spaces/{id}/wiki — the wiki's status (screen `wiki-view`, slice 2): every page with its owner and verification, the expired first.
    require_once dirname(__DIR__, 2) . '/app/features/pages/queries.php';
    require_once dirname(__DIR__, 2) . '/app/features/pages/present.php';
    require_login();
    require_human();
    $pdo = db();
    $me = (int) current_member_id();
    $id = request_integer('id') ?? request_integer('space') ?? refuse(404, 'Space not found.');
    $s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
    if (!$s['is_wiki']) { refuse(404, 'Space "' . $s['name'] . '" is not a wiki.'); }
    $rows = wiki_status($pdo, $id);
    $may = ['owner' => $s['i_am_owner'], 'verify_any' => $s['i_am_owner']];
    log_screen_view($pdo, 'wiki-view');
    if (wants_json()) {
        respond_screen(['space' => present_space($s), 'pages' => array_map('present_wiki_row', $rows), 'may' => $may]);
    }
    render_screen($s['name'] . ' · Wiki', view('spaces/wiki.php', ['s' => $s, 'rows' => $rows, 'me' => $me, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => sp_notice($_GET['notice'] ?? null, ['verified' => ['success', 'Verified.']] + space_notices($s))]),
        ['activeNav' => 'spaces', 'screen' => 'wiki-view', 'entity' => 'space', 'recordId' => (string) $id]);
    exit;
}
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$s = space_from_request($pdo);
require_space_owner($s);
$wiki = sp_yes('wiki', $s['is_wiki']);
$errors = [];
$months = sp_int('verify_months', $s['wiki_default_verify_months'] ?? (int) one_value($pdo, 'SELECT wiki_default_verify_months FROM sp_settings WHERE id = 1'), 1, 12, 'Verify months', $errors, true);
if ($wiki && !in_array($months, WIKI_MONTHS, true)) { $errors['verify_months'] = 'Verify every 1, 3, 6 or 12 months.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
$owners = sp_guard($pdo, static function () use ($pdo, $me, $s, $wiki, $months): int {
    $pdo->beginTransaction();
    $n = set_space_wiki($pdo, $s['space_id'], $wiki, $wiki ? $months : null, $me);
    space_log($pdo, 'space.wiki_set', $s['space_id'], ['before' => ['is_wiki' => $s['is_wiki'], 'verify_months' => $s['wiki_default_verify_months']], 'after' => ['is_wiki' => $wiki, 'verify_months' => $wiki ? $months : null, 'owners_set' => $n]]);
    $pdo->commit();
    return $n;
});
sp_done($wiki ? $s['name'] . ' is a wiki now' . ($owners > 0 ? ' (' . $owners . ' page' . ($owners === 1 ? '' : 's') . ' given an owner)' : '') : $s['name'] . ' is no longer a wiki', $s['space_id'], sp_land(return_path('/spaces/' . $s['space_id']), 'wiki'), 'spaceChanged', ['owners_set' => $owners]);
