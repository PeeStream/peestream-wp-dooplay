#!/usr/bin/env bash
# Byte-reproducible WordPress plugin ZIP for PeeStream for WordPress & DooPlay.
# Fixed timestamps + allowlisted files + explicit compression, so two runs with the
# same sources always produce the same SHA-256. Writes dist/ and updates SHA256SUMS.
set -euo pipefail
cd "$(dirname "$0")/.."

VER=$(sed -n "s/^define( 'PEESTREAM_VERSION', '\([^']*\)' );$/\1/p" peestream.php)
[ -n "$VER" ] || { echo "ERROR: cannot read PEESTREAM_VERSION" >&2; exit 1; }

NAME="peestream-wp-dooplay_v${VER}.zip"
PREFIX="peestream-wp-dooplay/"

for f in peestream.php peestream-autopublish.php readme.txt README.md CHANGELOG.md LICENSE; do
  [ -f "$f" ] || { echo "ERROR: missing package candidate $f" >&2; exit 1; }
done
for d in includes src assets languages; do
  [ -d "$d" ] || { echo "ERROR: missing package directory $d" >&2; exit 1; }
done

mkdir -p dist
python3 - "$NAME" "$PREFIX" <<'PY'
import os, sys, zipfile

name, prefix = sys.argv[1], sys.argv[2]
roots = ["includes", "src", "assets", "languages"]
files = ["peestream.php", "peestream-autopublish.php", "readme.txt",
         "README.md", "CHANGELOG.md", "LICENSE"]
for root in roots:
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames.sort()
        for fn in sorted(filenames):
            files.append(os.path.join(dirpath, fn))
files.sort()

stamp = (2026, 11, 15, 0, 0, 0)   # fixed, keeps the archive reproducible
out = os.path.join("dist", name)
with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for rel in files:
        with open(rel, "rb") as fh:
            data = fh.read()
        info = zipfile.ZipInfo(prefix + rel, date_time=stamp)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.create_system = 3
        info.external_attr = (0o644 & 0xFFFF) << 16
        z.writestr(info, data, compresslevel=9)
print("built %s (%d bytes, %d files)" % (out, os.path.getsize(out), len(files)))
PY

# SHA256SUMS covers exactly the artifact built here, so `sha256sum -c` passes on a
# fresh clone without keeping historical archives (those live on GitHub Releases).
python3 - "$NAME" <<'PY'
import hashlib, os, sys
name = sys.argv[1]
path = os.path.join("dist", name)
digest = hashlib.sha256(open(path, "rb").read()).hexdigest()
with open("SHA256SUMS", "w") as fh:
    fh.write("%s  %s\n" % (digest, name))
PY

echo "version ${VER}"
cat SHA256SUMS
echo "verify with: ( cd dist && sha256sum -c ../SHA256SUMS )"
