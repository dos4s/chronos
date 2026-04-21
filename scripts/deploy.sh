#!/usr/bin/env bash
set -euo pipefail

# Config — override via env vars if needed
HOST="${HOST:-root@deb00}"
DEST="${DEST:-/var/www/nextcloud/custom_apps/chronos}"
OCC="${OCC:-/var/www/nextcloud/occ}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"
SSH_OPTS="${SSH_OPTS:--o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new}"
SKIP_BUILD="${SKIP_BUILD:-0}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-0}"

SSH_CMD="ssh -i $SSH_KEY $SSH_OPTS"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if [[ "$SKIP_BUILD" != "1" ]]; then
	./scripts/build.sh prod
fi

echo "==> Syncing to $HOST:$DEST"
rsync -av --delete \
	-e "$SSH_CMD" \
	--exclude=node_modules \
	--exclude=vendor-bin \
	--exclude=.git \
	--exclude=tests \
	--exclude=scripts \
	./ "$HOST:$DEST/"

echo "==> Fixing permissions"
$SSH_CMD "$HOST" "chown -R www-data:www-data '$DEST'"

if [[ "$RUN_MIGRATIONS" == "1" ]]; then
	echo "==> Running migrations"
	$SSH_CMD "$HOST" "sudo -u www-data php '$OCC' migrations:migrate chronos"
fi

echo "==> Done"
