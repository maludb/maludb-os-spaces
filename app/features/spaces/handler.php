<?php
declare(strict_types=1);

/**
 * The prelude of a space handler (slice 1, THE CRUD EXEMPLAR): the feature's files, the space a request names (through mcp_spaces —
 * 404 when unseen), the owner gate, the "archived" refusal, and the one logger that stamps space_id on every row.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The space the request names (`space`, `space_id` or `id`), through the view: 404 when unseen; $live refuses an archived one in words. */
function space_from_request(PDO $pdo, bool $live = true): array
{
    $id = request_integer('space') ?? request_integer('space_id') ?? request_integer('id') ?? refuse(422, 'Say which space.');
    $s = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
    if ($live && $s['archived_at'] !== null) {
        refuse(422, 'Space "' . $s['name'] . '" is archived: nothing changes in it.');
    }
    return $s;
}

/** The owner gate: an owner of the space, or the Spaces admin (sp_is_space_owner, db/006). */
function require_space_owner(array $space): void
{
    require_login();
    if (empty($space['i_am_owner'])) {
        refuse(403, 'You may not manage this space.');
    }
}

/** A row of the trail about a space: space_id on every one (design §6). */
function space_log(PDO $pdo, string $action, int $spaceId, array $opts = [], ?string $entityType = 'space', int|string|null $entityId = null): void
{
    log_activity($pdo, $action, $entityType, $entityId ?? ($entityType === 'space' ? $spaceId : null), ['space_id' => $spaceId] + $opts);
}

/** The notice banners a space screen lands with. */
function space_notices(array $s): array
{
    $n = $s['name'];
    return ['saved' => ['success', 'Saved ' . $n . '.'], 'created' => ['success', 'Made ' . $n . '. You own it.'], 'kind' => ['success', $n . ' is now ' . $s['kind'] . '.'],
            'archived' => ['warning', $n . ' is archived. Everything stays readable; nothing changes in it.'], 'restored' => ['success', $n . ' is back.'],
            'joined' => ['success', 'You are in ' . $n . ' now.'], 'left' => ['success', 'You left ' . $n . '.'], 'requested' => ['success', 'You asked to join ' . $n . '. Its owners are told.'],
            'withdrawn' => ['success', 'Your request is withdrawn.'], 'decided' => ['success', 'Decided.'], 'member' => ['success', 'Saved the members.'], 'owner' => ['success', 'Saved who owns ' . $n . '.'],
            'removed' => ['success', 'Removed from ' . $n . '.'], 'section' => ['success', 'Saved the sections.'], 'wiki' => ['success', $s['is_wiki'] ? $n . ' is a wiki.' : $n . ' is no longer a wiki.']];
}
