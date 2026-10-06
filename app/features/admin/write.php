<?php
declare(strict_types=1);
/** Reading the workspace settings form (slice 9): every field optional ("left out stays as it was"), every refusal a sentence naming its field. The database's CHECKs stand behind each number (db/005). */
require_once __DIR__ . '/queries.php';

/** Hosts from a textarea (one per line), a list or a comma-separated string: lower-cased, no scheme, no path, unique; a bad one is a field error. */
function embed_hosts_from(mixed $raw, array &$errors): array
{
    $items = is_array($raw) ? $raw : preg_split('/[\r\n,;]+/', (string) $raw);
    $out = [];
    foreach ($items as $i) {
        $h = strtolower(trim((string) $i));
        if ($h === '') { continue; }
        $h = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $h) ?? $h;
        $h = explode('/', $h, 2)[0];
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $h) || !str_contains($h, '.')) { $errors['allowed_embed_hosts'] = '"' . mb_substr(trim((string) $i), 0, 60) . '" is not a host name (like www.youtube.com).'; continue; }
        $out[$h] = true;
    }
    return array_keys($out);
}

/** The fields to change, checked: [column => value]; adds to $errors (field => sentence). */
function settings_from_request(PDO $pdo, array $cur, array &$errors): array
{
    $f = [];
    if (req_has('business_name')) { $v = (string) req_val('business_name'); if (mb_strlen($v) > 120) { $errors['business_name'] = 'The business name is at most 120 characters.'; } else { $f['business_name'] = $v === '' ? null : $v; } }
    foreach (SETTINGS_FIELDS as $k => $def) {
        $kind = $def[1];
        if ($kind === 'enum' && req_has($k)) {
            $v = (string) req_val($k);
            if (!array_key_exists($v, $def[2])) { $errors[$k] = $def[0] . ': choose one of ' . implode(', ', array_map('strval', array_keys($def[2]))) . '.'; } else { $f[$k] = is_int($cur[$k]) ? (int) $v : $v; }
        } elseif ($kind === 'int' && $k !== 'max_attachment_mb') {
            $v = sp_int($k, $cur[$k], $def[2][0], $def[2][1], $def[0], $errors);
            if (req_has($k) && !isset($errors[$k])) { $f[$k] = $v; }
        } elseif ($kind === 'bool' && req_has($k)) {
            $f[$k] = sp_yes($k, $cur[$k]);
        } elseif ($kind === 'url' && req_has($k)) {
            $v = rtrim((string) req_val($k), '/');
            if ($v === '') { $f[$k] = null; } elseif (!preg_match('#^https?://[^\s/]+[^\s]*$#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false) { $errors[$k] = 'The public base URL starts with https:// (or http://).'; } else { $f[$k] = $v; }
        } elseif ($kind === 'timezone' && req_has($k)) {
            $v = (string) req_val($k);
            if (!in_array($v, timezone_identifiers_list(), true)) { $errors[$k] = '"' . mb_substr($v, 0, 60) . '" is not a time zone (like America/Chicago).'; } else { $f[$k] = $v; }
        } elseif ($kind === 'slug' && req_has($k)) {
            $v = strtolower(ltrim((string) req_val($k), '#'));
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', $v)) { $errors[$k] = 'A channel name is lower-case letters, digits, - and _.'; } else { $f[$k] = $v; }
        } elseif ($kind === 'lines' && req_has($k)) {
            $f[$k] = embed_hosts_from($_POST[$k], $errors);
        }
    }
    // The attachment limit: MB from the form, bytes from an agent; the table's bound is 1 MB to 1 GB.
    if (req_has('max_attachment_mb') && (string) req_val('max_attachment_mb') !== '') {
        $mb = sp_int('max_attachment_mb', null, 1, 1024, 'The largest attachment', $errors);
        if ($mb !== null) { $f['max_attachment_bytes'] = $mb * 1048576; }
    } elseif (req_has('max_attachment_bytes')) {
        $b = sp_int('max_attachment_bytes', null, 1048576, 1073741824, 'The largest attachment (bytes)', $errors);
        if ($b !== null) { $f['max_attachment_bytes'] = $b; }
    }
    return $f;
}
