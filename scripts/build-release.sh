#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf "$TMP_DIR"
}

trap cleanup EXIT

resolve_version() {
  php -r '$data = json_decode(file_get_contents($argv[1]), true); if (!is_array($data) || empty($data["version"])) { fwrite(STDERR, "install.json version is missing\n"); exit(1); } echo $data["version"];' \
    "$ROOT_DIR/install.json"
}

VERSION="${1:-$(resolve_version)}"
TAG="v$VERSION"

OC4_FILENAME="bookurier-opencart-courier-oc4-${TAG}.zip"
OC3_FILENAME="bookurier-opencart-courier-oc3-${TAG}.ocmod.zip"

mkdir -p "$DIST_DIR"
rm -f "$DIST_DIR/$OC4_FILENAME" "$DIST_DIR/$OC4_FILENAME.sha256" "$DIST_DIR/$OC3_FILENAME" "$DIST_DIR/$OC3_FILENAME.sha256"

build_oc4() {
  local stage_dir="$TMP_DIR/oc4"

  mkdir -p "$stage_dir"
  cp -R "$ROOT_DIR/admin" "$stage_dir/admin"
  cp -R "$ROOT_DIR/catalog" "$stage_dir/catalog"
  cp -R "$ROOT_DIR/system" "$stage_dir/system"
  cp "$ROOT_DIR/install.json" "$stage_dir/install.json"

  (
    cd "$stage_dir"
    zip -rq "$DIST_DIR/$OC4_FILENAME" install.json admin catalog system
  )

  sha256sum "$DIST_DIR/$OC4_FILENAME" | sed "s#  $DIST_DIR/#  dist/#" > "$DIST_DIR/$OC4_FILENAME.sha256"
}

build_oc3() {
  local stage_dir="$TMP_DIR/oc3"
  local upload_dir="$stage_dir/upload"
  local shared_dir="$upload_dir/system/library/extension/bookurier"

  mkdir -p "$upload_dir"
  cp -R "$ROOT_DIR/oc3/upload/admin" "$upload_dir/admin"
  cp -R "$ROOT_DIR/oc3/upload/catalog" "$upload_dir/catalog"
  cp -R "$ROOT_DIR/oc3/upload/system" "$upload_dir/system"
  mkdir -p "$shared_dir"
  cp "$ROOT_DIR"/system/library/*.php "$shared_dir/"

  (
    cd "$stage_dir"
    zip -rq "$DIST_DIR/$OC3_FILENAME" upload
  )

  sha256sum "$DIST_DIR/$OC3_FILENAME" | sed "s#  $DIST_DIR/#  dist/#" > "$DIST_DIR/$OC3_FILENAME.sha256"
}

build_oc4
build_oc3

printf 'Built:\n- %s\n- %s\n' "$DIST_DIR/$OC4_FILENAME" "$DIST_DIR/$OC3_FILENAME"
