<?php /** The workspace settings and the emoji list (screen `admin-settings`). Data: s (find_settings), emoji, notice, here */
$kinds = SETTINGS_FIELDS;
$field = static function (string $k, array $def, array $s): string {
    $id = 'settings-form-field-' . $k; [$label, $kind] = $def;
    $h = '<div class="mb-3" id="settings-form-' . e($k) . '"><label class="form-label fw-semibold" for="' . e($id) . '">' . e($label) . '</label>';
    if ($kind === 'enum') {
        $h .= '<select class="form-select btn-touch" name="' . e($k) . '" id="' . e($id) . '">';
        foreach ($def[2] as $v => $l) { $h .= '<option value="' . e((string) $v) . '"' . ((string) $s[$k] === (string) $v ? ' selected' : '') . '>' . e($l) . '</option>'; }
        $h .= '</select>';
    } elseif ($kind === 'int') {
        $key = $k === 'max_attachment_mb' ? 'max_attachment_mb' : $k;
        $h .= '<input type="number" inputmode="numeric" class="form-control btn-touch" name="' . e($k) . '" id="' . e($id) . '" min="' . (int) $def[2][0] . '" max="' . (int) $def[2][1] . '" value="' . (int) $s[$key] . '"><div class="form-text">' . (int) $def[2][0] . ' to ' . (int) $def[2][1] . '</div>';
    } elseif ($kind === 'bool') {
        $h = '<div class="mb-3" id="settings-form-' . e($k) . '"><input type="hidden" name="' . e($k) . '" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="' . e($id) . '"><input type="checkbox" class="form-check-input mt-0" name="' . e($k) . '" value="yes" id="' . e($id) . '"' . ($s[$k] ? ' checked' : '') . '>' . e($label) . '</label>';
    } elseif ($kind === 'lines') {
        $h .= '<textarea class="form-control" rows="6" name="' . e($k) . '" id="' . e($id) . '" spellcheck="false">' . e(implode("\n", $s[$k])) . '</textarea>';
    } else {
        $h .= '<input type="' . ($kind === 'url' ? 'url' : 'text') . '" class="form-control btn-touch" name="' . e($k) . '" id="' . e($id) . '" value="' . e((string) ($s[$k] ?? '')) . '" autocomplete="off">';
    }
    return $h . '</div>';
};
$groups = ['The workspace' => ['business_name', 'public_base_url', 'timezone', 'week_start_dow'],
           'New spaces' => ['default_space_kind', 'default_member_level', 'default_everyone_level', 'agents_join_default_space', 'admin_channel_name'],
           'History and the trash' => ['version_snapshot_minutes', 'version_retention_days', 'trash_retention_days'],
           'The wiki and the questions' => ['stale_page_days', 'unanswered_hours', 'wiki_default_verify_months'],
           'Files, embeds and the web' => ['max_attachment_mb', 'allowed_embed_hosts', 'public_pages_noindex'],
           'Telling people' => ['digest_hour', 'away_minutes', 'group_dm_max_members']]; ?>
<?= view('shared/header.php', ['id' => 'admin-settings', 'title' => 'Workspace settings', 'crumbs' => [['Home', '/'], ['Workspace settings', null]], 'back' => back_link()]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/admin/settings.php" hx-post="/admin/settings.php" hx-target="#flash" id="settings-form"><?= csrf_field() ?><input type="hidden" name="return_to" value="/admin/settings">
        <div class="row g-3">
        <?php foreach ($groups as $title => $keys): ?>
            <div class="col-12 col-lg-6"><div class="card h-100" id="settings-group-<?= e(strtolower(preg_replace('/[^a-z]+/i', '-', $title))) ?>"><div class="card-header"><h5 class="card-title mb-0"><?= e($title) ?></h5></div><div class="card-body">
                <?php foreach ($keys as $k): ?><?= $field($k, $kinds[$k], $s) ?><?php endforeach; ?>
            </div></div></div>
        <?php endforeach; ?>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary btn-touch" id="settings-form-save-btn">Save the settings</button>
            <span class="fs-12 text-muted ms-2" id="settings-form-updated">Last saved <?= e(format_ts($s['updated_at'], member_timezone(), 'M j, g:i A')) ?></span></div>
    </form>
    <div class="card mt-4" id="emoji-card"><div class="card-header"><h5 class="card-title mb-0">Emoji</h5></div>
        <div class="card-body border-bottom">
            <form method="post" action="/admin/emoji.php" hx-post="/admin/emoji.php" hx-target="#flash" id="emoji-form"><?= csrf_field() ?><input type="hidden" name="return_to" value="/admin/settings">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-3"><label class="form-label fw-semibold" for="emoji-form-field-shortcode">Shortcode</label><input type="text" class="form-control btn-touch" name="shortcode" id="emoji-form-field-shortcode" placeholder="shipit" autocomplete="off"></div>
                    <div class="col-5 col-md-2"><label class="form-label fw-semibold" for="emoji-form-field-emoji">Emoji</label><input type="text" class="form-control btn-touch" name="emoji" id="emoji-form-field-emoji" placeholder="🚢" autocomplete="off"></div>
                    <div class="col-7 col-md-4"><label class="form-label fw-semibold" for="emoji-form-field-keywords">Keywords</label><input type="text" class="form-control btn-touch" name="keywords" id="emoji-form-field-keywords" placeholder="ship, release" autocomplete="off"></div>
                    <div class="col-12 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="emoji-form-save-btn">Add or replace</button></div>
                </div>
                <div class="form-text">A shortcode that is already here is replaced.</div>
            </form>
        </div>
        <div class="list-group list-group-flush" id="emoji-list">
        <?php foreach ($emoji as $e): ?>
            <div class="list-group-item d-flex align-items-center gap-2" id="emoji-row-<?= e($e['shortcode']) ?>">
                <span class="fs-4" aria-hidden="true"><?= e($e['emoji']) ?></span>
                <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate">:<?= e($e['shortcode']) ?>:</div><?php if ($e['keywords'] !== []): ?><div class="fs-11 text-muted text-truncate"><?= e(implode(', ', $e['keywords'])) ?></div><?php endif; ?></div>
                <form method="post" action="/admin/emoji-delete.php" hx-post="/admin/emoji-delete.php" hx-target="#flash" hx-confirm="Remove :<?= e($e['shortcode']) ?>: from the picker?"><?= csrf_field() ?><input type="hidden" name="shortcode" value="<?= e($e['shortcode']) ?>"><input type="hidden" name="return_to" value="/admin/settings">
                    <button type="submit" class="btn btn-outline-danger btn-touch" id="emoji-row-<?= e($e['shortcode']) ?>-delete-btn">Remove</button></form>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
