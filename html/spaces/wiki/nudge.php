<?php
declare(strict_types=1);
/**
 * Action `wiki_nudge_send` (log `page.nudge`; entity_uuid, space_id, member_id, reason — never the page's words): ask a wiki page's owner (or `member`) to look at it again. The caller needs to see the page
 * and own its space; the notice is a `verification` one through sp_notify() with the dedupe key wiki_nudge:<page>:<ISO week>, so a second nudge the same week queues nothing and says so.
 * `reason` (expired | never | stale) is optional — it is read from the page when left out. An agent's nudge is the Librarian's own: it does not pause.
 */
require_once dirname(__DIR__, 3) . '/app/features/pages/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/spaces/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/wiki/queries.php';
sp_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$p = page_from_request($pdo);
require_page_level($p['page_id'], 'view');
$s = $p['space_id'] === null ? null : find_space($pdo, (int) $p['space_id']);
if ($s === null) { refuse(404, 'Page not found.'); }
if (!$s['is_wiki']) { refuse(422, 'Space "' . $s['name'] . '" is not a wiki.'); }
require_space_owner($s);
$errors = [];
$member = sp_ref($pdo, 'member', null, "SELECT id FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors);
$reason = req_val('reason');
if ($reason !== null && $reason !== '' && !in_array($reason, ['expired', 'never', 'stale'], true)) { $errors['reason'] = 'The reason is expired, never or stale.'; }
if ($errors !== []) { sp_refuse_fields($errors); }
$r = sp_guard($pdo, static function () use ($pdo, $me, $p, $s, $member, $reason): array {
    $pdo->beginTransaction();
    $r = nudge_owner($pdo, $p['page_id'], $member, $me, $reason === '' ? null : $reason);
    if ($r['queued']) {
        page_log($pdo, 'page.nudge', $p['page_id'], (int) $s['space_id'], ['member_id' => $r['member_id'], 'reason' => $r['reason']]);
    }
    $pdo->commit();
    return $r;
});
$land = sp_land(return_path('/spaces/' . (int) $s['space_id'] . '/wiki/report'), $r['queued'] ? 'nudged' : 'already', 'nudge-' . $p['page_id']);
sp_done($r['queued'] ? 'Nudged ' . $r['member_name'] : 'Already nudged this week: nothing more was sent', $p['page_id'], $land, 'wikiChanged', ['queued' => $r['queued'], 'member_id' => $r['member_id']]);
