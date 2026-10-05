<?php
declare(strict_types=1);

/** The Librarian's proposals (slice 7): reads through mcp_librarian_proposals (it hides what the caller may not see), the writes in write.php. */

const PROPOSAL_KINDS = [
    'thread_to_page' => ['feather-file-plus', 'Thread to page'], 'verify' => ['feather-check-circle', 'Verify'], 'orphan' => ['feather-link-2', 'Orphan'],
    'duplicate' => ['feather-copy', 'Duplicate'], 'broken_link' => ['feather-alert-triangle', 'Broken link'], 'unanswered' => ['feather-help-circle', 'Unanswered'], 'stale' => ['feather-clock', 'Stale'],
];
const PROPOSAL_STATUSES = ['proposed' => 'Open', 'accepted' => 'Accepted', 'dismissed' => 'Dismissed'];

/** The row cast: ids as ints, uuids as strings. */
function proposal_cast(array $r): array
{
    foreach (['proposal_id', 'subject_message_id', 'subject_channel_id', 'proposed_by', 'decided_by'] as $k) { $r[$k] = ($r[$k] ?? null) === null ? null : (int) $r[$k]; }
    return $r;
}

const PROPOSAL_SELECT = "SELECT l.proposal_id, l.kind, l.subject_page_id::text AS subject_page_id, l.subject_message_id, l.subject_channel_id, l.proposed_page_id::text AS proposed_page_id,
        l.title, l.reason, l.status, l.proposed_by, pb.display_name AS proposed_by_name, l.decided_by, db.display_name AS decided_by_name, l.decided_at, l.created_at, l.decision_note,
        sp.plain_title AS subject_page_title, dp.plain_title AS draft_title, left(m.plain_text, 140) AS subject_first_line, m.thread_root_id AS subject_thread_root, c.name AS channel_name, c.kind AS channel_kind,
        c.space_id AS channel_space_id, COALESCE(sp.space_id, c.space_id) AS space_id
   FROM mcp_librarian_proposals l
   LEFT JOIN mcp_members pb ON pb.member_id = l.proposed_by
   LEFT JOIN mcp_members db ON db.member_id = l.decided_by
   LEFT JOIN mcp_pages sp ON sp.page_id = l.subject_page_id
   LEFT JOIN mcp_pages dp ON dp.page_id = l.proposed_page_id
   LEFT JOIN mcp_messages m ON m.message_id = l.subject_message_id
   LEFT JOIN mcp_channels c ON c.channel_id = l.subject_channel_id";

/** The proposals the caller may see, newest first. $f: status (default proposed; '' = all), kind. */
function find_proposals(PDO $pdo, array $f): array
{
    $where = ['true'];
    $args = [];
    $status = (string) ($f['status'] ?? 'proposed');
    if (isset(PROPOSAL_STATUSES[$status])) { $where[] = 'l.status = :status'; $args['status'] = $status; }
    if (isset(PROPOSAL_KINDS[(string) ($f['kind'] ?? '')])) { $where[] = 'l.kind = :kind'; $args['kind'] = (string) $f['kind']; }
    $st = $pdo->prepare(PROPOSAL_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.proposal_id DESC LIMIT 200');
    $st->execute($args);
    return array_map('proposal_cast', $st->fetchAll());
}

function find_proposal(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(PROPOSAL_SELECT . ' WHERE l.proposal_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : proposal_cast($r);
}

/** How many proposals wait, per status (for the tabs): the caller's view. */
function proposal_counts(PDO $pdo): array
{
    $out = array_fill_keys(array_keys(PROPOSAL_STATUSES), 0);
    foreach ($pdo->query('SELECT status, count(*) AS n FROM mcp_librarian_proposals GROUP BY status')->fetchAll() as $r) { $out[$r['status']] = (int) $r['n']; }
    return $out;
}

/** Dismissed within the last $days days (the Librarian does not propose them again): [{proposal_id, kind, title, subject_page_id, subject_message_id, decided_at, decision_note}]. */
function recently_dismissed(PDO $pdo, int $days): array
{
    $st = $pdo->prepare("SELECT proposal_id, kind, title, subject_page_id::text AS subject_page_id, subject_message_id, subject_channel_id, decided_at, decision_note
                           FROM mcp_librarian_proposals WHERE status = 'dismissed' AND decided_at > now() - make_interval(days => :d) ORDER BY decided_at DESC");
    $st->bindValue('d', max(1, $days), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** A page or a space root the caller may put the draft in: pages they may edit (title, space) and the spaces they may create in. */
function accept_destinations(PDO $pdo): array
{
    return ['spaces' => $pdo->query('SELECT s.space_id, s.name, s.icon FROM mcp_spaces s WHERE s.archived_at IS NULL AND sp_level_rank(sp_space_level(s.space_id)) >= 4 ORDER BY s.is_default DESC, lower(s.name)')->fetchAll(),
            'pages' => $pdo->query("SELECT p.page_id::text AS page_id, p.plain_title, s.name AS space_name FROM mcp_pages p JOIN mcp_spaces s ON s.space_id = p.space_id
                                     WHERE p.archived_at IS NULL AND NOT p.is_template AND p.parent_database_id IS NULL AND p.kind = 'page' AND sp_level_rank(p.my_level) >= 4
                                     ORDER BY lower(s.name), lower(p.plain_title) LIMIT 200")->fetchAll()];
}
