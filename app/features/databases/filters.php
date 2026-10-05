<?php
declare(strict_types=1);

/**
 * Databases (slice 5): the filter editor's conditions per type and the form <-> Notion's filter object mapping, and the one place a form
 * field, an agent's JSON value or a CSV cell becomes a value of a property's type (coerce_value). PHP never EVALUATES a filter — the
 * database's sp_row_matches() does; this file only builds and reads the object.
 */

/** The property types whose value the person (or the agent) cannot set: computed or kept elsewhere. A value for one in a POST is ignored, never refused. */
const COMPUTED_PROPERTY_TYPES = ['rollup', 'relation', 'created_time', 'created_by', 'last_edited_time', 'last_edited_by', 'unique_id', 'verification'];

const NO_VALUE_CONDITIONS = ['is_empty', 'is_not_empty'];
const TIME_CONDITIONS = ['past_week', 'past_month', 'past_year', 'this_week', 'next_week', 'next_month', 'next_year'];

const CONDITION_LABELS = [
    'equals' => 'is', 'does_not_equal' => 'is not', 'contains' => 'contains', 'does_not_contain' => 'does not contain', 'starts_with' => 'starts with', 'ends_with' => 'ends with',
    'is_empty' => 'is empty', 'is_not_empty' => 'is not empty', 'greater_than' => '>', 'less_than' => '<', 'greater_than_or_equal_to' => '≥', 'less_than_or_equal_to' => '≤',
    'before' => 'is before', 'after' => 'is after', 'on_or_before' => 'is on or before', 'on_or_after' => 'is on or after',
    'past_week' => 'is in the past week', 'past_month' => 'is in the past month', 'past_year' => 'is in the past year', 'this_week' => 'is this week',
    'next_week' => 'is in the next week', 'next_month' => 'is in the next month', 'next_year' => 'is in the next year',
];

/** The conditions the editor offers for a property type — exactly what sp_row_matches() evaluates. [condition => label] */
function property_conditions(string $type): array
{
    $sets = match ($type) {
        'title', 'rich_text', 'url', 'email', 'phone_number' => ['equals', 'does_not_equal', 'contains', 'does_not_contain', 'starts_with', 'ends_with', 'is_empty', 'is_not_empty'],
        'number', 'unique_id' => ['equals', 'does_not_equal', 'greater_than', 'less_than', 'greater_than_or_equal_to', 'less_than_or_equal_to', 'is_empty', 'is_not_empty'],
        'select', 'status' => ['equals', 'does_not_equal', 'is_empty', 'is_not_empty'],
        'multi_select' => ['contains', 'does_not_contain', 'is_empty', 'is_not_empty'],
        'date', 'created_time', 'last_edited_time' => ['equals', 'before', 'after', 'on_or_before', 'on_or_after', 'past_week', 'past_month', 'past_year', 'this_week', 'next_week', 'next_month', 'next_year', 'is_empty', 'is_not_empty'],
        'checkbox' => ['equals', 'does_not_equal'],
        'people', 'created_by', 'last_edited_by', 'relation', 'files' => ['contains', 'does_not_contain', 'is_empty', 'is_not_empty'],
        'rollup' => ['equals', 'does_not_equal', 'greater_than', 'less_than', 'contains', 'is_empty', 'is_not_empty'],
        default => ['equals', 'does_not_equal', 'is_empty', 'is_not_empty'],
    };
    $out = [];
    foreach ($sets as $c) {
        $out[$c] = CONDITION_LABELS[$c] ?? $c;
    }
    return $out;
}

/** One condition row of the form → Notion's leaf {property, <type>: {<condition>: <value>}}; null for a blank row. */
function filter_leaf(array $row, array $schema): ?array
{
    $key = trim((string) ($row['property'] ?? ''));
    $cond = trim((string) ($row['condition'] ?? ''));
    if ($key === '' && $cond === '') {
        return null;
    }
    if ($key === '' || $cond === '') {
        throw new DomainException('Each filter row needs a property and a condition.');
    }
    $def = $schema[$key] ?? null;
    if ($def === null) {
        throw new DomainException('The filter names a property that is not here: "' . $key . '".');
    }
    $type = (string) $def['type'];
    if (!isset(property_conditions($type)[$cond])) {
        throw new DomainException(($def['name'] ?? $key) . ' cannot be filtered by "' . (CONDITION_LABELS[$cond] ?? $cond) . '".');
    }
    $v = trim((string) ($row['value'] ?? ''));
    if (in_array($cond, NO_VALUE_CONDITIONS, true)) {
        $val = true;
    } elseif (in_array($cond, TIME_CONDITIONS, true)) {
        $val = new stdClass();
    } elseif (in_array($type, ['number', 'unique_id'], true)) {
        if (!is_numeric($v)) {
            throw new DomainException(($def['name'] ?? $key) . ' is compared with a number.');
        }
        $val = $v + 0;
    } elseif ($type === 'checkbox') {
        $val = in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'checked'], true);
    } else {
        $val = $v;
    }
    return ['property' => $key, $type => [$cond => $val]];
}

/**
 * The filter editor's fields → Notion's filter object, or null when the editor is empty. `fj` the join (and|or) of the top level, `f[n]` the rows
 * (property, condition, value), `g[i]` the one nested level: {join, rows[n]}. Throws a DomainException in words.
 */
function filter_from_form(array $post, array $schema): ?array
{
    $items = [];
    foreach ((array) ($post['f'] ?? []) as $row) {
        if (is_array($row) && ($leaf = filter_leaf($row, $schema)) !== null) {
            $items[] = $leaf;
        }
    }
    foreach ((array) ($post['g'] ?? []) as $g) {
        if (!is_array($g)) {
            continue;
        }
        $leaves = [];
        foreach ((array) ($g['rows'] ?? []) as $row) {
            if (is_array($row) && ($leaf = filter_leaf($row, $schema)) !== null) {
                $leaves[] = $leaf;
            }
        }
        if ($leaves !== []) {
            $items[] = count($leaves) === 1 ? $leaves[0] : [(($g['join'] ?? 'and') === 'or' ? 'or' : 'and') => $leaves];
        }
    }
    if ($items === []) {
        return null;
    }
    if (count($items) === 1) {
        return $items[0];
    }
    return [(($post['fj'] ?? 'and') === 'or' ? 'or' : 'and') => $items];
}

/** A leaf of Notion's filter → [property, condition, value-as-text]; null when it is not a leaf the editor shows. */
function filter_leaf_to_row(array $leaf): ?array
{
    if (!isset($leaf['property'])) {
        return null;
    }
    foreach ($leaf as $k => $v) {
        if ($k === 'property' || !is_array($v) || $v === []) {
            continue;
        }
        $cond = (string) array_key_first($v);
        $val = $v[$cond];
        return ['property' => (string) $leaf['property'], 'condition' => $cond, 'value' => is_bool($val) ? (in_array($cond, NO_VALUE_CONDITIONS, true) ? '' : ($val ? 'true' : 'false')) : (is_array($val) ? '' : (string) $val)];
    }
    foreach ($leaf as $k => $v) {                       // {"property": "Done", "checkbox": {...}} with an empty condition object: nothing to show
        if ($k !== 'property') {
            return null;
        }
    }
    return null;
}

/**
 * Notion's filter object → what the editor shows: ['join' => and|or, 'rows' => [...], 'groups' => [['join', 'rows']], 'complex' => bool]. `complex` = it nests deeper
 * than the editor draws (the form then keeps the JSON as it is). Property references are resolved to keys through the schema (a name or an id is accepted).
 */
function filter_to_form(?array $filter, array $schema): array
{
    $out = ['join' => 'and', 'rows' => [], 'groups' => [], 'complex' => false];
    if ($filter === null || $filter === []) {
        return $out;
    }
    $resolve = static function (array $row) use ($schema): array {
        $p = $row['property'];
        if (!isset($schema[$p])) {
            foreach ($schema as $k => $d) {
                if (strcasecmp((string) ($d['name'] ?? ''), $p) === 0 || ($d['id'] ?? null) === $p) {
                    $row['property'] = $k;
                    break;
                }
            }
        }
        return $row;
    };
    $join = isset($filter['or']) ? 'or' : (isset($filter['and']) ? 'and' : null);
    $items = $join === null ? [$filter] : (array) $filter[$join];
    $out['join'] = $join ?? 'and';
    foreach ($items as $item) {
        if (!is_array($item)) {
            $out['complex'] = true;
            continue;
        }
        if (isset($item['and']) || isset($item['or'])) {
            $gj = isset($item['or']) ? 'or' : 'and';
            $g = ['join' => $gj, 'rows' => []];
            foreach ((array) $item[$gj] as $leaf) {
                $r = is_array($leaf) ? filter_leaf_to_row($leaf) : null;
                if ($r === null) {
                    $out['complex'] = true;
                } else {
                    $g['rows'][] = $resolve($r);
                }
            }
            $out['groups'][] = $g;
            continue;
        }
        $r = filter_leaf_to_row($item);
        if ($r === null) {
            $out['complex'] = true;
        } else {
            $out['rows'][] = $resolve($r);
        }
    }
    return $out;
}

/** An agent's (or a stored) filter object checked for shape: and/or lists, leaves naming a property of the schema. Returns it as given. */
function validate_filter(?array $filter, array $schema, PDO $pdo): ?array
{
    if ($filter === null || $filter === []) {
        return null;
    }
    $walk = static function (array $f) use (&$walk, $schema, $pdo): void {
        if (isset($f['and']) || isset($f['or'])) {
            $list = $f['and'] ?? $f['or'];
            if (!is_array($list)) {
                throw new DomainException('A filter\'s and/or is a list of conditions.');
            }
            foreach ($list as $sub) {
                if (!is_array($sub)) {
                    throw new DomainException('A filter\'s and/or is a list of conditions.');
                }
                $walk($sub);
            }
            return;
        }
        $name = $f['property'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new DomainException('A filter condition names a property.');
        }
        if (resolve_property_key($pdo, $schema, $name) === null) {
            throw new DomainException('The filter names a property that is not here: "' . $name . '".');
        }
    };
    $walk($filter);
    return $filter;
}

/** A property named by its key, display name or id → its key (the database's own sp_prop_key()); null when there is none. */
function resolve_property_key(PDO $pdo, array $schema, string $name): ?string
{
    if (isset($schema[$name])) {
        return $name;
    }
    $v = one_value($pdo, 'SELECT sp_prop_key(CAST(:s AS jsonb), :n)', ['s' => json_encode($schema), 'n' => $name]);
    return $v === null || $v === false ? null : (string) $v;
}

// ---- values ----------------------------------------------------------------------------------------------------------------------------

/** A date as the form or an agent gives it: "2026-10-01", "2026-10-01 → 2026-10-05", {start, end}, or [start, end] → {start, end}|null. */
function coerce_date(mixed $raw, string $label): ?array
{
    if ($raw === null || $raw === '' || $raw === []) {
        return null;
    }
    $start = null;
    $end = null;
    if (is_array($raw)) {
        $start = $raw['start'] ?? ($raw[0] ?? null);
        $end = $raw['end'] ?? ($raw[1] ?? null);
    } else {
        $parts = preg_split('/\s*(→|\.\.|\bto\b)\s*/u', trim((string) $raw));
        $start = $parts[0] ?? null;
        $end = $parts[1] ?? null;
    }
    $start = trim((string) $start);
    $end = trim((string) $end);
    if ($start === '' && $end === '') {
        return null;
    }
    $ok = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $d) === 1 && strtotime($d) !== false;
    if ($start === '' || !$ok($start)) {
        throw new DomainException($label . ' takes a date like 2026-10-31.');
    }
    if ($end !== '' && !$ok($end)) {
        throw new DomainException($label . '\'s end is a date like 2026-10-31.');
    }
    if ($end !== '' && strtotime($end) < strtotime($start)) {
        throw new DomainException($label . ' ends before it starts.');
    }
    return ['start' => $start, 'end' => $end === '' ? null : $end];
}

/** The options of a select, multi-select or status property: [{name, color}]. */
function property_options(array $def): array
{
    $t = (string) ($def['type'] ?? '');
    return array_values(array_filter((array) ($def[$t]['options'] ?? []), 'is_array'));
}

/** A list of members named by id or by display name (the visible ones) → member ids; a name that is nobody, or two people, is refused in words. */
function resolve_people(PDO $pdo, mixed $raw, string $label): array
{
    $list = is_array($raw) ? $raw : (($raw === null || $raw === '') ? [] : explode(',', (string) $raw));
    $ids = [];
    foreach ($list as $x) {
        if (is_array($x)) {
            $x = $x['id'] ?? ($x['name'] ?? '');
        }
        $x = trim((string) $x);
        if ($x === '') {
            continue;
        }
        if (ctype_digit($x)) {
            $row = $pdo->prepare('SELECT member_id FROM mcp_members WHERE member_id = :m');
            $row->execute(['m' => (int) $x]);
            if ($row->fetchColumn() === false) {
                throw new DomainException($label . ': member ' . $x . ' is not here.');
            }
            $ids[] = (int) $x;
            continue;
        }
        $st = $pdo->prepare('SELECT member_id FROM mcp_members WHERE lower(display_name) = lower(:n)');
        $st->execute(['n' => $x]);
        $hits = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($hits) === 0) {
            $st = $pdo->prepare('SELECT member_id FROM mcp_members WHERE display_name ILIKE :n ORDER BY display_name LIMIT 3');
            $st->execute(['n' => str_replace(['%', '_'], ['\\%', '\\_'], $x) . '%']);
            $hits = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        if (count($hits) === 0) {
            throw new DomainException($label . ': nobody is called "' . $x . '".');
        }
        if (count($hits) > 1) {
            throw new DomainException($label . ': "' . $x . '" could be more than one person — use the id.');
        }
        $ids[] = (int) $hits[0];
    }
    return array_values(array_unique($ids));
}

/**
 * A raw value (a form field, an agent's JSON, a CSV cell) → the value stored in pages.properties for the property's type. null = clear it.
 * A refusal is a DomainException in words naming the property. The computed types are not set here (COMPUTED_PROPERTY_TYPES).
 */
function coerce_value(string $type, mixed $raw, array $def, PDO $pdo): mixed
{
    $label = (string) ($def['name'] ?? 'That property');
    $empty = $raw === null || $raw === '' || $raw === [];
    switch ($type) {
        case 'title':
        case 'rich_text':
            if (is_array($raw) && isset($raw[0]['type'])) {
                return $raw;                                          // already rich text (an agent that read one)
            }
            $s = is_array($raw) ? implode(' ', array_map('strval', $raw)) : (string) $raw;
            if (mb_strlen($s) > ($type === 'title' ? 300 : 20000)) {
                throw new DomainException($label . ' is at most ' . ($type === 'title' ? '300' : '20,000') . ' characters.');
            }
            return json_decode((string) one_value($pdo, 'SELECT sp_rich_text(:t)::text', ['t' => $s]), true);
        case 'number':
            if ($empty) {
                return null;
            }
            if (is_array($raw) || !is_numeric(str_replace([',', ' '], '', (string) $raw))) {
                throw new DomainException($label . ' takes a number.');
            }
            $n = str_replace([',', ' '], '', (string) $raw) + 0;
            return $n;
        case 'checkbox':
            if (is_bool($raw)) {
                return $raw;
            }
            $v = strtolower(trim((string) (is_array($raw) ? end($raw) : $raw)));
            if (in_array($v, ['1', 'true', 'yes', 'on', 'checked'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
            throw new DomainException($label . ' takes yes or no.');
        case 'select':
        case 'status':
            if ($empty) {
                return null;
            }
            $name = trim((string) (is_array($raw) ? ($raw['name'] ?? '') : $raw));
            if ($name === '') {
                return null;
            }
            $opts = property_options($def);
            foreach ($opts as $o) {
                if (strcasecmp((string) ($o['name'] ?? ''), $name) === 0) {
                    return array_filter(['name' => $o['name'], 'color' => $o['color'] ?? null], static fn ($x) => $x !== null);
                }
            }
            throw new DomainException($label . ' has no option "' . $name . '"' . ($opts === [] ? '.' : ' (' . implode(', ', array_map(static fn (array $o): string => (string) ($o['name'] ?? ''), $opts)) . ').'));
        case 'multi_select':
            $list = is_array($raw) ? $raw : ($empty ? [] : explode(',', (string) $raw));
            $out = [];
            $opts = property_options($def);
            foreach ($list as $x) {
                $name = trim((string) (is_array($x) ? ($x['name'] ?? '') : $x));
                if ($name === '') {
                    continue;
                }
                $hit = null;
                foreach ($opts as $o) {
                    if (strcasecmp((string) ($o['name'] ?? ''), $name) === 0) {
                        $hit = $o;
                        break;
                    }
                }
                if ($hit === null) {
                    throw new DomainException($label . ' has no option "' . $name . '"' . ($opts === [] ? '.' : ' (' . implode(', ', array_map(static fn (array $o): string => (string) ($o['name'] ?? ''), $opts)) . ').'));
                }
                $out[$hit['name']] = array_filter(['name' => $hit['name'], 'color' => $hit['color'] ?? null], static fn ($y) => $y !== null);
            }
            return array_values($out);
        case 'date':
            return coerce_date($raw, $label);
        case 'people':
            return resolve_people($pdo, $raw, $label);
        case 'files':
            $list = is_array($raw) ? $raw : ($empty ? [] : explode(',', (string) $raw));
            $ids = [];
            foreach ($list as $x) {
                $x = is_array($x) ? ($x['id'] ?? '') : $x;
                $x = trim((string) $x);
                if ($x === '') {
                    continue;
                }
                if (!ctype_digit($x) || !db_bool($pdo, 'SELECT sp_can_see_attachment(:a)', ['a' => (int) $x])) {
                    throw new DomainException($label . ': file ' . $x . ' is not here.');
                }
                $ids[] = (int) $x;
            }
            return array_values(array_unique($ids));
        case 'url':
            if ($empty) {
                return null;
            }
            $s = trim((string) $raw);
            if (!preg_match('#^(https?://|mailto:|/)\S+$#i', $s) || mb_strlen($s) > 2000) {
                throw new DomainException($label . ' takes a link that starts with http:// or https://.');
            }
            return $s;
        case 'email':
            if ($empty) {
                return null;
            }
            $s = trim((string) $raw);
            if (filter_var($s, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException($label . ' takes an email address.');
            }
            return $s;
        case 'phone_number':
            if ($empty) {
                return null;
            }
            $s = trim((string) $raw);
            if (!preg_match('/^[+()\d][\d\s().\-+x#]{2,29}$/', $s)) {
                throw new DomainException($label . ' takes a phone number.');
            }
            return $s;
        default:
            throw new DomainException($label . ' is computed: it cannot be set.');
    }
}
