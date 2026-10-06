<?php
/**
 * Helpers for the slice 8 proofs (docs/build-specs/worker-import-export.md, "Proof"). Builds on slice 7's lib (the chain: 7 → 6 → 5 → 4 → 3 → 2 → 1 → Phase 2). The worker is `php bin/worker.php`, run as a
 * process against the scratch database, the fake kernel (K6's states, the chat endpoint) and the fake MaluMail (:8406, its log shows what was sent); SP_WORKER_NOW drives what PHP judges. Fixtures — a Markdown
 * file, a Markdown zip, a Notion zip, a CSV, a damaged zip and a large one — are built once into tests/phase3/slice8/fixtures/ by fixtures(). Everything a proof makes is named "SMOKE …".
 */
require dirname(__DIR__) . '/slice7/lib.php';

const FIX = __DIR__ . '/fixtures';

/** The worker as a process: [decoded JSON line, exit code, raw]. $args e.g. ['--only=outbox']; $env e.g. ['SP_WORKER_NOW' => '2026-10-06T08:00:00Z']. */
function run_worker(array $args = [], array $env = []): array
{
    $cmd = '';
    foreach ($env as $k => $v) { $cmd .= $k . '=' . escapeshellarg((string) $v) . ' '; }
    $cmd .= 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null; echo "EXIT:$?"';
    $out = trim((string) shell_exec($cmd));
    preg_match('/EXIT:(\d+)$/', $out, $m);
    $raw = trim(preg_replace('/EXIT:\d+$/', '', $out));
    return [json_decode($raw, true) ?? [], (int) ($m[1] ?? -1), $raw];
}
/** One step by name: its counts. */
function step(string $name, array $env = []): array { [$r] = run_worker(['--only=' . $name], $env); return $r['steps'][$name] ?? ['_missing' => true] + $r; }

function mail_log(): array { $f = need('FAKE_MALUMAIL_LOG'); return array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) @file_get_contents($f))))); }
function mail_count(): int { return count(mail_log()); }
function sms_log(): array { $f = need('FAKE_KERNEL_STATE') . '.sms'; return array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) @file_get_contents($f))))); }
function k6(array $s): void { kernel_state(function ($st) use ($s) { $st['sms'] = $s; return $st; }); }
function outbox_rows(?string $kind = null, ?int $member = null, ?int $since = null): array
{
    return q('SELECT id, channel, member_id, kind, status, attempts, detail, provider_ref, subject, body, send_after, to_email FROM notification_outbox WHERE id > :s AND (CAST(:k AS text) IS NULL OR kind = :k) AND (CAST(:m AS bigint) IS NULL OR member_id = :m) ORDER BY id', ['s' => $since ?? 0, 'k' => $kind, 'm' => $member]);
}
function last_outbox_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM notification_outbox'); }
/** Something to flush: every queued outbox row sent or skipped, so a proof starts from nothing waiting. */
function drain_outbox(): void { pdo()->exec("UPDATE notification_outbox SET status = 'skipped', detail = 'drained' WHERE status = 'queued'"); }

/** A multipart POST of one file as a signed-in member, answered as JSON: [status, body, raw]. */
function act_file(string $jar, string $path, array $form, string $filePath, ?string $name = null, string $field = 'file'): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => JSONH,
        CURLOPT_POSTFIELDS => $form + ['csrf_token' => $tok[$jar], $field => new CURLFile($filePath, 'application/octet-stream', $name ?? basename($filePath))]]);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, json_decode(substr($r, $hs), true) ?? [], substr($r, $hs)];
}
function import_row(int $id): array { return q('SELECT id, kind, file_name, status, pages_made, rows_made, blocks_made, unsupported, log, target_space_id, target_page_id::text AS target_page_id, target_database_id::text AS target_database_id FROM imports WHERE id = :i', ['i' => $id])[0] ?? []; }
function export_row(int $id): array { return q('SELECT id, kind, format, status, storage_path, byte_size, item_count, log, created_by, expires_at, downloaded_at, params::text AS params FROM exports WHERE id = :i', ['i' => $id])[0] ?? []; }
/** The files of a zip: [name => bytes]. */
function zip_files(string $path): array
{
    $z = new ZipArchive();
    if ($z->open($path) !== true) { return []; }
    $o = [];
    for ($i = 0; $i < $z->numFiles; $i++) { $o[$z->getNameIndex($i)] = (string) $z->getFromIndex($i); }
    $z->close();
    return $o;
}
/** An export's file on disk (full path) or ''. */
function export_path(int $id): string { $p = one('SELECT storage_path FROM exports WHERE id = :i', ['i' => $id]); return $p ? dirname(__DIR__, 3) . '/storage/' . $p : ''; }
function page_types(string $page): array { return array_column(q('SELECT DISTINCT type FROM blocks WHERE page_id = CAST(:p AS uuid) ORDER BY type', ['p' => $page]), 'type'); }
function page_by_exact(string $title, ?string $parent = null): ?string
{
    $v = one('SELECT id::text FROM pages WHERE plain_title = :t AND archived_at IS NULL AND parent_database_id IS NULL AND (CAST(:p AS uuid) IS NULL OR parent_page_id = CAST(:p AS uuid)) ORDER BY created_at DESC LIMIT 1', ['t' => $title, 'p' => $parent]);
    return $v === false || $v === null ? null : (string) $v;
}

/** SQL as the cluster's superuser on the scratch database — for what the application's own role may not do (versions are never rewritten; a proof ages them). */
function as_postgres(string $sql): void { $o = shell_exec('sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1'); if (trim((string) $o) !== '') { fwrite(STDERR, 'as_postgres: ' . $o); } }

/** The fixtures, built once: notes.md, tree.zip, notion.zip, tasks.csv, bad.zip, big.zip. */
function fixtures(): array
{
    if (!is_dir(FIX)) { mkdir(FIX, 0775, true); }
    $png = function (): string { $im = imagecreatetruecolor(16, 12); imagefill($im, 0, 0, imagecolorallocate($im, 52, 84, 209)); ob_start(); imagepng($im); return (string) ob_get_clean(); };
    $zip = function (string $name, array $files, bool $store = false): string {
        $p = FIX . '/' . $name;
        if (is_file($p)) { return $p; }
        $z = new ZipArchive();
        $z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $n => $b) { $z->addFromString($n, $b); if ($store) { $z->setCompressionName($n, ZipArchive::CM_STORE); } }
        $z->close();
        return $p;
    };
    if (!is_file(FIX . '/notes.md')) {
        file_put_contents(FIX . '/notes.md', <<<'MD'
# SMOKE Imported notes

Intro with **bold**, *italic*, `code`, ~~struck~~ and [a link](https://example.com/x).

# Heading one

## Section two

### Section three

- bullet one
  - nested bullet
- bullet two

1. first
2. second

- [ ] todo open
- [x] todo done

<details><summary>Toggle title</summary>

Hidden text

</details>

> A quote

> [!NOTE] 💡 A callout

```python
print("hi")
```

---

$$
E = mc^2
$$

![An image](https://example.com/pic.png)

https://example.com/bookmark

[[SMOKE Runbook]]

| a | b |
|---|---|
| 1 | 2 |
MD);
    }
    $tasks = "Task,Points,Done,Due,Kind,Tags,Link,Notes,SMOKE Epics\n"
        . "SMOKE Draft spec,3,yes,2026-10-01,Feature,\"ui,db\",https://example.com/spec,\"A long note that goes past one hundred characters so that this column can only be plain text and never a select, since an option is at most a hundred.\",SMOKE Epic One\n"
        . "SMOKE Build it,8,no,2026-10-20,Feature,ui,https://example.com/build,short,SMOKE Epic One\n"
        . "SMOKE Test it,5,no,2026-10-25,Bug,\"db,ops\",https://example.com/test,second,SMOKE Epic Two\n"
        . "SMOKE Ship it,2,no,2026-11-01,Feature,ops,https://example.com/ship,third,\"SMOKE Epic One,SMOKE Epic Two\"\n"
        . "SMOKE Retro,1,yes,2026-11-05,Bug,ui,https://example.com/retro,fourth,SMOKE Epic Two\n";
    $epics = "Epic,Owner\nSMOKE Epic One,Priya\nSMOKE Epic Two,Marco\n";
    $out = [];
    $out['md'] = FIX . '/notes.md';
    $out['tree'] = $zip('tree.zip', [
        'SMOKE Handbook.md' => "# SMOKE Handbook\n\nWelcome to the handbook.\n",
        'SMOKE Handbook/SMOKE Onboarding.md' => "# SMOKE Onboarding\n\nDay one checklist.\n\n![The logo](img/logo.png)\n\n![A web picture](https://example.com/web.png)\n",
        'SMOKE Handbook/img/logo.png' => $png(),
        'SMOKE Handbook/SMOKE Policies/SMOKE Leave.md' => "## Leave\n\nTwenty days.\n",
        'SMOKE Handbook/SMOKE Tasks.csv' => $tasks,
        'SMOKE Handbook/SMOKE Epics.csv' => $epics,
        '__MACOSX/._junk' => 'x', 'SMOKE Handbook/.DS_Store' => 'x',
    ]);
    $out['notion'] = $zip('notion.zip', [
        'SMOKE Projects 1a2b3c4d5e6f40718293a4b5c6d7e8f9.md' => "# SMOKE Projects\n\nCreated: October 5, 2026\n\nThe projects page.\n",
        'SMOKE Projects 1a2b3c4d5e6f40718293a4b5c6d7e8f9/SMOKE Roadmap 0123456789abcdef0123456789abcdef.md' => "# SMOKE Roadmap\n\nIntro.\n\n#### A fourth level heading\n\n<meeting-notes>\n\nNotes from the meeting.\n\n</meeting-notes>\n\n<transcription>\n\nWords.\n\n</transcription>\n\nAfter.\n",
        'SMOKE Backlog 9f8e7d6c5b4a39281706f5e4d3c2b1a0.csv' => "Name,Status,Tags\nSMOKE Write the spec,Todo,\"a,b\"\nSMOKE Review the spec,Todo,a\nSMOKE Ship the spec,Done,b\n",
        'SMOKE Backlog 9f8e7d6c5b4a39281706f5e4d3c2b1a0/SMOKE Write the spec aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.md' => "# SMOKE Write the spec\n\nThe row's own page.\n",
    ]);
    $out['csv'] = FIX . '/tasks.csv';
    if (!is_file($out['csv'])) {
        file_put_contents($out['csv'], "Task,Points,Kind,Impact,Urgent\nSMOKE Csv one,4,Bug,High,yes\nSMOKE Csv two,6,Feature,Low,no\nSMOKE Csv three,1,Feature,High,no\n");
    }
    if (!is_file(FIX . '/bad.zip')) { file_put_contents(FIX . '/bad.zip', "this is not a zip file at all\n"); }
    $out['bad'] = FIX . '/bad.zip';
    $out['evil'] = $zip('evil.zip', ['../escape.md' => "# escape\n"]);
    $out['big'] = $zip('big.zip', ['SMOKE Big.md' => "# SMOKE Big import\n\nA small page in a big zip.\n", 'padding/blob.dat' => random_bytes(3 * 1024 * 1024 + 1000)], true);
    return $out;
}

/** The world of the slice: slice 7's (agents, channels, pages) and slice 5's (databases, relations). */
function worker_world(): array
{
    $w = agents_world();
    $d = databases_world();
    return $w + ['tasks' => $d['tasks'], 'epics' => $d['epics'], 'fix' => $d['fix'], 'build' => $d['build'], 'ship' => $d['ship'], 'launch_epic' => $d['launch']];
}

/** The kernel's role of a member, changed through the change feed (the proof's way to make Dana a space owner): roles e.g. ['space_owner', 'user']. */
function give_roles(int $member, array $roles): void
{
    static $n = 0;
    $cap = in_array('admin', $roles, true) ? 'admin' : (in_array('guest', $roles, true) ? 'read' : 'write');
    kernel_state(function ($s) use ($member, $roles, $cap, &$n) { $s['incremental'] = incr(['access' => [['member_id' => $member, 'role' => $roles[0], 'roles' => $roles, 'rights' => [], 'capability' => $cap, 'scopes' => []]]], '2026-10-05T01:00:0' . (++$n % 10) . '.000000Z'); return $s; });
    sync();
    kernel_state(function ($s) { unset($s['incremental']); return $s; });
}

/** A multipart POST of one file under an action token (no session): [status, body]. */
function act_file_token(string $path, array $form, string $filePath, array $headers, ?string $name = null): array
{
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => array_merge(JSONH, $headers),
        CURLOPT_POSTFIELDS => $form + ['file' => new CURLFile($filePath, 'application/octet-stream', $name ?? basename($filePath))]]);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($r, true) ?? ['raw' => $r]];
}

/** The ids the browser proof drives: a finished page export, a queued space export (the worker will make it while the page polls), a queued big import, a finished and a failed import, a stored preview of the tree zip, a channel with retention. */
function worker_browser_world(): array
{
    $w = worker_world(); $fx = fixtures();
    $marco = as_member(27);
    $run = substr(md5((string) microtime(true)), 0, 6);
    $pg = mk_in($marco, $w['product'], "SMOKE browser export page $run");
    act($marco, '/blocks/append.php', ['page' => $pg, 'markdown' => 'Words for the browser export.']);
    [, $b] = act($marco, '/exports/page.php', ['page' => $pg, 'format' => 'md']); $done = (int) $b['record_id'];
    [, $b] = act($marco, '/exports/page.php', ['page' => $pg, 'format' => 'html']); $expired = (int) $b['record_id'];
    pdo()->exec("UPDATE exports SET expires_at = now() - interval '1 day' WHERE id = $expired");
    step('exports');
    [, $b] = act($marco, '/exports/space.php', ['space' => $w['product'], 'format' => 'md']); $queued = (int) $b['record_id'];
    [, $b] = act_file($marco, '/import/start.php', ['space' => $w['product']], $fx['tree']); $doneImport = (int) $b['record_id'];
    [, $b] = act_file($marco, '/import/start.php', ['space' => $w['product']], $fx['bad'], 'broken.zip'); $failedImport = (int) $b['record_id'];
    [, $b] = act_file($marco, '/import/start.php', ['space' => $w['product'], 'preview' => 'yes'], $fx['notion']);
    parse_str((string) parse_url((string) $b['location'], PHP_URL_QUERY), $q);
    [, $b] = act_file($marco, '/import/start.php', ['space' => $w['product']], $fx['big']); $queuedImport = (int) $b['record_id'];
    act($marco, '/channels/retention.php', ['channel' => $w['launch'], 'days' => 7]);
    return ['product' => $w['product'], 'launch' => $w['launch'], 'tasks' => $w['tasks'], 'page' => $pg, 'done' => $done, 'expired' => $expired, 'queued' => $queued, 'done_import' => $doneImport, 'failed_import' => $failedImport,
            'queued_import' => $queuedImport, 'preview' => $q['preview'], 'tree_zip' => $fx['tree'], 'notion_zip' => $fx['notion'], 'md' => $fx['md']];
}
