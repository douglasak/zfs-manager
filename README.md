# zfs.manager — Unraid ZFS snapshot plugin

A small PHP/web interface to manage ZFS snapshots on Unraid: browse datasets,
create snapshots (optionally recursive), roll back, and destroy — from
auto-refreshing tables that mirror Unraid's native styling.

License: **MIT** · Repo: **github.com/douglasak/zfs-manager**

> **Use at your own risk.** **Rollback** discards every change made since the
> chosen snapshot *and destroys any newer snapshots* of that dataset
> (`zfs rollback -r`); **Destroy** permanently deletes a snapshot. Actions take
> effect immediately and can lead to data loss. Provided **AS IS, without
> warranty of any kind** (see [LICENSE](src/LICENSE)).

## How it works

The plugin runs **on** the Unraid server. Unraid's webGUI (emhttp) and its
php-fpm worker run as **root on the same host as ZFS**, so the plugin calls the
`zfs` CLI directly — no SSH, no agent. The core logic lives in
`include/zfslib.php` (thin `zfs` wrappers plus action handlers);
`include/api.php` is a small JSON endpoint the page calls; and
`ZFSManager.page` is the GUI under **Tools**.

Every dataset/snapshot argument is validated against the live `zfs list` output
before use, shell arguments are escaped, and all mutating actions (create,
destroy, rollback) require a valid Unraid CSRF token. Read-only listing needs no
token.

## Install

**From URL (recommended):** Unraid GUI → **Plugins → Install Plugin**, paste:

```
https://raw.githubusercontent.com/douglasak/zfs-manager/main/zfs.manager.plg
```

and click Install. Installing this way registers the `pluginURL`, so Unraid
notifies you of updates when a newer version is pushed to the repo.

**From a local file:** copy `zfs.manager.plg` to the server (e.g. to
`/boot/config/plugins/`) and install that path instead.

Then open it at **Tools → ZFS Manager**.

The `.plg` is self-contained — every file is inlined as CDATA, so there is no
external download at install time.

## Layout

```
src/
  ZFSManager.page   # GUI (HTML + CSS + JS), Tools menu
  include/zfslib.php        # zfs wrappers, validation, action handlers
  include/api.php           # JSON endpoint
  LICENSE
build_plg.py                # inlines src/ into the .plg
zfs.manager.plg    # generated, self-contained installer (commit this)
```

## Building

`src/` is the source of truth. After editing anything under `src/`, regenerate
the installer:

```
python3 build_plg.py
```

This rewrites `zfs.manager.plg` with the current `src/` contents and
today's date as the version. Commit both `src/` and the regenerated `.plg`, then
push.

> If your default branch is `master` rather than `main`, change `BRANCH` near the
> top of `build_plg.py` so the `pluginURL` resolves.

## Uninstall

Unraid GUI → **Plugins**, remove **zfs.manager**. The plugin directory
is deleted; ZFS datasets and snapshots are untouched.
