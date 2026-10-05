<?php
declare(strict_types=1);

/** A plain line diff (an LCS over lines) for the version page: [['op' => ' ' | '-' | '+', 'line' => …], …]. */
function line_diff(string $a, string $b): array
{
    $x = preg_split('/\r\n|\r|\n/', rtrim($a)) ?: [];
    $y = preg_split('/\r\n|\r|\n/', rtrim($b)) ?: [];
    $n = count($x);
    $m = count($y);
    if ($n * $m > 4000000) {                                                   // a page that big is compared by whole
        return $a === $b ? array_map(static fn ($l) => ['op' => ' ', 'line' => $l], $x) : array_merge(array_map(static fn ($l) => ['op' => '-', 'line' => $l], $x), array_map(static fn ($l) => ['op' => '+', 'line' => $l], $y));
    }
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $L[$i][$j] = $x[$i] === $y[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
        }
    }
    $out = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($x[$i] === $y[$j]) { $out[] = ['op' => ' ', 'line' => $x[$i]]; $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $out[] = ['op' => '-', 'line' => $x[$i]]; $i++; }
        else { $out[] = ['op' => '+', 'line' => $y[$j]]; $j++; }
    }
    while ($i < $n) { $out[] = ['op' => '-', 'line' => $x[$i++]]; }
    while ($j < $m) { $out[] = ['op' => '+', 'line' => $y[$j++]]; }
    return $out;
}
