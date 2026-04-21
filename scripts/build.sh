#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

MODE="${1:-prod}"

if [[ ! -d node_modules ]]; then
	echo "==> Installing npm dependencies"
	npm install
fi

if [[ ! -d vendor ]]; then
	echo "==> Installing composer dependencies"
	composer install --no-dev -o
fi

case "$MODE" in
	prod)
		echo "==> Building frontend (production)"
		npm run build
		;;
	dev)
		echo "==> Building frontend (development)"
		npm run dev
		;;
	watch)
		echo "==> Starting watcher"
		exec npm run watch
		;;
	*)
		echo "Usage: $0 [prod|dev|watch]" >&2
		exit 1
		;;
esac

echo "==> Build complete"
