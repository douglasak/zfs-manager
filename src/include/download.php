<?php
/**
 * download.php — stream a single file from a snapshot's point-in-time tree.
 *
 *   GET ?snapshot=<dataset@snap>&path=<rel/path/to/file>
 *
 * Read-only and path-jailed via snapshot_resolve() (same guard as browse): the
 * resolved real path must sit inside <mountpoint>/.zfs/snapshot/<name>/, so
 * ../ and absolute paths cannot escape. Directories are refused. Behind the
 * Unraid login like the rest of the plugin; runs as root under php-fpm.
 */

require_once __DIR__ . '/zfslib.php';

$snap = trim($_GET['snapshot'] ?? '');
$rel  = (string)($_GET['path'] ?? '');

function deny(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

$r = snapshot_resolve($snap, $rel);
if (!$r[0])              deny(404, $r[1]);
$file = $r[1];
if (is_dir($file))      deny(400, 'Cannot download a directory.');
if (!is_file($file))    deny(404, 'Not a file.');
if (!is_readable($file)) deny(403, 'File is not readable.');

$size = @filesize($file);

// Clear any output buffering so the body is exactly the file bytes.
while (ob_get_level() > 0) ob_end_clean();

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($file)) . '"');
header('X-Content-Type-Options: nosniff');
if ($size !== false) header('Content-Length: ' . $size);

$fp = fopen($file, 'rb');
if ($fp === false) deny(500, 'Could not open file.');
fpassthru($fp);
fclose($fp);
exit;
