#!/usr/bin/env bash
#
# Back up NPharmadb, then run the sell-by-piece migration.
#
# The migration rewrites every stock quantity from boxes into pieces, so this
# refuses to migrate unless a verified dump exists first. Run it from the
# project root:
#
#     bash backup-and-migrate.sh
#
set -euo pipefail

cd "$(dirname "$0")"

ENV_FILE=".env"
BACKUP_DIR="$HOME/Desktop"
STAMP="$(date +%Y%m%d-%H%M%S)"

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n\n' "$*" >&2; exit 1; }

[ -f "$ENV_FILE" ] || die "No .env here. Run this from the project root."

# ---------------------------------------------------------------- settings
env_get() {
  grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- \
    | sed 's/^["'\'']//; s/["'\'']$//' | tr -d '\r'
}

DB_HOST="$(env_get DB_HOST)";     DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_get DB_PORT)";     DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_get DB_DATABASE)"
DB_USER="$(env_get DB_USERNAME)"; DB_USER="${DB_USER:-root}"
DB_PASS="$(env_get DB_PASSWORD)"

[ -n "$DB_NAME" ] || die "DB_DATABASE is empty in .env"

DUMP="$BACKUP_DIR/${DB_NAME}-before-units-${STAMP}.sql"

# ------------------------------------------------------------------- tools
find_tool() {
  local name="$1" p
  command -v "$name" 2>/dev/null && return 0
  for p in \
    "/opt/homebrew/bin/$name" \
    "/usr/local/bin/$name" \
    "/usr/local/mysql/bin/$name" \
    "$HOME/Library/Application Support/DBngin/mysql"/*/bin/"$name" \
    "$HOME/Library/Application Support/Herd"/**/"$name"
  do
    [ -x "$p" ] && { printf '%s\n' "$p"; return 0; }
  done
  return 1
}

shopt -s globstar nullglob 2>/dev/null || true

MYSQLDUMP="$(find_tool mysqldump || true)"
[ -n "$MYSQLDUMP" ] || die "Could not find mysqldump. Install the MySQL client tools, or tell Claude and it will write a PHP-based backup instead."

command -v php >/dev/null 2>&1 || die "php is not on your PATH. Open a terminal where Herd's php works, then re-run."

say "Using"
ok "database   $DB_NAME @ $DB_HOST:$DB_PORT (user $DB_USER)"
ok "mysqldump  $MYSQLDUMP"
ok "backup to  $DUMP"

# ------------------------------------------------------------------ backup
say "1/4  Backing up"

PW_ARGS=()
[ -n "$DB_PASS" ] && PW_ARGS=(--password="$DB_PASS")

"$MYSQLDUMP" \
  -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "${PW_ARGS[@]}" \
  --single-transaction --routines --events \
  "$DB_NAME" > "$DUMP" || die "mysqldump failed. Nothing has been changed."

# A half-written dump is worse than none, so prove it finished.
[ -s "$DUMP" ] || die "The dump is empty. Nothing has been changed."
tail -3 "$DUMP" | grep -q "Dump completed" \
  || die "The dump did not finish cleanly. Nothing has been changed."

ok "$(du -h "$DUMP" | cut -f1) written and verified"

# --------------------------------------------------------------- migrating
say "2/4  Migrating"
php artisan migrate --force
ok "migrations applied"

say "3/4  Clearing caches"
php artisan config:clear
php artisan view:clear
ok "caches cleared"

# ------------------------------------------------------------ verification
say "4/4  Checking the result"

php artisan tinker --execute '
$stuck = DB::table("products")->where("unit", "like", "%(%")->count();
$split = DB::table("products")->where("pieces_per_box", ">", 1)->count();
$lines = DB::table("sale_items")->where("unit_multiplier", ">", 1)->count();

echo "  products with a pack size : {$split}\n";
echo "  old sale lines relabelled : {$lines}\n";
echo "  units that failed to parse: {$stuck}\n";

if ($stuck > 0) {
    echo "\n  These kept their old text and cannot be sold by piece yet:\n";
    foreach (DB::table("products")->where("unit", "like", "%(%")->get(["id","name","unit"]) as $p) {
        echo "    #{$p->id}  {$p->name}  ({$p->unit})\n";
    }
}
'

say "Done"
ok "backup kept at $DUMP"
echo
echo "  To undo everything:"
echo "      mysql -h $DB_HOST -u $DB_USER $DB_NAME < \"$DUMP\""
echo
