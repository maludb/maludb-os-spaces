<?php /** The slash menu: every adopted type with a hint; filtered as you type; Enter picks. Data: types */
$hints = ['paragraph' => 'Plain text', 'heading_1' => 'Big heading', 'heading_2' => 'Medium heading', 'heading_3' => 'Small heading', 'bulleted_list_item' => 'A bullet', 'numbered_list_item' => 'A numbered item', 'to_do' => 'A checkbox',
    'toggle' => 'A block that folds', 'quote' => 'A quotation', 'callout' => 'A note with an icon', 'code' => 'Code with a language', 'divider' => 'A line across', 'image' => 'An image (upload or paste a link)', 'video' => 'A video file or link', 'audio' => 'An audio file',
    'file' => 'A file to download', 'pdf' => 'A PDF', 'bookmark' => 'A link card', 'embed' => 'An embedded page (allowed hosts)', 'equation' => 'A formula', 'table_of_contents' => 'The headings of this page', 'breadcrumb' => 'Where this page is', 'column_list' => 'Two columns side by side',
    'synced_block' => 'A block shown on several pages', 'table' => 'A simple table', 'link_to_page' => 'A link to a page', 'child_page' => 'A new page inside this one', 'child_database' => 'A database (slice 5)', 'link_preview' => 'A link preview', 'template' => 'A template button (Extended)', 'column' => '—', 'table_row' => '—', 'unsupported' => '—'];
$labels = ['heading_1' => 'Heading 1', 'heading_2' => 'Heading 2', 'heading_3' => 'Heading 3', 'bulleted_list_item' => 'Bulleted list', 'numbered_list_item' => 'Numbered list', 'to_do' => 'To-do', 'table_of_contents' => 'Table of contents', 'column_list' => 'Columns', 'synced_block' => 'Synced block', 'link_to_page' => 'Link to page', 'child_page' => 'Page', 'child_database' => 'Database', 'link_preview' => 'Link preview'];
$offer = array_values(array_filter($types, static fn (string $t): bool => !in_array($t, ['column', 'table_row', 'unsupported', 'template', 'breadcrumb', 'link_preview', 'child_database'], true))); ?>
<div class="sp-slash-menu card shadow d-none" id="slash-menu" role="listbox" aria-label="Block types">
    <div class="list-group list-group-flush" id="slash-menu-list">
        <?php foreach ($offer as $t): ?>
        <button type="button" class="list-group-item list-group-item-action sp-slash-item" role="option" data-type="<?= e($t) ?>" data-label="<?= e(strtolower($labels[$t] ?? ucfirst(str_replace('_', ' ', $t)))) ?>">
            <span class="fw-semibold"><?= e($labels[$t] ?? ucfirst(str_replace('_', ' ', $t))) ?></span><span class="fs-12 text-muted ms-2"><?= e($hints[$t] ?? '') ?></span>
        </button>
        <?php endforeach; ?>
    </div>
</div>
