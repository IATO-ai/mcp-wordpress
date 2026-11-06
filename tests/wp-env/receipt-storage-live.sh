#!/bin/zsh
# Live checks that a change receipt is a real undo record (1.16.0): any change_id
# a tool returns exists in the table and rolls back; long meta keys are stored;
# a missing actor or rolled-back-by column loses only who made the change; a
# table that refuses the row gives no ID and a warning, also on bulk rows; the
# call log keeps working with a column missing.
#   tests/wp-env/receipt-storage-live.sh [base-url]
#   tests/wp-env/receipt-storage-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
if [ "${1:-}" = "--cleanup" ]; then
	exec python3 -I "$REPO/tests/wp-env/receipt-storage-live.py" --cleanup
fi
export BASE="${1:-http://localhost:8888}"
python3 -I "$REPO/tests/wp-env/receipt-storage-live.py"
