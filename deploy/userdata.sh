#!/bin/bash
#
# EC2 user data for the LetsVote webapp instances (Amazon Linux 2023).
#
# Paste this into the "User data" box of the launch template. It runs as root
# on first boot, before the instance can pass its health check.
#
# EDIT THE FOUR VARIABLES BELOW, then read the script top to bottom with your
# students — every line corresponds to something in the architecture diagram.
#
# Output goes to /var/log/cloud-init-output.log on the instance.

set -euxo pipefail

# ---------------------------------------------------------------------------
# 1. Things you must change
# ---------------------------------------------------------------------------
APP_REPO="https://github.com/jayakuma998/letsvote.git"    # or use the S3 method below
SECRET_ID="letsvote/app-config"                           # AWS Secrets Manager secret name
AWS_REGION="us-east-2"
BASE_URL="https://letsvotes.com"                          # your real domain, no trailing slash

APP_DIR="/var/www/letsvote"

# ---------------------------------------------------------------------------
# 2. Packages
#    php-mysqlnd  -> PDO MySQL driver
#    php-mbstring -> mb_* string functions
#    mariadb105   -> the `mysql` client, for loading sql/schema.sql by hand
#
#    AL2023 offers PHP 8.1 through 8.5. Plain `php` installs the distribution
#    default; to pin a version instead, use `php8.3`, `php8.3-mysqlnd`, etc.
# ---------------------------------------------------------------------------
dnf -y update
dnf -y install httpd php php-cli php-mysqlnd php-mbstring php-opcache php-xml \
               git jq mariadb105

# The application uses PHP 8.1 syntax (enums of union types, `never` return
# type, readonly-style promotion). Fail loudly at boot rather than serving 500s.
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || {
    echo "letsvote: PHP 8.1+ required, found $(php -r 'echo PHP_VERSION;')" >&2
    exit 1
}

# ---------------------------------------------------------------------------
# 3. Application code
#    Public repo: git clone. Private repo: push a zip to S3 instead and use
#      aws s3 cp s3://YOUR-BUCKET/letsvote.zip /tmp/ && unzip -o /tmp/letsvote.zip -d "$APP_DIR"
#    (the instance role needs s3:GetObject on that object)
# ---------------------------------------------------------------------------
rm -rf "$APP_DIR"
git clone --depth 1 "$APP_REPO" "$APP_DIR"

# ---------------------------------------------------------------------------
# 4. Configuration from AWS Secrets Manager
#
#    The secret is a JSON object, for example:
#    {
#      "db_host": "database-1.xxxx.us-east-2.rds.amazonaws.com",
#      "db_host_read": "database-1-replica.xxxx.us-east-2.rds.amazonaws.com",
#      "db_name": "letsvote",
#      "db_user": "letsvote_app",
#      "db_pass": "...",
#      "cognito_user_pool_id": "us-east-2_XXXXXXXXX",
#      "cognito_client_id": "...",
#      "cognito_client_secret": "...",
#      "cognito_domain": "letsvote-auth.auth.us-east-2.amazoncognito.com"
#    }
#
#    No credentials are baked into the AMI, the launch template or the repo:
#    the instance profile lets this instance, and only this instance, read it.
# ---------------------------------------------------------------------------
mkdir -p /etc/letsvote

# IMPORTANT: turn OFF command tracing first. With `set -x` still on, bash would
# print the assignment below — database password and all — straight into
# /var/log/cloud-init-output.log, which is readable by anyone who can reach the
# instance or its console output.
set +x

SECRET_JSON="$(aws secretsmanager get-secret-value \
                 --secret-id "$SECRET_ID" \
                 --region "$AWS_REGION" \
                 --query SecretString --output text)"

# A heredoc quoted as 'INI' would stop the shell expanding $(...) — we want
# expansion here, so it is unquoted, and every value comes from jq -r.
cat > /etc/letsvote/config.ini <<INI
[app]
base_url = "${BASE_URL}"
env = "production"

[db]
host      = "$(jq -r '.db_host'      <<<"$SECRET_JSON")"
host_read = "$(jq -r '.db_host_read' <<<"$SECRET_JSON")"
port      = 3306
name      = "$(jq -r '.db_name'      <<<"$SECRET_JSON")"
user      = "$(jq -r '.db_user'      <<<"$SECRET_JSON")"
pass      = "$(jq -r '.db_pass'      <<<"$SECRET_JSON")"

[cognito]
region        = "${AWS_REGION}"
user_pool_id  = "$(jq -r '.cognito_user_pool_id'  <<<"$SECRET_JSON")"
client_id     = "$(jq -r '.cognito_client_id'     <<<"$SECRET_JSON")"
client_secret = "$(jq -r '.cognito_client_secret' <<<"$SECRET_JSON")"
domain        = "$(jq -r '.cognito_domain'        <<<"$SECRET_JSON")"
INI

# Readable by Apache, by nobody else.
chown root:apache /etc/letsvote/config.ini
chmod 0640 /etc/letsvote/config.ini
unset SECRET_JSON

set -x   # safe to trace again

# ---------------------------------------------------------------------------
# 5. Apache
# ---------------------------------------------------------------------------
cp "$APP_DIR/deploy/letsvote.conf" /etc/httpd/conf.d/letsvote.conf

# The stock welcome page would otherwise answer "/" before our vhost does.
rm -f /etc/httpd/conf.d/welcome.conf

# Hide the PHP version from response headers.
echo 'expose_php = Off' > /etc/php.d/99-letsvote.ini

chown -R root:apache "$APP_DIR"
chmod -R 0750 "$APP_DIR"

# SELinux is enforcing on AL2023: let Apache read the app and make outbound
# connections (RDS on 3306, Cognito on 443).
if command -v setsebool >/dev/null 2>&1; then
    setsebool -P httpd_can_network_connect 1 || true
    setsebool -P httpd_can_network_connect_db 1 || true
    restorecon -R "$APP_DIR" || true
fi

systemctl enable --now httpd
systemctl restart httpd

# ---------------------------------------------------------------------------
# 6. Prove to ourselves the instance is serving before the ALB tries
# ---------------------------------------------------------------------------
curl -fsS http://localhost/health.php || {
    echo "health check failed on boot" >&2
    exit 1
}

echo "letsvote: instance $(hostname) is ready"
