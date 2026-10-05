<?php
declare(strict_types=1);

/** Writes to comments (slice 3): the guard of db/011 keeps the discussion one level deep, on its page, resolved as a whole, edited by its author. */

function add_comment(PDO $pdo, string $pageId, ?string $blockId, ?string $parent, array $body): string
{
    $st = $pdo->prepare('INSERT INTO comments (page_id, block_id, parent_comment_id, body, agent_run_id) VALUES (CAST(:p AS uuid), CAST(:b AS uuid), CAST(:par AS uuid), CAST(:body AS jsonb), :run) RETURNING id::text');
    $st->execute(['p' => $pageId, 'b' => $blockId, 'par' => $parent, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'run' => current_agent_run_id()]);
    return (string) $st->fetchColumn();
}

function edit_comment(PDO $pdo, string $id, array $body): void
{
    $st = $pdo->prepare('UPDATE comments SET body = CAST(:body AS jsonb) WHERE id = CAST(:id AS uuid) AND deleted_at IS NULL');
    $st->execute(['body' => json_encode($body, JSON_UNESCAPED_UNICODE), 'id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
}

function resolve_comment(PDO $pdo, string $id, bool $resolved): void
{
    $st = $pdo->prepare($resolved ? 'UPDATE comments SET resolved_at = now() WHERE id = CAST(:id AS uuid) AND resolved_at IS NULL AND deleted_at IS NULL'
                                  : 'UPDATE comments SET resolved_at = NULL, resolved_by = NULL WHERE id = CAST(:id AS uuid) AND resolved_at IS NOT NULL');
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException($resolved ? 'That discussion is resolved already.' : 'That discussion is open already.');
    }
}

function delete_comment(PDO $pdo, string $id): void
{
    $st = $pdo->prepare('UPDATE comments SET deleted_at = now() WHERE id = CAST(:id AS uuid) AND deleted_at IS NULL');
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
}
