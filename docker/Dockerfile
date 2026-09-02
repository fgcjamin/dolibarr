# Overlay image: official Dolibarr base + this fork's custom accountancy/treasury REST API code.
#
# The base image already ships the full install/entrypoint/cron logic and every DOLI_* env var
# documented at https://github.com/Dolibarr/dolibarr-docker — this Dockerfile only adds the files
# that differ between this fork and the upstream release it is based on.
#
# Kept in sync with: git diff --stat <base-tag> HEAD -- htdocs
# If that diff ever touches a path outside the COPY lines below, add it here — the
# docker-build workflow's guard step fails the build if it doesn't.
ARG DOLIBARR_BASE_VERSION=23.0.3
FROM dolibarr/dolibarr:${DOLIBARR_BASE_VERSION}

COPY htdocs/accountancy/ /var/www/html/accountancy/
COPY htdocs/api/class/api_setup.class.php /var/www/html/api/class/api_setup.class.php
COPY htdocs/core/class/fiscalyear.class.php /var/www/html/core/class/fiscalyear.class.php
COPY htdocs/core/lib/functions2.lib.php /var/www/html/core/lib/functions2.lib.php
COPY htdocs/compta/bank/class/account.class.php /var/www/html/compta/bank/class/account.class.php
COPY htdocs/compta/bank/class/api_bankaccounts.class.php /var/www/html/compta/bank/class/api_bankaccounts.class.php
