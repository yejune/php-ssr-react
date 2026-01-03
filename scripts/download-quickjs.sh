#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
BUILD_DIR="$ROOT_DIR/build"

REPO="yejune/php-ssr-react"
VERSION="${QUICKJS_VERSION:-latest}"

# Detect platform
OS="$(uname -s)"
ARCH="$(uname -m)"

case "$OS" in
    Darwin)
        EXT="dylib"
        case "$ARCH" in
            arm64) PLATFORM="darwin-arm64" ;;
            x86_64) PLATFORM="darwin-x64" ;;
            *) echo "Unsupported architecture: $ARCH"; exit 1 ;;
        esac
        ;;
    Linux)
        EXT="so"
        case "$ARCH" in
            x86_64) PLATFORM="linux-x64" ;;
            aarch64) PLATFORM="linux-arm64" ;;
            *) echo "Unsupported architecture: $ARCH"; exit 1 ;;
        esac
        ;;
    *)
        echo "Unsupported OS: $OS"
        echo "Please build manually: scripts/build-quickjs.sh"
        exit 1
        ;;
esac

LIB_FILE="$BUILD_DIR/libquickjs.$EXT"

# Check if already exists
if [ -f "$LIB_FILE" ]; then
    echo "QuickJS already exists: $LIB_FILE"
    exit 0
fi

# Get release URL
if [ "$VERSION" = "latest" ]; then
    API_URL="https://api.github.com/repos/$REPO/releases/latest"
else
    API_URL="https://api.github.com/repos/$REPO/releases/tags/$VERSION"
fi

echo "Fetching release info..."

# Get download URL
ASSET_NAME="libquickjs-$PLATFORM.$EXT"
DOWNLOAD_URL=$(curl -s "$API_URL" | grep "browser_download_url.*$ASSET_NAME" | cut -d '"' -f 4)

if [ -z "$DOWNLOAD_URL" ]; then
    echo "No pre-built binary for $PLATFORM"
    echo "Building from source..."
    "$SCRIPT_DIR/build-quickjs.sh"
    exit 0
fi

echo "Downloading: $DOWNLOAD_URL"

mkdir -p "$BUILD_DIR"
curl -L -o "$LIB_FILE" "$DOWNLOAD_URL"
chmod 755 "$LIB_FILE"

echo "Downloaded: $LIB_FILE"
