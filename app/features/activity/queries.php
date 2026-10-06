<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log, db/017: their own rows; rows about pages, channels and spaces they may see; everything for the admin). */
const ACTIVITY_PAGE = 50;

/** Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days), space, channel, message (ints), page (a UUID). */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    foreach (['space' => 'space_id', 'channel' => 'channel_id', 'message' => 'message_id'] as $k => $col) {
        if (($filters[$k] ?? null) !== null) {
            $where[] = "$col = :$k";
            $args[$k] = (int) $filters[$k];
            $keyed = true;
        }
    }
    if (($filters['page'] ?? null) !== null && is_uuid($filters['page'])) {
        $where[] = 'entity_uuid = CAST(:page AS uuid)';
        $args['page'] = (string) $filters['page'];
        $keyed = true;
    }
    if (($filters['entity_type'] ?? null) !== null) {
        if (is_uuid($filters['entity_id'] ?? null)) {
            $where[] = 'entity_type = :etype AND entity_uuid = CAST(:eid AS uuid)';
            $args['eid'] = (string) $filters['entity_id'];
        } else {
            $where[] = 'entity_type = :etype AND entity_id = :eid';
            $args['eid'] = (int) $filters['entity_id'];
        }
        $args['etype'] = (string) $filters['entity_type'];
        $keyed = true;
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, entity_type, entity_id, entity_uuid, after, space_id, channel_id, message_id, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail (the view decides who sees it): by its key column where it has one (space, channel, message, page), else by entity. */
function find_record_activity(PDO $pdo, string $type, int|string $id, int $limit = 30): array
{
    $f = match ($type) { 'space' => ['space' => $id], 'channel' => ['channel' => $id], 'message' => ['message' => $id], 'page' => ['page' => $id], default => ['entity_type' => $type, 'entity_id' => $id] };
    return array_slice(find_my_activity($pdo, 0, 1, $f)['rows'], 0, $limit);
}

require_once __DIR__ . '/present.php';
