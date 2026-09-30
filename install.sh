#!/usr/bin/env bash
# ============================================================================
# Uptime Telegram Bot — Fully automatic installer for a RAW Linux server
# Usage:
#   chmod +x install.sh
#   sudo ./install.sh --token 123:ABC --admin 123456 --username MyBot \
#        --domain example.com/bots/uptime1 --base-url https://example.com/bots/uptime1 \
#        --db-host 127.0.0.1 --db-port 3306 --db-name uptimebot \
#        --db-user uptime --db-pass S3cret
#   # or non-interactive with env vars: BOT_TOKEN ADMIN_ID BOT_USERNAME DOMAIN
#   # BASE_URL DB_HOST DB_PORT DB_NAME DB_USER DB_PASS
#   ./install.sh --help
#
# Design: NEVER abort on first error. Every single failure path is guarded
# with `if`. Idempotent: safe to run twice.
# ============================================================================

APP_DIR="$(cd "$(dirname "$0")" 2>/dev/null && pwd || echo "")"
if [ -z "$APP_DIR" ] || [ ! -f "$APP_DIR/config.php" ]; then
  APP_DIR="$(pwd)"
fi
LOG_FILE="$APP_DIR/install.log"

# ---------- defaults (overridable by env or flags) ----------
BOT_TOKEN="${BOT_TOKEN:-}"
ADMIN_ID="${ADMIN_ID:-}"
BOT_USERNAME="${BOT_USERNAME:-}"
DOMAIN="${DOMAIN:-}"
BASE_URL="${BASE_URL:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-uptimebot}"
DB_USER="${DB_USER:-uptime}"
DB_PASS="${DB_PASS:-}"
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:-}"
TZ_OFFSET="${TZ_OFFSET:-3.5}"
NONINTERACTIVE=0
SKIP_OS=0; SKIP_DB=0; SKIP_CRON=0; SKIP_WEBHOOK=0; SKIP_PERMS=0
FORCE=0

# ---------- helpers ----------
log()  { echo "[install] $*" | tee -a "$LOG_FILE" 2>/dev/null || echo "[install] $*"; }
warn() { echo "[install][WARN] $*" | tee -a "$LOG_FILE" 2>/dev/null || echo "[install][WARN] $*"; }
ok()   { echo "[install][OK] $*" | tee -a "$LOG_FILE" 2>/dev/null || echo "[install][OK] $*"; }
fail() { echo "[install][FAIL] $*" | tee -a "$LOG_FILE" 2>/dev/null || echo "[install][FAIL] $*"; }

have() { command -v "$1" >/dev/null 2>&1; }

usage() {
  sed -n '2,20p' "$0" 2>/dev/null || echo "see header of install.sh"
  echo ""
  echo "Flags: --token --admin --username --domain --base-url --db-host --db-port"
  echo "       --db-name --db-user --db-pass --tz --non-interactive --force"
  echo "       --skip-os --skip-db --skip-cron --skip-webhook --skip-perms --help"
}

# ---------- parse flags ----------
while [ $# -gt 0 ]; do
  case "$1" in
    --token=*) BOT_TOKEN="${1#--token=}"; shift ;;
    --admin=*) ADMIN_ID="${1#--admin=}"; shift ;;
    --username=*) BOT_USERNAME="${1#--username=}"; shift ;;
    --domain=*) DOMAIN="${1#--domain=}"; shift ;;
    --base-url=*|--base_url=*) BASE_URL="${1#*=}"; shift ;;
    --db-host=*) DB_HOST="${1#*=}"; shift ;;
    --db-port=*) DB_PORT="${1#*=}"; shift ;;
    --db-name=*) DB_NAME="${1#*=}"; shift ;;
    --db-user=*) DB_USER="${1#*=}"; shift ;;
    --db-pass=*) DB_PASS="${1#*=}"; shift ;;
    --tz=*) TZ_OFFSET="${1#*=}"; shift ;;
    --token) BOT_TOKEN="${2:-}"; shift 2 || shift ;;
    --admin) ADMIN_ID="${2:-}"; shift 2 || shift ;;
    --username) BOT_USERNAME="${2:-}"; shift 2 || shift ;;
    --domain) DOMAIN="${2:-}"; shift 2 || shift ;;
    --base-url|--base_url) BASE_URL="${2:-}"; shift 2 || shift ;;
    --db-host) DB_HOST="${2:-}"; shift 2 || shift ;;
    --db-port) DB_PORT="${2:-}"; shift 2 || shift ;;
    --db-name) DB_NAME="${2:-}"; shift 2 || shift ;;
    --db-user) DB_USER="${2:-}"; shift 2 || shift ;;
    --db-pass)
      if [ $# -ge 2 ] && case "${2:-}" in -*) false;; *) true;; esac; then DB_PASS="${2:-}"; shift 2 || shift;
      else DB_PASS=""; shift; fi ;;
    --tz) TZ_OFFSET="${2:-3.5}"; shift 2 || shift ;;
    --non-interactive) NONINTERACTIVE=1; shift ;;
    --force) FORCE=1; shift ;;
    --skip-os) SKIP_OS=1; shift ;;
    --skip-db) SKIP_DB=1; shift ;;
    --skip-cron) SKIP_CRON=1; shift ;;
    --skip-webhook) SKIP_WEBHOOK=1; shift ;;
    --skip-perms) SKIP_PERMS=1; shift ;;
    --help|-h) usage; exit 0 ;;
    *) warn "unknown flag: $1 (ignored)"; shift ;;
  esac
done

ask() { # ask VAR PROMPT DEFAULT — no-op in non-interactive
  _var="$1"; _prompt="$2"; _def="$3"
  eval "_cur=\${$_var:-}"
  if [ -n "$_cur" ]; then return 0; fi
  if [ "$NONINTERACTIVE" = "1" ]; then
    if [ -n "$_def" ]; then
      if have printf && printf -v "$_var" '%s' "$_def" 2>/dev/null; then return 0; fi
      eval "$_var=\$_def"
      return 0
    fi
    return 1
  fi
  printf "%s [%s]: " "$_prompt" "$_def" 2>/dev/null || true
  if ! read -r _ans 2>/dev/null; then _ans=""; fi
  if [ -z "$_ans" ]; then _ans="$_def"; fi
  if have printf && printf -v "$_var" '%s' "$_ans" 2>/dev/null; then return 0; fi
  eval "$_var=\$_ans"
  return 0
}

is_root() { [ "$(id -u 2>/dev/null || echo 1)" = "0" ]; }
pkg_mgr() {
  if have apt-get; then echo apt
  elif have dnf; then echo dnf
  elif have yum; then echo yum
  elif have apk; then echo apk
  elif have pacman; then echo pacman
  else echo none; fi
}

ERRORS=0
note_err() { ERRORS=$((ERRORS + 1)); fail "$1"; }

echo "=== UptimeBot installer ===" | tee "$LOG_FILE" 2>/dev/null || echo "=== UptimeBot installer ==="
log "app dir: $APP_DIR"

# ================================================================= 0) inputs
if ! ask BOT_TOKEN "Bot token (from @BotFather)" ""; then warn "token empty"; fi
if ! ask ADMIN_ID "Admin numeric id" ""; then warn "admin empty"; fi
if ! ask BOT_USERNAME "Bot username (without @)" ""; then warn "username empty"; fi
if ! ask DOMAIN "Domain/path (no https, no trailing slash, e.g. example.com/bots/uptime1)" ""; then warn "domain empty"; fi
if ! ask BASE_URL "Base URL (https://... full folder)" ""; then warn "base_url empty"; fi
if ! ask DB_HOST "DB host" "127.0.0.1"; then DB_HOST="127.0.0.1"; fi
if ! ask DB_PORT "DB port" "3306"; then DB_PORT="3306"; fi
if ! ask DB_NAME "DB name" "uptimebot"; then DB_NAME="uptimebot"; fi
if ! ask DB_USER "DB user" "uptime"; then DB_USER="uptime"; fi
if [ -z "$DB_PASS" ] && [ "$NONINTERACTIVE" != "1" ]; then
  printf "DB pass (empty = generate): " 2>/dev/null || true
  if read -r -s _p 2>/dev/null; then DB_PASS="$_p"; echo "" 2>/dev/null || true; else DB_PASS=""; fi
  unset _p 2>/dev/null || true
fi
if [ -z "$DB_PASS" ]; then
  if have openssl; then DB_PASS="$(openssl rand -hex 12 2>/dev/null || echo "")"; fi
  if [ -z "$DB_PASS" ]; then DB_PASS="Uptime-$(date +%s 2>/dev/null || echo 12345)";
    warn "could not generate strong password, using fallback (change it later)"
  else log "generated DB password (saved in config.php)"; fi
fi

# normalize
BOT_USERNAME="$(echo "$BOT_USERNAME" | tr -d '@' | tr -d ' ' 2>/dev/null || echo "$BOT_USERNAME")"
DOMAIN="$(echo "$DOMAIN" | sed -e 's#^https\?://##' -e 's#/$##' 2>/dev/null || echo "$DOMAIN")"
BASE_URL="$(echo "$BASE_URL" | sed -e 's#/$##' 2>/dev/null || echo "$BASE_URL")"
if [ -z "$BASE_URL" ] && [ -n "$DOMAIN" ]; then BASE_URL="https://$DOMAIN"; log "base_url defaulted to $BASE_URL"; fi

# validate (warn-only unless --force missing critical)
if [ -z "$BOT_TOKEN" ]; then note_err "BOT_TOKEN is empty — bot cannot work"; fi
if [ -n "$BOT_TOKEN" ]; then
  if ! echo "$BOT_TOKEN" | grep -Eq '^[0-9]+:[A-Za-z0-9_-]{10,}$'; then
    warn "token format looks wrong (expected 123456:ABC...). Continuing (use --force to bypass tests)."
  fi
fi
if [ -z "$ADMIN_ID" ]; then note_err "ADMIN_ID is empty"; fi
if [ -n "$ADMIN_ID" ]; then
  if ! echo "$ADMIN_ID" | grep -Eq '^[0-9]+$'; then note_err "ADMIN_ID must be numeric (got: $ADMIN_ID)"; fi
fi
if [ -z "$BOT_USERNAME" ]; then warn "BOT_USERNAME empty — deep links will break"; fi
if [ -z "$DOMAIN" ]; then warn "DOMAIN empty — status links will break"; fi
if ! echo "$DB_PORT" | grep -Eq '^[0-9]+$'; then warn "DB_PORT invalid ($DB_PORT), reset to 3306"; DB_PORT=3306; fi
if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then note_err "DB_NAME/DB_USER must not be empty"; fi

# Check for required template files - if missing, try to set APP_DIR to cwd
if [ ! -f "$APP_DIR/config.php" ]; then
  note_err "config.php template not found in $APP_DIR, trying $(pwd)"
  APP_DIR="$(pwd)"
fi
if [ ! -f "$APP_DIR/config.php" ]; then
  note_err "config.php template still not found - some features may fail"
fi
if [ ! -f "$APP_DIR/lib/bootstrap.php" ]; then
  note_err "lib/bootstrap.php missing — wrong APP_DIR?"
fi

# ============================================================ 1) OS packages
PHP_BIN=""
pick_php() {
  for c in php php8.3 php8.2 php8.1 php8.0; do
    if have "$c"; then PHP_BIN="$(command -v "$c")"; return 0; fi
  done
  return 1
}
need_ext() { # need_ext NAME PHPCODE-TO-TEST
  if [ -z "$PHP_BIN" ]; then return 0; fi
  if "$PHP_BIN" -r "$2" >/dev/null 2>&1; then return 1; else return 0; fi
}

if [ "$SKIP_OS" = "1" ]; then log "--skip-os: package install skipped";
else
  MGR="$(pkg_mgr)"
  log "package manager: $MGR"
  if [ "$MGR" = "none" ]; then
    warn "no package manager found — install manually: php8.1+ curl mbstring openssl json pdo_mysql, mysql/mariadb, cron, iputils-ping"
  else
    if ! is_root; then warn "not root — cannot install OS packages (run with sudo). Will only check."; fi
    if pick_php; then log "php found: $PHP_BIN ($("$PHP_BIN" -v 2>/dev/null | head -n1))";
    else
      warn "php not found"
      if is_root; then
        log "installing php + extensions..."
        if [ "$MGR" = "apt" ]; then
          if ! apt-get update -y >>"$LOG_FILE" 2>&1; then warn "apt-get update failed (offline mirror?) — continuing"; fi
          if ! DEBIAN_FRONTEND=noninteractive apt-get install -y php php-cli php-mysql php-curl php-mbstring php-xml php-zip curl cron iputils-ping openssl unzip >>"$LOG_FILE" 2>&1; then
            warn "apt php install failed — trying php8.1 names"
            if ! DEBIAN_FRONTEND=noninteractive apt-get install -y php8.1 php8.1-cli php8.1-mysql php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip curl cron iputils-ping >>"$LOG_FILE" 2>&1; then
              note_err "php install failed; install PHP 8.1+ manually"
            fi
          fi
        elif [ "$MGR" = "dnf" ] || [ "$MGR" = "yum" ]; then
          if ! "$MGR" install -y php php-cli php-mysqlnd php-curl php-mbstring php-xml php-zip curl cronie iputils openssl unzip >>"$LOG_FILE" 2>&1; then
            note_err "php install failed ($MGR)"
          fi
        elif [ "$MGR" = "apk" ]; then
          if ! apk add --no-cache php php-cli php-mysqli php-pdo_mysql php-curl php-mbstring php-xml php-zip curl cronie iputils openssl unzip >>"$LOG_FILE" 2>&1; then
            note_err "php install failed (apk)"
          fi
        else warn "unsupported manager $MGR for auto-install"; fi
      else note_err "php missing and no root to install it"; fi
      pick_php || warn "php still missing"
    fi
    # extensions check (warn, try install on apt)
    if [ -n "$PHP_BIN" ]; then
      MISSING=""; MISSING_APT=""
      if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^curl$'; then MISSING="$MISSING php-curl"; MISSING_APT="$MISSING_APT php-curl"; fi
      if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^mbstring$'; then MISSING="$MISSING php-mbstring"; MISSING_APT="$MISSING_APT php-mbstring"; fi
      if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^pdo_mysql$'; then MISSING="$MISSING php-mysql"; MISSING_APT="$MISSING_APT php-mysql"; fi
      if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^openssl$'; then MISSING="$MISSING openssl(core)"; fi
      if ! "$PHP_BIN" -r 'exit(function_exists("json_encode")?0:1);' >/dev/null 2>&1; then MISSING="$MISSING php-json"; fi
      if [ -n "$MISSING" ]; then
        warn "missing php extensions:$MISSING"
        if [ -n "$MISSING_APT" ] && is_root && [ "$MGR" = "apt" ]; then
          log "trying: apt-get install -y$MISSING_APT"
          if ! DEBIAN_FRONTEND=noninteractive apt-get install -y $MISSING_APT >>"$LOG_FILE" 2>&1; then warn "extension install failed"; fi
        fi
      else ok "php extensions OK (curl/mbstring/pdo_mysql/openssl/json)"; fi
      if ! "$PHP_BIN" -r 'exit(function_exists("proc_open")?0:1);' >/dev/null 2>&1; then warn "proc_open disabled — ping falls back to TCP:443 (ok)"; fi
      if ! have ping && ! have ping.exe; then
        warn "ping binary missing"
        if is_root && [ "$MGR" = "apt" ]; then
          if ! DEBIAN_FRONTEND=noninteractive apt-get install -y iputils-ping >>"$LOG_FILE" 2>&1; then warn "iputils-ping install failed"; fi
        fi
      fi
      if ! have curl; then
        warn "curl binary missing"
        if is_root; then
          if [ "$MGR" = "apt" ]; then DEBIAN_FRONTEND=noninteractive apt-get install -y curl >>"$LOG_FILE" 2>&1 || warn "curl install failed";
          elif [ "$MGR" = "dnf" ] || [ "$MGR" = "yum" ]; then "$MGR" install -y curl >>"$LOG_FILE" 2>&1 || warn "curl install failed"; fi
        fi
      fi
      if ! have mysql && ! have mariadb; then
        warn "mysql client missing (only needed for auto DB create)"
        if is_root; then
          if [ "$MGR" = "apt" ]; then DEBIAN_FRONTEND=noninteractive apt-get install -y default-mysql-client >>"$LOG_FILE" 2>&1 || warn "mysql-client install failed";
          elif [ "$MGR" = "dnf" ] || [ "$MGR" = "yum" ]; then "$MGR" install -y mysql >>"$LOG_FILE" 2>&1 || warn "mysql install failed"; fi
        fi
      fi
    fi
  fi
  pick_php || true
fi
if [ -z "$PHP_BIN" ]; then
  if have php; then PHP_BIN="$(command -v php)"; fi
fi
if [ -z "$PHP_BIN" ]; then note_err "php binary not found — cannot continue with migrate/selftest"; fi
if [ -n "$PHP_BIN" ]; then log "using php: $PHP_BIN"; fi

# ============================================================ 2) permissions
if [ "$SKIP_PERMS" = "1" ]; then log "--skip-perms";
else
  if [ ! -d "$APP_DIR/logs" ]; then
    if ! mkdir -p "$APP_DIR/logs" 2>/dev/null; then warn "cannot create logs/"; else ok "logs/ created"; fi
  fi
  if [ -f "$APP_DIR/.htaccess" ]; then ok ".htaccess present"; else warn ".htaccess missing — logs/lib may be web-readable"; fi
  WWW_USER=""
  if id www-data >/dev/null 2>&1; then WWW_USER="www-data";
  elif id apache >/dev/null 2>&1; then WWW_USER="apache";
  elif id nginx >/dev/null 2>&1; then WWW_USER="nginx"; fi
  if [ -n "$WWW_USER" ] && is_root; then
    if ! chown -R "$WWW_USER:$WWW_USER" "$APP_DIR/logs" 2>/dev/null; then warn "chown logs failed"; else ok "logs/ owned by $WWW_USER"; fi
    if ! chmod 755 "$APP_DIR/logs" 2>/dev/null; then warn "chmod logs failed"; fi
  else
    if ! chmod 775 "$APP_DIR/logs" 2>/dev/null; then warn "chmod logs failed (non-root?)"; fi
  fi
  if [ ! -w "$APP_DIR/logs" ]; then warn "logs/ not writable — cron/webhook will fail to lock"; else ok "logs/ writable"; fi
fi

# ============================================================ 3) config.php
if [ -f "$APP_DIR/config.php" ]; then
  BK="$APP_DIR/config.php.bak.$(date +%Y%m%d-%H%M%S 2>/dev/null || echo bak)"
  if ! cp "$APP_DIR/config.php" "$BK" 2>/dev/null; then warn "config backup failed"; else log "backup: $BK"; fi
else note_err "config.php template missing"; fi

php_escape() {
  # escape for PHP single-quoted string: backslash first, then single quote.
  # (a trailing lone backslash would otherwise escape the closing quote)
  printf "%s" "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g" 2>/dev/null || printf "%s" "$1"
}
C_TOKEN="$(php_escape "$BOT_TOKEN")"; C_ADMIN="$(php_escape "$ADMIN_ID")"
C_USER="$(php_escape "$BOT_USERNAME")"; C_DOMAIN="$(php_escape "$DOMAIN")"
C_BASE="$(php_escape "$BASE_URL")"; C_DH="$(php_escape "$DB_HOST")"
C_DP="$(php_escape "$DB_PORT")"; C_DN="$(php_escape "$DB_NAME")"
C_DU="$(php_escape "$DB_USER")"; C_DPW="$(php_escape "$DB_PASS")"
C_TZ="$(php_escape "$TZ_OFFSET")"

if ! cat > "$APP_DIR/config.php" <<EOF
<?php
/* Auto-generated by install.sh on $(date -u '+%Y-%m-%d %H:%M:%S UTC' 2>/dev/null || echo install) */
return [
    'bot_token'    => '$C_TOKEN',
    'admin_id'     => '$C_ADMIN',
    'bot_username' => '$C_USER',
    'domain'       => '$C_DOMAIN',
    'base_url'     => '$C_BASE',
    'db' => [
        'host' => '$C_DH',
        'port' => '$C_DP',
        'name' => '$C_DN',
        'user' => '$C_DU',
        'pass' => '$C_DPW',
    ],
    'tz_offset' => '$C_TZ',
    'defaults' => [
        'check_interval'  => '20',
        'max_sites'       => '5',
        'vip_max_sites'   => '10',
        'max_users'       => '0',
        'fail_threshold'  => '2',
        'access_mode'     => 'open',
        'price'           => '0',
        'card'            => '',
        'vip_days'        => '30',
        'notify'          => '1',
        'pause_all'       => '0',
        'notify_slow'     => '1',
        'group_max_sites' => '10',
        'group_mention'   => 'admins',
        'ssl_warn_days'   => '14',
        'ssl_interval'    => '3600',
        'domain_warn_days'=> '14',
        'whois_interval'  => '21600',
        'max_domains'     => '10',
        'points_per_day'         => '1',
        'points_per_uptime_hour' => '5',
        'maint_interval'  => '1800',
        'resp_interval'   => '3600',
    ],
];
EOF
then note_err "cannot write config.php (permissions?)";
else ok "config.php written"; fi

if [ -n "$PHP_BIN" ]; then
  if ! "$PHP_BIN" -l "$APP_DIR/config.php" >/dev/null 2>&1; then note_err "config.php syntax error";
  else ok "config.php syntax OK"; fi
fi
if [ -n "$PHP_BIN" ]; then
  for f in index.php api.php status.php pay.php table.php botapi.php cron/checker.php lib/bootstrap.php; do
    if [ -f "$APP_DIR/$f" ]; then
      if ! "$PHP_BIN" -l "$APP_DIR/$f" >/dev/null 2>&1; then warn "syntax error in $f"; fi
    else warn "missing file: $f"; fi
  done
fi

# ============================================================ 4) database
db_ok() { # test via php PDO (uses real config)
  [ -n "$PHP_BIN" ] || return 1
  "$PHP_BIN" -r '
    $c=require($argv[1]); $db=$c["db"];
    try { $p=new PDO("mysql:host={$db["host"]};port={$db["port"]};dbname={$db["name"]};charset=utf8mb4",
      $db["user"], $db["pass"], [PDO::ATTR_TIMEOUT=>6]); $p->query("SELECT 1"); exit(0); }
    catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
  ' "$APP_DIR/config.php" >>"$LOG_FILE" 2>&1
}
mysql_exec() { # mysql_exec SQL — tries app user, then root socket, then root+pass
  local _sql="$1" M=""
  if have mysql; then M=mysql; elif have mariadb; then M=mariadb; else return 1; fi
  if MYSQL_PWD="$DB_PASS" "$M" -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -e "SELECT 1" >/dev/null 2>&1; then
    echo "$_sql" | MYSQL_PWD="$DB_PASS" "$M" -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" 2>>"$LOG_FILE" && return 0
  fi
  if [ "$DB_HOST" = "127.0.0.1" ] || [ "$DB_HOST" = "localhost" ]; then
    if "$M" -u root -e "SELECT 1" >/dev/null 2>&1; then
      echo "$_sql" | "$M" -u root 2>>"$LOG_FILE" && return 0
    fi
    if [ -n "$MYSQL_ROOT_PASS" ]; then
      if echo "$_sql" | MYSQL_PWD="$MYSQL_ROOT_PASS" "$M" -u root 2>>"$LOG_FILE"; then return 0; fi
    fi
  fi
  return 1
}

if [ "$SKIP_DB" = "1" ]; then log "--skip-db";
else
  # 4a) local mysql server up? (only for localhost)
  if [ "$DB_HOST" = "127.0.0.1" ] || [ "$DB_HOST" = "localhost" ]; then
    if have mysql || have mariadb; then ok "mysql client present";
    else warn "no mysql client — will rely on PHP PDO only"; fi
    if ! (mysqladmin -h "$DB_HOST" -P "$DB_PORT" ping 2>/dev/null || mysql -h "$DB_HOST" -P "$DB_PORT" -u root -e "SELECT 1" >/dev/null 2>&1); then
      warn "mysql not reachable at $DB_HOST:$DB_PORT"
      # Try installing MariaDB server for common package managers
      if is_root && [ "$SKIP_OS" != "1" ]; then
        log "installing mariadb-server for $(pkg_mgr)..."
        if [ "$(pkg_mgr)" = "apt" ]; then
          if DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server >>"$LOG_FILE" 2>&1; then
            service mariadb start >>"$LOG_FILE" 2>&1 || systemctl start mariadb >>"$LOG_FILE" 2>&1 || service mysql start >>"$LOG_FILE" 2>&1 || warn "cannot start mariadb (start it manually)"
          fi
        elif [ "$(pkg_mgr)" = "dnf" ] || [ "$(pkg_mgr)" = "yum" ]; then
          if "$(pkg_mgr)" install -y mariadb-server >>"$LOG_FILE" 2>&1; then
            service mariadb start >>"$LOG_FILE" 2>&1 || systemctl start mariadb >>"$LOG_FILE" 2>&1 || warn "cannot start mariadb (start it manually)"
          fi
        elif [ "$(pkg_mgr)" = "apk" ]; then
          if apk add --no-cache mariadb-server >>"$LOG_FILE" 2>&1; then
            service mariadb start >>"$LOG_FILE" 2>&1 || rc-service mariadb start >>"$LOG_FILE" 2>&1 || warn "cannot start mariadb (start it manually)"
          fi
        else warn "unsupported package manager $(pkg_mgr) for mariadb auto-install"; fi
      fi
      # retry up to 30s for MySQL to start (skip if mysqladmin missing)
      if have mysqladmin; then
        for i in $(seq 1 30 2>/dev/null || echo 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15); do
          if mysqladmin -h "$DB_HOST" -P "$DB_PORT" ping 2>/dev/null; then break; fi
          sleep 1
        done
      else
        sleep 2
      fi
    fi
  fi
  # 4b) create db+user if possible (idempotent, safe-quoted via mysql client)
  SAFE_DB="$(echo "$DB_NAME" | tr -cd 'A-Za-z0-9_$-' 2>/dev/null || echo "$DB_NAME")"
  if [ "$SAFE_DB" != "$DB_NAME" ]; then warn "db name has odd chars — using as-is, may fail"; SAFE_DB="$DB_NAME"; fi
  if [ -n "$DB_PASS" ]; then
    ESC_PASS="$(printf "%s" "$DB_PASS" | sed "s/'/''/g" 2>/dev/null || printf "%s" "$DB_PASS")"
    if ! mysql_exec "CREATE DATABASE IF NOT EXISTS \`$SAFE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$ESC_PASS'; CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$ESC_PASS'; GRANT ALL PRIVILEGES ON \`$SAFE_DB\`.* TO '$DB_USER'@'%'; GRANT ALL PRIVILEGES ON \`$SAFE_DB\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"; then
      warn "auto-create DB failed (no root? remote host?) — assuming DB/user already exist"
    else
      ok "database/user ensured"
      # user may pre-exist with a different password (CREATE ... IF NOT EXISTS
      # does not rotate it) — sync password so app credentials work. Warn-only.
      if ! mysql_exec "ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$ESC_PASS'; ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$ESC_PASS'; FLUSH PRIVILEGES;"; then
        warn "password sync skipped (old MySQL/MariaDB without ALTER USER?) — continuing"
      fi
    fi
  else
    if ! mysql_exec "CREATE DATABASE IF NOT EXISTS \`$SAFE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"; then
      warn "auto-create DB failed — assuming it exists"
    fi
  fi
  # 4c) connectivity via app credentials
  if db_ok; then ok "DB connection OK";
  else
    warn "DB connection with app user failed — see install.log tail:"
    tail -n 5 "$LOG_FILE" 2>/dev/null || true
    warn "fix: check DB_HOST/PORT/NAME/USER/PASS and mysql GRANTs, then re-run ./install.sh"
    if [ "$FORCE" != "1" ] && [ "$NONINTERACTIVE" = "1" ]; then note_err "database unreachable"; fi
  fi
  # 4d) migrate
  if [ -n "$PHP_BIN" ]; then
    if [ ! -f "$APP_DIR/table.php" ]; then
      note_err "table.php missing - skipping migration";
    else
      log "running migrate (php table.php)..."
      # Run migration with up to 3 retries
      for attempt in 1 2 3; do
        if "$PHP_BIN" "$APP_DIR/table.php" >>"$LOG_FILE" 2>&1; then
          ok "migrate succeeded on attempt $attempt"
          break
        else
          warn "table.php attempt $attempt failed, retrying after 3s..."
          sleep 3
        fi
      done
      # Verify core tables after migration
      if ! "$PHP_BIN" -r '
        $c=require($argv[1]); $db=$c["db"];
        try { $p=new PDO("mysql:host={$db["host"]};port={$db["port"]};dbname={$db["name"]};charset=utf8mb4",$db["user"],$db["pass"]);
          foreach (["user","site","settings","check_log"] as $t){ $p->query("SELECT 1 FROM `$t` LIMIT 1"); } exit(0); }
        catch (Throwable $e){ fwrite(STDERR,$e->getMessage()); exit(1); }
      ' "$APP_DIR/config.php" >>"$LOG_FILE" 2>&1; then
        note_err "post-migrate check failed (tables missing)";
      else
        ok "tables ready";
      fi
    fi
  fi
fi

# ============================================================ 5) selftest
if [ -n "$PHP_BIN" ] && [ -f "$APP_DIR/cron/checker.php" ]; then
  log "selftest..."
  if ! "$PHP_BIN" "$APP_DIR/cron/checker.php" --selftest >>"$LOG_FILE" 2>&1; then warn "selftest failed (see install.log) — cron still installed";
  else ok "selftest passed ($(grep -a -m1 -o 'OK.*' "$LOG_FILE" 2>/dev/null || echo ok))"; fi
  if ! "$PHP_BIN" "$APP_DIR/cron/checker.php" --once --quiet >>"$LOG_FILE" 2>&1; then warn "--once round failed (empty install = normal if no sites yet)"; else ok "first check round done"; fi
else warn "checker.php missing — selftest skipped"; fi

# ============================================================ 6) cron
if [ "$SKIP_CRON" = "1" ]; then log "--skip-cron";
else
  if [ -z "$PHP_BIN" ]; then warn "no php — cron skipped";
  elif [ ! -f "$APP_DIR/cron/checker.php" ]; then warn "cron/checker.php missing — cron skipped";
  else
    LINE="* * * * * $PHP_BIN $APP_DIR/cron/checker.php >> $APP_DIR/logs/cron.log 2>&1"
    # /etc/cron.d entries REQUIRE a user field (unlike per-user crontabs)
    CRON_D_LINE="* * * * * root $PHP_BIN $APP_DIR/cron/checker.php >> $APP_DIR/logs/cron.log 2>&1"
    if case "$APP_DIR" in *" "*) true;; *) false;; esac; then
      warn "APP_DIR contains spaces ($APP_DIR) — cron line may not work; prefer a path without spaces"
    fi
    if ! have crontab && is_root && [ "$SKIP_OS" != "1" ]; then
      log "crontab tool missing — trying to install cron package..."
      if [ "$(pkg_mgr)" = "apt" ]; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y cron >>"$LOG_FILE" 2>&1 || warn "cron package install failed"
      elif [ "$(pkg_mgr)" = "dnf" ] || [ "$(pkg_mgr)" = "yum" ]; then
        "$(pkg_mgr)" install -y cronie >>"$LOG_FILE" 2>&1 || warn "cronie install failed"
      elif [ "$(pkg_mgr)" = "apk" ]; then
        apk add --no-cache cronie >>"$LOG_FILE" 2>&1 || warn "cronie install failed"
      fi
    fi
    if have crontab; then
      OLD="$(crontab -l 2>/dev/null || true)"
      if echo "$OLD" | grep -qF "$APP_DIR/cron/checker.php"; then ok "cron already installed";
      else
        # Try to add the cron line, handling empty crontab gracefully
        if ( echo "$OLD" | grep -v '^$' 2>/dev/null; echo "$LINE" ) | crontab - 2>>"$LOG_FILE"; then
          ok "cron installed via crontab: $LINE"
        else
          warn "crontab write failed — trying /etc/cron.d"
          if is_root; then
            # Write to /etc/cron.d as fallback (needs user field!)
            echo "$CRON_D_LINE" > /etc/cron.d/uptimebot 2>>"$LOG_FILE" && ok "cron file: /etc/cron.d/uptimebot" || note_err "cron install failed (write to /etc/cron.d failed)"
          else
            note_err "cron install failed (not root, no crontab write)"
          fi
        fi
      fi
    elif is_root; then
      # Write directly to /etc/cron.d when no crontab tool available but we're root
      echo "$LINE" > /etc/cron.d/uptimebot 2>>"$LOG_FILE" && ok "cron file: /etc/cron.d/uptimebot" || note_err "cron install failed (root but write failed)"
    else
      note_err "no crontab tool and not root — add manually: $LINE"
    fi
    # Ensure cron service is running if we installed it
    if is_root; then
      service cron start >>"$LOG_FILE" 2>&1 || systemctl start cron >>"$LOG_FILE" 2>&1 || true
    fi
  fi
fi

# ============================================================ 7) webhook
sha256() {
  if have sha256sum; then printf "%s" "$1" | sha256sum 2>/dev/null | cut -d' ' -f1;
  elif have shasum; then printf "%s" "$1" | shasum -a 256 2>/dev/null | cut -d' ' -f1;
  elif [ -n "$PHP_BIN" ]; then "$PHP_BIN" -r 'echo hash("sha256",$argv[1]);' "$1" 2>/dev/null;
  else echo ""; fi
}
if [ "$SKIP_WEBHOOK" = "1" ]; then log "--skip-webhook";
else
  if [ -z "$BOT_TOKEN" ] || [ -z "$BASE_URL" ]; then warn "webhook skipped (need token + base-url)";
  elif ! have curl; then warn "webhook skipped (curl missing)";
  else
    SECRET="$(sha256 "${BOT_TOKEN}_uptime_webhook_secret")"
    HOOK="${BASE_URL}/index.php"
    if [ -z "$SECRET" ]; then warn "cannot compute webhook secret";
    else
      log "setting webhook to $HOOK ..."
      RESP="$(curl -s -m 20 --retry 2 -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
        --data-urlencode "url=$HOOK" --data-urlencode "secret_token=$SECRET" \
        --data-urlencode "allowed_updates=[\"message\",\"callback_query\",\"channel_post\",\"my_chat_member\"]" 2>>"$LOG_FILE" || echo "")"
      if echo "$RESP" | grep -q '"ok":true'; then ok "webhook set";
      else
        warn "setWebhook failed: $(echo "$RESP" | head -c 200 2>/dev/null || echo no-response)"
        warn "check token/HTTPS reachability, then set manually"
      fi
      INFO="$(curl -s -m 20 "https://api.telegram.org/bot${BOT_TOKEN}/getWebhookInfo" 2>>"$LOG_FILE" || echo "")"
      if [ -n "$INFO" ]; then echo "$INFO" >>"$LOG_FILE" 2>&1 || true; fi
      if echo "$INFO" | grep -q '"ok":true'; then ok "webhook info OK"; else warn "getWebhookInfo failed"; fi
    fi
  fi
fi

# ============================================================ 8) webserver hint
DOC_OK=1
if [ -n "$PHP_BIN" ]; then
  if ! "$PHP_BIN" -r 'exit(0);' >/dev/null 2>&1; then warn "php CLI broken"; DOC_OK=0; fi
fi
if [ -f "$APP_DIR/.htaccess" ]; then :; else warn "no .htaccess (apache hardening missing)"; fi
if have nginx; then log "nginx detected — ensure root points at $APP_DIR and php-fpm passes *.php"; DOC_OK=1; fi
if have apache2 || have httpd; then log "apache detected — vhost docroot must be $APP_DIR (or parent with Alias)"; DOC_OK=1; fi
if [ "$DOC_OK" = "1" ]; then ok "web layer ready (serve $APP_DIR over HTTPS)"; fi

# ================================================================= summary
echo ""
echo "================ RESULT ================"
if [ "$ERRORS" = "0" ]; then echo "SUCCESS — raw server is ready.";
else echo "DONE WITH $ERRORS ERROR(S) — see install.log, fix above, re-run (idempotent)."; fi
echo "App:      $APP_DIR"
echo "Base URL: $BASE_URL"
echo "Status:   $BASE_URL/status.php"
if [ -n "$BOT_TOKEN" ]; then
  echo "Cron secret:    $(sha256 "${BOT_TOKEN}_uptime_cron_secret")"
  echo "Table secret:   $(sha256 "${BOT_TOKEN}_uptime_table_secret")"
  echo "Webhook secret: $(sha256 "${BOT_TOKEN}_uptime_webhook_secret")"
  echo "Cron line: * * * * * $PHP_BIN $APP_DIR/cron/checker.php"
fi
echo "Log: $LOG_FILE"
if [ "$ERRORS" = "0" ]; then exit 0; else exit 1; fi
