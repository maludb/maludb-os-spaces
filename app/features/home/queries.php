<?php
declare(strict_types=1);
/**
 * The home's regions (screen `home`), as the design §9 names them. Phase 2 fixes the SHAPE; each slice fills its key from its own
 * query functions. A key is null until its slice ships; the view then shows the empty state naming that slice.
 *   unread           slice 4  — unread channels with their first unread lines (sp_unread)
 *   mentions         slice 4  — mentions and replies waiting (sp_activity_feed)
 *   recent_pages     slice 2  — recently edited pages in my spaces (sp_pages_changed_since)
 *   favorites        slice 2  — my favorites (mcp_page_favorites)
 *   verification_due slice 6  — pages I own needing verification (sp_wiki_status)
 *   librarian_note   slice 7  — the Librarian's last Monday note
 *   pending_joins    slice 1  — for a space owner: requests to join a closed space (mcp_space_join_requests)
 *   unanswered       slice 7  — for a space owner: the space's unanswered questions (sp_unanswered_questions)
 *   admin            slice 9  — for the admin: pending dispatches, published pages, trash size
 *   sidebar          Phase 0  — sp_sidebar(): the spaces, their root pages and channels (what sign-on lands on until the shell)
 */
function home_summary(PDO $pdo, int $memberId): array
{
    $sidebar = json_decode((string) one_value($pdo, 'SELECT sp_sidebar()::text'), true) ?: [];
    return [
        'unread' => null, 'mentions' => null, 'recent_pages' => null, 'favorites' => null, 'verification_due' => null, 'librarian_note' => null,
        'pending_joins' => null, 'unanswered' => null, 'admin' => null,
        'sidebar' => $sidebar,
        'may' => [
            'member' => has_right('spaces.join'), 'guest' => has_right('spaces.guest') && !has_right('spaces.join'),
            'owner' => has_right('space.manage') || array_filter($sidebar['spaces'] ?? [], static fn (array $s): bool => !empty($s['is_owner'])) !== [],
            'admin' => has_right('settings.manage'),
        ],
    ];
}
