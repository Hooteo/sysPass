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

namespace SP\Modules\Web\Controllers;

use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use SP\Core\Exceptions\SessionTimeout;
use SP\Http\JsonResponse;
use SP\Modules\Web\Controllers\Traits\JsonTrait;
use SP\Services\User\UserMfaService;

/**
 * Class UserMfaController
 *
 * Self-service enable/disable of the user's own login-time 2FA. The tab
 * content itself (secret generation, current status) is rendered from
 * UserSettingsManagerController - this controller only handles the two
 * form submissions.
 *
 * @package SP\Modules\Web\Controllers
 */
final class UserMfaController extends SimpleControllerBase
{
    use JsonTrait;

    /**
     * @var UserMfaService
     */
    private $userMfaService;

    /**
     * Confirms and enables 2FA: the secret was already generated and shown
     * to the user (indexAction, via UserSettingsManagerController), this
     * just checks it was set up correctly before persisting it.
     */
    public function saveAction()
    {
        try {
            $this->checkSecurityToken($this->previousSk, $this->request);

            $userData = $this->session->getUserData();
            $userPass = $this->request->analyzeEncrypted('pass');
            $secret = $this->request->analyzeString('secret');
            $code = $this->request->analyzeString('mfacode');

            if (empty($userPass) || empty($secret) || empty($code)) {
                return $this->returnJsonResponse(JsonResponse::JSON_ERROR, __u('All fields are required'));
            }

            if (!UserMfaService::codeMatchesSecret($secret, $code)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('Wrong code - check the secret was entered correctly in your authenticator app')
                );
            }

            $this->userMfaService->enable($userData->getId(), $userData->getLogin(), $userPass, $secret);

            return $this->returnJsonResponse(JsonResponse::JSON_SUCCESS, __u('Two-factor authentication enabled'));
        } catch (Exception $e) {
            processException($e);

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * Disables 2FA for the current user
     */
    public function deleteAction()
    {
        try {
            $this->checkSecurityToken($this->previousSk, $this->request);

            $this->userMfaService->disable($this->session->getUserData()->getId());

            return $this->returnJsonResponse(JsonResponse::JSON_SUCCESS, __u('Two-factor authentication disabled'));
        } catch (Exception $e) {
            processException($e);

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws SessionTimeout
     */
    protected function initialize()
    {
        $this->checks();

        $this->userMfaService = $this->dic->get(UserMfaService::class);
    }
}
