<?php
/**
 * Proof — coverage: every tool the surface names (62 records + 6 activity) was CALLED at least once by the proofs of this run (lib.php's mcp_tool() records each call in $SP_DEV_STATE/p4-called.txt), and every question of design §7 has a tool.
 * Run last, after the others: PROOFS="coverage" alone has nothing to read.
 */
require __DIR__ . '/lib.php';
$S = surface();
$called = array_count_values(array_filter(array_map('trim', file(need('SP_DEV_STATE') . '/p4-called.txt'))));
ok(count($called) > 0, 'the proofs of this run recorded their calls (' . array_sum($called) . ' calls, ' . count($called) . ' distinct tools)');
$never = array_values(array_filter(array_merge($S['records'], $S['activity']), fn ($t) => !isset($called[$t])));
ok($never === [], 'every one of the surface\'s ' . (count($S['records']) + count($S['activity'])) . ' tools was called at least once' . ($never ? ' — never called: ' . implode(', ', $never) : ''));
$stray = array_values(array_diff(array_keys($called), array_merge($S['records'], $S['activity'])));
ok($stray === [], 'and no call named a tool the surface does not have' . ($stray ? ' — ' . implode(', ', $stray) : ''));
$doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/spaces-design.md');
$a7 = strpos($doc, "\n## 7."); $b7 = strpos($doc, "\n## 8.");
preg_match_all('/^\| (S\d|P\d+|D\d|C\d+|Q\d|G\d|ACT\d) \|/m', substr($doc, $a7, $b7 - $a7), $qs);
$cov = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/spaces-mcp-tool-surface.md');
$missing = array_filter(array_unique($qs[1]), fn ($q) => !preg_match('/\| ' . preg_quote($q, '/') . ' \|/', $cov) && !preg_match('/\b' . preg_quote($q, '/') . '\b/', $cov));
ok($missing === [], 'every question of design §7 (' . count(array_unique($qs[1])) . ' of them) appears in the surface\'s coverage table' . ($missing ? ' — missing: ' . implode(', ', $missing) : ''));
finish();
