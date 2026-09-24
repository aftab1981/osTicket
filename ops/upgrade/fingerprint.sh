#!/bin/sh
# Row count + checksum of every osTicket table, one line per table.
# Run before and after the upgrade, then diff the two files.
#   sh ops/upgrade/fingerprint.sh -u <user> -p <db> > before.txt
# Read-only: SELECT COUNT(*) and CHECKSUM TABLE only.
MYSQL="${MYSQL:-mysql}"
TABLES=$($MYSQL -N "$@" -e "SHOW TABLES")
for t in $TABLES; do
    n=$($MYSQL -N "$@" -e "SELECT COUNT(*) FROM \`$t\`")
    c=$($MYSQL -N "$@" -e "CHECKSUM TABLE \`$t\`" | awk '{print $2}')
    printf '%-40s rows=%-8s checksum=%s\n' "$t" "$n" "$c"
done
