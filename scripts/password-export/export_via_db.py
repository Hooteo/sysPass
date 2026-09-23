#!/usr/bin/env python3
"""
Export every account's password IN CLEARTEXT to a CSV file, reading
directly from the sysPass database - the direct-DB counterpart to
export_via_api.py in this same folder (use that one if the app itself is
still reachable; use this one if it isn't, e.g. the app container is
down but the database is still up, which is closer to the actual
disaster-recovery scenario this export exists for).

This is a Python port of a working PHP script the team used to run as
`php export.php <MasterPassword>` against the same database (see git
history for the original). The query and CSV shape are kept close to
that original on purpose. The decryption itself is done by
defuse_decrypt.py, a from-source Python re-implementation of
defuse/php-encryption's decrypt path, verified against real ciphertext
produced by the actual PHP library before being trusted here - see that
file's docstring and the commit history for the verification.

USAGE
----------------------------------------------------------------
    pip install -r requirements.txt
    python3 export_via_db.py --host 127.0.0.1 --database syspass --db-user root

You'll be prompted for the database password and the sysPass master
password interactively (never pass either on the command line - they'd
land in your shell history and in `ps` output visible to other users on
the same machine).

The script sanity-checks the master password against the first account
it finds before processing the rest - if it's wrong, it stops
immediately with a clear message instead of grinding through the whole
database failing row by row.

AFTER YOU RUN THIS
----------------------------------------------------------------
The output file is every credential in your company in plain text.
    - Print it and put it in the safe immediately.
    - Delete the CSV file from disk right after - this script does not
      do that for you (securely wiping a file is filesystem/OS-dependent
      and outside what a script can promise; at minimum remove it and
      empty the trash/recycle bin on the machine you ran it from).
    - Don't email it, don't put it in cloud storage, don't commit it
      anywhere, don't leave it in a Downloads folder.
"""

import argparse
import csv
import getpass
import sys
from collections import defaultdict
from typing import Any, Dict, List

try:
    import pymysql
except ImportError:
    sys.exit("This script needs PyMySQL: pip install -r requirements.txt")

from defuse_decrypt import DecryptError, decrypt_account_password

ACCOUNTS_QUERY = """
    SELECT
        a.id,
        ug.name  AS userGroup,
        u.name   AS userName,
        c.name   AS clientName,
        cat.name AS categoryName,
        a.name   AS accountName,
        a.login,
        a.url,
        a.pass,
        a.`key`,
        a.notes,
        a.dateAdd,
        a.isPrivate,
        a.isPrivateGroup,
        a.passDate
    FROM Account a
    LEFT JOIN UserGroup ug ON a.userGroupId = ug.id
    LEFT JOIN User u       ON a.userId = u.id
    LEFT JOIN Client c     ON a.clientId = c.id
    LEFT JOIN Category cat ON a.categoryId = cat.id
    ORDER BY a.id
"""

TAGS_QUERY = """
    SELECT att.accountId, t.name
    FROM AccountToTag att
    INNER JOIN Tag t ON t.id = att.tagId
"""

CSV_FIELDS = [
    "id", "userGroup", "userName", "clientName", "categoryName",
    "accountName", "login", "url", "password", "notes", "dateAdd",
    "isPrivate", "isPrivateGroup", "passDate", "tags",
]


def as_hex_str(value) -> str:
    """DB drivers return varbinary columns as bytes; the actual content
    is an ASCII hex string (that's the format sysPass itself stores)."""
    if isinstance(value, (bytes, bytearray)):
        return value.decode("ascii")
    return value


def fetch_tags(cursor) -> Dict[int, List[str]]:
    cursor.execute(TAGS_QUERY)
    tags_by_account: Dict[int, List[str]] = defaultdict(list)
    for row in cursor.fetchall():
        tags_by_account[row["accountId"]].append(row["name"])
    return tags_by_account


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--host", default="127.0.0.1", help="Database host (default: 127.0.0.1)")
    parser.add_argument("--port", type=int, default=3306, help="Database port (default: 3306)")
    parser.add_argument("--database", default="syspass", help="Database name (default: syspass)")
    parser.add_argument("--db-user", default="root", help="Database user (default: root)")
    parser.add_argument("--db-password", default=None, help="Database password (insecure - prefer the interactive prompt)")
    parser.add_argument("--output", default="accounts_export.csv", help="CSV file to write (default: accounts_export.csv)")
    args = parser.parse_args()

    db_password = args.db_password or getpass.getpass(f"Database password for {args.db_user}: ")
    master_password = getpass.getpass("sysPass master password: ")

    try:
        conn = pymysql.connect(
            host=args.host,
            port=args.port,
            user=args.db_user,
            password=db_password,
            database=args.database,
            cursorclass=pymysql.cursors.DictCursor,
            charset="utf8mb4",
        )
    except pymysql.MySQLError as e:
        sys.exit(f"Could not connect to the database: {e}")

    with conn:
        with conn.cursor() as cursor:
            print("Fetching accounts...", file=sys.stderr)
            cursor.execute(ACCOUNTS_QUERY)
            accounts = cursor.fetchall()
            print(f"Found {len(accounts)} accounts.", file=sys.stderr)

            print("Fetching tags...", file=sys.stderr)
            tags_by_account = fetch_tags(cursor)

        if not accounts:
            sys.exit("No accounts found - check --database and the connection details.")

        # Sanity-check the master password against the first decryptable
        # account before grinding through the rest of the database.
        for account in accounts:
            pass_hex = as_hex_str(account["pass"])
            key_hex = as_hex_str(account["key"])
            if not pass_hex or not key_hex:
                continue
            try:
                decrypt_account_password(pass_hex, key_hex, master_password)
                break
            except DecryptError as e:
                sys.exit(
                    f"Master password check failed on account id={account['id']} "
                    f"({account['accountName']}): {e}\n"
                    "Double-check the master password and try again."
                )
        else:
            sys.exit("No account had both a password and a key set - nothing to verify against.")

        rows = []
        failures = []

        for i, account in enumerate(accounts, start=1):
            name = account["accountName"]
            print(f"[{i}/{len(accounts)}] {name} (id={account['id']})...", file=sys.stderr)

            pass_hex = as_hex_str(account["pass"])
            key_hex = as_hex_str(account["key"])

            if not pass_hex or not key_hex:
                password = ""
            else:
                try:
                    password = decrypt_account_password(pass_hex, key_hex, master_password)
                except DecryptError as e:
                    print(f"  FAILED: {e}", file=sys.stderr)
                    failures.append((account["id"], name, str(e)))
                    password = "<<EXPORT FAILED - SEE LOG>>"

            tags = "|".join(tags_by_account.get(account["id"], []))

            rows.append({
                "id": account["id"],
                "userGroup": account["userGroup"] or "",
                "userName": account["userName"] or "",
                "clientName": account["clientName"] or "",
                "categoryName": account["categoryName"] or "",
                "accountName": name,
                "login": account["login"] or "",
                "url": account["url"] or "",
                "password": password,
                "notes": (account["notes"] or "").replace("\r", " ").replace("\n", " "),
                "dateAdd": account["dateAdd"],
                "isPrivate": account["isPrivate"],
                "isPrivateGroup": account["isPrivateGroup"],
                "passDate": account["passDate"],
                "tags": tags,
            })

    with open(args.output, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_FIELDS)
        writer.writeheader()
        writer.writerows(rows)

    print(f"\nWrote {len(rows)} accounts to {args.output}", file=sys.stderr)

    if failures:
        print(f"\n{len(failures)} account(s) failed to decrypt - check them manually:", file=sys.stderr)
        for account_id, name, error in failures:
            print(f"  - id={account_id} ({name}): {error}", file=sys.stderr)

    print(
        "\nThis file contains every account password in plain text.\n"
        "Print it, put it in the safe, then delete this file.",
        file=sys.stderr,
    )

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
