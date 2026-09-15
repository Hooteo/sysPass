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

namespace SP\Util;

/**
 * Class TotpUtil
 *
 * RFC 6238 TOTP code generation, hardcoded to the de-facto standard
 * 30 second period / 6 digits / SHA1 used by Google Authenticator and
 * virtually every service offering TOTP-based 2FA.
 *
 * @package SP\Util
 */
final class TotpUtil
{
    const PERIOD = 30;
    const DIGITS = 6;
    const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Decode a RFC 4648 base32 string (the format used by authenticator apps)
     *
     * @param string $secret
     *
     * @return string
     */
    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[\s=]+/', '', $secret));

        $buffer = 0;
        $bitsLeft = 0;
        $binary = '';

        for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
            $value = strpos(self::BASE32_ALPHABET, $secret[$i]);

            if ($value === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $binary;
    }

    /**
     * Generate the current TOTP code for a given base32 secret
     *
     * @param string   $secret    Base32 encoded secret, as provided by the service being protected
     * @param int|null $timestamp Defaults to the current time
     *
     * @return string
     */
    public static function generateCode(string $secret, int $timestamp = null): string
    {
        $counter = (int)floor(($timestamp ?? time()) / self::PERIOD);

        // pack() 'N' is only 32-bit wide, so the 64-bit counter needs two words
        $counterBytes = pack('N*', 0, $counter);

        $hash = hash_hmac('sha1', $counterBytes, self::base32Decode($secret), true);

        // Dynamic truncation, RFC 4226 section 5.3
        $offset = ord($hash[19]) & 0xf;

        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $code = $binary % (10 ** self::DIGITS);

        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Seconds remaining until the current code expires
     *
     * @param int|null $timestamp Defaults to the current time
     *
     * @return int
     */
    public static function getSecondsRemaining(int $timestamp = null): int
    {
        return self::PERIOD - (($timestamp ?? time()) % self::PERIOD);
    }
}
