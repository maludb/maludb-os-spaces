<?php
/**
 * Helpers for the slice 1 proofs (docs/build-specs/spaces-core.md, "Proof"). Run through tests/phase3/slice1/run.sh: a fresh SCRATCH database, the
 * application on :8401, a fake kernel, a fake MaluDB. Everything a proof makes is named "SMOKE …". Builds on tests/phase2/lib.php.
 * The cast (bin/dev_directory.json): the owner (1, super-admin; manages IT), Priya (26, Design), Marco (27, Design, space_owner), Bea (28, Accounting),
 * Ann (29, external, Guest), Dana (30) and Lee (31, Engineering), Seamus (40, an agent), the Watcher (41, an agent the kernel never vouched for).
 * Seeded: General (open, default), Accounting and IT (closed, the standing departments'; the owner owns IT as its manager). Design and Engineering
 * are not standing departments: no space of their own.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

const JSONH = ['Accept: application/json'];

/** A signed-on jar for a fixture member (cached per proof). */
function as_member(int $m): string
{
    static $jars = [];
    if (isset($jars[$m])) { return $jars[$m]; }
    [$j, $r] = sign_on($m);
    if ($r['code'] !== 302) { fwrite(STDERR, "sign-on of $m failed: {$r['code']}\n"); }
    return $jars[$m] = $j;
}
/** A write over HTTP as a signed-on person, answered as JSON: [status, decoded body, raw]. The CSRF token is fetched from the shell once per jar. */
function act(string $jar, string $path, array $form, array $extraHeaders = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => array_merge(JSONH, $extraHeaders), 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
/** A screen as JSON (data only): [status, data, raw]. */
function screen(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => JSONH]); return [$r['code'], json_decode($r['body'], true)['data'] ?? [], $r]; }
function msg(array $body): string { return (string) ($body['error']['message'] ?? ''); }
function fields(array $body): array { return (array) ($body['error']['fields'] ?? []); }
/** A write under an action token (no session, no CSRF): [status, body]. */
function act_token(string $path, array $form, array $headers): array { $r = req('POST', $path, ['headers' => array_merge(JSONH, $headers), 'form' => $form]); return [(int) $r['code'], json_decode($r['body'], true) ?? [], $r]; }
/** The space with a slug, or null (names repeat — smoke-product-2 —, slugs never). */
function space_id(string $slug): ?int { $v = one('SELECT id FROM spaces WHERE slug = :s', ['s' => $slug]); return $v === false || $v === null ? null : (int) $v; }
function general_id(): int { return (int) one('SELECT id FROM spaces WHERE is_default'); }
/** A root page by SQL (slice 2 brings the screens): in a space, titled, optionally in a section. */
function mkpage(int $space, string $title, ?int $section = null, int $by = 27): string
{
    return (string) one("INSERT INTO pages (space_id, title, section_id, created_by, last_edited_by, owner_member_id) VALUES (:s, sp_rich_text(:t), :sec, :by, :by, :by) RETURNING id::text", ['s' => $space, 't' => $title, 'sec' => $section, 'by' => $by]);
}
/**
 * The world of the spec: Marco makes "SMOKE Product" (closed) and "SMOKE Design leads" (private) through the handlers; Priya is a member of Product.
 * Idempotent: finds them when they exist.
 */
function spaces_world(): array
{
    $marco = as_member(27);
    $product = space_id('smoke-product');
    if ($product === null) {
        [, $b] = act($marco, '/spaces/save.php', ['name' => 'SMOKE Product', 'kind' => 'closed', 'icon' => '🚀', 'description' => "What we build.\nSecond line."]);
        $product = (int) $b['record_id'];
        act($marco, '/spaces/members/add.php', ['space' => $product, 'member' => 26]);
    }
    $leads = space_id('smoke-design-leads');
    if ($leads === null) {
        [, $b] = act($marco, '/spaces/save.php', ['name' => 'SMOKE Design leads', 'kind' => 'private']);
        $leads = (int) $b['record_id'];
    }
    return ['product' => $product, 'leads' => $leads, 'general' => general_id(), 'it' => space_id('it'), 'accounting' => space_id('accounting')];
}
