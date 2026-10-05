<?php
/**
 * The timeline (id database-rows): week columns by the view's start and end properties, a bar per row; ◂ ▸ weeks. Scrolls sideways inside its card.
 * Data: d, view, rows, schema, cols, may, here, week (Y-m-d, the first day shown), weeks (how many), base
 */
$sk = (string) $view['timeline_start'];
$ek = $view['timeline_end'] ?: $sk;
$start = new DateTimeImmutable($week);
$days = $weeks * 7;
$endDay = $start->modify('+' . ($days - 1) . ' days');
$nav = static fn (string $w): string => $base . (str_contains($base, '?') ? '&' : '?') . 'week=' . $w;
$title = static fn (array $r): string => display_value('title', $r['properties'][$d['title_property_key']] ?? []) ?: ($r['title'] !== '' ? $r['title'] : 'Untitled');
$bars = [];
$undated = 0;
foreach ($rows as $r) {
    $s = row_date($r, $sk, $schema);
    $e = row_date($r, $ek, $schema, true) ?? $s;
    if ($s === null) { $undated++; continue; }
    if ($e < $s) { $e = $s; }
    $bars[] = [$r, $s, $e];
}
$shown = 0;
?>
<div class="card" id="database-rows" data-week="<?= e($week) ?>"><div class="card-header d-flex align-items-center justify-content-between gap-2">
    <?= hx_link($nav($start->modify('-' . $weeks . ' weeks')->format('Y-m-d')), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch', 'id="timeline-prev" aria-label="Earlier"') ?>
    <h6 class="mb-0 text-center" id="timeline-range"><?= e($start->format('M j')) ?> – <?= e($endDay->format('M j, Y')) ?></h6>
    <?= hx_link($nav($start->modify('+' . $weeks . ' weeks')->format('Y-m-d')), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'id="timeline-next" aria-label="Later"') ?>
</div>
<div class="sp-table-wrap"><div class="sp-timeline" style="min-width: <?= $weeks * 140 ?>px">
    <div class="sp-timeline-head" id="timeline-head"><?php for ($i = 0; $i < $weeks; $i++): $ws = $start->modify('+' . ($i * 7) . ' days'); ?><div class="sp-timeline-week" id="timeline-week-<?= e($ws->format('Y-m-d')) ?>"><?= e($ws->format('M j')) ?></div><?php endfor; ?></div>
    <?php foreach ($bars as [$r, $s, $e]):
        $from = (int) $start->diff(new DateTimeImmutable($s))->format('%r%a');
        $to = (int) $start->diff(new DateTimeImmutable($e))->format('%r%a');
        if ($to < 0 || $from > $days - 1) { continue; }
        $l = max(0, $from); $w = min($days - 1, $to) - $l + 1; $shown++; ?>
        <div class="sp-timeline-row" id="timeline-row-<?= e($r['row_id']) ?>">
            <div class="sp-timeline-bar" style="left: <?= round($l / $days * 100, 3) ?>%; width: <?= round($w / $days * 100, 3) ?>%" title="<?= e($title($r) . ' · ' . $s . ($e !== $s ? ' → ' . $e : '')) ?>"><?= hx_link(row_url($d['database_id'], $r['row_id'], $here), e($title($r)), 'text-white text-truncate d-block') ?></div>
        </div>
    <?php endforeach; ?>
    <?php if ($shown === 0): ?><div class="p-3 text-muted" id="database-empty">No dated rows in these weeks.</div><?php endif; ?>
</div></div>
<div class="card-footer fs-12 text-muted" id="database-rows-foot"><?= count($bars) ?> row<?= count($bars) === 1 ? '' : 's' ?> with dates (<?= (int) $shown ?> in these weeks)<?= $undated > 0 ? ' · ' . $undated . ' without (' . e($schema[$sk]['name'] ?? $sk) . ')' : '' ?></div></div>
