<?php /** My status line (status_set). Data: status (my_status() or null), tz */ $until = $status['until'] ?? null; ?>
<div class="card mb-3" id="status">
    <div class="card-header"><h5 class="card-title mb-0">Your status</h5></div>
    <div class="card-body">
        <?php if ($status !== null): ?>
            <div class="mb-3 d-flex align-items-center gap-2" id="status-current"><span class="fs-5" id="status-current-emoji"><?= e($status['emoji'] ?? '') ?></span><span class="fw-semibold" id="status-current-text"><?= e($status['text'] ?? '') ?></span>
                <?php if ($until !== null): ?><span class="fs-12 text-muted" id="status-current-until">until <?= e(format_ts($until, $tz, 'M j, g:i A')) ?></span><?php endif; ?></div>
        <?php else: ?>
            <div class="fs-12 text-muted mb-3" id="status-none">No status set. People see it beside your name.</div>
        <?php endif; ?>
        <form method="post" action="/settings/status.php" hx-post="/settings/status.php" hx-target="#flash" id="status-form" class="row g-2">
            <?= csrf_field() ?><input type="hidden" name="return_to" value="/settings/">
            <div class="col-3"><label class="form-label fs-12 text-muted" for="status-form-field-emoji">Emoji</label><input type="text" name="emoji" id="status-form-field-emoji" class="form-control btn-touch text-center" maxlength="16" placeholder="🏖️" value="<?= e($status['emoji'] ?? '') ?>"></div>
            <div class="col-9"><label class="form-label fs-12 text-muted" for="status-form-field-text">What's up</label><input type="text" name="text" id="status-form-field-text" class="form-control btn-touch" maxlength="100" placeholder="In a meeting until 3" value="<?= e($status['text'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="status-form-field-until">Until (empty: until you clear it)</label><input type="datetime-local" name="until" id="status-form-field-until" class="form-control btn-touch" value="<?= e($until !== null ? format_ts($until, $tz, 'Y-m-d\TH:i') : '') ?>"></div>
            <div class="col-8"><button type="submit" class="btn btn-primary btn-touch w-100" id="status-form-save-btn">Set status</button></div>
            <div class="col-4"><button type="submit" class="btn btn-light btn-touch w-100" id="status-form-clear-btn" name="clear" value="1" <?= $status === null ? 'disabled' : '' ?>>Clear</button></div>
        </form>
    </div>
</div>
