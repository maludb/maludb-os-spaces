<?php
/**
 * Router for `php -S 127.0.0.1:8401 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-spaces.conf), so the
 * proofs run without Apache: /sso, /sso/logout, /api/v1/health, the public door /p/{token}, the attachment door /files/{id},
 * UUID records (/pages/{uuid}, /pages/{uuid}/edit …), a page of a record (/channels/12/members) and the canonical URLs
 * (/x/new, /x/{id}/edit, /x/{id}, /x → x.php or x/index.php). Anything that is a real file is served as it is; a rewrite to
 * a file its slice has not built yet answers 404, as Apache does.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/html';
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.webmanifest')) { header('Content-Type: application/manifest+json'); readfile($root . $path); return true; }
    return false;                                             // a static file or a real .php file
}
if (str_starts_with($path, '/api/') && !preg_match('#^/api/v1/health/?$#', $path)) { http_response_code(404); echo 'Not found'; return true; }
$map = ['/sso' => '/sso.php', '/sso/logout' => '/sso/logout.php', '/api/v1/health' => '/api/v1/health.php'];
$rel = rtrim($path, '/') ?: '/';
$set = static function (array $kv): void { foreach ($kv as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; } };
$UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
if (preg_match('#^/p/([a-f0-9]{48})/?$#', $path, $m)) { $set(['token' => $m[1]]); $target = '/p.php'; }
elseif (preg_match('#^/p/([a-f0-9]{48})/files/([0-9]+)$#', $path, $m)) { $set(['token' => $m[1], 'file' => $m[2]]); $target = '/p.php'; }
elseif (preg_match('#^/p/([a-f0-9]{48})/(' . $UUID . ')$#', $path, $m)) { $set(['token' => $m[1], 'page' => $m[2]]); $target = '/p.php'; }
elseif (preg_match('#^/files/([0-9]+)$#', $path, $m)) { $set(['id' => $m[1]]); $target = '/files.php'; }
elseif (preg_match('#^/files/([0-9]+)/thumb$#', $path, $m)) { $set(['id' => $m[1], 'thumb' => '1']); $target = '/files.php'; }
elseif (preg_match('#^/channels/([0-9]+)/threads/([0-9]+)(/since)?$#', $path, $m)) { $target = '/channels/threads/' . (isset($m[3]) && $m[3] !== '' ? 'since' : 'view') . '.php'; $set(['id' => $m[1], 'message' => $m[2]]); }
elseif (preg_match('#^/(.+)/([0-9]+)/([a-z_-]+)$#', $path, $m) && is_file($root . '/' . $m[1] . '/' . $m[3] . '.php')) { $target = '/' . $m[1] . '/' . $m[3] . '.php'; $set(['id' => $m[2]]); }
elseif (preg_match('#^/pages/(' . $UUID . ')/versions/([0-9]+)$#', $path, $m)) { $target = '/pages/versions/view.php'; $set(['id' => $m[1], 'version' => $m[2]]); }
elseif (preg_match('#^/databases/(' . $UUID . ')/views/new$#', $path, $m)) { $target = '/databases/views/form.php'; $set(['id' => $m[1]]); }
elseif (preg_match('#^/databases/(' . $UUID . ')/views/(' . $UUID . ')/edit$#', $path, $m)) { $target = '/databases/views/form.php'; $set(['id' => $m[1], 'view' => $m[2]]); }
elseif (preg_match('#^/databases/(' . $UUID . ')/rows/(' . $UUID . ')$#', $path, $m)) { $target = '/databases/rows/view.php'; $set(['id' => $m[1], 'row' => $m[2]]); }
elseif (preg_match('#^/(pages|databases)/(' . $UUID . ')/([a-z_-]+)$#', $path, $m)) { $target = '/' . $m[1] . '/' . $m[3] . '.php'; $set(['id' => $m[2]]); }
elseif (preg_match('#^/(pages|databases)/(' . $UUID . ')$#', $path, $m)) { $target = '/' . $m[1] . '/view.php'; $set(['id' => $m[2]]); }
elseif (isset($map[$rel])) { $target = $map[$rel]; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (preg_match('#^/(.+)/new$#', $rel, $m)) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/(.+)/([0-9]+)/edit$#', $rel, $m)) { $target = '/' . $m[1] . '/form.php'; $set(['id' => $m[2]]); }
elseif (preg_match('#^/(.+)/([0-9]+)$#', $rel, $m)) { $target = '/' . $m[1] . '/view.php'; $set(['id' => $m[2]]); }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
else { http_response_code(404); echo 'Not found'; return true; }
if (!is_file($root . $target)) { http_response_code(404); echo 'Not found'; return true; }     // a rewrite to a file its slice has not built yet: 404, as Apache answers
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
