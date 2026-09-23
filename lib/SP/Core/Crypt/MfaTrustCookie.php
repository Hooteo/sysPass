<?php
/**
 * sysPass fork
 *
 * @author    Infonet Solutions
 * @copyright 2026, Infonet Solutions
 *
 * New file added in this fork, part of a modified version of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Core\Crypt;

use SP\Http\Request;

/**
 * Class MfaTrustCookie
 *
 * "Remember this browser" for login-time 2FA: once a code is entered
 * correctly, this signed cookie lets LoginService skip asking for a new
 * one from the same browser for TRUST_SECONDS - sliding, refreshed on
 * every successful use, so a browser used at least once within that
 * window stays trusted indefinitely, while one left untouched falls back
 * to asking again.
 *
 * The cookie payload is `userId|enrollmentMarker|expiry`, HMAC-signed
 * (via Cookie::sign(), keyed with the instance passwordSalt - same
 * mechanism SecureSessionService already uses for UUIDCookie) so it
 * can't be forged or replayed for a different user without the key. The
 * password itself is never involved: only user + a valid code ever gets
 * here in the first place.
 *
 * enrollmentMarker is the user's current UserMfa.dateAdd. Disabling and
 * re-enabling 2FA (self-service, or an admin reset after a lost device)
 * always gives that row a fresh dateAdd, so any previously-trusted
 * browser's cookie stops matching and is treated as untrusted again -
 * a stale trust cookie can't outlive an actual 2FA reset. A plain
 * password change does NOT touch dateAdd (UserMfaService re-keys the
 * existing row in place), so trust correctly survives that.
 *
 * @package SP\Core\Crypt
 */
final class MfaTrustCookie extends Cookie
{
    const COOKIE_NAME = 'SYSPASS_MFA_TRUST';
    const TRUST_SECONDS = 7 * 86400;

    /**
     * @param Request $request
     *
     * @return MfaTrustCookie
     */
    public static function factory(Request $request)
    {
        return new self(self::COOKIE_NAME, $request);
    }

    /**
     * Marks this browser as trusted for this user's current MFA
     * enrollment, for TRUST_SECONDS from now.
     *
     * @param int    $userId
     * @param int    $enrollmentMarker
     * @param string $signKey
     *
     * @return bool
     */
    public function trust($userId, $enrollmentMarker, $signKey)
    {
        $expire = time() + self::TRUST_SECONDS;
        $payload = $userId . '|' . $enrollmentMarker . '|' . $expire;

        return $this->setCookieWithExpire($this->sign($payload, $signKey), $expire);
    }

    /**
     * Whether this browser already holds a valid, unexpired trust cookie
     * for this exact user and MFA enrollment.
     *
     * @param int    $userId
     * @param int    $enrollmentMarker
     * @param string $signKey
     *
     * @return bool
     */
    public function isTrusted($userId, $enrollmentMarker, $signKey)
    {
        $raw = $this->getCookie();

        if ($raw === false) {
            return false;
        }

        $payload = $this->getCookieData($raw, $signKey);

        if ($payload === false) {
            return false;
        }

        $parts = explode('|', $payload, 3);

        if (count($parts) !== 3) {
            return false;
        }

        [$cookieUserId, $cookieMarker, $cookieExpire] = $parts;

        return (int)$cookieUserId === (int)$userId
            && (string)$cookieMarker === (string)$enrollmentMarker
            && (int)$cookieExpire > time();
    }
}
