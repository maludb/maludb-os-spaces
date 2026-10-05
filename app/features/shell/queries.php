<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): the sidebar in one call (sp_sidebar(), db/015), the
 * unread counts per channel (sp_unread()), the bell's count (mcp_notifications), my status line and the business name.
 * Each is cached for the request — one query per render, whatever the screen.
 */

/** The sidebar as sp_sidebar() answers it: favorites, spaces (sections → pages, channels), shared, private, dms, joinable. */
function sidebar(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = json_decode((string) one_value($pdo, 'SELECT sp_sidebar()::text'), true) ?: [];
        foreach (['favorites', 'spaces', 'shared', 'private', 'dms', 'joinable'] as $k) {
            $cache[$k] = is_array($cache[$k] ?? null) ? $cache[$k] : [];
        }
    }
    return $cache;
}

/** channel_id => ['unread' => n, 'mentions' => n, 'first_unread_id' => id] for every channel the caller may read (sp_unread()). */
function unread_counts(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query('SELECT channel_id, unread_count, first_unread_id, mention_count FROM sp_unread()')->fetchAll() as $r) {
            $cache[(int) $r['channel_id']] = ['unread' => (int) $r['unread_count'], 'mentions' => (int) $r['mention_count'], 'first_unread_id' => $r['first_unread_id'] === null ? null : (int) $r['first_unread_id']];
        }
    }
    return $cache;
}

/** The bell: how many of my notifications are unread. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/** My status line, or null when none or it has lapsed: ['text', 'emoji', 'until']. */
function my_status(PDO $pdo, int $memberId): ?array
{
    static $cache = false;
    if ($cache === false) {
        $st = $pdo->prepare('SELECT status_text, status_emoji, status_until FROM members WHERE id = :id AND (status_text IS NOT NULL OR status_emoji IS NOT NULL) AND (status_until IS NULL OR status_until > now())');
        $st->execute(['id' => $memberId]);
        $r = $st->fetch();
        $cache = $r === false ? null : ['text' => $r['status_text'], 'emoji' => $r['status_emoji'], 'until' => $r['status_until']];
    }
    return $cache;
}

/** The business's name on the header (sp_settings.business_name), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT business_name FROM sp_settings WHERE id = 1') ?: app_name());
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}
