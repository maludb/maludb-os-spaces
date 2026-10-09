<?php
/**
 * Helpers for the record picker's proofs (docs/spaces-record-picker.md). Builds on slice 4's lib (pages_world, channel_world and the cast of tests/phase2/lib.php:
 * 1 the owner (super-admin), 26 Priya, 27 Marco (space_owner), 28 Bea, 29 Ann (guest), 30 Dana, 31 Lee, 40 Seamus (an agent)). Everything made is named "SMOKE …".
 * Product (closed): Marco owns it, Priya is in; Design leads (private): Marco's alone. Bea is in neither, and in General (open) with everyone.
 */
require dirname(__DIR__) . '/phase3/slice5/lib.php';

/** A GET of the picker endpoint as a signed-in jar (or anonymous with ''): [status, body]. */
function pick(string $jar, string $source, array $q = []): array
{
    $r = req('GET', '/pick/?' . http_build_query(['source' => $source] + $q), ['jar' => $jar === '' ? null : $jar, 'headers' => ['HX-Request: true']]);
    return [$r['code'], $r['body'], $r];
}
/** The (value, label) pairs of the rows a fragment renders. */
function rows_of(string $html): array
{
    preg_match_all('/<button type="button" class="list-group-item[^"]*record-picker-row[^"]*" id="record-picker-row-[^"]*" role="option"[^>]* data-value="([^"]*)" data-label="([^"]*)"/', $html, $m, PREG_SET_ORDER);
    return array_map(static fn (array $x): array => [html_entity_decode($x[1]), html_entity_decode($x[2])], $m);
}
function labels_of(string $html): array { return array_column(rows_of($html), 1); }
function values_of(string $html): array { return array_column(rows_of($html), 0); }
/** The hidden input and the button label of a picker field with this id in a page: [value, label] or null. */
function field_of(string $html, string $id): ?array
{
    if (!preg_match('/<input type="hidden" name="([^"]*)" id="' . preg_quote($id, '/') . '" value="([^"]*)" data-picker-value/', $html, $h)) { return null; }
    preg_match('/id="' . preg_quote($id, '/') . '-open".*?<span class="record-picker-label[^"]*">([^<]*)<\/span>/s', $html, $l);
    return [html_entity_decode($h[2]), html_entity_decode($l[1] ?? ''), $h[1]];
}
/** The CSRF token of the shell as seen by a jar. */
function csrf(string $jar): string { return csrf_of(req('GET', '/', ['jar' => $jar])['body']); }

/** The world of the picker proofs: pages_world + channel_world, plus 30 SMOKE spaces and 60 SMOKE pages (for paging). Idempotent. */
function picker_world(): array
{
    $w = channel_world() + databases_world();
    $marco = as_member(27);
    if (space_id('smoke-pick-30') === null) {
        for ($i = 1; $i <= 30; $i++) { act($marco, '/spaces/save.php', ['name' => sprintf('SMOKE Pick %02d', $i), 'kind' => 'open']); }
    }
    if (page_by_title('SMOKE Bulk 60') === null) {
        for ($i = 1; $i <= 60; $i++) { act($marco, '/pages/save.php', ['title' => sprintf('SMOKE Bulk %02d', $i), 'space' => $w['product']]); }
    }
    if (space_id('smoke-pick-elsewhere') === null) {                       // an open space Marco makes, where everyone may edit and Bea does not belong
        [, $b] = act($marco, '/spaces/save.php', ['name' => 'SMOKE Pick Elsewhere', 'kind' => 'open', 'everyone_level' => 'edit']);
        act($marco, '/pages/save.php', ['title' => 'SMOKE Elsewhere page', 'space' => (int) $b['record_id']]);
    }
    if ((int) one("SELECT count(*) FROM members WHERE display_name LIKE 'SMOKE Person %'") === 0) {      // 30 more people, for the member list to page
        for ($i = 1; $i <= 30; $i++) {
            pdo()->prepare("INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES (:id, 'human', :n, :e, 'user', false, 'active', 'write', '{}')")
                ->execute(['id' => 900 + $i, 'n' => sprintf('SMOKE Person %02d', $i), 'e' => sprintf('person%02d@example.invalid', $i)]);
        }
    }
    return $w + ['elsewhere' => space_id('smoke-pick-elsewhere'), 'elsewhere_page' => page_by_title('SMOKE Elsewhere page'), 'tasks' => db_by_title('SMOKE Tasks'), 'epics' => db_by_title('SMOKE Epics')];
}
function picker_browser_world(): array { return picker_world(); }
