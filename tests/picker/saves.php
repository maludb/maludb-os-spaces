<?php
/**
 * Saves through the hidden inputs: the form is fetched, parsed and submitted the way a browser submits it (every named control of the form, the
 * hidden input holding what the picker chose — here the id the picker's own rows carry), so a handler that needs no change proves it needs none.
 */
require __DIR__ . '/lib.php';
$w = picker_world();
$marco = as_member(27); $priya = as_member(26); $bea = as_member(28); $owner = as_member(1);
$product = (int) $w['product']; $leads = (int) $w['leads'];

/** The successful controls of a form in a page: name => value (checkboxes only when checked, the first option of a select without a selected one). */
function form_fields(string $html, string $formId): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $x = new DOMXPath($dom);
    $form = $x->query('//form[@id="' . $formId . '"]')->item(0);
    if ($form === null) { return []; }
    $out = [];
    foreach ($x->query('.//input|.//select|.//textarea', $form) as $el) {
        $name = $el->getAttribute('name');
        if ($name === '') { continue; }
        $type = strtolower($el->getAttribute('type'));
        if (in_array($type, ['submit', 'button', 'file'], true)) { continue; }
        if (in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')) { continue; }
        if ($el->nodeName === 'select') {
            $sel = $x->query('.//option[@selected]', $el)->item(0) ?? $x->query('.//option', $el)->item(0);
            $out[$name] = $sel ? $sel->getAttribute('value') : '';
        } else { $out[$name] = $el->nodeName === 'textarea' ? $el->textContent : $el->getAttribute('value'); }
    }
    return $out;
}
/** The id the picker offers for a label (what a person taps). */
function picked(string $jar, string $source, array $params, string $label): string
{
    [, $b] = pick($jar, $source, $params + ['q' => $label]);
    foreach (rows_of($b) as [$v, $l]) { if ($l === $label) { return $v; } }
    fwrite(STDERR, "picker offers no \"$label\" in $source\n");
    return '';
}
function submit(string $jar, string $path, array $fields): array
{
    $tok = csrf($jar);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => ['Accept: application/json'], 'form' => $fields + ['csrf_token' => $tok]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
function page_html(string $jar, string $path): string { return req('GET', $path, ['jar' => $jar])['body']; }

echo "-- add a person to a space\n";
$h = page_html($marco, "/spaces/$leads/members");
$f = form_fields($h, 'member-add-form');
ok(array_key_exists('member', $f) && $f['member'] === '' && ($f['space'] ?? '') == $leads, 'the form posts member (empty until picked), space, role…');
$id = picked($marco, 'member', ['space' => $leads], 'SMOKE Priya');
ok($id === '26', 'the picker\'s row for SMOKE Priya carries her member id (26)');
[$c, $b] = submit($marco, '/spaces/members/add.php', ['member' => $id] + $f);
ok($c === 200 && ($b['ok'] ?? false) === true, 'POST /spaces/members/add.php with the picked id: 200 ok (' . $c . ')');
ok((bool) one('SELECT EXISTS (SELECT 1 FROM space_members WHERE space_id = :s AND member_id = 26)', ['s' => $leads]), 'Priya is a member of the private space');
[, $b] = pick($marco, 'member', ['space' => $leads, 'q' => 'SMOKE Priya']);
ok(rows_of($b) === [], 'and the picker no longer offers her for that space');
[$c, $b] = submit($marco, '/spaces/members/add.php', ['member' => '', 'space' => $leads]);
ok($c === 422 || $c === 400, 'an empty pick is refused by the handler as before (' . $c . ')');

echo "-- share a page with a person, and with a guest\n";
$h = page_html($marco, "/pages/{$w['pricing']}/share");
$f = form_fields($h, 'share-form');
$dana = picked($marco, 'member', ['page' => $w['pricing']], 'SMOKE Dana');
ok($dana === '30', 'the picker offers SMOKE Dana (30)');
[$c, $b] = submit($marco, '/pages/share.php', ['member' => $dana, 'level' => 'comment'] + $f);
ok($c === 200 && ($b['ok'] ?? false) === true, 'POST /pages/share.php with the picked id: ok');
ok((bool) one("SELECT EXISTS (SELECT 1 FROM page_permissions WHERE page_id = CAST(:p AS uuid) AND principal_id = 30 AND level = 'comment')", ['p' => $w['pricing']]), 'Dana holds comment on the page');
$g = form_fields($h, 'share-guest-form');
$ann = picked($marco, 'guest', ['page' => $w['pricing']], one('SELECT display_name FROM members WHERE id = 29'));
ok($ann === '29' && array_key_exists('guest', $g), 'the guest form posts guest; the picker offers Ann (29)');
[$c, $b] = submit($marco, '/pages/share-guest.php', ['guest' => $ann, 'level' => 'view'] + $g);
ok($c === 200 && ($b['ok'] ?? false) === true, 'POST /pages/share-guest.php with the picked id: ok');

echo "-- add to a channel\n";
$h = page_html($marco, "/channels/{$w['leads_channel']}/members");
$f = form_fields($h, 'channel-member-add-form');
[, $b] = pick($marco, 'channel_member', ['channel' => $w['leads_channel'], 'q' => 'SMOKE Bea']);
ok(rows_of($b) === [], 'Bea is not in Product, so she is not offered for its channel (the database says "join the space first")');
$dana = picked($marco, 'channel_member', ['channel' => $w['leads_channel']], 'SMOKE Dana');
ok($dana === '30', 'Dana, a member of Product, is offered');
[$c, $b] = submit($marco, '/channels/members/add.php', ['member' => $dana] + $f);
ok($c === 200 && ($b['ok'] ?? false) === true && in_channel((int) $w['leads_channel'], 30), 'POST /channels/members/add.php with the picked id: Dana is in the channel');
[, $b] = pick($marco, 'channel_member', ['channel' => $w['leads_channel'], 'q' => 'SMOKE Dana']);
ok(rows_of($b) === [], 'and she is no longer offered');

echo "-- a new page: space and parent through the hidden inputs\n";
$h = page_html($marco, '/pages/new');
$f = form_fields($h, 'page-form');
ok(isset($f['space'], $f['parent'], $f['where']) && $f['space'] === '' && $f['parent'] === '', 'the form posts where, space and parent (hidden, empty)');
$sp = picked($marco, 'space_page', [], '🚀 SMOKE Product');
[$c, $b] = submit($marco, '/pages/save.php', ['title' => 'SMOKE Picker page', 'where' => 'space', 'space' => $sp, 'parent' => ''] + $f);
$made = page_by_title('SMOKE Picker page');
ok($c === 200 && $made !== null && (int) page_row($made)['space_id'] === $product, 'a page made at the Product root through the picked space id (' . $c . ')');
$par = picked($marco, 'page', ['editable' => 1], 'SMOKE Picker page');
ok($par === $made, 'the page picker offers it as a parent (by its UUID)');
[$c, $b] = submit($marco, '/pages/save.php', ['title' => 'SMOKE Picker child', 'where' => 'parent', 'space' => '', 'parent' => $par] + $f);
$kid = page_by_title('SMOKE Picker child');
ok($c === 200 && $kid !== null && page_row($kid)['parent_page_id'] === $made, 'a page made under it through the picked page id');

echo "-- move it\n";
$h = page_html($marco, "/pages/$kid/move");
$f = form_fields($h, 'move-form');
$leadsPick = picked($marco, 'space_page', [], 'SMOKE Design leads');
[$c, $b] = submit($marco, '/pages/move.php', ['where' => 'space', 'space' => $leadsPick, 'parent' => ''] + $f);
ok($c === 200 && (int) page_row($kid)['space_id'] === $leads, 'moved to another space through the picked space id (' . $c . ')');
[, $b] = pick($marco, 'page', ['editable' => 1, 'except' => $kid, 'q' => 'SMOKE Picker child']);
ok(rows_of($b) === [], 'and the page itself is not offered as its own parent');

echo "-- export a page; pin a page; a new channel\n";
$h = page_html($marco, '/exports/');
$f = form_fields($h, 'export-page-form');
$pg = picked($marco, 'page', ['pages_only' => 1], 'SMOKE Pricing');
[$c, $b] = submit($marco, '/exports/page.php', ['page' => $pg, 'format' => 'md'] + $f);
ok($c === 200 && ($b['ok'] ?? false) === true, 'POST /exports/page.php with the picked page id: ok (' . $c . ')');
$h = page_html($marco, "/channels/{$w['launch']}/pins");
$f = form_fields($h, 'pin-page-form');
[$c, $b] = submit($marco, '/channels/pins/add.php', ['page' => $pg] + $f);
ok($c === 200 && ($b['ok'] ?? false) === true, 'POST /channels/pins/add.php with the picked page id: ok (' . $c . ')');
$h = page_html($marco, '/channels/new');
$f = form_fields($h, 'channel-form');
ok(($f['space'] ?? '') !== '', 'the new-channel form posts a space on its first render (the first space, as the select did)');
[$c, $b] = submit($marco, '/channels/save.php', ['name' => 'smoke-picker', 'kind' => 'public', 'space' => $sp] + $f);
ok($c === 200 && channel_by_name($product, 'smoke-picker') !== null, 'POST /channels/save.php with the picked space id: the channel is made in Product (' . $c . ')');
finish();
