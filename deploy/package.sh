#!/bin/bash
#
# Build a versioned deployment artifact for LetsVote.
#
#   ./deploy/package.sh 1.0.0
#
# Produces dist/letsvote-1.0.0.zip containing exactly what belongs on an
# instance: no .git, no local config, no editor droppings.
#
# Upload the result to the artifact bucket (see DEPLOYMENT.md step 4b), then
# set APP_VERSION in deploy/userdata.sh to the same version string.
#
# Why a version in the filename: the instances fetch one specific object. Two
# instances launched an hour apart get byte-identical code, and a rollback is
# changing APP_VERSION back and refreshing the Auto Scaling group -- not a
# git revert and a race against whatever launches next.

set -euo pipefail

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    echo "usage: $0 <version>    e.g. $0 1.0.0" >&2
    exit 1
fi

# Version strings end up in an S3 key and a filename; keep them boring.
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "error: version must look like 1.0.0, got '$VERSION'" >&2
    exit 1
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$REPO_ROOT/dist"
OUT="$OUT_DIR/letsvote-$VERSION.zip"

cd "$REPO_ROOT"
mkdir -p "$OUT_DIR"
rm -f "$OUT"

# -x excludes. config/config.local.ini holds the database password and the
# Cognito client secret; the instances get those from Secrets Manager, so the
# artifact must never carry them.
zip -r -q "$OUT" \
    public src templates sql deploy config README.md \
    -x '*.git*' \
    -x 'config/config.local.ini' \
    -x 'config/config.ini' \
    -x '*.local.ini' \
    -x '*/.DS_Store' \
    -x 'dist/*'

echo "built $OUT"

# Fail loudly rather than shipping a secret to S3.
if unzip -l "$OUT" | grep -qE 'config\.(local\.)?ini$'; then
    echo "ERROR: a real config file got into the artifact -- refusing to ship" >&2
    unzip -l "$OUT" | grep -E 'config\.(local\.)?ini$' >&2
    rm -f "$OUT"
    exit 1
fi

echo
echo "contents:"
unzip -l "$OUT" | tail -n +4 | head -20
echo "  ..."
echo
echo "next:"
echo "  1. upload to  s3://<your-bucket>/letsvote/letsvote-$VERSION.zip"
echo "  2. set        APP_VERSION=\"$VERSION\"  in deploy/userdata.sh"
echo "  3. refresh    the Auto Scaling group so instances pick it up"
