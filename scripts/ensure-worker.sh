#!/bin/bash
#
# Gold Digger - make sure the queue worker exists and is running.
#
# Idempotent, and safe to run on every deploy. Both deploy paths call it before anything
# else is touched; scripts/server-setup.sh calls it as its worker step.
#
# Why provisioning lives here rather than in the one-time setup: the first deploy that
# checked for the worker found there was no supervisor on the production box at all -
# `supervisorctl: command not found`. The setup script had installed it on the box it was
# written for, and the box actually serving was set up some other way. Strategy evaluation
# and the strategy improver go through the queue, so a box without a worker stores every
# bar and never evaluates one, and the only thing that says so is an alert a quarter of an
# hour later. A deploy that can put the worker there is worth more than one that refuses
# until somebody does it by hand.
#
# Requires root (or sudo) - it installs a package and writes under /etc.

set -e

APP_DIR="${APP_DIR:-/var/www/gold-digger}"
PROGRAM="gold-digger-worker"
CONF="/etc/supervisor/conf.d/${PROGRAM}.conf"

SUDO=""
if [ "$(id -u)" -ne 0 ]; then
    SUDO="sudo"
fi

if ! command -v supervisorctl >/dev/null 2>&1; then
    echo "Supervisor is not installed; installing it."
    export DEBIAN_FRONTEND=noninteractive
    $SUDO apt-get update -qq
    $SUDO apt-get install -y -qq supervisor
    $SUDO systemctl enable --now supervisor
fi

if [ ! -f "$CONF" ]; then
    echo "Writing $CONF"
    $SUDO tee "$CONF" >/dev/null <<SUPERVISOR
[program:${PROGRAM}]
process_name=%(program_name)s_%(process_num)02d
; --queue names every queue this worker drains, and the order is priority. Strategy
; evaluation (App\\Jobs\\EvaluateNewBars) goes onto config('trading.queue'), which is
; "strategy" - a worker left on the default queue alone stores every bar and never
; evaluates one, and nothing but the queue_stalled alert says so.
command=php ${APP_DIR}/artisan queue:work --queue=strategy,default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=${APP_DIR}/storage/logs/worker.log
stopwaitsecs=3600
SUPERVISOR
    $SUDO supervisorctl reread
    $SUDO supervisorctl update
fi

# Known but stopped is the other way a deploy lands on a box that will not trade.
if ! $SUDO supervisorctl status "${PROGRAM}:*" | grep -q RUNNING; then
    echo "Worker is not running; starting it."
    $SUDO supervisorctl start "${PROGRAM}:*" || true
fi

if ! $SUDO supervisorctl status "${PROGRAM}:*" | grep -q RUNNING; then
    echo "ERROR: ${PROGRAM} could not be started. Nothing has been deployed."
    $SUDO supervisorctl status "${PROGRAM}:*" || true
    echo "       Check ${APP_DIR}/storage/logs/worker.log and /var/log/supervisor/supervisord.log"
    exit 1
fi

echo "Queue worker is running:"
$SUDO supervisorctl status "${PROGRAM}:*"
