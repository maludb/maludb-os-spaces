<?php
declare(strict_types=1);

/**
 * The prelude of a database handler (slice 5): the feature's files, the database, the row or the view a request names (through the mcp_* views — 404 when
 * unseen; the permission tree decides, never this code), and the one logger stamping entity_uuid and space_id on every row.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/pages/queries.php';
require_once __DIR__ . '/filters.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/render.php';

/** The database a request names (`database`, `id`), 404 when unseen; $live refuses one in the trash in words. */
function database_from_request(PDO $pdo, bool $live = true): array
{
    $id = (string) (req_val('database') ?? ($_GET['database'] ?? ($_GET['id'] ?? '')));
    if (!is_uuid($id)) {
        refuse(422, 'Say which database.');
    }
    $d = find_database($pdo, $id) ?? refuse(404, 'Database not found.');
    if ($live && $d['archived_at'] !== null) {
        refuse(422, 'Database "' . $d['plain_title'] . '" is in the trash: restore it first.');
    }
    return $d;
}

/** The row a request names (`row`), 404 when unseen or not a row. */
function row_from_request(PDO $pdo, bool $live = true): array
{
    $id = (string) (req_val('row') ?? ($_GET['row'] ?? ''));
    if (!is_uuid($id)) {
        refuse(422, 'Say which row.');
    }
    $r = find_row($pdo, $id) ?? refuse(404, 'Row not found.');
    if ($live && $r['archived_at'] !== null) {
        refuse(422, 'Row "' . $r['plain_title'] . '" is in the trash: restore it first.');
    }
    return $r;
}

/** The view a request names (`view`), of the database, or the first when none is named. 404 when it is not the database's. */
function view_for(array $d, ?string $viewId): ?array
{
    $own = array_values(array_filter($d['views'], static fn (array $v): bool => $v['linked_from_page_id'] === null));
    if ($viewId !== null && $viewId !== '') {
        foreach ($d['views'] as $v) {
            if ($v['view_id'] === $viewId) {
                return $v;
            }
        }
        return null;
    }
    return $own[0] ?? ($d['views'][0] ?? null);
}

/** A row of the trail about a database, a row or a view: entity_uuid and space_id on every one. Never a row's text or a filter's values. */
function database_log(PDO $pdo, string $action, string $entityType, string $uuid, ?int $spaceId, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $uuid, ['space_id' => $spaceId] + $opts);
}

/** The notice banners a database screen lands with. */
function database_notices(array $d): array
{
    $t = $d['plain_title'] !== '' ? $d['plain_title'] : 'the database';
    return ['created' => ['success', 'Made ' . $t . '.'], 'saved' => ['success', 'Saved ' . $t . '.'], 'row' => ['success', 'Added the row.'], 'rowsaved' => ['success', 'Saved the row.'],
            'rowdeleted' => ['warning', 'The row is in the trash.'], 'property' => ['success', 'Saved the property.'], 'removed' => ['warning', 'Removed the property.'],
            'schema' => ['success', 'Saved the schema.'], 'view' => ['success', 'Saved the view.'], 'viewdeleted' => ['warning', 'Deleted the view.'], 'reordered' => ['success', 'Moved the view.'],
            'related' => ['success', 'Saved the links.'], 'uploaded' => ['success', 'Attached the file.']];
}

/**
 * The property values a request carries, keyed as given (a key, a display name or an id): the form's `p[key]` fields and an agent's `properties` (a JSON object
 * or nested fields). `p` wins over `properties`. Returns [raw values, errors].
 */
function row_values_from_request(): array
{
    $raw = [];
    $errors = [];
    if (array_key_exists('properties', $_POST)) {
        $v = $_POST['properties'];
        if (is_string($v)) {
            $v = trim($v) === '' ? [] : json_decode($v, true);
            if (!is_array($v)) {
                $errors['properties'] = 'The values are a JSON object keyed by property name.';
                $v = [];
            }
        }
        if (is_array($v) && array_is_list($v) && $v !== []) {
            $errors['properties'] = 'The values are a JSON object keyed by property name.';
            $v = [];
        }
        $raw = (array) $v;
    }
    if (isset($_POST['p']) && is_array($_POST['p'])) {
        foreach ($_POST['p'] as $k => $v) {
            $raw[(string) $k] = $v;
        }
    }
    return [$raw, $errors];
}

/** The row's own template: a page of this database marked as a template. null when none is asked for; refused in words when it is not one. */
function row_template_from_request(PDO $pdo, string $databaseId): ?string
{
    $t = req_has('template') ? (string) req_val('template') : '';
    if ($t === '') {
        return null;
    }
    if (!is_uuid($t) || !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_pages WHERE page_id = CAST(:t AS uuid) AND parent_database_id = CAST(:d AS uuid) AND is_template)', ['t' => $t, 'd' => $databaseId])) {
        sp_refuse_fields(['template' => 'That row template is not here.']);
    }
    return $t;
}

/** The row's properties panel as HTML (the row page, and what a panel edit answers with). */
function render_row_panel(PDO $pdo, array $row, string $here): string
{
    $d = $row['database'] ?? find_database($pdo, (string) $row['parent_database_id']);
    return view('databases/partials/properties-panel.php', ['d' => $d, 'schema' => $d['properties'], 'values' => $row['resolved'], 'row' => $row, 'may' => PAGE_LEVELS[$row['my_level']] >= 3 && $row['archived_at'] === null,
        'here' => $here, 'members' => member_choices($pdo), 'mode' => 'panel']);
}
