#!/usr/bin/env python3
"""Assemble zfs.manager.plg by inlining the source tree as CDATA <FILE> blocks."""
import os
from datetime import date

HERE = os.path.dirname(os.path.abspath(__file__))
SRC  = os.path.join(HERE, "src")
OUT  = os.path.join(HERE, "zfs.manager.plg")

AUTH    = "douglasak"
# CalVer. Append a letter suffix (a, b, c…) for a second+ release on the same
# day so Unraid sees it as a newer version than the previous build.
SUFFIX  = "a"
VER     = date.today().strftime("%Y.%m.%d") + SUFFIX
GH_USER = "douglasak"
GH_REPO = "zfs-manager"
BRANCH  = "main"   # change to "master" if your repo's default branch is master
PLUGIN_URL = f"https://raw.githubusercontent.com/{GH_USER}/{GH_REPO}/{BRANCH}/zfs.manager.plg"
SUPPORT    = f"https://github.com/{GH_USER}/{GH_REPO}/issues"

# (source path relative to SRC, destination path on Unraid, mode)
FILES = [
    ("ZFSManager.page",      "&plugdir;/ZFSManager.page",      "0644"),
    ("include/zfslib.php",   "&plugdir;/include/zfslib.php",   "0644"),
    ("include/api.php",      "&plugdir;/include/api.php",      "0644"),
    ("include/download.php", "&plugdir;/include/download.php", "0644"),
    ("LICENSE",              "&plugdir;/LICENSE",              "0644"),
]

def cdata(text: str) -> str:
    # CDATA can't contain the literal ]]> — none of our files do, but guard anyway.
    return text.replace("]]>", "]]]]><![CDATA[>")

parts = []
parts.append(f"""<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "zfs.manager">
<!ENTITY author    "{AUTH}">
<!ENTITY version   "{VER}">
<!ENTITY pluginURL "{PLUGIN_URL}">
<!ENTITY support   "{SUPPORT}">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&name;"
        author="&author;"
        version="&version;"
        pluginURL="&pluginURL;"
        support="&support;"
        launch="Settings/ZFSManager"
        icon="camera"
        min="6.12.0">

<CHANGES>
###&version;
- Web GUI under Settings -> Utilities -> ZFS Manager: dataset overview table
  (type, used, avail, refer, block size, compression, ratio, snapshot count),
  create snapshots (optionally recursive), roll back, and destroy — in
  auto-refreshing tables.
- All zfs calls run on the host with escaped args; every dataset/snapshot is
  validated against the live `zfs list` before use. Mutations require a valid
  Unraid CSRF token.
- MIT licensed. Provided AS IS, without warranty; use at your own risk.
</CHANGES>

<!-- clean any prior install, recreate the tree (runs at install AND every boot) -->
<FILE Run="/bin/bash">
<INLINE>
rm -rf &plugdir;
mkdir -p &plugdir;/include
</INLINE>
</FILE>
""")

for rel, dest, mode in FILES:
    with open(os.path.join(SRC, rel), "r") as fh:
        content = cdata(fh.read())
    parts.append(f"""<FILE Name="{dest}" Mode="{mode}">
<INLINE>
<![CDATA[
{content}]]>
</INLINE>
</FILE>
""")

parts.append("""<!-- post-install -->
<FILE Run="/bin/bash">
<INLINE>
echo ""
echo "+=============================================================+"
echo "| zfs.manager installed.                                      |"
echo "| Open it at:  Settings -> Utilities -> ZFS Manager           |"
echo "+=============================================================+"
echo ""
</INLINE>
</FILE>

<!-- uninstall handler -->
<FILE Run="/bin/bash" Method="remove">
<INLINE>
rm -rf &plugdir;
echo "zfs.manager removed."
</INLINE>
</FILE>

</PLUGIN>
""")

with open(OUT, "w") as fh:
    fh.write("".join(parts))

print("wrote", OUT)
