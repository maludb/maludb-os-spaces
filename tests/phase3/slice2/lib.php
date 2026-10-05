<?php
/**
 * Helpers for the slice 2 proofs (docs/build-specs/pages-tree.md, "Proof"). Builds on slice 1's lib (its world: Product, closed, Marco owner, Priya member;
 * Design leads, private). pages_world() adds: Product holds "SMOKE Product handbook" › "SMOKE Pricing" › "SMOKE Pricing 2027"; Bea keeps a private page
 * "SMOKE Bea's notes"; General is a wiki. Everything a proof makes is named "SMOKE …".
 */
require dirname(__DIR__) . '/slice1/lib.php';
if (!function_exists('is_uuid')) { function is_uuid(mixed $v): bool { return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1; } }

function page_by_title(string $title): ?string { $v = one('SELECT id::text FROM pages WHERE plain_title = :t AND archived_at IS NULL ORDER BY created_at LIMIT 1', ['t' => $title]); return $v === false || $v === null ? null : (string) $v; }
function page_row(string $uuid): array { return q('SELECT id::text AS id, space_id, parent_page_id::text AS parent_page_id, plain_title, is_locked, archived_at, archived_via::text AS archived_via, permission_root_id::text AS permission_root_id, owner_member_id, wiki_owner_member_id, verification_state, verify_until, is_template, position FROM pages WHERE id = CAST(:id AS uuid)', ['id' => $uuid])[0] ?? []; }
function level_of(int $member, string $uuid): string { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); return (string) one('SELECT sp_page_level(CAST(:p AS uuid))', ['p' => $uuid]); }
/** A block by SQL (the editor is slice 3). */
function mkblock(string $page, string $type, array $content, ?string $parent = null, ?string $after = null): string
{
    return (string) one('SELECT sp_block_insert(CAST(:p AS uuid), CAST(:par AS uuid), CAST(:a AS uuid), :t, CAST(:c AS jsonb))::text', ['p' => $page, 'par' => $parent, 'a' => $after, 't' => $type, 'c' => $content === [] ? '{}' : json_encode($content)]);
}
function rt(string $text, array $ann = [], ?string $link = null): array { return [['type' => 'text', 'text' => ['content' => $text, 'link' => $link === null ? null : ['url' => $link]], 'annotations' => $ann, 'plain_text' => $text]]; }
/** An image file in storage/proof and its attachments row on a block. Returns the attachment id. */
function mkimage(string $blockUuid, string $name = 'proof.png'): int
{
    $root = dirname(__DIR__, 3) . '/storage';
    @mkdir($root . '/proof', 0775, true);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    file_put_contents($root . '/proof/' . $name, $png);
    return (int) one("INSERT INTO attachments (record_type, record_uuid, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('block', CAST(:b AS uuid), :n, 'image/png', :s, :h, :p, 27) RETURNING id",
        ['b' => $blockUuid, 'n' => $name, 's' => strlen($png), 'h' => hash('sha256', $png), 'p' => 'proof/' . $name]);
}
function pages_world(): array
{
    $w = spaces_world();
    $marco = as_member(27); $priya = as_member(26); $bea = as_member(28); $owner = as_member(1);
    $hb = page_by_title('SMOKE Product handbook');
    if ($hb === null) {
        [, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Product handbook', 'space' => $w['product'], 'icon' => '📘', 'markdown' => 'Everything about the product.']);
        $hb = (string) $b['record_id'];
        [, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Pricing', 'parent' => $hb]);
        $pr = (string) $b['record_id'];
        [, $b] = act($priya, '/pages/save.php', ['title' => 'SMOKE Pricing 2027', 'parent' => $pr]);
        [, $b] = act($bea, '/pages/save.php', ['title' => "SMOKE Bea's notes", 'private' => 'yes']);
        if (!(bool) one('SELECT is_wiki FROM spaces WHERE id = :g', ['g' => $w['general']])) {
            act($owner, '/spaces/wiki.php', ['space' => $w['general'], 'wiki' => 'yes', 'verify_months' => '6']);
        }
    }
    return $w + ['handbook' => $hb, 'pricing' => page_by_title('SMOKE Pricing'), 'pricing2027' => page_by_title('SMOKE Pricing 2027'), 'notes' => page_by_title("SMOKE Bea's notes")];
}
