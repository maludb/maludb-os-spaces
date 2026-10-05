<?php
declare(strict_types=1);

/**
 * The one converter IN (block-editor.md "The converter"): Markdown → the tree in the shape sp_page_tree() gives (type, content, children) —
 * every adopted type the Markdown of sp_page_markdown() produces, so the round trip holds (columns flatten, by design). The way OUT is
 * SQL's sp_page_markdown(); nothing here renders Markdown from blocks.
 *   markdown_to_blocks(string $md, array $ctx): array      $ctx: page_by_title (callable(string): ?string), member_by_name (callable(string): ?array{id,kind,name}),
 *                                                           embed_hosts (string[])
 *   markdown_inline_runs(string $text, array $ctx): array   one line's inline Markdown as rich-text runs
 */

function markdown_to_blocks(string $md, array $ctx = []): array
{
    $ctx += ['page_by_title' => static fn (string $t): ?string => null, 'member_by_name' => static fn (string $n): ?array => null, 'embed_hosts' => []];
    $lines = preg_split('/\r\n|\r|\n/', $md) ?: [];
    $i = 0;
    return md_parse_blocks($lines, $i, 0, $ctx, null);
}

/** Parse lines from $i at an indentation (spaces) until the end or a line that belongs to an outer level; $stop: a closing marker to stop at. */
function md_parse_blocks(array $lines, int &$i, int $indent, array $ctx, ?string $stop): array
{
    $out = [];
    $n = count($lines);
    while ($i < $n) {
        $raw = $lines[$i];
        if ($stop !== null && trim($raw) === $stop) {
            $i++;
            return $out;
        }
        if (trim($raw) === '') {
            $i++;
            continue;
        }
        $lead = strlen($raw) - strlen(ltrim($raw, ' '));
        if ($lead < $indent) {
            return $out;                                                          // belongs to an outer list
        }
        $line = substr($raw, $indent);
        $t = ltrim($line, ' ');
        $extra = strlen($line) - strlen($t);
        // code fence
        if (preg_match('/^```([\w+-]*)\s*$/', $t, $m)) {
            $lang = $m[1] !== '' ? $m[1] : 'plain';
            $i++;
            $code = [];
            while ($i < $n && !preg_match('/^\s*```\s*$/', $lines[$i])) { $code[] = $indent > 0 ? preg_replace('/^ {0,' . $indent . '}/', '', $lines[$i]) : $lines[$i]; $i++; }
            $i++;
            $out[] = md_block('code', ['rich_text' => md_plain_runs(implode("\n", $code)), 'language' => $lang]);
            continue;
        }
        // equation
        if (trim($t) === '$$') {
            $i++;
            $expr = [];
            while ($i < $n && trim($lines[$i]) !== '$$') { $expr[] = trim($lines[$i]); $i++; }
            $i++;
            $out[] = md_block('equation', ['expression' => implode("\n", $expr)]);
            continue;
        }
        // toggle: <details><summary>x</summary> … </details>
        if (preg_match('/^<details><summary>(.*)<\/summary>\s*$/', $t, $m)) {
            $i++;
            $kids = md_parse_blocks($lines, $i, $indent, $ctx, '</details>');
            $out[] = md_block('toggle', ['rich_text' => markdown_inline_runs($m[1], $ctx)], $kids);
            continue;
        }
        if (preg_match('/^<!-- unsupported block\s*([\w-]*)\s*-->\s*$/', $t, $m)) {
            $i++;
            $out[] = md_block('unsupported', $m[1] !== '' ? ['original_type' => $m[1]] : []);
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.*)$/', $t, $m)) {
            $i++;
            $out[] = md_block('heading_' . strlen($m[1]), ['rich_text' => markdown_inline_runs($m[2], $ctx)]);
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})\s*$/', $t)) {
            $i++;
            $out[] = md_block('divider', []);
            continue;
        }
        if (preg_match('/^!\[(.*?)\]\((\S*)\)\s*$/', $t, $m)) {
            $i++;
            $out[] = md_block('image', md_media_content($m[2]) + ['caption' => markdown_inline_runs($m[1], $ctx)]);
            continue;
        }
        if (preg_match('/^\[\[(.+?)\]\]\s*$/', $t, $m)) {
            $i++;
            $pid = ($ctx['page_by_title'])($m[1]);
            $out[] = $pid !== null ? md_block('link_to_page', ['page_id' => $pid]) : md_block('paragraph', ['rich_text' => md_plain_runs('[[' . $m[1] . ']]')]);
            continue;
        }
        // a bare URL on its own line: an embed for an allowed host, else a bookmark; <url> the same
        if (preg_match('/^<?(https?:\/\/\S+?)>?\s*$/', $t, $m)) {
            $i++;
            $host = strtolower((string) parse_url($m[1], PHP_URL_HOST));
            $out[] = md_block(in_array($host, array_map('strtolower', $ctx['embed_hosts']), true) ? 'embed' : 'bookmark', ['url' => $m[1]]);
            continue;
        }
        // a link on its own line: a file (a /files/ URL) or a bookmark with a caption
        if (preg_match('/^\[(.*?)\]\((\S+)\)\s*$/', $t, $m) && !str_starts_with($m[2], '#')) {
            $i++;
            if (preg_match('#^/files/(\d+)#', $m[2], $fm)) {
                $out[] = md_block('file', ['attachment_id' => (int) $fm[1], 'name' => $m[1], 'caption' => markdown_inline_runs($m[1], $ctx)]);
            } else {
                $out[] = md_block('bookmark', ['url' => $m[2], 'caption' => markdown_inline_runs($m[1], $ctx)]);
            }
            continue;
        }
        // a table: | a | b | with a separator line next
        if (preg_match('/^\|.*\|\s*$/', $t) && $i + 1 < $n && preg_match('/^\s*\|(\s*:?-+:?\s*\|)+\s*$/', $lines[$i + 1])) {
            $rows = [];
            $width = 0;
            while ($i < $n && preg_match('/^\s*\|.*\|\s*$/', $lines[$i])) {
                $l = trim($lines[$i]);
                $i++;
                if (preg_match('/^\|(\s*:?-+:?\s*\|)+$/', $l)) { continue; }
                $cells = array_map('trim', explode('|', substr($l, 1, -1)));
                $cells = array_map(static fn (string $c): string => str_replace('\\|', '|', $c), $cells);
                $width = max($width, count($cells));
                $rows[] = md_block('table_row', ['cells' => array_map(static fn (string $c): array => markdown_inline_runs($c, $ctx), $cells)]);
            }
            $out[] = md_block('table', ['has_column_header' => true, 'has_row_header' => false, 'table_width' => $width], $rows);
            continue;
        }
        // lists: the item, then its continuation (deeper-indented lines) as children
        if (preg_match('/^([-*+]|\d+\.)\s+(.*)$/', $t, $m)) {
            $marker = $m[1];
            $text = $m[2];
            $i++;
            $childIndent = $indent + $extra + strlen($marker) + 1;
            $kids = md_parse_list_children($lines, $i, $childIndent, $ctx);
            if (preg_match('/^\[([ xX])\]\s+(.*)$/', $text, $tm) || $text === '[ ]' || $text === '[x]') {
                $out[] = md_block('to_do', ['rich_text' => markdown_inline_runs($tm[2] ?? '', $ctx), 'checked' => strtolower($tm[1] ?? ' ') === 'x'], $kids);
            } elseif (preg_match('/^\[\[(.+?)\]\]\s*(\(database\))?$/', $text, $pm) && ($pid = ($ctx['page_by_title'])($pm[1])) !== null && $kids === []) {
                $out[] = md_block('link_to_page', [($pm[2] ?? '') !== '' ? 'database_id' : 'page_id' => $pid]);
            } elseif ($marker === '-' || $marker === '*' || $marker === '+') {
                $out[] = md_block('bulleted_list_item', ['rich_text' => markdown_inline_runs($text, $ctx)], $kids);
            } else {
                $out[] = md_block('numbered_list_item', ['rich_text' => markdown_inline_runs($text, $ctx)], $kids);
            }
            continue;
        }
        // a quote or a callout: consecutive "> " lines; a callout starts with [!NOTE] (and an emoji)
        if (preg_match('/^>\s?(.*)$/', $t)) {
            $block = [];
            while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $qm)) { $block[] = $qm[1]; $i++; }
            $first = array_shift($block) ?? '';
            $j = 0;
            $kids = $block === [] ? [] : md_parse_blocks($block, $j, 0, $ctx, null);
            if (preg_match('/^\[!NOTE\]\s*(.*)$/', $first, $cm)) {
                $rest = $cm[1];
                $icon = null;
                if (preg_match('/^(\X)\s+(.*)$/u', $rest, $em) && preg_match('/^[^\w\s[:punct:]]/u', $em[1])) { $icon = $em[1]; $rest = $em[2]; }
                $out[] = md_block('callout', ['rich_text' => markdown_inline_runs($rest, $ctx)] + ($icon !== null ? ['icon' => ['emoji' => $icon]] : []), $kids);
            } else {
                $out[] = md_block('quote', ['rich_text' => markdown_inline_runs($first, $ctx)], $kids);
            }
            continue;
        }
        // a paragraph: this line (one line = one paragraph, as sp_page_markdown writes them)
        $i++;
        $out[] = md_block('paragraph', ['rich_text' => markdown_inline_runs($t, $ctx)]);
    }
    return $out;
}

/** The lines indented under a list item (at least $indent spaces) become its children. */
function md_parse_list_children(array $lines, int &$i, int $indent, array $ctx): array
{
    $n = count($lines);
    $sub = [];
    while ($i < $n) {
        $raw = $lines[$i];
        if (trim($raw) === '') { $i++; continue; }
        $lead = strlen($raw) - strlen(ltrim($raw, ' '));
        if ($lead < $indent) { break; }
        $sub[] = substr($raw, $indent);
        $i++;
    }
    if ($sub === []) {
        return [];
    }
    $j = 0;
    return md_parse_blocks($sub, $j, 0, $ctx, null);
}

function md_block(string $type, array $content, array $children = []): array
{
    return ['type' => $type, 'content' => $content, 'children' => $children];
}

function md_plain_runs(string $text): array
{
    return $text === '' ? [] : [['type' => 'text', 'text' => ['content' => $text, 'link' => null], 'annotations' => [], 'plain_text' => $text]];
}

function md_media_content(string $url): array
{
    if (preg_match('#^/files/(\d+)#', $url, $m)) {
        return ['attachment_id' => (int) $m[1]];
    }
    return ['url' => $url, 'external' => ['url' => $url]];
}

/** One line's inline Markdown as runs: **bold**, *italic*, `code`, ~~struck~~, <u>u</u>, [text](url), [[Page]], @Name, $equation$. */
function markdown_inline_runs(string $text, array $ctx = []): array
{
    $ctx += ['page_by_title' => static fn (string $t): ?string => null, 'member_by_name' => static fn (string $n): ?array => null];
    $runs = [];
    $pos = 0;
    $len = strlen($text);
    $buf = '';
    $flush = static function () use (&$buf, &$runs): void {
        if ($buf !== '') { $runs[] = ['type' => 'text', 'text' => ['content' => $buf, 'link' => null], 'annotations' => [], 'plain_text' => $buf]; $buf = ''; }
    };
    $ann = static fn (string $s, array $a, ?string $href = null): array => ['type' => 'text', 'text' => ['content' => $s, 'link' => $href === null ? null : ['url' => $href]], 'annotations' => $a, 'plain_text' => $s];
    while ($pos < $len) {
        $rest = substr($text, $pos);
        if (preg_match('/^`([^`]+)`/', $rest, $m)) { $flush(); $runs[] = $ann($m[1], ['code' => true]); $pos += strlen($m[0]); continue; }
        if (preg_match('/^\*\*(.+?)\*\*/', $rest, $m)) { $flush(); foreach (markdown_inline_runs($m[1], $ctx) as $r) { $r['annotations']['bold'] = true; $runs[] = $r; } $pos += strlen($m[0]); continue; }
        if (preg_match('/^~~(.+?)~~/', $rest, $m)) { $flush(); foreach (markdown_inline_runs($m[1], $ctx) as $r) { $r['annotations']['strikethrough'] = true; $runs[] = $r; } $pos += strlen($m[0]); continue; }
        if (preg_match('/^<u>(.+?)<\/u>/', $rest, $m)) { $flush(); foreach (markdown_inline_runs($m[1], $ctx) as $r) { $r['annotations']['underline'] = true; $runs[] = $r; } $pos += strlen($m[0]); continue; }
        if (preg_match('/^\*(?!\s)(.+?)(?<!\s)\*/', $rest, $m)) { $flush(); foreach (markdown_inline_runs($m[1], $ctx) as $r) { $r['annotations']['italic'] = true; $runs[] = $r; } $pos += strlen($m[0]); continue; }
        if (preg_match('/^\[\[(.+?)\]\]/', $rest, $m)) {
            $flush();
            $pid = ($ctx['page_by_title'])($m[1]);
            $runs[] = $pid !== null ? ['type' => 'mention', 'mention' => ['type' => 'page', 'id' => $pid, 'name' => $m[1]], 'plain_text' => $m[1]] : $ann('[[' . $m[1] . ']]', []);
            $pos += strlen($m[0]);
            continue;
        }
        if (preg_match('/^\[([^\]]+)\]\(([^)\s]+)\)/', $rest, $m)) { $flush(); foreach (markdown_inline_runs($m[1], $ctx) as $r) { $r['text']['link'] = ['url' => $m[2]]; $r['href'] = $m[2]; $runs[] = $r; } $pos += strlen($m[0]); continue; }
        if (preg_match('/^\$([^$]+)\$/', $rest, $m)) { $flush(); $runs[] = ['type' => 'equation', 'equation' => ['expression' => $m[1]], 'plain_text' => $m[1]]; $pos += strlen($m[0]); continue; }
        if (($pos === 0 || !ctype_alnum($text[$pos - 1])) && preg_match('/^@([\p{L}\p{N}][\p{L}\p{N} .\'-]{0,60})(?=[^\p{L}\p{N}_]|$)/u', $rest, $m)) {
            // the longest leading name that is a member or a department: try the whole run of words, then shorter word prefixes (trailing punctuation dropped)
            $words = preg_split('/\s+/', trim($m[1])) ?: [];
            $found = null;
            for ($k = count($words); $k >= 1; $k--) {
                $cand = rtrim(implode(' ', array_slice($words, 0, $k)), ".,;:!?)'-");
                if ($cand === '') { continue; }
                $hit = ($ctx['member_by_name'])($cand);
                if ($hit !== null) { $found = [$cand, $hit]; break; }
            }
            if ($found !== null) {
                $flush();
                [$cand, $hit] = $found;
                $runs[] = ['type' => 'mention', 'mention' => ['type' => $hit['kind'], 'id' => (string) $hit['id'], 'name' => $hit['name']], 'plain_text' => '@' . $hit['name']];
                $pos += 1 + strlen($cand);
                continue;
            }
        }
        $buf .= $text[$pos];
        $pos++;
    }
    $flush();
    return $runs;
}
