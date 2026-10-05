<?php
declare(strict_types=1);

/**
 * Databases (slice 5): the HTML of a property value, read-only — a chip, a number in its format, a link — shared by the table's read-only cells, the board/gallery/list cards,
 * the properties panel's computed lines and the linked view on a page. Every dynamic value through e().
 */

/** A property key as an id-safe fragment (a two-way relation's mirror is "Related to tasks"). */
function kid(string $key): string
{
    return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $key), '-');
}

/** Notion's colors → the chip's class (databases.css defines bg-<color>-subtle: the bundled Bootstrap has none). */
function chip_class(?string $color): string
{
    $c = in_array((string) $color, OPTION_COLORS, true) ? (string) $color : 'default';
    return 'sp-chip bg-' . $c . '-subtle';
}

function render_chip(string $name, ?string $color = null): string
{
    return '<span class="' . e(chip_class($color)) . '">' . e($name) . '</span>';
}

/** A number in its format (the schema's number.format). */
function format_number(mixed $v, string $format = 'number'): string
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '';
    }
    $n = $v + 0;
    $dec = is_float($n) ? 2 : 0;
    return match ($format) {
        'number_with_commas' => number_format($n, is_float($n) ? 2 : 0),
        'percent' => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') . '%',
        'dollar' => '$' . number_format($n, $dec),
        'euro' => '€' . number_format($n, $dec),
        'pound' => '£' . number_format($n, $dec),
        'yen' => '¥' . number_format($n, 0),
        default => (string) $n,
    };
}

/** The colour of an option name in a select-type property (what the schema says now). */
function option_color(array $def, string $name): ?string
{
    foreach (property_options($def) as $o) {
        if (strcasecmp((string) ($o['name'] ?? ''), $name) === 0) {
            return $o['color'] ?? null;
        }
    }
    return null;
}

/**
 * A resolved property value as HTML for reading. $row names the row's id (a title links to it), $here the page to come back to. Returns '' for an empty value.
 * Rollups, unique ids and the created/edited ones are text-muted (computed); a rollup carries its function as a tooltip.
 */
function render_value(string $type, array $def, mixed $v, ?string $rowId = null, string $here = '', ?string $databaseId = null): string
{
    if ($v === null || $v === [] || $v === '') {
        return '';
    }
    switch ($type) {
        case 'title':
            $t = display_value('title', $v);
            $t = $t !== '' ? $t : 'Untitled';
            return $rowId === null ? '<span class="fw-semibold">' . e($t) . '</span>' : hx_link(with_back('/databases/' . ($databaseId ?? '-') . '/rows/' . $rowId, $here), e($t), 'fw-semibold text-dark');
        case 'select':
        case 'status':
            $n = display_value($type, $v);
            return $n === '' ? '' : render_chip($n, is_array($v) ? ($v['color'] ?? option_color($def, $n)) : option_color($def, $n));
        case 'multi_select':
            $out = '';
            foreach ((array) $v as $o) {
                $n = is_array($o) ? (string) ($o['name'] ?? '') : (string) $o;
                if ($n !== '') {
                    $out .= render_chip($n, (is_array($o) ? ($o['color'] ?? null) : null) ?? option_color($def, $n)) . ' ';
                }
            }
            return trim($out);
        case 'checkbox':
            return '<span class="sp-check' . ($v ? ' on' : '') . '" aria-label="' . ($v ? 'Yes' : 'No') . '">' . ($v ? '☑' : '☐') . '</span>';
        case 'number':
            return e(format_number($v, (string) ($def['number']['format'] ?? 'number')));
        case 'url':
            return '<a href="' . e((string) $v) . '" target="_blank" rel="noopener noreferrer" class="text-break">' . e((string) $v) . '</a>';
        case 'email':
            return '<a href="mailto:' . e((string) $v) . '">' . e((string) $v) . '</a>';
        case 'phone_number':
            return '<a href="tel:' . e((string) $v) . '">' . e((string) $v) . '</a>';
        case 'people':
            return implode(', ', array_map(static fn ($p): string => '<span class="sp-person">' . ((!empty($p['is_agent'])) ? '<i class="feather-cpu"></i> ' : '') . e((string) ($p['name'] ?? '')) . '</span>', (array) $v));
        case 'files':
            return implode(', ', array_map(static fn ($f): string => '<a href="/files/' . (int) ($f['id'] ?? 0) . '">' . e((string) ($f['filename'] ?? 'file')) . '</a>', (array) $v));
        case 'relation':
            return implode(', ', array_map(static fn ($r): string => '[[' . '<a href="' . e('/pages/' . ($r['id'] ?? '')) . '">' . e((string) ($r['title'] !== '' && $r['title'] !== null ? $r['title'] : 'Untitled')) . '</a>' . ']]', (array) $v));
        case 'rollup':
            $fn = (string) ($def['rollup']['function'] ?? '');
            return '<span class="text-muted" title="' . e(str_replace('_', ' ', $fn) . ' of ' . ($def['rollup']['property'] ?? '') . ' through ' . ($def['rollup']['relation'] ?? '')) . '">' . e(display_value('rollup', $v)) . '</span>';
        case 'unique_id':
        case 'created_time':
        case 'last_edited_time':
        case 'created_by':
        case 'last_edited_by':
        case 'verification':
            return '<span class="text-muted">' . e(display_value($type, $v)) . '</span>';
        default:
            return e(display_value($type, $v));
    }
}

/** A short type chip for the schema screen and the panel's labels. */
function type_chip(string $type): string
{
    return '<span class="badge bg-light text-dark sp-type-chip">' . e(TYPE_LABELS[$type] ?? $type) . '</span>';
}

/** Where a row lives: its page under the database. */
function row_url(string $databaseId, string $rowId, string $here = ''): string
{
    $u = '/databases/' . $databaseId . '/rows/' . $rowId;
    return $here === '' ? $u : with_back($u, $here);
}

/** The text a gallery/list card shows for the visible properties other than the title: [[name, html], …] for non-empty ones. */
function card_lines(array $cols, array $props, string $titleKey, string $here = '', ?string $databaseId = null): array
{
    $out = [];
    foreach ($cols as $k => $def) {
        if ($k === $titleKey) {
            continue;
        }
        $html = render_value((string) $def['type'], $def, $props[$k] ?? null, null, $here, $databaseId);
        if ($html !== '') {
            $out[] = [(string) ($def['name'] ?? $k), $html, (string) $def['type']];
        }
    }
    return $out;
}

/** A value as an id-safe slug (board-column-{value}, table-group-{value}). */
function value_slug(?string $v): string
{
    $s = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $v), '-');
    return $s === '' ? 'none' : $s;
}

/**
 * Rows grouped by a property: [['value' => what a drag posts, 'label', 'color', 'slug', 'rows' => [...]]] — a select or status in its option order (an option with no rows still a column when
 * $allOptions), the rest in the order of first sight, and "No <property>" last. A people or multi-select row stands in each of its groups. $sub = the row key to group by ('sub_group_value' for a sub-group).
 */
function group_rows(array $rows, string $groupKey, array $def, bool $allOptions = false, string $field = 'group_value'): array
{
    $type = (string) $def['type'];
    $label = (string) ($def['name'] ?? $groupKey);
    $groups = [];
    $put = static function (string $value, string $lbl, ?string $color, array $row) use (&$groups): void {
        $k = strtolower($value);
        $groups[$k] ??= ['value' => $value, 'label' => $lbl, 'color' => $color, 'slug' => value_slug($lbl), 'rows' => []];
        $groups[$k]['rows'][] = $row;
    };
    if (in_array($type, ['select', 'status'], true) && $allOptions) {
        foreach (property_options($def) as $o) {
            $k = strtolower((string) $o['name']);
            $groups[$k] = ['value' => (string) $o['name'], 'label' => (string) $o['name'], 'color' => $o['color'] ?? null, 'slug' => value_slug((string) $o['name']), 'rows' => []];
        }
    } elseif (in_array($type, ['select', 'status'], true)) {
        // the option order even without all options: sorted below
    }
    $none = ['value' => '', 'label' => 'No ' . $label, 'color' => null, 'slug' => 'none', 'rows' => []];
    foreach ($rows as $r) {
        $v = $r['properties'][$groupKey] ?? null;
        if (in_array($type, ['people'], true)) {
            $list = (array) ($v ?? []);
            if ($list === []) { $none['rows'][] = $r; }
            foreach ($list as $p) { $put((string) ($p['id'] ?? ''), (string) ($p['name'] ?? ''), null, $r); }
        } elseif ($type === 'multi_select') {
            $list = (array) ($v ?? []);
            if ($list === []) { $none['rows'][] = $r; }
            foreach ($list as $o) { $n = is_array($o) ? (string) ($o['name'] ?? '') : (string) $o; $put($n, $n, is_array($o) ? ($o['color'] ?? option_color($def, $n)) : option_color($def, $n), $r); }
        } else {
            $text = $field === 'sub_group_value' ? ($r['sub_group_value'] ?? '') : ($r['group_value'] ?? '');
            $text = (string) ($text ?? '');
            if ($text === '') { $none['rows'][] = $r; continue; }
            $color = in_array($type, ['select', 'status'], true) ? option_color($def, $text) : null;
            $put($text, $type === 'checkbox' ? ($text === 'true' || $text === 'Yes' ? 'Yes' : 'No') : $text, $color, $r);
        }
    }
    $out = array_values($groups);
    if (in_array($type, ['select', 'status'], true) && !$allOptions) {
        $order = array_flip(array_map(static fn (array $o): string => strtolower((string) $o['name']), property_options($def)));
        usort($out, static fn (array $a, array $b): int => ($order[strtolower($a['value'])] ?? 999) <=> ($order[strtolower($b['value'])] ?? 999));
    } elseif (!in_array($type, ['select', 'status'], true)) {
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    }
    if ($none['rows'] !== [] || $allOptions) {
        $out[] = $none;
    }
    return $out;
}

/** A row's first date as Y-m-d for a date property key (a date {start}, or created/edited time); null when it has none. */
function row_date(array $row, ?string $key, array $schema, bool $end = false): ?string
{
    if ($key === null || !isset($schema[$key])) {
        return null;
    }
    $v = $row['properties'][$key] ?? null;
    $type = (string) $schema[$key]['type'];
    if (in_array($type, ['created_time', 'last_edited_time'], true)) {
        return is_string($v) && strlen($v) >= 10 ? substr($v, 0, 10) : null;
    }
    if (!is_array($v)) {
        return null;
    }
    $s = $v[$end ? 'end' : 'start'] ?? null;
    if ($end && ($s === null || $s === '')) {
        $s = $v['start'] ?? null;
    }
    return is_string($s) && strlen($s) >= 10 ? substr($s, 0, 10) : null;
}

/**
 * A view's layout as HTML — the one place the six layouts are chosen, for the database's own screen and for a linked view on a page. $may: edit (rows), schema, full. $o: limit, month (Y-m), wk (week
 * start 0-6), week (Y-m-d), weeks, base (the view's URL), members (people choices, when cells are editable).
 */
function render_layout(PDO $pdo, array $d, array $view, array $rows, int $total, array $may, string $here, array $o = []): string
{
    $schema = $d['properties'];
    $cols = visible_schema($schema, $view['visible_properties'], (string) $d['title_property_key']);
    $limit = (int) ($o['limit'] ?? 100);
    $layout = (string) $view['layout'];
    if ($layout === 'gallery') {
        $covers = [];
        $mimes = [];
        $cc = $view['card_cover'] ?? 'page_cover';
        if ($cc === 'page_cover') {
            $covers = row_covers($pdo, array_column($rows, 'row_id'));
            $mimes = attachment_mimes($pdo, array_values($covers));
        } elseif (isset($schema[$cc])) {
            foreach ($rows as $r) {
                $f = $r['properties'][$cc][0] ?? null;
                if (is_array($f) && isset($f['id'])) {
                    $covers[$r['row_id']] = (int) $f['id'];
                    $mimes[(int) $f['id']] = ['mime_type' => $f['mime_type'] ?? null];
                }
            }
        }
        return view('databases/partials/layout-gallery.php', ['d' => $d, 'view' => $view, 'cols' => $cols, 'rows' => $rows, 'total' => $total, 'limit' => $limit, 'may' => $may, 'here' => $here, 'schema' => $schema, 'covers' => $covers, 'mimes' => $mimes]);
    }
    return match ($layout) {
        'board' => view('databases/partials/layout-board.php', ['d' => $d, 'view' => $view, 'cols' => $cols, 'rows' => $rows, 'total' => $total, 'may' => $may, 'here' => $here, 'schema' => $schema]),
        'list' => view('databases/partials/layout-list.php', ['d' => $d, 'view' => $view, 'cols' => $cols, 'rows' => $rows, 'total' => $total, 'limit' => $limit, 'may' => $may, 'here' => $here]),
        'calendar' => view('databases/partials/layout-calendar.php', ['d' => $d, 'view' => $view, 'cols' => $cols, 'rows' => $rows, 'may' => $may, 'here' => $here, 'schema' => $schema, 'month' => $o['month'] ?? (new DateTimeImmutable('today'))->format('Y-m'), 'wk' => (int) ($o['wk'] ?? 1), 'base' => $o['base'] ?? $here]),
        'timeline' => view('databases/partials/layout-timeline.php', ['d' => $d, 'view' => $view, 'cols' => $cols, 'rows' => $rows, 'may' => $may, 'here' => $here, 'schema' => $schema, 'week' => $o['week'] ?? (new DateTimeImmutable('monday this week'))->format('Y-m-d'), 'weeks' => (int) ($o['weeks'] ?? 8), 'base' => $o['base'] ?? $here]),
        default => view('databases/partials/layout-table.php', ['d' => $d, 'view' => $view, 'cols' => $cols, 'rows' => $rows, 'total' => $total, 'limit' => $limit, 'may' => $may, 'here' => $here, 'members' => $o['members'] ?? [], 'schema' => $schema]),
    };
}

/**
 * A linked view on a page (a link_to_page block with database_id and view): the view's rows, read-only, through the same layouts. The caller sees only what they may see: a database they
 * cannot see is "a database you cannot see"; a view that was deleted says so.
 */
function linked_view_html(string $databaseId, string $viewId): string
{
    $pdo = db();
    $d = find_database($pdo, $databaseId);
    if ($d === null || $d['archived_at'] !== null) {
        return '<div class="rt-linked-view text-muted fs-12">A database you cannot see' . ($d !== null ? ' (it is in the trash)' : '') . '.</div>';
    }
    $view = null;
    foreach ($d['views'] as $v) {
        if ($v['view_id'] === $viewId) {
            $view = $v;
        }
    }
    if ($view === null) {
        return '<div class="rt-linked-view text-muted fs-12">This view of ' . hx_link('/databases/' . $d['database_id'], e($d['plain_title'] ?: 'the database')) . ' was removed.</div>';
    }
    $rows = database_rows($pdo, $d['database_id'], $view['view_id'], null, null, in_array($view['layout'], ['calendar', 'timeline'], true) ? 500 : 100, 0);
    $total = $rows === [] ? 0 : $rows[0]['total'];
    $here = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $body = render_layout($pdo, $d, $view, $rows, $total, ['edit' => false, 'schema' => false, 'full' => false, 'comment' => false], $here, ['base' => '/databases/' . $d['database_id'] . '?view=' . $view['view_id']]);
    return '<div class="rt-linked-view card my-2" id="linked-view-' . e($view['view_id']) . '" contenteditable="false"><div class="card-header d-flex justify-content-between align-items-center gap-2 py-2"><span class="fw-semibold text-truncate">'
        . e(($d['icon'] ?? '') !== '' ? $d['icon'] . ' ' : '▦ ') . hx_link('/databases/' . $d['database_id'] . '?view=' . $view['view_id'], e($d['plain_title'] ?: 'Untitled'), 'text-dark') . ' <span class="text-muted fw-normal">· ' . e($view['name']) . '</span></span>'
        . '<span class="badge bg-light text-dark">' . e($view['layout']) . '</span></div><div class="card-body p-2">' . $body . '</div><link rel="stylesheet" href="/assets/css/databases.css"></div>';
}
