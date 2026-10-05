<?php
/**
 * Helpers for the slice 3 proofs (docs/build-specs/block-editor.md, "Proof"). Builds on slice 2's lib (its world). editor_world() adds: in Product
 * (closed; Marco its owner, Priya a member) the page "SMOKE Runbook" with every block type, Dana a member of Product, Ann (the guest) at view on
 * Runbook; Bea's private page "SMOKE Bea's notes" (slice 2's). Everything a proof makes is named "SMOKE …".
 */
require dirname(__DIR__) . '/slice2/lib.php';

function block_row(string $id): array { return q('SELECT id::text AS id, page_id::text AS page_id, parent_block_id::text AS parent_block_id, type, position, content::text AS content, plain_text, version, synced_from::text AS synced_from FROM blocks WHERE id = CAST(:id AS uuid)', ['id' => $id])[0] ?? []; }
function root_blocks(string $page): array { return q('SELECT id::text AS id, type, plain_text, position, version FROM blocks WHERE page_id = CAST(:p AS uuid) AND parent_block_id IS NULL ORDER BY position', ['p' => $page]); }
function children_of(string $block): array { return q('SELECT id::text AS id, type, plain_text, position, version FROM blocks WHERE parent_block_id = CAST(:b AS uuid) ORDER BY position', ['b' => $block]); }
function page_md(string $page, bool $title = false): string { return (string) one('SELECT sp_page_markdown(CAST(:p AS uuid), :t)', ['p' => $page, 't' => $title ? 't' : 'f']); }
function content_rev(string $page): int { return (int) one('SELECT content_rev FROM pages WHERE id = CAST(:p AS uuid)', ['p' => $page]); }
function para(string $text): string { return json_encode(['rich_text' => rt($text)]); }
/** A multipart upload as a signed-on person: [status, body]. */
function upload(string $jar, string $path, array $form, string $file, string $name, string $mime): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $ch = curl_init(BASE . $path);
    $fields = $form + ['csrf_token' => $tok[$jar], 'file' => new CURLFile($file, $mime, $name)];
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, json_decode($body, true) ?? ['raw' => $body]];
}
function proof_file(string $name, string $bytes): string { $dir = dirname(__DIR__, 3) . '/storage/proof'; @mkdir($dir, 0775, true); file_put_contents($dir . '/' . $name, $bytes); return $dir . '/' . $name; }
function png_bytes(int $w = 8, int $h = 6): string { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30)); ob_start(); imagepng($im); return (string) ob_get_clean(); }
function editor_world(): array
{
    $w = pages_world();
    $marco = as_member(27);
    $rb = page_by_title('SMOKE Runbook');
    if ($rb === null) {
        [, $b] = act($marco, '/pages/save.php', ['title' => 'SMOKE Runbook', 'space' => $w['product'], 'icon' => '📗']);
        $rb = (string) $b['record_id'];
        $md = "# Runbook heading\n\n## Second\n\n### Third\n\nA paragraph with **bold**, *italic*, `code`, ~~gone~~, a [link](https://example.com/x) and [[SMOKE Product handbook]] by @SMOKE Priya.\n\n- one\n- two\n  - nested\n\n1. first\n2. second\n\n- [ ] open\n- [x] done\n\n> quoted\n\n> [!NOTE] 💡 a callout\n\n```php\necho 1;\n```\n\n---\n\n\$\$\nE = mc^2\n\$\$\n\n<details><summary>a toggle</summary>\n\ninside the toggle\n\n</details>\n\nhttps://www.youtube.com/watch?v=abc\n\nhttps://example.org/page\n\n| a | b |\n|---|---|\n| 1 | 2 |\n";
        act($marco, '/blocks/append.php', ['page' => $rb, 'markdown' => $md]);
        act($marco, '/spaces/members/add.php', ['space' => $w['product'], 'member' => 30]);
        act($marco, '/pages/share-guest.php', ['page' => $rb, 'guest' => 29, 'level' => 'view']);
    }
    return $w + ['runbook' => $rb];
}
