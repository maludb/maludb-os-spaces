<?php
/**
 * The calendar (id database-rows): a month grid by the view's date property, rows on their start date, a day's overflow "+n"; ◂ ▸ months. At 375 px the grid gives way to a week list.
 * Data: d, view, rows, schema, cols, may, here, month (Y-m), wk (week start 0-6), base (the view's URL without month)
 */
$key = (string) $view['calendar_by'];
$first = new DateTimeImmutable($month . '-01');
$last = $first->modify('last day of this month');
$gridStart = $first->modify('-' . ((((int) $first->format('w')) - $wk + 7) % 7) . ' days');
$gridEnd = $last->modify('+' . ((($wk + 6 - (int) $last->format('w')) + 7) % 7) . ' days');
$byDay = [];
$undated = 0;
foreach ($rows as $r) {
    $day = row_date($r, $key, $schema);
    if ($day === null) { $undated++; continue; }
    $byDay[$day][] = $r;
}
$prev = $first->modify('-1 month')->format('Y-m');
$next = $first->modify('+1 month')->format('Y-m');
$nav = static fn (string $m): string => $base . (str_contains($base, '?') ? '&' : '?') . 'month=' . $m;
$title = static fn (array $r): string => display_value('title', $r['properties'][$d['title_property_key']] ?? []) ?: ($r['title'] !== '' ? $r['title'] : 'Untitled');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
?>
<div class="card" id="database-rows" data-month="<?= e($month) ?>"><div class="card-header d-flex align-items-center justify-content-between gap-2">
    <?= hx_link($nav($prev), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch', 'id="calendar-prev" aria-label="Previous month"') ?>
    <h6 class="mb-0" id="calendar-month"><?= e($first->format('F Y')) ?></h6>
    <?= hx_link($nav($next), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'id="calendar-next" aria-label="Next month"') ?>
</div>
<div class="card-body p-2">
    <div class="sp-cal" id="calendar-grid">
        <?php for ($i = 0; $i < 7; $i++): ?><div class="sp-cal-dow"><?= e($gridStart->modify('+' . $i . ' days')->format('D')) ?></div><?php endfor; ?>
        <?php for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')): $ds = $day->format('Y-m-d'); $in = $day->format('Y-m') === $month; $list = $byDay[$ds] ?? []; ?>
            <div class="sp-cal-day<?= $in ? '' : ' sp-cal-out' ?><?= $ds === $today ? ' sp-cal-today' : '' ?>" id="calendar-day-<?= e($ds) ?>" data-date="<?= e($ds) ?>">
                <div class="sp-cal-num"><?= (int) $day->format('j') ?></div>
                <?php foreach (array_slice($list, 0, 3) as $r): ?><div class="sp-cal-chip text-truncate"><?= hx_link(row_url($d['database_id'], $r['row_id'], $here), e($title($r)), 'text-dark') ?></div><?php endforeach; ?>
                <?php if (count($list) > 3): ?><details class="sp-cal-more"><summary class="fs-12 text-primary">+<?= count($list) - 3 ?></summary><?php foreach (array_slice($list, 3) as $r): ?><div class="sp-cal-chip text-truncate"><?= hx_link(row_url($d['database_id'], $r['row_id'], $here), e($title($r)), 'text-dark') ?></div><?php endforeach; ?></details><?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
    <div class="sp-cal-weeks" id="calendar-weeks">
        <?php $wn = 0; for ($ws = $gridStart; $ws <= $gridEnd; $ws = $ws->modify('+7 days')): $any = false; $html = ''; for ($k = 0; $k < 7; $k++) { $day = $ws->modify('+' . $k . ' days'); $ds = $day->format('Y-m-d'); if ($day->format('Y-m') !== $month) { continue; } if (!empty($byDay[$ds])) { $any = true; } } ?>
            <div class="mb-3" id="calendar-week-<?= e($ws->format('Y-m-d')) ?>">
                <div class="fs-12 text-muted fw-semibold mb-1">Week of <?= e($ws->format('M j')) ?></div>
                <?php for ($k = 0; $k < 7; $k++): $day = $ws->modify('+' . $k . ' days'); $ds = $day->format('Y-m-d'); if ($day->format('Y-m') !== $month) { continue; } $list = $byDay[$ds] ?? []; ?>
                    <div class="d-flex gap-2 py-1 border-top" id="calendar-weekday-<?= e($ds) ?>"><div class="sp-cal-wdate<?= $ds === $today ? ' text-primary fw-bold' : '' ?>"><?= e($day->format('D j')) ?></div>
                        <div class="flex-grow-1"><?php foreach ($list as $r): ?><div><?= hx_link(row_url($d['database_id'], $r['row_id'], $here), e($title($r)), 'text-dark') ?></div><?php endforeach; ?><?php if ($list === []): ?><span class="text-muted fs-12">—</span><?php endif; ?></div></div>
                <?php endfor; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>
<div class="card-footer fs-12 text-muted" id="database-rows-foot"><?= count($rows) - $undated ?> dated row<?= count($rows) - $undated === 1 ? '' : 's' ?><?= $undated > 0 ? ' · ' . $undated . ' without a date (' . e($schema[$key]['name'] ?? $key) . ')' : '' ?></div></div>
