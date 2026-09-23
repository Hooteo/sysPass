"""
Pure-Python re-implementation of the decrypt path sysPass uses for
account passwords (defuse/php-encryption v2 wire format).

Why this exists instead of shelling out to PHP: export_via_db.py talks to
the database directly and needs to decrypt locally. This module was NOT
written from memory - it was built by reading the actual PHP source
(Core.php, Crypto.php, KeyOrPassword.php, KeyProtectedByPassword.php,
Key.php, Encoding.php from defuse/php-encryption v2.3.1, the version
pinned in sysPass's composer.lock) line by line, then verified against
real ciphertext produced by that same real PHP library (two vectors,
including one with unicode in both the master password and the account
password) before being trusted for anything - see the sysPass fork's
commit history for that verification. A wrong master password raises
DecryptError rather than returning garbage, matching the PHP library's
own fail-closed behavior (it HMAC-verifies before returning plaintext).

The scheme, in order (matches SP\\Core\\Crypt\\Crypt::decrypt()):
  1. The account's `key` column is a KeyProtectedByPassword blob: a
     random 256-bit key, itself encrypted with a key derived from the
     vault's master password (via SHA-256 pre-hash + PBKDF2-SHA256,
     100,000 iterations + HKDF-SHA256).
  2. Unlocking it (with the master password) yields the raw 256-bit key
     that was actually used to encrypt this specific account.
  3. The account's `pass` column is decrypted with that key: HKDF-SHA256
     splits it into an encryption and an authentication subkey (salted
     with a salt embedded in this ciphertext), the trailing 32-byte
     HMAC-SHA256 is verified, then AES-256-CTR decrypts the rest.

Wire format for both blobs (defuse v2): hex(
    4-byte version header || 32-byte salt || 16-byte IV ||
    ciphertext || 32-byte HMAC-SHA256
), with the HMAC computed over everything before it (header+salt+iv+
ciphertext). The KeyProtectedByPassword blob has its own, different
4-byte header and is itself an inner defuse-password-encrypted blob
(same header+salt+iv+ciphertext+hmac shape) wrapping a checksummed,
hex-encoded raw key.
"""
import hashlib
import hmac as hmac_mod

from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend

HEADER_V2 = b"\xde\xf5\x02\x00"
KEY_HEADER = b"\xde\xf0\x00\x00"
KEYPROTECTED_HEADER = b"\xde\xf1\x00\x00"
SALT_SIZE = 32
IV_SIZE = 16
MAC_SIZE = 32
HEADER_SIZE = 4
ENC_INFO = b"DefusePHP|V2|KeyForEncryption"
AUTH_INFO = b"DefusePHP|V2|KeyForAuthentication"
PBKDF2_ITERATIONS = 100000


class DecryptError(Exception):
    """Wrong master password, or corrupted/truncated data. Never raised
    for a merely-unexpected-but-valid plaintext - only when the HMAC or
    checksum genuinely doesn't match, so this is a reliable signal."""


def _hkdf_rfc5869(ikm: bytes, length: int, info: bytes, salt: bytes) -> bytes:
    """RFC 5869 HKDF-SHA256, matching Core::HKDF()'s extract+expand exactly."""
    prk = hmac_mod.new(salt, ikm, hashlib.sha256).digest()

    t = b""
    last_block = b""
    block_index = 1
    while len(t) < length:
        last_block = hmac_mod.new(prk, last_block + info + bytes([block_index]), hashlib.sha256).digest()
        t += last_block
        block_index += 1

    return t[:length]


def _aes256_ctr_decrypt(ciphertext: bytes, key: bytes, iv: bytes) -> bytes:
    decryptor = Cipher(algorithms.AES(key), modes.CTR(iv), backend=default_backend()).decryptor()
    return decryptor.update(ciphertext) + decryptor.finalize()


def _verify_and_decrypt(raw_ciphertext: bytes, ekey: bytes, akey: bytes) -> bytes:
    """Equivalent of Crypto::decryptInternal(), given already-derived subkeys."""
    if len(raw_ciphertext) < HEADER_SIZE + SALT_SIZE + IV_SIZE + MAC_SIZE:
        raise DecryptError("Ciphertext is too short.")

    header = raw_ciphertext[:HEADER_SIZE]
    if header != HEADER_V2:
        raise DecryptError(f"Bad version header: {header!r}")

    iv = raw_ciphertext[HEADER_SIZE + SALT_SIZE: HEADER_SIZE + SALT_SIZE + IV_SIZE]
    mac = raw_ciphertext[-MAC_SIZE:]
    encrypted = raw_ciphertext[HEADER_SIZE + SALT_SIZE + IV_SIZE: -MAC_SIZE]
    signed_part = raw_ciphertext[:-MAC_SIZE]

    expected_mac = hmac_mod.new(akey, signed_part, hashlib.sha256).digest()
    if not hmac_mod.compare_digest(expected_mac, mac):
        raise DecryptError("Integrity check failed (wrong master password, or corrupted data).")

    return _aes256_ctr_decrypt(encrypted, ekey, iv)


def decrypt_with_key(hex_ciphertext: str, raw_key: bytes) -> bytes:
    """Equivalent of Crypto::decrypt($ciphertext, Key $key)."""
    raw = bytes.fromhex(hex_ciphertext)
    salt = raw[HEADER_SIZE: HEADER_SIZE + SALT_SIZE]
    ekey = _hkdf_rfc5869(raw_key, 32, ENC_INFO, salt)
    akey = _hkdf_rfc5869(raw_key, 32, AUTH_INFO, salt)
    return _verify_and_decrypt(raw, ekey, akey)


def decrypt_with_password(hex_ciphertext: str, password_bytes: bytes) -> bytes:
    """Equivalent of Crypto::decryptWithPassword($ciphertext, $password)."""
    raw = bytes.fromhex(hex_ciphertext)
    salt = raw[HEADER_SIZE: HEADER_SIZE + SALT_SIZE]

    prehash = hashlib.sha256(password_bytes).digest()
    prekey = hashlib.pbkdf2_hmac("sha256", prehash, salt, PBKDF2_ITERATIONS, dklen=32)
    ekey = _hkdf_rfc5869(prekey, 32, ENC_INFO, salt)
    akey = _hkdf_rfc5869(prekey, 32, AUTH_INFO, salt)
    return _verify_and_decrypt(raw, ekey, akey)


def _load_checksummed_ascii(expected_header: bytes, hex_string: str) -> bytes:
    """Equivalent of Encoding::loadBytesFromChecksummedAsciiSafeString()."""
    raw = bytes.fromhex(hex_string.strip())
    if len(raw) < 4 + 32:
        raise DecryptError("Encoded data is shorter than expected.")

    header = raw[:4]
    if header != expected_header:
        raise DecryptError(f"Invalid header: expected {expected_header!r}, got {header!r}")

    checked_bytes = raw[:-32]
    checksum_a = raw[-32:]
    checksum_b = hashlib.sha256(checked_bytes).digest()
    if not hmac_mod.compare_digest(checksum_a, checksum_b):
        raise DecryptError("Checksum mismatch - corrupted key blob.")

    return raw[4:-32]


def unlock_secured_key(key_blob_hex: str, password: str) -> bytes:
    """Equivalent of Crypt::unlockSecuredKey() / KeyProtectedByPassword::unlockKey().
    Returns the raw 32-byte inner symmetric key used to decrypt one account's password.
    """
    encrypted_key_hex = _load_checksummed_ascii(KEYPROTECTED_HEADER, key_blob_hex).hex()

    password_hash = hashlib.sha256(password.encode("utf-8")).digest()
    inner_key_encoded = decrypt_with_password(encrypted_key_hex, password_hash)

    inner_key_bytes = _load_checksummed_ascii(KEY_HEADER, inner_key_encoded.decode("ascii"))
    if len(inner_key_bytes) != 32:
        raise DecryptError("Bad inner key length.")

    return inner_key_bytes


def decrypt_account_password(pass_blob_hex: str, key_blob_hex: str, master_password: str) -> str:
    """Equivalent of SP\\Core\\Crypt\\Crypt::decrypt($pass, $key, $masterPassword) -
    the exact call sysPass itself makes to show you a password in the UI."""
    raw_key = unlock_secured_key(key_blob_hex, master_password)
    plaintext = decrypt_with_key(pass_blob_hex, raw_key)
    return plaintext.decode("utf-8")
