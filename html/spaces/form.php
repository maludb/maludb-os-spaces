<?php
declare(strict_types=1);
/** /spaces/new and /spaces/{id}/edit (screens `space-add`, `space-edit`): name, icon (a popover of emoji), description, kind, levels, the wiki. An owner edits; a member creates. */
require_once dirname(__DIR__, 2) . '/app/features/spaces/handler.php';
require_right('spaces.join');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('space');
$cur = null;
if ($id !== null) {
    $cur = find_space($pdo, $id) ?? refuse(404, 'Space not found.');
    require_space_owner($cur);
    if ($cur['archived_at'] !== null) {
        refuse(422, 'Space "' . $cur['name'] . '" is archived: restore it before changing it.');
    }
}
$screen = $cur === null ? 'space-add' : 'space-edit';
$settings = $pdo->query('SELECT default_space_kind, default_member_level, default_everyone_level, wiki_default_verify_months FROM sp_settings WHERE id = 1')->fetch();
$emoji = $pdo->query('SELECT shortcode, emoji FROM mcp_emoji ORDER BY shortcode')->fetchAll();
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['space' => $cur === null ? null : present_space($cur), 'kinds' => array_keys(SPACE_KINDS), 'levels' => array_keys(SPACE_LEVELS), 'everyone_levels' => array_keys(SPACE_EVERYONE_LEVELS), 'wiki_months' => WIKI_MONTHS, 'defaults' => $settings]);
}
render_screen($cur === null ? 'Make a space' : 'Change ' . $cur['name'], view('spaces/form.php', ['cur' => $cur, 'settings' => $settings, 'emoji' => $emoji, 'here' => here_url()]),
    ['activeNav' => 'spaces', 'screen' => $screen, 'entity' => 'space', 'recordId' => $id === null ? '' : (string) $id]);
