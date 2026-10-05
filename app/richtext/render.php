<?php
declare(strict_types=1);

/**
 * The reader (pages-tree.md "The reader"): sp_page_tree()'s JSON rendered to HTML — every adopted block type, nesting, numbered lists
 * counted per sibling group, rich text runs with their annotations and links, mentions as chips. Shared with slice 3's editor
 * (read-only here). Output through e(); no inline scripts; data-block-id on every block (the editor hangs on it).
 * $opts: file_url (callable(int $attachmentId): string — default /files/{id}), page_url (callable(string $uuid): ?string — null = no link),
 *        titles (uuid => title, for link_to_page / child_page / mentions), embed_hosts (string[] allowed for an iframe), headings (bool: a
 *        table of contents may list them — computed from the tree).
 */

const RT_LIST_TYPES = ['bulleted_list_item' => 'ul', 'numbered_list_item' => 'ol', 'to_do' => 'ul'];

function rt_safe_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }
    return preg_match('#^(https?://|mailto:)#i', $url) === 1 ? $url : null;
}

/** The one rich-text array as HTML. */
function render_rich_text(?array $runs, array $opts = []): string
{
    if ($runs === null) {
        return '';
    }
    $out = '';
    foreach ($runs as $r) {
        if (!is_array($r)) {
            continue;
        }
        $a = is_array($r['annotations'] ?? null) ? $r['annotations'] : [];
        $type = (string) ($r['type'] ?? 'text');
        $href = null;
        if ($type === 'text') {
            $text = (string) ($r['text']['content'] ?? ($r['plain_text'] ?? ''));
            $link = $r['text']['link'] ?? null;
            $href = rt_safe_url(is_array($link) ? ($link['url'] ?? null) : (is_string($link) ? $link : ($r['href'] ?? null)));
            $html = e($text);
        } elseif ($type === 'equation') {
            $html = '<code class="rt-equation">' . e('$' . (string) ($r['equation']['expression'] ?? '') . '$') . '</code>';
        } elseif ($type === 'mention') {
            $m = is_array($r['mention'] ?? null) ? $r['mention'] : [];
            $kind = (string) ($m['type'] ?? '');
            $id = (string) ($m['id'] ?? '');
            $name = (string) ($m['name'] ?? ($r['plain_text'] ?? ''));
            if (in_array($kind, ['page', 'database'], true)) {
                $title = $opts['titles'][$id] ?? ($name !== '' ? $name : 'Untitled');
                $url = isset($opts['page_url']) ? $opts['page_url']($id) : '/pages/' . $id;
                $html = $url === null ? '<span class="rt-mention rt-mention-page">' . e($title) . '</span>' : '<a class="rt-mention rt-mention-page" href="' . e($url) . '">' . e($title) . '</a>';
            } elseif ($kind === 'date') {
                $d = is_array($m['date'] ?? null) ? $m['date'] : [];
                $html = '<span class="rt-mention rt-mention-date">' . e((string) ($d['start'] ?? $name)) . (isset($d['end']) ? ' → ' . e((string) $d['end']) : '') . '</span>';
            } elseif (in_array($kind, ['channel', 'here', 'everyone'], true)) {
                $html = '<span class="rt-mention rt-mention-all">@' . e($kind) . '</span>';
            } else {
                $html = '<span class="rt-mention rt-mention-' . e($kind ?: 'member') . '" data-id="' . e($id) . '">@' . e($name !== '' ? ltrim($name, '@') : ($kind ?: 'someone')) . '</span>';
            }
        } else {
            $html = e((string) ($r['plain_text'] ?? ''));
        }
        if ($html === '') {
            continue;
        }
        if (!empty($a['code'])) { $html = '<code>' . $html . '</code>'; }
        if (!empty($a['bold'])) { $html = '<strong>' . $html . '</strong>'; }
        if (!empty($a['italic'])) { $html = '<em>' . $html . '</em>'; }
        if (!empty($a['strikethrough'])) { $html = '<s>' . $html . '</s>'; }
        if (!empty($a['underline'])) { $html = '<u>' . $html . '</u>'; }
        $color = (string) ($a['color'] ?? 'default');
        if ($color !== '' && $color !== 'default') { $html = '<span class="rt-color-' . e($color) . '">' . $html . '</span>'; }
        if ($href !== null) { $html = '<a href="' . e($href) . '" rel="noopener"' . (str_starts_with($href, '/') ? '' : ' target="_blank"') . '>' . $html . '</a>'; }
        $out .= $html;
    }
    return $out;
}

/** The plain words of a rich-text array (for alt texts and summaries). */
function rt_plain(?array $runs): string
{
    $t = '';
    foreach ($runs ?? [] as $r) {
        $t .= (string) ($r['plain_text'] ?? ($r['text']['content'] ?? ''));
    }
    return $t;
}

/** sp_page_tree()'s JSON (decoded) as HTML. */
function render_blocks(array $tree, array $opts = []): string
{
    $opts += ['file_url' => static fn (int $id): string => '/files/' . $id, 'page_url' => static fn (string $uuid): ?string => '/pages/' . $uuid, 'titles' => [], 'embed_hosts' => []];
    if (!isset($opts['headings'])) {
        $opts['headings'] = rt_collect_headings($tree);
    }
    $out = '';
    $n = count($tree);
    for ($i = 0; $i < $n; $i++) {
        $b = $tree[$i];
        $type = (string) ($b['type'] ?? 'unsupported');
        if (isset(RT_LIST_TYPES[$type])) {
            // consecutive list items of one type make one list
            $tag = RT_LIST_TYPES[$type];
            $items = '';
            $j = $i;
            while ($j < $n && ($tree[$j]['type'] ?? '') === $type) {
                $items .= rt_list_item($tree[$j], $opts);
                $j++;
            }
            $out .= '<' . $tag . ' class="rt-list rt-' . e($type) . '">' . $items . '</' . $tag . '>';
            $i = $j - 1;
            continue;
        }
        $out .= rt_block($b, $opts);
    }
    return $out;
}

function rt_collect_headings(array $tree, array &$acc = []): array
{
    foreach ($tree as $b) {
        $t = (string) ($b['type'] ?? '');
        if (in_array($t, ['heading_1', 'heading_2', 'heading_3'], true)) {
            $acc[] = ['id' => (string) $b['id'], 'level' => (int) substr($t, -1), 'text' => rt_plain($b['content']['rich_text'] ?? [])];
        }
        if (!empty($b['children']) && is_array($b['children'])) {
            rt_collect_headings($b['children'], $acc);
        }
    }
    return $acc;
}

function rt_attrs(array $b, string $extra = ''): string
{
    return ' class="rt-block rt-' . e((string) ($b['type'] ?? '')) . ($extra !== '' ? ' ' . e($extra) : '') . '" data-block-id="' . e((string) ($b['id'] ?? '')) . '"';
}

function rt_children(array $b, array $opts): string
{
    return !empty($b['children']) && is_array($b['children']) ? render_blocks($b['children'], $opts) : '';
}

function rt_list_item(array $b, array $opts): string
{
    $c = is_array($b['content'] ?? null) ? $b['content'] : [];
    $text = render_rich_text($c['rich_text'] ?? [], $opts);
    $color = (string) ($c['color'] ?? 'default');
    $cls = $color !== 'default' && $color !== '' ? 'rt-color-' . $color : '';
    if (($b['type'] ?? '') === 'to_do') {
        $checked = !empty($c['checked']);
        return '<li' . rt_attrs($b, trim('rt-todo ' . ($checked ? 'rt-done ' : '') . $cls)) . '><label><input type="checkbox" disabled' . ($checked ? ' checked' : '') . '> <span>' . $text . '</span></label>' . rt_children($b, $opts) . '</li>';
    }
    return '<li' . rt_attrs($b, $cls) . '>' . $text . rt_children($b, $opts) . '</li>';
}

function rt_media_url(array $c, array $opts): ?string
{
    $url = $c['url'] ?? ($c['external']['url'] ?? ($c['file']['url'] ?? null));
    if (($url === null || $url === '') && isset($c['attachment_id']) && (int) $c['attachment_id'] > 0) {
        $url = $opts['file_url']((int) $c['attachment_id']);
    }
    return rt_safe_url(is_string($url) ? $url : null);
}

function rt_block(array $b, array $opts): string
{
    $type = (string) ($b['type'] ?? 'unsupported');
    $c = is_array($b['content'] ?? null) ? $b['content'] : [];
    $rt = render_rich_text($c['rich_text'] ?? [], $opts);
    $color = (string) ($c['color'] ?? 'default');
    $cls = $color !== 'default' && $color !== '' ? 'rt-color-' . $color : '';
    $kids = rt_children($b, $opts);
    $caption = render_rich_text($c['caption'] ?? [], $opts);
    switch ($type) {
        case 'paragraph':
            return '<p' . rt_attrs($b, $cls) . '>' . ($rt === '' ? '&nbsp;' : $rt) . '</p>' . ($kids !== '' ? '<div class="rt-indent">' . $kids . '</div>' : '');
        case 'heading_1': case 'heading_2': case 'heading_3':
            $tag = ['heading_1' => 'h2', 'heading_2' => 'h3', 'heading_3' => 'h4'][$type];
            if (!empty($c['is_toggleable'])) {
                return '<details' . rt_attrs($b, 'rt-toggle-heading ' . $cls) . '><summary><' . $tag . ' id="h-' . e((string) $b['id']) . '">' . $rt . '</' . $tag . '></summary>' . $kids . '</details>';
            }
            return '<' . $tag . rt_attrs($b, $cls) . ' id="h-' . e((string) $b['id']) . '">' . $rt . '</' . $tag . '>';
        case 'toggle':
            return '<details' . rt_attrs($b, $cls) . '><summary>' . $rt . '</summary>' . $kids . '</details>';
        case 'quote':
            return '<blockquote' . rt_attrs($b, $cls) . '><p>' . $rt . '</p>' . $kids . '</blockquote>';
        case 'callout':
            $icon = (string) ($c['icon']['emoji'] ?? '');
            return '<div' . rt_attrs($b, 'rt-callout-box ' . $cls) . '>' . ($icon !== '' ? '<span class="rt-callout-icon">' . e($icon) . '</span>' : '') . '<div class="rt-callout-body"><p>' . $rt . '</p>' . $kids . '</div></div>';
        case 'code':
            return '<figure' . rt_attrs($b) . '><pre><code class="language-' . e((string) ($c['language'] ?? 'plain')) . '">' . e(rt_plain($c['rich_text'] ?? [])) . '</code></pre>' . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
        case 'divider':
            return '<hr' . rt_attrs($b) . '>';
        case 'image':
            $url = rt_media_url($c, $opts);
            return '<figure' . rt_attrs($b) . '>' . ($url === null ? '<span class="text-muted">An image that is not here.</span>' : '<img src="' . e($url) . '" alt="' . e(rt_plain($c['caption'] ?? [])) . '" loading="lazy">') . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
        case 'video': case 'audio':
            $url = rt_media_url($c, $opts);
            return '<figure' . rt_attrs($b) . '>' . ($url === null ? '<span class="text-muted">Media that is not here.</span>' : '<' . $type . ' controls src="' . e($url) . '" preload="none"></' . $type . '>') . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
        case 'file': case 'pdf':
            $url = rt_media_url($c, $opts);
            $name = $caption !== '' ? $caption : e((string) ($c['name'] ?? ($type === 'pdf' ? 'A PDF' : 'A file')));
            return '<p' . rt_attrs($b) . '>' . ($url === null ? '<span class="text-muted">' . $name . '</span>' : '<a href="' . e($url) . '" class="rt-file" rel="noopener"><i class="feather-paperclip me-1"></i>' . $name . '</a>') . '</p>';
        case 'bookmark': case 'link_preview': case 'embed':
            $url = rt_safe_url((string) ($c['url'] ?? ''));
            $host = $url === null ? '' : (string) parse_url($url, PHP_URL_HOST);
            $iframe = '';
            if ($type === 'embed' && $url !== null && in_array(strtolower($host), array_map('strtolower', $opts['embed_hosts']), true)) {
                $iframe = '<iframe src="' . e($url) . '" loading="lazy" sandbox="allow-scripts allow-same-origin allow-popups" referrerpolicy="no-referrer" title="' . e($host) . '"></iframe>';
            }
            return '<div' . rt_attrs($b, 'rt-bookmark-card') . '>' . $iframe . ($url === null ? '<span class="text-muted">A link that is not here.</span>' : '<a href="' . e($url) . '" rel="noopener" target="_blank" class="rt-bookmark-link"><span class="rt-bookmark-host">' . e($host) . '</span><span class="rt-bookmark-url">' . e($url) . '</span></a>') . ($caption !== '' ? '<div class="rt-caption">' . $caption . '</div>' : '') . '</div>';
        case 'equation':
            return '<p' . rt_attrs($b) . '><code class="rt-equation">' . e((string) ($c['expression'] ?? '')) . '</code></p>';
        case 'table_of_contents':
            $items = '';
            foreach ($opts['headings'] as $h) {
                $items .= '<li class="rt-toc-' . (int) $h['level'] . '"><a href="#h-' . e($h['id']) . '">' . e($h['text'] !== '' ? $h['text'] : 'Untitled heading') . '</a></li>';
            }
            return '<nav' . rt_attrs($b) . '><ul class="rt-toc">' . ($items === '' ? '<li class="text-muted">No headings yet.</li>' : $items) . '</ul></nav>';
        case 'breadcrumb': case 'template':
            return '';
        case 'column_list':
            $cols = '';
            foreach ($b['children'] ?? [] as $col) {
                $cols .= '<div' . rt_attrs($col, 'rt-column-box') . '>' . rt_children($col, $opts) . '</div>';
            }
            return '<div' . rt_attrs($b, 'rt-columns') . '>' . $cols . '</div>';
        case 'column':
            return '<div' . rt_attrs($b, 'rt-column-box') . '>' . $kids . '</div>';
        case 'synced_block':
            $from = $b['synced_from'] ?? ($c['synced_from']['block_id'] ?? null);
            if ($from !== null && $kids === '') {
                return '<div' . rt_attrs($b, 'rt-synced') . ' data-synced-from="' . e((string) $from) . '"><span class="text-muted fs-12">A synced block you cannot see.</span></div>';
            }
            return '<div' . rt_attrs($b, 'rt-synced') . ($from !== null ? ' data-synced-from="' . e((string) $from) . '"' : '') . '>' . $kids . '</div>';
        case 'link_to_page':
            $id = (string) ($c['page_id'] ?? ($c['database_id'] ?? ''));
            $title = $opts['titles'][$id] ?? 'A page you cannot see';
            $url = $id !== '' ? $opts['page_url']($id) : null;
            return '<p' . rt_attrs($b) . '>' . ($url === null ? '<span class="rt-page-link text-muted">' . e($title) . '</span>' : '<a class="rt-page-link" href="' . e($url) . '"><i class="feather-arrow-up-right me-1"></i>' . e($title) . '</a>') . '</p>';
        case 'child_page': case 'child_database':
            $id = (string) ($c['page_id'] ?? ($c['database_id'] ?? ''));
            $title = $opts['titles'][$id] ?? (string) ($c['title'] ?? 'Untitled');
            $url = $id !== '' ? $opts['page_url']($id) : null;
            if ($type === 'child_database' && $url !== null && str_starts_with($url, '/pages/')) {
                $url = '/databases/' . $id;
            }
            $icon = $type === 'child_database' ? '▦' : '📄';
            return '<p' . rt_attrs($b) . '>' . ($url === null ? '<span class="rt-child-link text-muted">' . e($icon . ' ' . $title) . '</span>' : '<a class="rt-child-link" href="' . e($url) . '">' . e($icon . ' ' . $title) . '</a>') . '</p>';
        case 'table':
            $rows = '';
            $colHeader = !empty($c['has_column_header']);
            $rowHeader = !empty($c['has_row_header']);
            $ri = 0;
            foreach ($b['children'] ?? [] as $row) {
                if (($row['type'] ?? '') !== 'table_row') {
                    continue;
                }
                $cells = '';
                $ci = 0;
                foreach ($row['content']['cells'] ?? [] as $cell) {
                    $tag = ($colHeader && $ri === 0) || ($rowHeader && $ci === 0) ? 'th' : 'td';
                    $cells .= '<' . $tag . '>' . render_rich_text(is_array($cell) ? $cell : [], $opts) . '</' . $tag . '>';
                    $ci++;
                }
                $rows .= '<tr data-block-id="' . e((string) $row['id']) . '">' . $cells . '</tr>';
                $ri++;
            }
            return '<div' . rt_attrs($b, 'table-responsive') . '><table class="table table-bordered rt-table-grid">' . $rows . '</table></div>';
        case 'table_row':
            return '';
        case 'unsupported':
            return '<div' . rt_attrs($b, 'text-muted fs-12') . '><i class="feather-help-circle me-1"></i>An unsupported block' . (isset($c['original_type']) ? ' (' . e((string) $c['original_type']) . ')' : '') . '.</div>';
        default:
            return '<p' . rt_attrs($b, $cls) . '>' . $rt . '</p>' . $kids;
    }
}
