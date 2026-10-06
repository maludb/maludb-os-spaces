<?php /** Home — the Librarian's note (`home-note`): the last message by the Librarian in the admin channel, shown to that channel's members. Data: s, tz, here */ $n = $s['librarian_note']; ?>
<div class="card h-100" id="home-note"><div class="card-header"><h5 class="card-title mb-0">The Librarian's note</h5></div>
    <div class="card-body">
        <div class="fs-13 text-break" id="home-note-text"><?= nl2br(e($n['excerpt'])) ?></div>
        <div class="fs-11 text-muted mt-2"><?= e((string) $n['author_name']) ?> · <?= e(format_ts($n['sent_at'], $tz, 'M j, g:i A')) ?> · <?= hx_link(with_back('/channels/' . $n['channel_id'] . '?message=' . $n['message_id'], $here), '#' . e((string) $n['channel_name']), 'text-muted', 'id="home-note-open"') ?></div>
    </div>
</div>
