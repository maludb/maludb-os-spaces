<?php /** The feed (polled every 30 s: 204 when nothing changed). Data: rows, since, kind, hash, tz, here */ ?>
<div id="activity-list" hx-get="/activity?list=1&amp;since=<?= e($since) ?>&amp;kind=<?= e($kind) ?>&amp;h=<?= e($hash) ?>" hx-trigger="every 30s [document.visibilityState=='visible']" hx-swap="outerHTML" hx-target="this">
    <?php if ($rows === []): ?>
        <div class="card" id="activity-empty"><div class="card-body"><div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-activity"></i></span>
            <div><div class="fw-semibold">Nothing in this window</div><div class="fs-12 text-muted">Mentions of you, replies to you, reactions to your messages and comments on your pages show up here.</div></div></div></div></div>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): ?><?= view('activity/partials/row.php', ['r' => $r, 'n' => $i + 1, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?>
</div>
