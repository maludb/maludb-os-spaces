<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'digest' => (bool) ($p['digest'] ?? false), 'away_minutes' => $p['away_minutes'] ?? null, 'saved' => (bool) ($p['saved'] ?? true)];
}

/** My status line as JSON (null when none). */
function present_status(?array $s): ?array
{
    return $s === null ? null : ['text' => $s['text'], 'emoji' => $s['emoji'], 'until' => json_ts($s['until'] ?? null)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}
