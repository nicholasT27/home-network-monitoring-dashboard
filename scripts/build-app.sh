#!/bin/bash
# Builds the double-click app for Windows, macOS and Linux into releases/  (needs Go and Node.js)
set -e
cd "$(dirname "$0")/.."
npm run build
rm -rf app/web && cp -r dist app/web
rm -rf releases && mkdir -p releases
cd app && go test ./... && cd ..

build() { # os arch zipname exe-name readme
  local dir="releases/stage/$3"; mkdir -p "$dir"
  (cd app && CGO_ENABLED=0 GOOS=$1 GOARCH=$2 go build -trimpath -ldflags "-s -w" -o "../$dir/$4" .)
  cp "app/readme/$5" "$dir/READ ME FIRST.txt"
  (cd releases/stage && zip -qr "../$3.zip" "$3")
}
build windows amd64 HomeNetworkMonitor-Windows "HomeNetworkMonitor.exe" windows.txt
build darwin arm64 HomeNetworkMonitor-Mac-AppleChip "HomeNetworkMonitor" mac.txt
build darwin amd64 HomeNetworkMonitor-Mac-Intel "HomeNetworkMonitor" mac.txt
build linux amd64 HomeNetworkMonitor-Linux "HomeNetworkMonitor" linux.txt
rm -rf releases/stage
ls -lh releases
