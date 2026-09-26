#!/bin/sh
# Nightly refresh of the sales-demo instance: rebuilds the fictional dataset
# relative to today's date (so arrears/upcoming installments stay realistic)
# and runs the daily jobs. Integration credentials entered by the owner are
# preserved. Credentials for the two demo logins come from ~/demo_app/.demo_env
# (chmod 600, never committed), which exports DEMO_PASSWORD and OWNER_PASSWORD.
#
# Crontab (02:30 daily):
#   30 2 * * * /bin/sh $HOME/demo_app/bin/demo_nightly.sh >> $HOME/demo_app/storage/demo_nightly.log 2>&1
set -e
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
. "$APP_DIR/.demo_env"
export MLS_DEMO_MODE=1
PHP="${PHP_BIN:-php}"
echo "== $(date '+%Y-%m-%d %H:%M:%S') demo refresh"
"$PHP" "$APP_DIR/bin/demo_seed.php"
"$PHP" "$APP_DIR/bin/accrue_interest.php"
"$PHP" "$APP_DIR/bin/sweep_loan_status.php"
