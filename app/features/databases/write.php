<?php
declare(strict_types=1);

/**
 * Writes to databases, their schema, rows, relations and views (slice 5): the verbs of db/009 (sp_database_create, sp_row_create, sp_row_relation_set,
 * sp_database_property_save/remove), sp_page_trash and the base tables. The database is the referee — a refusal is the database's own sentence
 * (P0001, shown by sp_guard()) or a DomainException here in words where the rule has no SQL home (a lossless retype, a value of the wrong shape).
 * Every function runs inside the handler's transaction.
 */

const OPTION_COLORS = ['default', 'gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];
const NUMBER_FORMATS = ['number', 'number_with_commas', 'percent', 'dollar', 'euro', 'pound', 'yen'];
const TYPE_LABELS = ['title' => 'title', 'rich_text' => 'text', 'number' => 'number', 'select' => 'select', 'multi_select' => 'multi-select', 'status' => 'status', 'date' => 'date', 'people' => 'people',
    'files' => 'files', 'checkbox' => 'checkbox', 'url' => 'URL', 'email' => 'email', 'phone_number' => 'phone', 'relation' => 'relation', 'rollup' => 'rollup', 'created_time' => 'created time',
    'created_by' => 'created by', 'last_edited_time' => 'last edited time', 'last_edited_by' => 'last edited by', 'unique_id' => 'unique ID', 'verification' => 'verification'];

/** A name as a property key: lowercase, letters and digits, _ between. */
function property_slug(string $name): string
{
    $s = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '_'));
    return $s === '' ? 'property' : substr($s, 0, 40);
}

/** A key not yet in the schema (the slug, then _2, _3 …). */
function unique_property_key(string $slug, array $schema): string
{
    $key = $slug;
    $n = 2;
    while (isset($schema[$key])) {
        $key = $slug . '_' . $n++;
    }
    return $key;
}

/** Select options as the form or an agent gives them (lines "Name" / "Name | color", {name, color} objects, strings) → [{name, color?}]. */
function normalize_options(mixed $raw): array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : preg_split('/\R|,/', $raw);
    }
    $out = [];
    foreach ((array) $raw as $o) {
        if (is_string($o)) {
            $j = json_decode($o, true);
            if (is_array($j) && isset($j['name'])) {
                $o = $j;
            } else {
                $parts = array_map('trim', explode('|', $o, 2));
                $o = ['name' => $parts[0], 'color' => $parts[1] ?? null];
            }
        }
        if (!is_array($o)) {
            continue;
        }
        $name = trim((string) ($o['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (mb_strlen($name) > 100) {
            throw new DomainException('An option is at most 100 characters.');
        }
        $color = isset($o['color']) && $o['color'] !== '' ? strtolower((string) $o['color']) : null;
        if ($color !== null && !in_array($color, OPTION_COLORS, true)) {
            throw new DomainException('An option\'s color is one of ' . implode(', ', OPTION_COLORS) . '.');
        }
        $out[strtolower($name)] = array_filter(['name' => $name, 'color' => $color], static fn ($x) => $x !== null);
    }
    return array_values($out);
}

/**
 * A property's definition from what the form or an agent sent: type, options[], relation_database + two_way, rollup_relation + rollup_property + rollup_function,
 * prefix, number_format. $old is the stored def of a property that is being changed (what is not sent stays). The database's guard validates the whole schema after.
 */
function build_property_def(PDO $pdo, array $spec, string $key, string $name, array $schema, ?array $old = null): array
{
    $type = (string) ($spec['type'] ?? ($old['type'] ?? ''));
    if ($type === '') {
        throw new DomainException('Say the property\'s type.');
    }
    $def = ['id' => $old['id'] ?? $key, 'name' => $name, 'type' => $type];
    $def['order'] = $old['order'] ?? ($spec['order'] ?? 1 + max(array_merge([0], array_map(static fn (array $x): int => (int) ($x['order'] ?? 0), $schema))));
    $has = static fn (string $k): bool => array_key_exists($k, $spec) && $spec[$k] !== null;
    switch ($type) {
        case 'select':
        case 'multi_select':
        case 'status':
            $same = $old !== null && ($old['type'] ?? null) === $type;
            $opts = $has('options') ? normalize_options($spec['options']) : ($same ? property_options($old) : []);
            $def[$type] = ['options' => $opts];
            break;
        case 'number':
            $fmt = $has('number_format') ? (string) $spec['number_format'] : (string) ($old['number']['format'] ?? 'number');
            if (!in_array($fmt, NUMBER_FORMATS, true)) {
                throw new DomainException('A number\'s format is one of ' . implode(', ', NUMBER_FORMATS) . '.');
            }
            $def['number'] = ['format' => $fmt];
            break;
        case 'relation':
            if ($old !== null && ($old['type'] ?? null) === 'relation') {
                $def['relation'] = $old['relation'];            // a relation's target and sides are fixed once made
                break;
            }
            $target = (string) ($spec['relation_database'] ?? '');
            if (!is_uuid($target) || find_database($pdo, $target) === null) {
                throw new DomainException('Choose the database this relation points at.');
            }
            $def['relation'] = ['database_id' => $target, 'two_way' => (bool) ($spec['two_way'] ?? false)];
            break;
        case 'rollup':
            $rel = (string) ($spec['rollup_relation'] ?? ($old['rollup']['relation'] ?? ''));
            $prop = (string) ($spec['rollup_property'] ?? ($old['rollup']['property'] ?? ''));
            $fn = (string) ($spec['rollup_function'] ?? ($old['rollup']['function'] ?? 'count'));
            $relKey = resolve_property_key($pdo, $schema, $rel) ?? $rel;                // a non-relation is the database's to refuse, in its words
            $targetDb = $schema[$relKey]['relation']['database_id'] ?? null;
            if ($targetDb !== null && $prop !== '') {
                $ts = database_state($pdo, (string) $targetDb)['properties'] ?? [];
                $pk = resolve_property_key($pdo, $ts, $prop);
                if ($pk === null) {
                    throw new DomainException('The rollup names a property that the related database does not have: "' . $prop . '".');
                }
                $prop = $pk;
            }
            $def['rollup'] = ['relation' => $relKey, 'property' => $prop, 'function' => $fn];
            break;
        case 'unique_id':
            $prefix = $has('prefix') ? trim((string) $spec['prefix']) : (string) ($old['unique_id']['prefix'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{0,12}$/', $prefix)) {
                throw new DomainException('A unique ID\'s prefix is up to 12 letters, digits, - or _.');
            }
            $def['unique_id'] = ['prefix' => $prefix];
            break;
        default:
            break;                                              // the rest carry no settings; an unknown one (formula, button, place) is the database's to refuse
    }
    return $def;
}

/** A schema as the form or an agent gives it, {name: type | {type, …}} or Notion-shaped → the stored {key: def}, with a title property. */
function schema_from_input(PDO $pdo, array $in): array
{
    $schema = [];
    $titleName = null;
    $later = [];
    foreach ($in as $name => $spec) {
        $name = (string) $name;
        if (is_string($spec)) {
            $spec = ['type' => $spec];
        }
        if (!is_array($spec)) {
            throw new DomainException('Property "' . $name . '" needs a type.');
        }
        $spec['name'] ??= $name;
        $display = (string) $spec['name'];
        $spec = $spec + ['options' => $spec[$spec['type'] ?? '']['options'] ?? null, 'number_format' => $spec['number']['format'] ?? null, 'prefix' => $spec['unique_id']['prefix'] ?? null,
            'relation_database' => $spec['relation']['database_id'] ?? null, 'two_way' => $spec['relation']['two_way'] ?? null,
            'rollup_relation' => $spec['rollup']['relation'] ?? null, 'rollup_property' => $spec['rollup']['property'] ?? null, 'rollup_function' => $spec['rollup']['function'] ?? null];
        if (($spec['type'] ?? '') === 'title') {
            if ($titleName !== null) {
                throw new DomainException('A database has exactly one title property (found 2)');
            }
            $titleName = $display;
            $schema['title'] = ['id' => 'title', 'name' => $display, 'type' => 'title', 'order' => 0];
            continue;
        }
        $later[] = [$display, $spec];
    }
    if ($titleName === null) {
        $schema = ['title' => ['id' => 'title', 'name' => 'Name', 'type' => 'title', 'order' => 0]] + $schema;
    }
    foreach ($later as [$display, $spec]) {
        if (in_array($spec['type'] ?? '', ['relation', 'rollup'], true)) {
            continue;                                           // made after the database exists (a relation needs its target; its dual is written by sp_database_property_save)
        }
        $key = unique_property_key(property_slug($display), $schema);
        $schema[$key] = build_property_def($pdo, $spec, $key, $display, $schema);
    }
    return [$schema, array_values(array_filter($later, static fn (array $l): bool => in_array($l[1]['type'] ?? '', ['relation', 'rollup'], true)))];
}

// ---- databases -------------------------------------------------------------------------------------------------------------------------

/** Make a database in a space's root or under a page, from a schema (JSON keyed by name), or by copying a database template. Returns its id. */
function create_database(PDO $pdo, ?int $spaceId, ?string $parentUuid, string $title, ?array $properties, bool $inline, ?string $templateUuid, int $by): string
{
    if ($templateUuid !== null) {
        $st = $pdo->prepare('SELECT sp_page_duplicate(CAST(:t AS uuid), :s, CAST(:p AS uuid), sp_rich_text(:title), false)::text');
        $st->execute(['t' => $templateUuid, 's' => $spaceId, 'p' => $parentUuid, 'title' => $title]);
        $id = (string) $st->fetchColumn();
        if (!db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM databases WHERE id = CAST(:id AS uuid))', ['id' => $id])) {
            throw new DomainException('That template is not a database.');
        }
        if ($inline) {
            $pdo->prepare('UPDATE databases SET is_inline = true WHERE id = CAST(:id AS uuid)')->execute(['id' => $id]);
        }
        return $id;
    }
    [$schema, $later] = schema_from_input($pdo, $properties ?? []);
    $st = $pdo->prepare('SELECT sp_database_create(:s, CAST(:p AS uuid), sp_rich_text(:t), CAST(:props AS jsonb), :i)::text');
    $st->execute(['s' => $spaceId, 'p' => $parentUuid, 't' => $title, 'props' => json_encode($schema), 'i' => $inline ? 't' : 'f']);
    $id = (string) $st->fetchColumn();
    $cur = $schema;
    foreach ($later as [$display, $spec]) {
        $key = unique_property_key(property_slug($display), $cur);
        $def = build_property_def($pdo, $spec, $key, $display, $cur);
        $pdo->prepare('SELECT sp_database_property_save(CAST(:d AS uuid), :k, CAST(:def AS jsonb))')->execute(['d' => $id, 'k' => $key, 'def' => json_encode($def)]);
        $cur = database_state($pdo, $id)['properties'];
    }
    return $id;
}

/** Change a database's title, description or inline flag (a field left out stays). The title is its page's title. */
function update_database(PDO $pdo, string $uuid, array $fields, int $by): void
{
    if (array_key_exists('title', $fields)) {
        $pdo->prepare('UPDATE pages SET title = sp_rich_text(:t) WHERE id = CAST(:id AS uuid)')->execute(['t' => (string) $fields['title'], 'id' => $uuid]);
    }
    $sets = [];
    $args = ['id' => $uuid];
    if (array_key_exists('description', $fields)) {
        $sets[] = 'description = sp_rich_text(:d)';
        $args['d'] = (string) $fields['description'];
    }
    if (array_key_exists('inline', $fields)) {
        $sets[] = 'is_inline = :i';
        $args['i'] = $fields['inline'] ? 't' : 'f';
    }
    if ($sets !== []) {
        $pdo->prepare('UPDATE databases SET ' . implode(', ', $sets) . ' WHERE id = CAST(:id AS uuid)')->execute($args);
    }
}

/** Replace the whole schema (the guard validates it). Returns [added[], removed[], retyped[]] keys. */
function save_schema(PDO $pdo, string $uuid, array $properties, int $by): array
{
    $before = database_state($pdo, $uuid)['properties'] ?? [];
    $new = [];
    foreach ($properties as $key => $def) {
        if (!is_array($def)) {
            throw new DomainException('Property "' . $key . '" needs a definition.');
        }
        $def['id'] ??= (string) $key;
        $def['name'] ??= (string) $key;
        $new[(string) $key] = $def;
    }
    $pdo->prepare('UPDATE databases SET properties = CAST(:p AS jsonb) WHERE id = CAST(:id AS uuid)')->execute(['p' => json_encode((object) $new, JSON_UNESCAPED_UNICODE), 'id' => $uuid]);
    $after = database_state($pdo, $uuid)['properties'];
    $retyped = [];
    foreach ($after as $k => $d) {
        if (isset($before[$k]) && ($before[$k]['type'] ?? null) !== ($d['type'] ?? null)) {
            $retyped[] = $k;
        }
    }
    return ['added' => array_values(array_diff(array_keys($after), array_keys($before))), 'removed' => array_values(array_diff(array_keys($before), array_keys($after))), 'retyped' => $retyped];
}

/** What a retype may do. Returns null when allowed, else the sentence refusing it. */
function retype_refusal(string $name, string $from, string $to): ?string
{
    if ($from === $to) {
        return null;
    }
    $lf = TYPE_LABELS[$from] ?? $from;
    $lt = TYPE_LABELS[$to] ?? $to;
    if ($from === 'title' || $to === 'title') {
        return 'The title is the title: ' . $name . ' cannot become ' . ($to === 'title' ? 'the title' : 'a ' . $lt) . '.';
    }
    if (in_array($from, ['relation', 'rollup', 'unique_id', 'people', 'files', 'date', 'created_time', 'created_by', 'last_edited_time', 'last_edited_by', 'verification'], true)) {
        return $name . ' is ' . (in_array($lf[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ') . $lf . '; make a new property instead.';
    }
    $allowed = ['number' => ['rich_text'], 'select' => ['multi_select', 'rich_text'], 'multi_select' => ['rich_text'], 'status' => ['rich_text'], 'url' => ['rich_text'], 'email' => ['rich_text'],
                'phone_number' => ['rich_text'], 'checkbox' => ['select', 'rich_text'], 'rich_text' => []];
    if (!in_array($to, $allowed[$from] ?? [], true)) {
        return $name . ' is ' . (in_array($lf[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ') . $lf . ' and would lose its values as ' . (in_array($lt[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ') . $lt . '; make a new property instead.';
    }
    return null;
}

/** One row's value, retyped (the value becomes what the new type holds). */
function retype_value(mixed $v, string $from, string $to): mixed
{
    if ($v === null) {
        return null;
    }
    $text = static function (mixed $x) use ($from): string {
        return match ($from) {
            'number' => (string) $x,
            'select', 'status' => is_array($x) ? (string) ($x['name'] ?? '') : (string) $x,
            'multi_select' => implode(', ', array_map(static fn ($o): string => is_array($o) ? (string) ($o['name'] ?? '') : (string) $o, (array) $x)),
            'checkbox' => $x ? 'Yes' : 'No',
            default => is_array($x) ? '' : (string) $x,
        };
    };
    return match (true) {
        $to === 'rich_text' => ['__text' => $text($v)],
        $from === 'select' && $to === 'multi_select' => is_array($v) ? [$v] : [['name' => (string) $v]],
        $from === 'checkbox' && $to === 'select' => ['name' => $v ? 'Yes' : 'No'],
        default => $v,
    };
}

/**
 * Add or change one property: $keyOrName an existing key, name or id (change) or a new name (add). $spec: type, name, options[], relation_database, two_way,
 * rollup_relation/property/function, prefix, number_format. A retype is lossless only (retype_refusal) and every row's value is converted in the same
 * transaction. Returns ['key', 'def', 'is_new', 'retyped' => rows converted, 'renamed' => bool, 'from_type'].
 */
function save_property(PDO $pdo, string $uuid, string $keyOrName, array $spec, int $by): array
{
    $schema = database_state($pdo, $uuid)['properties'] ?? throw new DomainException('Not found.');
    $key = resolve_property_key($pdo, $schema, $keyOrName);
    $isNew = $key === null;
    $retyped = 0;
    $fromType = null;
    if ($isNew) {
        if (trim($keyOrName) === '') {
            throw new DomainException('Name the property.');
        }
        $name = trim((string) ($spec['name'] ?? $keyOrName));
        $key = unique_property_key(property_slug($name), $schema);
        $def = build_property_def($pdo, $spec, $key, $name, $schema);
    } else {
        $old = $schema[$key];
        $fromType = (string) $old['type'];
        $name = trim((string) ($spec['name'] ?? ($old['name'] ?? $key)));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('A property\'s name is 1 to 100 characters.');
        }
        $to = (string) ($spec['type'] ?? $fromType);
        if (($why = retype_refusal($name, $fromType, $to)) !== null) {
            throw new DomainException($why);
        }
        $def = build_property_def($pdo, ['type' => $to] + $spec, $key, $name, $schema, $old);
        if ($fromType === 'checkbox' && $to === 'select' && !isset($spec['options'])) {
            $def['select'] = ['options' => [['name' => 'Yes', 'color' => 'green'], ['name' => 'No', 'color' => 'gray']]];
        }
        if ($fromType === 'select' && $to === 'multi_select' && !isset($spec['options'])) {
            $def['multi_select'] = ['options' => property_options($old)];
        }
    }
    $pdo->prepare('SELECT sp_database_property_save(CAST(:d AS uuid), :k, CAST(:def AS jsonb))')->execute(['d' => $uuid, 'k' => $key, 'def' => json_encode($def, JSON_UNESCAPED_UNICODE)]);
    if (!$isNew && $fromType !== $def['type']) {
        // the schema now says the new type; every row's value follows (the guard checks the shape)
        $st = $pdo->prepare('SELECT id::text AS id, properties -> :k AS v FROM pages WHERE parent_database_id = CAST(:d AS uuid) AND jsonb_exists(properties, :k)');
        $st->execute(['d' => $uuid, 'k' => $key]);
        foreach ($st->fetchAll() as $r) {
            $v = json_decode((string) $r['v'], true);
            $nv = retype_value($v, $fromType, (string) $def['type']);
            if (is_array($nv) && array_key_exists('__text', $nv)) {
                $pdo->prepare('UPDATE pages SET properties = properties || jsonb_build_object(CAST(:k AS text), sp_rich_text(:t)) WHERE id = CAST(:id AS uuid)')->execute(['k' => $key, 't' => $nv['__text'], 'id' => $r['id']]);
            } else {
                $pdo->prepare('UPDATE pages SET properties = properties || jsonb_build_object(CAST(:k AS text), CAST(:v AS jsonb)) WHERE id = CAST(:id AS uuid)')->execute(['k' => $key, 'v' => json_encode($nv), 'id' => $r['id']]);
            }
            $retyped++;
        }
    }
    $after = database_state($pdo, $uuid)['properties'];
    return ['key' => $key, 'def' => $after[$key] ?? $def, 'is_new' => $isNew, 'retyped' => $retyped, 'from_type' => $fromType, 'renamed' => !$isNew && ($schema[$key]['name'] ?? $key) !== $name];
}

/** Remove a property from the schema; the rows keep their values until $purge. The database refuses the title and a relation a rollup goes through. */
function remove_property(PDO $pdo, string $uuid, string $keyOrName, bool $purge, int $by): string
{
    $schema = database_state($pdo, $uuid)['properties'] ?? throw new DomainException('Not found.');
    $key = resolve_property_key($pdo, $schema, $keyOrName) ?? throw new DomainException('No property is called "' . $keyOrName . '".');
    $pdo->prepare('SELECT sp_database_property_remove(CAST(:d AS uuid), :k, :p)')->execute(['d' => $uuid, 'k' => $key, 'p' => $purge ? 't' : 'f']);
    return $key;
}

/** To the trash, with its rows. Returns the rows' count. */
function delete_database(PDO $pdo, string $uuid, int $by): int
{
    $n = (int) one_value($pdo, 'SELECT count(*) FROM pages WHERE parent_database_id = CAST(:id AS uuid) AND archived_at IS NULL', ['id' => $uuid]);
    $pdo->prepare('SELECT sp_page_trash(CAST(:id AS uuid))')->execute(['id' => $uuid]);
    return $n;
}

// ---- rows ------------------------------------------------------------------------------------------------------------------------------

/**
 * A request's property values → [values keyed by property key, field errors]. $raw is keyed by key, display name or id (the form's p[key], an agent's `properties`);
 * `$titleRaw` the title as a plain string. A computed property in $raw is ignored. Each refusal is a sentence, filed under the field's name.
 */
function coerce_row_values(PDO $pdo, array $schema, string $titleKey, array $raw, ?string $titleRaw): array
{
    $values = [];
    $errors = [];
    if ($titleRaw !== null) {
        $raw[$titleKey] = $titleRaw;
    }
    foreach ($raw as $name => $value) {
        $key = resolve_property_key($pdo, $schema, (string) $name);
        if ($key === null) {
            $errors['p[' . $name . ']'] = 'No property is called "' . $name . '".';
            continue;
        }
        $def = $schema[$key];
        $type = (string) $def['type'];
        if (in_array($type, COMPUTED_PROPERTY_TYPES, true)) {
            continue;
        }
        try {
            $v = coerce_value($type, $value, $def, $pdo);
        } catch (DomainException $e) {
            $errors['p[' . $key . ']'] = $e->getMessage();
            continue;
        }
        if ($type === 'title' && ($v === [] || $v === null)) {
            $errors['p[' . $key . ']'] = 'Give the row a title.';
            continue;
        }
        $values[$key] = $v;
    }
    return [$values, $errors];
}

/** A row in a database from coerced values (the title property included; the database numbers a unique id); optionally with a row template's blocks. Returns its id. */
function create_row(PDO $pdo, string $databaseUuid, array $properties, ?string $templateUuid, int $by): string
{
    $st = $pdo->prepare('SELECT sp_row_create(CAST(:d AS uuid), CAST(:p AS jsonb), CAST(:t AS uuid))::text');
    $st->execute(['d' => $databaseUuid, 'p' => json_encode($properties === [] ? new stdClass() : $properties, JSON_UNESCAPED_UNICODE), 't' => $templateUuid]);
    return (string) $st->fetchColumn();
}

/** Merge coerced values into a row's properties. Returns ['changed' => keys whose value differs, 'added_people' => [key => member ids newly named]]. */
function update_row(PDO $pdo, string $rowUuid, array $properties, int $by): array
{
    $before = row_stored($pdo, $rowUuid);
    if ($properties === []) {
        return ['changed' => [], 'added_people' => []];
    }
    $pdo->prepare('UPDATE pages SET properties = properties || CAST(:p AS jsonb) WHERE id = CAST(:id AS uuid)')->execute(['p' => json_encode($properties, JSON_UNESCAPED_UNICODE), 'id' => $rowUuid]);
    $after = row_stored($pdo, $rowUuid);
    $changed = [];
    $added = [];
    foreach (array_keys($properties) as $k) {
        if (($before[$k] ?? null) !== ($after[$k] ?? null)) {
            $changed[] = $k;
            $was = array_map(static fn ($x) => (int) (is_array($x) ? ($x['id'] ?? 0) : $x), (array) ($before[$k] ?? []));
            $now = array_map(static fn ($x) => (int) (is_array($x) ? ($x['id'] ?? 0) : $x), (array) ($after[$k] ?? []));
            if (is_array($after[$k] ?? null) && $now !== [] && array_is_list($after[$k])) {
                $new = array_values(array_diff($now, $was));
                if ($new !== []) {
                    $added[$k] = $new;
                }
            }
        }
    }
    return ['changed' => $changed, 'added_people' => $added];
}

/** Tell the members a people property newly names ("Marco assigned you Build the board in Tasks"): a bell row of kind mention. */
function notify_assigned(PDO $pdo, string $rowUuid, string $rowTitle, string $databaseTitle, string $actor, array $schema, array $addedByKey): int
{
    $n = 0;
    foreach ($addedByKey as $key => $ids) {
        if (($schema[$key]['type'] ?? '') !== 'people') {
            continue;
        }
        foreach ($ids as $mid) {
            $pdo->prepare("SELECT sp_notify(:m, 'mention', :t, NULL, 'page', NULL, CAST(:r AS uuid), NULL, NULL, NULL)")
                ->execute(['m' => $mid, 't' => $actor . ' assigned you ' . ($rowTitle !== '' ? $rowTitle : 'a row') . ' in ' . ($databaseTitle !== '' ? $databaseTitle : 'a database'), 'r' => $rowUuid]);
            $n++;
        }
    }
    return $n;
}

function delete_row(PDO $pdo, string $rowUuid, int $by): void
{
    $pdo->prepare('SELECT sp_page_trash(CAST(:id AS uuid))')->execute(['id' => $rowUuid]);
}

/** The whole list of a relation property's targets for a row. A target the caller cannot see is "not here"; one of another database is the guard's to refuse. */
function set_row_relation(PDO $pdo, string $rowUuid, string $key, array $targets, int $by): void
{
    $ids = [];
    foreach ($targets as $t) {
        $t = trim((string) $t);
        if ($t === '') {
            continue;
        }
        if (!is_uuid($t) || !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_pages WHERE page_id = CAST(:t AS uuid) AND archived_at IS NULL)', ['t' => $t])) {
            throw new DomainException('A row you are linking is not here.');
        }
        $ids[$t] = $t;
    }
    $pdo->prepare('SELECT sp_row_relation_set(CAST(:r AS uuid), :k, CAST(:t AS uuid[]))')->execute(['r' => $rowUuid, 'k' => $key, 't' => '{' . implode(',', $ids) . '}']);
}

// ---- views -----------------------------------------------------------------------------------------------------------------------------

/** The words for a view's CHECK, by constraint name. */
function view_check_words(PDOException $e): ?string
{
    $m = $e->getMessage();
    return match (true) {
        str_contains($m, 'database_views_check2') => 'A board needs a property to group by.',
        str_contains($m, 'database_views_check1') => 'A timeline needs a start date property.',
        str_contains($m, 'database_views_check') => 'A calendar needs a date property to place its rows by.',
        str_contains($m, 'database_views_layout_check') => 'A view\'s layout is table, board, gallery, list, calendar or timeline.',
        str_contains($m, 'database_views_name_check') => 'A view\'s name is 1 to 80 characters.',
        str_contains($m, 'database_views_card_size_check') => 'A card\'s size is small, medium or large.',
        default => null,
    };
}

/**
 * Save a view: $fields name, layout, filter, sort, group_by, sub_group_by, visible_properties, calendar_by, timeline_start, timeline_end, card_size, card_cover, wrap,
 * linked_from — a field left out stays. $viewUuid null makes one. A linked view also puts a pointer block on its page (a link_to_page with database_id and view) once.
 */
function save_view(PDO $pdo, string $databaseUuid, ?string $viewUuid, array $fields, int $by): string
{
    $cols = ['name' => 'name', 'layout' => 'layout', 'group_by' => 'group_by', 'sub_group_by' => 'sub_group_by', 'calendar_by' => 'calendar_by', 'timeline_start' => 'timeline_start',
             'timeline_end' => 'timeline_end', 'card_size' => 'card_size', 'card_cover' => 'card_cover'];
    $sets = [];
    $args = [];
    foreach ($cols as $f => $c) {
        if (array_key_exists($f, $fields)) {
            $sets[$c] = ':' . $c;
            $args[$c] = $fields[$f] === '' ? null : $fields[$f];
        }
    }
    if (array_key_exists('filter', $fields)) {
        $sets['filter'] = 'CAST(:filter AS jsonb)';
        $args['filter'] = $fields['filter'] === null ? null : json_encode($fields['filter'], JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('sort', $fields)) {
        $sets['sort'] = 'CAST(:sort AS jsonb)';
        $args['sort'] = json_encode($fields['sort'] ?? [], JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('visible_properties', $fields)) {
        $sets['visible_properties'] = 'CAST(:vp AS text[])';
        $args['vp'] = $fields['visible_properties'] === null ? null : pg_array_literal(array_values($fields['visible_properties']));
    }
    if (array_key_exists('wrap', $fields)) {
        $sets['wrap'] = ':wrap';
        $args['wrap'] = $fields['wrap'] ? 't' : 'f';
    }
    if (array_key_exists('linked_from', $fields)) {
        $sets['linked_from_page_id'] = 'CAST(:lf AS uuid)';
        $args['lf'] = $fields['linked_from'] === '' ? null : $fields['linked_from'];
    }
    try {
        if ($viewUuid === null) {
            $args['d'] = $databaseUuid;
            $args['by'] = $by;
            $names = array_keys($sets);
            $st = $pdo->prepare('INSERT INTO database_views (database_id, created_by, position' . ($names === [] ? '' : ', ' . implode(', ', $names)) . ')
                                 VALUES (CAST(:d AS uuid), :by, sp_position_between((SELECT max(position) FROM database_views WHERE database_id = CAST(:d AS uuid)), NULL)' . ($names === [] ? '' : ', ' . implode(', ', $sets)) . ') RETURNING id::text');
            $st->execute($args);
            $viewUuid = (string) $st->fetchColumn();
        } elseif ($sets !== []) {
            $upd = [];
            foreach ($sets as $c => $ph) {
                $upd[] = $c . ' = ' . $ph;
            }
            $args['id'] = $viewUuid;
            $pdo->prepare('UPDATE database_views SET ' . implode(', ', $upd) . ' WHERE id = CAST(:id AS uuid)')->execute($args);
        }
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23514' && ($w = view_check_words($e)) !== null) {
            throw new DomainException($w);
        }
        throw $e;
    }
    $linked = (string) one_value($pdo, 'SELECT COALESCE(linked_from_page_id::text, \'\') FROM database_views WHERE id = CAST(:v AS uuid)', ['v' => $viewUuid]);
    if ($linked !== '' && !db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM blocks WHERE page_id = CAST(:p AS uuid) AND type = 'link_to_page' AND content->>'view' = :v)", ['p' => $linked, 'v' => $viewUuid])) {
        $pdo->prepare("SELECT sp_block_insert(CAST(:p AS uuid), NULL, NULL, 'link_to_page', jsonb_build_object('database_id', CAST(:d AS text), 'view', CAST(:v AS text)))")
            ->execute(['p' => $linked, 'd' => $databaseUuid, 'v' => $viewUuid]);
    }
    return $viewUuid;
}

/** Delete a view. A database keeps one view of its own (a linked view may always go). */
function delete_view(PDO $pdo, string $viewUuid, int $by): void
{
    $v = view_state($pdo, $viewUuid) ?? throw new DomainException('Not found.');
    if ($v['linked_from_page_id'] === null && (int) one_value($pdo, 'SELECT count(*) FROM database_views WHERE database_id = CAST(:d AS uuid) AND linked_from_page_id IS NULL', ['d' => $v['database_id']]) <= 1) {
        throw new DomainException('A database keeps one view: make another before deleting this one.');
    }
    $pdo->prepare('DELETE FROM database_views WHERE id = CAST(:id AS uuid)')->execute(['id' => $viewUuid]);
}

/** Put a view right after another (empty = first) among the database's own views. */
function reorder_view(PDO $pdo, string $viewUuid, ?string $afterUuid, int $by): void
{
    $v = view_state($pdo, $viewUuid) ?? throw new DomainException('Not found.');
    $d = $v['database_id'];
    if ($afterUuid === null) {
        $next = $pdo->prepare('SELECT position FROM database_views WHERE database_id = CAST(:d AS uuid) AND linked_from_page_id IS NULL AND id <> CAST(:v AS uuid) ORDER BY position LIMIT 1');
        $next->execute(['d' => $d, 'v' => $viewUuid]);
        $n = $next->fetchColumn();
        $pdo->prepare('UPDATE database_views SET position = sp_position_between(NULL, :n) WHERE id = CAST(:v AS uuid)')->execute(['n' => $n === false ? null : $n, 'v' => $viewUuid]);
        return;
    }
    $a = view_state($pdo, $afterUuid);
    if ($a === null || $a['database_id'] !== $d) {
        throw new DomainException('The view to follow is not one of this database\'s.');
    }
    $next = $pdo->prepare('SELECT position FROM database_views WHERE database_id = CAST(:d AS uuid) AND linked_from_page_id IS NULL AND id <> CAST(:v AS uuid) AND position > :p ORDER BY position LIMIT 1');
    $next->execute(['d' => $d, 'v' => $viewUuid, 'p' => $a['position']]);
    $n = $next->fetchColumn();
    $pdo->prepare('UPDATE database_views SET position = sp_position_between(:a, :n) WHERE id = CAST(:v AS uuid)')->execute(['a' => $a['position'], 'n' => $n === false ? null : $n, 'v' => $viewUuid]);
}
