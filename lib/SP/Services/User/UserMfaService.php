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

namespace SP\Services\User;

use Defuse\Crypto\Exception\CryptoException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Core\Crypt\Crypt;
use SP\Core\Exceptions\ConstraintException;
use SP\Core\Exceptions\QueryException;
use SP\Repositories\User\UserMfaRepository;
use SP\Services\Service;
use SP\Util\TotpUtil;

/**
 * Class UserMfaService
 *
 * Login-time TOTP second factor. Deliberately separate from the per-account
 * OTP feature (CustomFieldType 'otp') - this secret is encrypted with a key
 * derived from the user's own login password (UserPassService::makeKeyForUser,
 * the same derivation already used for mPass/mKey), not the shared instance
 * master password. That keeps one user's second factor from being derivable
 * by anyone who only knows the shared master password.
 *
 * @package SP\Services\User
 */
final class UserMfaService extends Service
{
    /**
     * @var UserMfaRepository
     */
    private $userMfaRepository;
    /**
     * @var UserPassService
     */
    private $userPassService;

    /**
     * @param int $userId
     *
     * @return bool
     * @throws ConstraintException
     * @throws QueryException
     */
    public function isEnabled(int $userId): bool
    {
        return $this->userMfaRepository->getByUserId($userId)->getNumRows() === 1;
    }

    /**
     * Encrypts and stores a new (or replacement) MFA secret for a user
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws CryptoException
     */
    public function enable(int $userId, string $login, string $userPass, string $secret): void
    {
        $key = $this->userPassService->makeKeyForUser($login, $userPass);
        $securedKey = Crypt::makeSecuredKey($key);
        $cryptSecret = Crypt::encrypt($secret, $securedKey, $key);

        $this->userMfaRepository->save($userId, $cryptSecret, $securedKey);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function disable(int $userId): void
    {
        $this->userMfaRepository->deleteByUserId($userId);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws CryptoException
     */
    public function verifyCode(int $userId, string $login, string $userPass, string $code): bool
    {
        $secret = $this->getSecretForUser($userId, $login, $userPass);

        return $secret !== null && self::codeMatchesSecret($secret, $code);
    }

    /**
     * Checks a code against a secret directly - used both here (after
     * decrypting a stored secret) and during enrollment, where the secret
     * hasn't been persisted/encrypted yet so verifyCode() doesn't apply.
     *
     * @return bool
     */
    public static function codeMatchesSecret(string $secret, string $code): bool
    {
        $now = time();

        // Accept the current period plus one period either side, to tolerate
        // small clock drift between the server and the user's device - a
        // standard TOTP practice.
        foreach ([$now, $now - TotpUtil::PERIOD, $now + TotpUtil::PERIOD] as $timestamp) {
            if (hash_equals(TotpUtil::generateCode($secret, $timestamp), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * No-op if the user has no MFA secret configured. Otherwise decrypts it
     * with the key derived from the old password and re-encrypts it with the
     * key derived from the new one, so the user's authenticator app keeps
     * working across a password change without needing to be reconfigured.
     *
     * @throws ConstraintException
     * @throws QueryException
     * @throws CryptoException
     */
    public function rekeyOnPasswordChange(int $userId, string $login, string $oldPass, string $newPass): void
    {
        $secret = $this->getSecretForUser($userId, $login, $oldPass);

        if ($secret === null) {
            return;
        }

        $this->enable($userId, $login, $newPass, $secret);
    }

    /**
     * @return string|null
     * @throws ConstraintException
     * @throws QueryException
     * @throws CryptoException
     */
    private function getSecretForUser(int $userId, string $login, string $userPass): ?string
    {
        $result = $this->userMfaRepository->getByUserId($userId);

        if ($result->getNumRows() !== 1) {
            return null;
        }

        $row = $result->getData();
        $key = $this->userPassService->makeKeyForUser($login, $userPass);

        return trim(Crypt::decrypt($row->secret, $row->key, $key));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function initialize()
    {
        $this->userMfaRepository = $this->dic->get(UserMfaRepository::class);
        $this->userPassService = $this->dic->get(UserPassService::class);
    }
}
