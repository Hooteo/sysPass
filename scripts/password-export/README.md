# Cleartext password export (disaster recovery)

Company policy requires a printed, plain-text copy of every account
password kept in a physical safe, for the case where sysPass itself
becomes unavailable and the team still needs to work. This folder has
two independent tools for producing that CSV - pick whichever fits the
situation, then **print it and delete the file**.

| | `export_via_api.py` | `export_via_db.py` |
|---|---|---|
| Needs | sysPass web app running | Only the database running |
| Talks to | sysPass's JSON-RPC API | MySQL/MariaDB directly |
| Decryption done by | sysPass's own PHP code | `defuse_decrypt.py` (this folder) |
| Use when | The app is up (routine export) | The app is down but the DB survived (the actual disaster scenario) |

Setup:

```bash
pip install -r requirements.txt
```

## `export_via_api.py`

Calls sysPass's own JSON-RPC API (`account/search` + `account/viewPass`),
so the decryption is done entirely by sysPass's own, already-tested PHP
code - this script never touches raw ciphertext. Needs two API tokens
(Users → Access Manager → API Tokens): one for the "Search Accounts"
action, one for "View Account's Password". Full setup and usage in the
docstring at the top of the file:

```bash
python3 export_via_api.py \
    --url https://your-syspass.example.com \
    --search-token <token> \
    --viewpass-token <token> \
    --output accounts_export.csv
```

## `export_via_db.py` + `defuse_decrypt.py`

Connects straight to the sysPass database and decrypts locally - this is
the one that still works if the app container is down but the database
is up, which is closer to the actual emergency this export exists for.
It's a Python port of a working PHP script the team used to run as
`php export.php <MasterPassword>` against the same database.

```bash
python3 export_via_db.py --host <db-host> --db-user root --database syspass
```

You're prompted interactively for the database password and the sysPass
master password (never pass either as a command-line argument - both
would land in shell history and be visible to other users via `ps` while
the command runs). The script checks the master password against the
first account before processing the rest, so a wrong password fails
immediately with a clear message instead of grinding through the whole
database.

### Running it as a container instead

`Dockerfile` here packages `export_via_db.py` (plus `defuse_decrypt.py`)
so you don't need a local Python/pip setup - just Docker, which you
already have. It's wired into the main `docker-compose.yml` as a
`tools`-profiled service (`export-db-passwords`), sharing that stack's
network so it can reach `syspass-db` by name, but kept out of a normal
`docker compose up` - it only ever runs when you explicitly ask for it:

```bash
cd /opt/sysPass   # the repo root, where docker-compose.yml lives
docker compose --profile tools build export-db-passwords
mkdir -p export-output
docker compose --profile tools run --rm export-db-passwords \
    --host syspass-db --db-user root --database syspass \
    --output /out/accounts_export.csv
```

Same interactive prompts as running it directly. `--rm` throws the
container away once it exits; the CSV itself lands in `./export-output`
on the host (via the volume mount), not inside the container, so it
survives that. `export-output/` is git-ignored on purpose - never let
this land in the repo.

### About `defuse_decrypt.py`

sysPass encrypts account passwords with
[defuse/php-encryption](https://github.com/defuse/php-encryption), a
two-layer scheme: each account has a random 256-bit key, itself
encrypted with a key derived from the vault's master password
(SHA-256 pre-hash → PBKDF2-SHA256, 100,000 iterations → HKDF-SHA256);
that per-account key then decrypts the password itself via
HKDF-SHA256-derived subkeys, AES-256-CTR, and an HMAC-SHA256 integrity
check.

`defuse_decrypt.py` re-implements exactly that decrypt path in Python.
It was **not** written from memory: built by reading the real PHP
library's source (`Core.php`, `Crypto.php`, `KeyOrPassword.php`,
`KeyProtectedByPassword.php`, `Key.php`, `Encoding.php`, from
`defuse/php-encryption` v2.3.1 - the version pinned in sysPass's
`composer.lock`) line by line, then verified two ways before being
trusted for this:

1. **Against real ciphertext.** A small PHP harness using the actual
   `defuse/php-encryption` library generated real encrypted test
   vectors (including one with unicode in both the master password and
   the account password); the Python decryptor was run against those
   exact bytes and reproduced the original plaintext exactly.
2. **Against a real database.** A MariaDB container was seeded with
   sysPass's real schema and a real encrypted account row (same
   vectors), and `export_via_db.py` was run against it end-to-end,
   producing the correct decrypted password in the output CSV. A wrong
   master password was also tested and correctly aborted immediately
   with no output file, rather than writing corrupted data.

It fails loudly (raises `DecryptError`) rather than returning garbage on
a wrong master password or corrupted data - the library's own
HMAC-verify-before-decrypt design makes that the natural behavior, not
something bolted on afterwards.

## After you run either one

The output file is every credential in the company in plain text.

- Print it and put it in the safe immediately.
- Delete the CSV from disk right after. Neither script does this for
  you automatically - securely wiping a file is filesystem/OS-dependent
  and outside what a script can promise. At minimum remove the file and
  empty the trash/recycle bin on the machine you ran it from.
- Don't email it, don't put it in cloud storage, don't commit it
  anywhere, don't leave it sitting in a Downloads folder.
- If you used `export_via_api.py`, consider deleting the "View
  Password" API token afterwards if this was a one-off export - a live
  token for that action is a standing "decrypt anything" credential.
