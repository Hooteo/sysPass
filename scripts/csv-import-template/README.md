# CSV import template

A starting point for **Configuration > Import** (CSV format). Copy
`template.csv`, delete the two example rows, and fill in your own -
there is no header row (every line, including the first, is read as an
account), so don't add one.

## Format

```
accountName;clientName;categoryName;url;login;password;notes;otp
```

| Column | Required | Notes |
|---|---|---|
| `accountName` | yes | |
| `clientName` | yes | Created automatically if it doesn't exist yet |
| `categoryName` | yes | Created automatically if it doesn't exist yet |
| `url` | no | |
| `login` | no | |
| `password` | no | |
| `notes` | no | |
| `otp` | no | TOTP secret (Base32, eg. `JBSWY3DPEHPK3PXP`) - fork-only addition, see `lib/SP/Services/Import/CsvImportBase.php`. Leave the column out entirely for rows that don't need it (a plain 7-column line still works) |

- Delimiter is `;` by default - matches the "CSV delimiter" field on the
  Import screen. Change both together if you use a different one (eg. a
  password containing `;` would need a different delimiter, or the
  password quoted - plain `fgetcsv()` rules apply).
- `clientName`/`categoryName` must both be non-empty on every row, or
  that row is skipped with an error in the event log - sysPass itself
  requires every account to belong to a client and a category.
- One row per account. A failed row is skipped (logged) rather than
  aborting the whole import - check the event log after importing to
  catch anything that didn't go in.

## Migrating from Vaultwarden/Bitwarden

Vaultwarden's own export (Settings > Export vault, JSON, unencrypted)
has each login item's TOTP secret at `items[].login.totp` - sometimes a
bare Base32 secret, sometimes a full `otpauth://totp/...?secret=XXXX...`
URI (extract just the `secret=` value in that case). There's no
built-in converter for this in the fork yet; reshaping that JSON into
this CSV format is a short script if you need it.
