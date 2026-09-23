#!/usr/bin/env python3
"""
Export every account's password IN CLEARTEXT to a CSV file, for the
"break glass" printed/safe-stored copy required by company policy in
case sysPass itself becomes unavailable.

WHY THIS USES THE API INSTEAD OF READING THE DATABASE DIRECTLY
----------------------------------------------------------------
Account passwords are encrypted at rest with defuse/php-encryption (a
password-protected key wrapping an AES-256-CTR + HMAC-SHA256 ciphertext).
Re-implementing that decryption in Python, by hand, for a script whose
whole purpose is producing the copy of last resort, is exactly the kind
of place a subtle bug would go unnoticed until the day it actually
matters. This script instead calls sysPass's own JSON-RPC API
(account/search, account/viewPass) so the decryption is done by sysPass's
own, already-tested PHP code - this script only ever handles the
already-decrypted plaintext.

SETUP (do this once, as an admin, from the sysPass web UI)
----------------------------------------------------------------
1. Go to Users -> Access Manager -> API Tokens (Gestione accessi -> Tokens
   API), and create TWO tokens for your own admin account:
     - one for the action "Search Accounts" / "ACCOUNT_SEARCH"
     - one for the action "View Account's Password" / "ACCOUNT_VIEW_PASS"
   Each sysPass API token is bound to exactly one action - you need both.
   When creating the ACCOUNT_VIEW_PASS token you'll be asked for a
   password: use your own sysPass login password. That same password is
   what this script calls --token-pass below (it is NOT the shared
   master password - sysPass unwraps the master password from an
   internal vault using your login password + that token together).
2. Have your sysPass base URL ready, e.g. https://syspass.example.com/

USAGE
----------------------------------------------------------------
    pip install requests
    python3 export_passwords_cleartext.py \
        --url https://syspass.example.com \
        --search-token <token for ACCOUNT_SEARCH> \
        --viewpass-token <token for ACCOUNT_VIEW_PASS> \
        --output accounts_export.csv

The script prompts for --token-pass interactively (never pass it on the
command line - it would land in your shell history). Add --insecure only
if your instance uses a self-signed certificate you can't otherwise
verify (see USE_SSL/README.md) - this disables certificate checking for
this run.

AFTER YOU RUN THIS
----------------------------------------------------------------
The output file is every credential in your company in plain text.
    - Print it and put it in the safe immediately.
    - Delete the CSV file from disk right after (this script does not
      do that for you - securely wiping a file is filesystem/OS-
      dependent and outside what this script can promise; on the
      machine you ran it from, at minimum remove it and empty the
      trash/recycle bin).
    - Don't email it, don't put it in cloud storage, don't commit it
      anywhere, don't leave it in a Downloads folder.
    - Consider deleting the two API tokens afterwards (Access Manager ->
      API Tokens) if this is a one-off export rather than a recurring
      process - a live ACCOUNT_VIEW_PASS token is a standing "decrypt
      anything" credential.
"""

import argparse
import csv
import getpass
import sys
from typing import Any, Dict, List

try:
    import requests
except ImportError:
    sys.exit("This script needs the 'requests' package: pip install requests")


class SysPassApiError(Exception):
    pass


def rpc_call(base_url: str, verify_ssl: bool, method: str, params: Dict[str, Any], request_id: int) -> Any:
    """Makes one JSON-RPC 2.0 call to sysPass's api.php and returns 'result'."""
    endpoint = base_url.rstrip("/") + "/api.php"
    payload = {
        "jsonrpc": "2.0",
        "method": method,
        "params": params,
        "id": request_id,
    }

    response = requests.post(endpoint, json=payload, verify=verify_ssl, timeout=30)
    response.raise_for_status()
    body = response.json()

    if "error" in body:
        error = body["error"]
        raise SysPassApiError(f"{method} failed: {error.get('message', error)}")

    return body.get("result", {}).get("result")


def fetch_accounts(base_url: str, verify_ssl: bool, search_token: str, count: int) -> List[Dict[str, Any]]:
    print(f"Fetching account list (up to {count})...", file=sys.stderr)

    result = rpc_call(
        base_url,
        verify_ssl,
        "account/search",
        {"authToken": search_token, "count": count},
        request_id=1,
    )

    accounts = result if isinstance(result, list) else []
    print(f"Found {len(accounts)} accounts.", file=sys.stderr)
    return accounts


def fetch_password(base_url: str, verify_ssl: bool, viewpass_token: str, token_pass: str, account_id: int) -> str:
    result = rpc_call(
        base_url,
        verify_ssl,
        "account/viewPass",
        {"authToken": viewpass_token, "tokenPass": token_pass, "id": account_id},
        request_id=account_id,
    )

    if isinstance(result, dict) and "password" in result:
        return result["password"]

    raise SysPassApiError(f"Unexpected response shape for account {account_id}: {result!r}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--url", required=True, help="sysPass base URL, e.g. https://syspass.example.com")
    parser.add_argument("--search-token", required=True, help="API token for the ACCOUNT_SEARCH action")
    parser.add_argument("--viewpass-token", required=True, help="API token for the ACCOUNT_VIEW_PASS action")
    parser.add_argument("--output", default="accounts_export.csv", help="CSV file to write (default: accounts_export.csv)")
    parser.add_argument("--count", type=int, default=10000, help="Max accounts to fetch (default: 10000)")
    parser.add_argument("--insecure", action="store_true", help="Skip TLS certificate verification (self-signed certs)")
    args = parser.parse_args()

    if args.insecure:
        import urllib3

        urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

    token_pass = getpass.getpass("Your sysPass login password (used as tokenPass): ")

    try:
        accounts = fetch_accounts(args.url, not args.insecure, args.search_token, args.count)
    except (SysPassApiError, requests.RequestException) as e:
        sys.exit(f"Could not fetch the account list: {e}")

    if not accounts:
        sys.exit("No accounts returned - check the search token and try again.")

    rows = []
    failures = []

    for i, account in enumerate(accounts, start=1):
        account_id = account.get("id")
        name = account.get("name", "")
        print(f"[{i}/{len(accounts)}] {name} (id={account_id})...", file=sys.stderr)

        try:
            password = fetch_password(args.url, not args.insecure, args.viewpass_token, token_pass, account_id)
        except (SysPassApiError, requests.RequestException) as e:
            print(f"  FAILED: {e}", file=sys.stderr)
            failures.append((account_id, name, str(e)))
            password = "<<EXPORT FAILED - SEE LOG>>"

        rows.append({
            "id": account_id,
            "name": name,
            "client": account.get("clientName", ""),
            "category": account.get("categoryName", ""),
            "login": account.get("login", ""),
            "url": account.get("url", ""),
            "password": password,
            "notes": account.get("notes", ""),
        })

    with open(args.output, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=["id", "name", "client", "category", "login", "url", "password", "notes"])
        writer.writeheader()
        writer.writerows(rows)

    print(f"\nWrote {len(rows)} accounts to {args.output}", file=sys.stderr)

    if failures:
        print(f"\n{len(failures)} account(s) failed to export - check them manually:", file=sys.stderr)
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
