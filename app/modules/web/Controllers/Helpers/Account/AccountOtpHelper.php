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

namespace SP\Modules\Web\Controllers\Helpers\Account;

use Defuse\Crypto\Exception\CryptoException;
use DI\DependencyException;
use DI\NotFoundException;
use SP\Core\Acl\Acl;
use SP\Core\Acl\ActionsInterface;
use SP\Core\Exceptions\ConstraintException;
use SP\Core\Exceptions\QueryException;
use SP\Modules\Web\Controllers\Helpers\HelperBase;
use SP\Modules\Web\Controllers\Helpers\HelperException;
use SP\Services\CustomField\CustomFieldService;
use SP\Services\ServiceException;
use SP\Util\TotpUtil;

/**
 * Class AccountOtpHelper
 *
 * Computes the current TOTP code for an account's OTP custom field,
 * decrypting the secret server-side and never returning it to the client.
 *
 * @package SP\Modules\Web\Controllers\Helpers
 */
final class AccountOtpHelper extends HelperBase
{
    /**
     * @var Acl
     */
    private $acl;

    /**
     * @param int $accountId
     *
     * @return array
     * @throws HelperException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws CryptoException
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     */
    public function getOtpCode(int $accountId): array
    {
        $this->checkActionAccess();

        $secret = $this->getSecretForAccount($accountId);

        return [
            'code' => TotpUtil::generateCode($secret),
            'secondsRemaining' => TotpUtil::getSecondsRemaining()
        ];
    }

    /**
     * @param int    $accountId
     * @param string $accountName
     *
     * @return array
     * @throws HelperException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws CryptoException
     * @throws ConstraintException
     * @throws QueryException
     * @throws ServiceException
     */
    public function getOtpView(int $accountId, string $accountName): array
    {
        $this->checkActionAccess();

        $otp = $this->getOtpCode($accountId);

        $this->view->addTemplate('viewotp');

        $this->view->assign('header', __('Account OTP Code'));
        $this->view->assign('accountName', $accountName);
        $this->view->assign('accountId', $accountId);
        $this->view->assign('code', $otp['code']);
        $this->view->assign('secondsRemaining', $otp['secondsRemaining']);
        $this->view->assign('period', TotpUtil::PERIOD);

        return ['html' => $this->view->render()];
    }

    /**
     * @param int $accountId
     *
     * @return string
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ConstraintException
     * @throws QueryException
     * @throws CryptoException
     * @throws ServiceException
     * @throws HelperException
     */
    private function getSecretForAccount(int $accountId): string
    {
        $customFieldService = $this->dic->get(CustomFieldService::class);

        foreach ($customFieldService->getForModuleAndItemId(ActionsInterface::ACCOUNT, $accountId) as $field) {
            if ($field->typeName === 'otp' && !empty($field->data) && !empty($field->key)) {
                return $customFieldService->decryptData($field->data, $field->key);
            }
        }

        throw new HelperException(__u('This account does not have an OTP code configured'));
    }

    /**
     * @throws HelperException
     */
    private function checkActionAccess()
    {
        if (!$this->acl->checkUserAccess(ActionsInterface::ACCOUNT_VIEW_PASS)) {
            throw new HelperException(__u('You don\'t have permission to access this account'));
        }
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function initialize()
    {
        $this->acl = $this->dic->get(Acl::class);
    }
}
