## sysPass fork - Systems Password Manager

Internal fork of the sysPass password manager, maintained for our own
infrastructure. The original upstream project has not seen active
maintenance in some time, so this fork carries our own fixes and features
on top of it.

---

PHP web based Password Manager for business and personal use.

- [x] AES-256 encryption in CTR mode
- [x] RSA for sending passwords from forms
- [x] Two factor authentication (login) and per-account OTP/TOTP codes
- [x] HTML5 and Ajax interface
- [x] Users, groups and profiles management with up to 29 access levels
- [x] MySQL, OpenLDAP and Active Directory authentication
- [x] Tags, custom fields, public links, private accounts, favorites, history, etc.
- [x] Activity notifications by email and in-app, and event log
- [x] Multilanguage
- [x] JSON-RPC API

## Changes in this fork

- Fixed a proxy header bug in `lib/SP/Http/Request.php`.
- Fixed a "copy password requires two clicks" clipboard bug.
- Added per-account OTP/TOTP code support (view/copy a live 6-digit code
  for an account, alongside its password).
- Added Docker packaging (`Dockerfile`, `docker-compose.yml`) with the
  image built and published automatically via GitHub Actions.
- Added support for provisioning the signing/HMAC secret from an
  environment variable (`SYSPASS_PASSWORD_SALT`) instead of only ever
  generating a random one on first boot - see `.env.example`.

## Running with Docker

```
cp .env.example .env   # fill in SYSPASS_PASSWORD_SALT if you want a reproducible one
docker compose up -d
```

See `Dockerfile` and `docker/` for the image build and `docker-compose.yml`
for the default service layout (app + MariaDB, `app/config` and
`app/backup` persisted as named volumes).

## License

This software is licensed under the GNU GPLv3. See the `COPYING` file for
the full license text. This is a modified/derivative version of the
original sysPass project; per the terms of the GPLv3, the original
copyright and license notices in the source files are preserved.
