<?php
/**
 * zfslib.php — core logic for the zfs.manager Unraid plugin.
 *
 * Runs on the Unraid host as root under emhttp's php-fpm, so it calls the zfs
 * CLI directly. Every dataset/snapshot argument is validated against the live
 * `zfs list` output before use, and shell args are escaped.
 */

const PLUGIN     = 'zfs.manager';
const PLUGIN_DIR = '/usr/local/emhttp/plugins/' . PLUGIN;
const SNAP_DATE  = 'Y-m-d-His';   // matches the @YYYY-MM-DD-HHMMSS pool convention

// --- var.ini / CSRF ---------------------------------------------------------

/**
 * Read Unraid's var.ini robustly. Under php-fpm, open_basedir may block PHP file
 * functions from /var/local/emhttp; exec() spawns a process not subject to it.
 */
function emhttp_var_raw(): string {
    $ini = @file_get_contents('/var/local/emhttp/var.ini');
    if ($ini === false || $ini === '') {
        $out = [];
        @exec('cat /var/local/emhttp/var.ini 2>/dev/null', $out);
        $ini = implode("\n", $out);
    }
    return (string)$ini;
}

/** Pull a single key from var.ini (quote-agnostic), avoiding parse_ini_file. */
function emhttp_var(string $key, string $default = ''): string {
    $re = '/^' . preg_quote($key, '/') . '="?([^"\r\n]*)"?/m';
    if (preg_match($re, emhttp_var_raw(), $m)) return $m[1];
    return $default;
}

/** Unraid CSRF token. */
function csrf_token(): string {
    return emhttp_var('csrf_token');
}

// --- zfs binary + runner ----------------------------------------------------

function zfs_bin(): string {
    static $b = null;
    if ($b !== null) return $b;
    foreach (['/usr/sbin/zfs', '/sbin/zfs', '/usr/bin/zfs', '/usr/local/sbin/zfs'] as $c) {
        if (is_executable($c)) return $b = $c;
    }
    return $b = 'zfs';
}

/** Run zfs with each argument escaped. Returns [rc, output-lines]. */
function zfs(array $args): array {
    $cmd = escapeshellarg(zfs_bin());
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    $out = []; $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, $out];
}

// --- queries / validation ---------------------------------------------------

/** Names of all filesystems and volumes. */
function ds_names(): array {
    [$rc, $out] = zfs(['list', '-H', '-o', 'name', '-t', 'filesystem,volume']);
    if ($rc !== 0) return [];
    return array_values(array_filter(array_map('trim', $out), fn($n) => $n !== ''));
}

/** Names of all snapshots. */
function snap_names(): array {
    [$rc, $out] = zfs(['list', '-H', '-o', 'name', '-t', 'snapshot']);
    if ($rc !== 0) return [];
    return array_values(array_filter(array_map('trim', $out), fn($n) => $n !== ''));
}

/** Only datasets zfs reports are accepted — guards create. */
function ds_valid(string $ds): bool {
    return $ds !== '' && in_array($ds, ds_names(), true);
}

/** Only snapshots zfs reports are accepted — guards destroy/rollback. */
function snap_valid(string $s): bool {
    return strpos($s, '@') !== false && in_array($s, snap_names(), true);
}

/** Per-dataset snapshot counts, keyed by dataset name. */
function snap_counts(): array {
    $counts = [];
    foreach (snap_names() as $s) {
        $ds = explode('@', $s, 2)[0];
        $counts[$ds] = ($counts[$ds] ?? 0) + 1;
    }
    return $counts;
}

/** Dataset detail rows: name,type,used,avail,refer,block,compression,ratio,snaps. */
function dataset_details(): array {
    $counts = snap_counts();
    [$rc, $lines] = zfs(['list', '-H', '-o',
        'name,type,used,avail,refer,recordsize,volblocksize,compression,compressratio',
        '-t', 'filesystem,volume']);
    $rows = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $f = explode("\t", $line);
        if (count($f) < 9) continue;
        [$name, $type, $used, $avail, $refer, $rec, $volblk, $comp, $ratio] = $f;
        $block = ($rec !== '-') ? $rec : $volblk;   // recordsize for fs, volblocksize for zvols
        $rows[] = [
            'name'        => $name,
            'type'        => $type,
            'used'        => $used,
            'avail'       => $avail,
            'refer'       => $refer,
            'block'       => $block,
            'compression' => $comp,
            'ratio'       => $ratio,
            'snaps'       => $counts[$name] ?? 0,
        ];
    }
    return $rows;
}

/** Snapshot rows, newest first: name,dataset,snap,used,refer,creation. */
function snapshot_rows(): array {
    [$rc, $lines] = zfs(['list', '-H', '-s', 'creation', '-o',
        'name,used,refer,creation', '-t', 'snapshot']);
    $rows = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $f = explode("\t", $line);
        if (count($f) < 4) continue;
        [$name, $used, $refer, $creation] = $f;
        $parts = explode('@', $name, 2);
        $rows[] = [
            'name'     => $name,
            'dataset'  => $parts[0],
            'snap'     => $parts[1] ?? '',
            'used'     => $used,
            'refer'    => $refer,
            'creation' => $creation,
        ];
    }
    return array_reverse($rows);   // newest first
}

// --- actions ----------------------------------------------------------------

function ok(string $msg, array $extra = []): array  { return ['ok' => true,  'message' => $msg] + $extra; }
function fail(string $msg, array $extra = []): array { return ['ok' => false, 'message' => $msg] + $extra; }

// --- read-only snapshot browser ---------------------------------------------
// A snapshot's point-in-time tree is exposed read-only by ZFS at
// <dataset-mountpoint>/.zfs/snapshot/<name>/. We list directories within that
// root and jail every request to it (realpath prefix check), so a crafted
// ../.. path can never escape the snapshot.

function ds_mountpoint(string $ds): string {
    [$rc, $out] = zfs(['get', '-H', '-o', 'value', 'mountpoint', $ds]);
    if ($rc !== 0) return '';
    $mp = trim(implode('', $out));
    return ($mp !== '' && $mp[0] === '/') ? $mp : '';   // skip legacy/none/-
}

/** List a directory inside a snapshot. $rel is relative to the snapshot root. */
function browse_snapshot(string $snap, string $rel): array {
    if (!snap_valid($snap)) return fail("Unknown snapshot: '$snap'.");
    [$ds, $name] = explode('@', $snap, 2);

    $mp = ds_mountpoint($ds);
    if ($mp === '') return fail("Dataset '$ds' has no browsable mountpoint.");

    $rootReal = realpath($mp . '/.zfs/snapshot/' . $name);   // automounts on access
    if ($rootReal === false) return fail('Snapshot tree not accessible.');

    $real = realpath($rootReal . '/' . ltrim($rel, '/'));
    // jail: must resolve to the root itself or something beneath "root/".
    if ($real === false ||
        ($real !== $rootReal && strncmp($real, $rootReal . '/', strlen($rootReal) + 1) !== 0)) {
        return fail('Path not found.');
    }
    if (!is_dir($real)) return fail('Not a directory.');

    $dirs = []; $files = [];
    $dh = @opendir($real);
    if ($dh === false) return fail('Cannot read directory.');
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') continue;
        $full   = $real . '/' . $e;
        $isLink = is_link($full);
        $isDir  = is_dir($full);
        $st     = @lstat($full);
        $entry  = [
            'name'  => $e,
            'type'  => $isLink ? 'link' : ($isDir ? 'dir' : 'file'),
            'size'  => ($isDir || $st === false) ? null : (int)$st['size'],
            'mtime' => $st !== false ? (int)$st['mtime'] : 0,
        ];
        if ($isDir && !$isLink) $dirs[] = $entry; else $files[] = $entry;
    }
    closedir($dh);
    $cmp = fn($a, $b) => strcasecmp($a['name'], $b['name']);
    usort($dirs, $cmp); usort($files, $cmp);

    return ok('', [
        'snapshot' => $snap,
        'path'     => ltrim(substr($real, strlen($rootReal)), '/'),   // '' = root
        'entries'  => array_merge($dirs, $files),
    ]);
}

function act_create(string $ds, bool $recursive): array {
    if (!ds_valid($ds)) return fail("Unknown dataset: '$ds'.");
    $snap = $ds . '@' . date(SNAP_DATE);
    $args = ['snapshot'];
    if ($recursive) $args[] = '-r';
    $args[] = $snap;
    [$rc, $out] = zfs($args);
    return $rc === 0 ? ok("Created $snap") : fail(trim(implode("\n", $out)));
}

function act_destroy(string $snap): array {
    if (!snap_valid($snap)) return fail("Unknown snapshot: '$snap'.");
    [$rc, $out] = zfs(['destroy', $snap]);
    return $rc === 0 ? ok("Destroyed $snap") : fail(trim(implode("\n", $out)));
}

function act_rollback(string $snap): array {
    if (!snap_valid($snap)) return fail("Unknown snapshot: '$snap'.");
    [$rc, $out] = zfs(['rollback', '-r', $snap]);
    return $rc === 0 ? ok("Rolled back to $snap") : fail(trim(implode("\n", $out)));
}
