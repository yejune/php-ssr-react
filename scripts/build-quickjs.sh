#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
QUICKJS_DIR="$ROOT_DIR/quickjs"
BUILD_DIR="$ROOT_DIR/build"

# Detect OS and architecture
OS="$(uname -s | tr '[:upper:]' '[:lower:]')"
ARCH="$(uname -m)"

case "$OS" in
    darwin)
        LIB_EXT="dylib"
        ;;
    linux)
        LIB_EXT="so"
        ;;
    *)
        echo "Unsupported OS: $OS"
        exit 1
        ;;
esac

LIB_FILE="$BUILD_DIR/libquickjs.$LIB_EXT"

# Check if already built
if [ -f "$LIB_FILE" ]; then
    echo "QuickJS already exists: $LIB_FILE"
    exit 0
fi

# Clone QuickJS if not exists
if [ ! -d "$QUICKJS_DIR" ]; then
    echo "Cloning QuickJS..."
    git clone --depth 1 https://github.com/bellard/quickjs "$QUICKJS_DIR"
fi

echo "Building QuickJS..."

cd "$QUICKJS_DIR"

# Build shared library
make clean 2>/dev/null || true
make libquickjs.$LIB_EXT

# Copy to lib directory
mkdir -p "$BUILD_DIR"
cp "libquickjs.$LIB_EXT" "$LIB_FILE"

echo "QuickJS built successfully: $LIB_FILE"
