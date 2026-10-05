<?php
declare(strict_types=1);
/**
 * POST: action `proposal_accept` (log `librarian.accept`; confirm): a thread_to_page proposal moves its draft where the acceptor says — `parent` (a page they may edit) or `space` (a space's root they
 * may make pages in; `parent=space:<id>` too); the other kinds are marked accepted and the page or thread linked for a person to act (a verify one is verified on the page, slice 2's page_verify).
 */
require_once dirname(__DIR__, 2) . '/app/features/proposals/handler.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
require_member_here();
$id = request_integer('proposal') ?? refuse(422, 'Say which proposal.');
$p = find_proposal($pdo, $id) ?? refuse(404, 'Proposal not found.');
$parent = req_has('parent') && (string) req_val('parent') !== '' ? (string) req_val('parent') : null;
$space = req_has('space') && (string) req_val('space') !== '' ? request_integer('space') : null;
if ($parent === null && $space !== null) { $parent = 'space:' . $space; }
if ($p['kind'] === 'thread_to_page' && $p['status'] === 'proposed') {
    if ($parent === null) { sp_refuse_fields(['parent' => 'Say where the draft goes: a parent page or a space.']); }
    if (str_starts_with($parent, 'space:')) {
        $sid = (int) substr($parent, 6);
        $sn = one_value($pdo, 'SELECT name FROM mcp_spaces WHERE space_id = :s AND archived_at IS NULL', ['s' => $sid]) ?? refuse(404, 'Space not found.');
        if (!db_bool($pdo, 'SELECT sp_level_rank(sp_space_level(:s)) >= 4', ['s' => $sid])) { refuse(403, 'You may not create pages in ' . $sn . '.'); }
    } elseif (!is_uuid($parent)) {
        sp_refuse_fields(['parent' => 'The parent is named by its id.']);
    } else {
        require_page_level($parent, 'edit', 'Destination page');
    }
} else {
    $parent = null;
}
$out = sp_guard($pdo, static function () use ($pdo, $me, $id, $parent): array {
    $pdo->beginTransaction();
    $out = accept_proposal($pdo, $id, $parent, $me);
    log_activity($pdo, 'librarian.accept', 'proposal', $id, ['entity_uuid' => $out['page_id'], 'space_id' => $out['space_id'], 'after' => ['proposal_id' => $id, 'kind' => $out['kind'], 'subject' => $out['moved'] ? 'moved' : 'linked']]);
    $pdo->commit();
    return $out;
});
sp_done('Accepted', $id, $out['link'], 'proposalChanged', ['proposal_id' => $id, 'page_id' => $out['page_id'], 'moved' => $out['moved']]);
