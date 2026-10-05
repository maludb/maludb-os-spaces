<?php
declare(strict_types=1);

/** The JSON shapes of a database, its schema, a view and a row — whitelists over the views' rows — and the one function that shows a property value as text. */

/** A property value as plain text (a cell in a list, a card, a tooltip). $v is the RESOLVED value (sp_row_resolved()). */
function display_value(string $type, mixed $v): string
{
    if ($v === null || $v === [] || $v === '') {
        return '';
    }
    switch ($type) {
        case 'title':
        case 'rich_text':
            return is_array($v) ? trim(implode('', array_map(static fn ($r): string => (string) ($r['plain_text'] ?? ($r['text']['content'] ?? '')), $v))) : (string) $v;
        case 'number':
            return is_numeric($v) ? (string) ($v + 0) : (string) $v;
        case 'checkbox':
            return $v ? 'Yes' : 'No';
        case 'select':
        case 'status':
            return is_array($v) ? (string) ($v['name'] ?? '') : (string) $v;
        case 'multi_select':
            return implode(', ', array_map(static fn ($o): string => is_array($o) ? (string) ($o['name'] ?? '') : (string) $o, (array) $v));
        case 'date':
            return is_array($v) ? trim((string) ($v['start'] ?? '') . (($v['end'] ?? null) !== null && $v['end'] !== '' ? ' → ' . $v['end'] : '')) : (string) $v;
        case 'people':
            return implode(', ', array_map(static fn ($p): string => (string) ($p['name'] ?? ''), (array) $v));
        case 'created_by':
        case 'last_edited_by':
            return is_array($v) ? (string) ($v['name'] ?? '') : (string) $v;
        case 'files':
            return implode(', ', array_map(static fn ($f): string => (string) ($f['filename'] ?? ''), (array) $v));
        case 'relation':
            return implode(', ', array_map(static fn ($r): string => (string) ($r['title'] ?? ''), (array) $v));
        case 'rollup':
            return is_array($v) ? (string) ($v['display'] ?? '') : (string) $v;
        case 'unique_id':
            return is_array($v) ? (($v['prefix'] ?? '') !== '' ? $v['prefix'] . '-' : '') . ($v['number'] ?? '') : (string) $v;
        case 'created_time':
        case 'last_edited_time':
            return substr((string) $v, 0, 16);
        case 'verification':
            return is_array($v) ? (string) ($v['state'] ?? '') : (string) $v;
        default:
            return is_array($v) ? json_encode($v) : (string) $v;
    }
}

/** The schema as a list for the screens and the tools: [{key, name, type, options[], relation, rollup, prefix, format, computed}]. */
function present_schema(array $schema): array
{
    $out = [];
    foreach ($schema as $key => $def) {
        $type = (string) $def['type'];
        $out[] = ['key' => (string) $key, 'name' => (string) ($def['name'] ?? $key), 'type' => $type, 'options' => in_array($type, ['select', 'multi_select', 'status'], true) ? array_map(static fn (array $o): array => ['name' => (string) ($o['name'] ?? ''), 'color' => $o['color'] ?? null], property_options($def)) : null,
                  'relation' => $type === 'relation' ? ['database_id' => $def['relation']['database_id'] ?? null, 'two_way' => (bool) ($def['relation']['two_way'] ?? false), 'dual_property' => $def['relation']['dual_property'] ?? null] : null,
                  'rollup' => $type === 'rollup' ? ['relation' => $def['rollup']['relation'] ?? null, 'property' => $def['rollup']['property'] ?? null, 'function' => $def['rollup']['function'] ?? null] : null,
                  'prefix' => $type === 'unique_id' ? ($def['unique_id']['prefix'] ?? '') : null, 'format' => $type === 'number' ? ($def['number']['format'] ?? 'number') : null,
                  'computed' => in_array($type, COMPUTED_PROPERTY_TYPES, true)];
    }
    return $out;
}

function present_database(array $d): array
{
    return ['database_id' => $d['database_id'], 'title' => $d['plain_title'], 'icon' => $d['icon'], 'space' => $d['space_id'] === null ? null : ['space_id' => $d['space_id'], 'name' => $d['space_name']],
            'parent_page_id' => $d['parent_page_id'], 'description' => display_value('rich_text', $d['description']), 'is_inline' => $d['is_inline'], 'properties' => present_schema($d['properties']),
            'title_property' => $d['title_property_key'], 'row_count' => $d['row_count'], 'property_count' => $d['property_count'], 'my_level' => $d['my_level'], 'is_locked' => $d['is_locked'],
            'breadcrumb' => $d['breadcrumb'] ?? null, 'last_edited' => ['by' => $d['last_edited_by'], 'name' => $d['editor_name'], 'at' => json_ts($d['last_edited_at'])], 'created_at' => json_ts($d['created_at']),
            'trashed' => $d['archived_at'] !== null];
}

function present_view(array $v): array
{
    return ['view_id' => $v['view_id'], 'database_id' => $v['database_id'], 'name' => $v['name'], 'layout' => $v['layout'], 'filter' => $v['filter'], 'sort' => $v['sort'], 'group_by' => $v['group_by'],
            'sub_group_by' => $v['sub_group_by'], 'visible_properties' => $v['visible_properties'], 'calendar_by' => $v['calendar_by'], 'timeline_start' => $v['timeline_start'], 'timeline_end' => $v['timeline_end'],
            'card_size' => $v['card_size'], 'card_cover' => $v['card_cover'], 'wrap' => $v['wrap'], 'linked_from_page_id' => $v['linked_from_page_id'], 'position' => $v['position']];
}

/** A row from sp_database_rows(): its resolved values and the same as text. */
function present_row(array $r, array $schema): array
{
    $values = [];
    foreach ($schema as $k => $def) {
        $values[$k] = display_value((string) $def['type'], $r['properties'][$k] ?? null);
    }
    return ['row_id' => $r['row_id'], 'title' => $r['title'], 'icon' => $r['icon'], 'properties' => $r['properties'], 'values' => $values, 'group' => $r['group_value'], 'sub_group' => $r['sub_group_value'],
            'last_edited_at' => json_ts($r['last_edited_at']), 'created_at' => json_ts($r['created_at'])];
}
