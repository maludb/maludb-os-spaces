<?php /** Accept (`proposal-accept-{id}`): a thread_to_page one asks where the draft goes. Data: p, dest = {spaces, pages} */ $id = (int) $p['proposal_id']; $ask = $p['kind'] === 'thread_to_page' && $p['proposed_page_id'] !== null; ?>
<form method="post" action="/proposals/accept.php" hx-post="/proposals/accept.php" hx-target="#flash" class="d-flex gap-2 flex-grow-1" id="proposal-accept-<?= $id ?>"><?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $id ?>">
    <?php if ($ask): ?>
    <select name="parent" class="form-select btn-touch" aria-label="Where the draft goes" id="proposal-accept-<?= $id ?>-parent" required>
        <option value="">Where does the draft go?</option>
        <optgroup label="Top of a space">
            <?php foreach ($dest['spaces'] as $s): ?><option value="space:<?= (int) $s['space_id'] ?>"><?= e((string) $s['name']) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="Under a page">
            <?php foreach ($dest['pages'] as $pg): ?><option value="<?= e($pg['page_id']) ?>"><?= e((string) $pg['space_name']) ?> › <?= e((string) ($pg['plain_title'] ?: 'Untitled')) ?></option><?php endforeach; ?>
        </optgroup>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-touch flex-shrink-0" id="proposal-accept-<?= $id ?>-btn"><?= $ask ? 'Accept' : 'Accept and open' ?></button>
</form>
