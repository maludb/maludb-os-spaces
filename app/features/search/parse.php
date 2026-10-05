<?php
declare(strict_types=1);

/**
 * The typed query as an object (slice 6): `rate limit in:#ops from:@priya has:link before:2026-09-01 after:2026-08-01 is:page space:3` → the words and Slack's modifiers, parsed in PHP
 * into the filters sp_search() takes. A modifier is read once (the first wins; a second is dropped with a notice); a bad date or an unknown value is dropped with a notice; a word that
 * only looks like a modifier (`ratio:2`) stays a word. Pure: nothing here touches the database — naming a channel or a person is resolve_search_filters() in queries.php.
 */
const SEARCH_MODIFIERS = ['in', 'from', 'has', 'before', 'after', 'is', 'space'];
const SEARCH_IS = ['page', 'row', 'message', 'comment'];
const SEARCH_HAS = ['link', 'file'];

/** @return array{q: string, in: ?string, from: ?string, has: ?string, before: ?string, after: ?string, is: ?string, space: ?string, notices: list<string>} */
function parse_search_query(string $q): array
{
    $out = ['q' => '', 'in' => null, 'from' => null, 'has' => null, 'before' => null, 'after' => null, 'is' => null, 'space' => null, 'notices' => []];
    $words = [];
    // tokens: a "quoted phrase" (kept, with its quotes, as a word), a modifier with an optionally quoted value, or a bare word
    preg_match_all('/(?:[a-z]+:)?"[^"]*"|\S+/iu', $q, $m);
    foreach ($m[0] as $tok) {
        if (!preg_match('/^([a-z]+):(.*)$/iu', $tok, $t) || !in_array(strtolower($t[1]), SEARCH_MODIFIERS, true)) {
            $words[] = $tok;
            continue;
        }
        $mod = strtolower($t[1]);
        $val = trim($t[2], " \t\"");
        if ($val === '') {
            $out['notices'][] = 'Nothing after ' . $mod . ': so it was left out.';
            continue;
        }
        if ($out[$mod] !== null) {
            $out['notices'][] = 'Only one ' . $mod . ': is used; "' . mb_substr($val, 0, 40) . '" was left out.';
            continue;
        }
        switch ($mod) {
            case 'before':
            case 'after':
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $val);
                if ($d === false || $d->format('Y-m-d') !== $val) {
                    $out['notices'][] = '"' . mb_substr($val, 0, 40) . '" is not a date (use 2026-09-01), so ' . $mod . ': was left out.';
                    continue 2;
                }
                break;
            case 'is':
                $val = strtolower($val);
                if (!in_array($val, SEARCH_IS, true)) {
                    $out['notices'][] = 'is: takes ' . implode(', ', SEARCH_IS) . '; "' . mb_substr($val, 0, 40) . '" was left out.';
                    continue 2;
                }
                break;
            case 'has':
                $val = strtolower($val);
                if (!in_array($val, SEARCH_HAS, true)) {
                    $out['notices'][] = 'has: takes ' . implode(' or ', SEARCH_HAS) . '; "' . mb_substr($val, 0, 40) . '" was left out.';
                    continue 2;
                }
                break;
            case 'space':
                $val = ltrim($val, '#');
                break;
        }
        $out[$mod] = $val;
    }
    $out['q'] = trim(implode(' ', $words));
    return $out;
}

/** A parsed query back to text (what the chips and "Search again" are made of): the words, then the modifiers in a fixed order. */
function search_query_string(array $p, array $drop = []): string
{
    $parts = [];
    if (($p['q'] ?? '') !== '' && !in_array('q', $drop, true)) {
        $parts[] = $p['q'];
    }
    foreach (SEARCH_MODIFIERS as $mod) {
        if (($p[$mod] ?? null) !== null && !in_array($mod, $drop, true)) {
            $v = (string) $p[$mod];
            $parts[] = $mod . ':' . (preg_match('/\s/', $v) ? '"' . $v . '"' : $v);
        }
    }
    return implode(' ', $parts);
}

/** Whether a parsed query asks for anything at all (words or a modifier). */
function search_is_empty(array $p): bool
{
    foreach (['q', ...SEARCH_MODIFIERS] as $k) {
        if (($p[$k] ?? null) !== null && $p[$k] !== '') { return false; }
    }
    return true;
}
