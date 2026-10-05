<?php
declare(strict_types=1);
/**
 * GET /spaces/{id}/wiki/report — the wiki's reports (screen `wiki-report`): Expired and Never verified (sp_wiki_status by state), Stale (sp_stale_pages), Orphans (sp_orphan_pages), Broken links
 * (sp_broken_links), Duplicates (sp_duplicate_titles) and Unanswered questions in the space's public channels (sp_unanswered_questions) — each the SQL's answer for the caller, narrowed to this space, so a
 * page they may not see is not in it. A space owner may nudge an owner (wiki_nudge_send); nothing here changes a page. JSON: the seven lists and the numbers they were cut with.
 */
require_once dirname(__DIR__, 3) . '/app/features/spaces/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/wiki/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/wiki/present.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id') ?? request_integer('space') ?? refuse(404, 'Space not found.');
$s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
if (!$s['is_wiki']) { refuse(404, 'Space "' . $s['name'] . '" is not a wiki.'); }
$report = wiki_report($pdo, $id);
$numbers = wiki_numbers($pdo, $s);
$may = ['nudge' => (bool) $s['i_am_owner']];
log_activity($pdo, 'screen.view', null, null, ['screen' => 'wiki-report', 'space_id' => $id]);
if (wants_json()) {
    respond_screen(['space' => present_space($s), 'numbers' => $numbers, 'counts' => array_map('count', $report), 'lists' => present_wiki_report($report), 'may' => $may]);
}
render_screen($s['name'] . ' · Wiki reports', view('wiki/report.php', ['s' => $s, 'report' => $report, 'numbers' => $numbers, 'may' => $may, 'me' => $me, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => sp_notice($_GET['notice'] ?? null, ['nudged' => ['success', 'Nudged. They have a notice now.'], 'already' => ['warning', 'Already nudged this week: nothing more was sent.'], 'verified' => ['success', 'Verified.']])]),
    ['activeNav' => 'spaces', 'screen' => 'wiki-report', 'entity' => 'space', 'recordId' => (string) $id]);
