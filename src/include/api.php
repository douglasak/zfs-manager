<?php
/**
 * api.php — JSON endpoint for the zfs.manager plugin UI.
 *
 * Read-only (no CSRF):
 *   GET ?action=list    -> { datasets:[...], snapshots:[...] }
 *   GET ?action=token   -> { token }            (live CSRF token)
 *   GET ?action=browse  snapshot=<dataset@snap> [path=<rel>]
 *                       -> { snapshot, path, entries:[{name,type,size,mtime}] }
 *
 * Mutating (require valid CSRF token; names validated against the live zfs list):
 *   create    dataset=<name> [recursive=1]
 *   destroy   snapshot=<dataset@snap>
 *   rollback  snapshot=<dataset@snap>
 *
 * Runs as root under emhttp's php-fpm.
 */

require_once __DIR__ . '/zfslib.php';

header('Content-Type: application/json');

function out(array $data): void { echo json_encode($data); exit; }

function require_csrf(): void {
    $real = csrf_token();
    $got  = (string)($_REQUEST['csrf_token'] ?? '');
    if ($real === '' || !hash_equals($real, $got)) {
        http_response_code(403);
        out(['ok' => false, 'message' => 'Invalid CSRF token.']);
    }
}

$action = $_REQUEST['action'] ?? '';

// --- read-only ---------------------------------------------------------------
if ($action === 'list') {
    out(['ok' => true, 'datasets' => dataset_details(), 'snapshots' => snapshot_rows()]);
}

// Live CSRF token (the page fetches this via jQuery GET right before each action;
// same-origin + Unraid login gate it).
if ($action === 'token') {
    out(['ok' => true, 'token' => csrf_token()]);
}

// Read-only directory listing inside a snapshot's point-in-time tree.
if ($action === 'browse') {
    out(browse_snapshot(trim($_REQUEST['snapshot'] ?? ''), (string)($_REQUEST['path'] ?? '')));
}

// --- everything below mutates state -----------------------------------------
require_csrf();

switch ($action) {
    case 'create':
        $ds  = trim($_REQUEST['dataset'] ?? '');
        $rec = ($_REQUEST['recursive'] ?? '') === '1';
        out(act_create($ds, $rec));

    case 'destroy':
        out(act_destroy(trim($_REQUEST['snapshot'] ?? '')));

    case 'rollback':
        out(act_rollback(trim($_REQUEST['snapshot'] ?? '')));

    default:
        http_response_code(400);
        out(['ok' => false, 'message' => "Unknown action: '$action'."]);
}
