<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2021, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
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
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Modules\Web\Controllers;

use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Core\Exceptions\InvalidArgumentException;
use SP\Core\Exceptions\SPException;
use SP\Core\Exceptions\ValidationException;
use SP\Domain\Notification\Services\MailService;
use SP\Domain\Security\Services\TrackService;
use SP\Domain\User\Services\UserPassRecoverService;
use SP\Domain\User\Services\UserService;
use SP\Http\JsonResponse;
use SP\Infrastructure\Security\Repositories\TrackRequest;
use SP\Modules\Web\Controllers\Helpers\LayoutHelper;
use SP\Modules\Web\Controllers\Traits\JsonTrait;
use SP\Util\ErrorUtil;

/**
 * Class PassresetController
 *
 * @package SP\Modules\Web\Controllers
 */
final class UserPassResetController extends ControllerBase
{
    use JsonTrait;

    protected ?TrackService $trackService = null;
    protected ?TrackRequest $trackRequest = null;

    /**
     * Password reset action
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function indexAction(): void
    {
        $this->dic->get(LayoutHelper::class)
            ->getCustomLayout('request', strtolower($this->getViewBaseName()));

        if (!$this->configData->isMailEnabled()) {
            ErrorUtil::showErrorInView(
                $this->view,
                self::ERR_UNAVAILABLE,
                true,
                'request'
            );
        }

        $this->view();
    }

    /**
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function saveRequestAction(): bool
    {
        try {
            $this->checkTracking();

            $login = $this->request->analyzeString('login');
            $email = $this->request->analyzeEmail('email');

            $userData = $this->dic->get(UserService::class)->getByLogin($login);

            if ($userData->getEmail() !== $email) {
                throw new SPException(__u('Wrong data'), SPException::WARNING);
            }

            if ($userData->isDisabled() || $userData->isLdap()) {
                throw new SPException(
                    __u('Unable to reset the password'),
                    SPException::WARNING,
                    __u('Please contact to the administrator')
                );
            }

            $hash = $this->dic->get(UserPassRecoverService::class)
                ->requestForUserId($userData->getId());

            $this->eventDispatcher->notifyEvent(
                'request.user.passReset',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Password Recovery'))
                        ->addDetail(__u('Requested for'), sprintf('%s (%s)', $login, $email))
                )
            );

            $this->dic->get(MailService::class)
                ->send(
                    __('Password Change'),
                    $email,
                    UserPassRecoverService::getMailMessage($hash)
                );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Request sent'),
                [__u('You will receive an email to complete the request shortly.')]
            );
        } catch (Exception $e) {
            processException($e);

            $this->addTracking();

            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * @throws SPException
     * @throws Exception
     */
    protected function checkTracking(): void
    {
        if ($this->trackService->checkTracking($this->trackRequest)) {
            throw new SPException(
                __u('Attempts exceeded'),
                SPException::INFO
            );
        }
    }

    /**
     * Añadir un seguimiento
     */
    private function addTracking(): void
    {
        try {
            $this->trackService->add($this->trackRequest);
        } catch (Exception $e) {
            processException($e);
        }
    }

    /**
     * @param  string|null  $hash
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function resetAction(?string $hash = null): void
    {
        $this->dic->get(LayoutHelper::class)
            ->getCustomLayout('reset', strtolower($this->getViewBaseName()));

        if ($hash && $this->configData->isMailEnabled()) {
            $this->view->assign('hash', $hash);
        } else {
            ErrorUtil::showErrorInView(
                $this->view,
                self::ERR_UNAVAILABLE,
                true,
                'reset'
            );
        }

        $this->view();
    }

    /**
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function saveResetAction(): bool
    {
        try {
            $this->checkTracking();

            $pass = $this->request->analyzeEncrypted('password');
            $passR = $this->request->analyzeEncrypted('password_repeat');

            if (!$pass || !$passR) {
                throw new ValidationException(__u('Password cannot be blank'));
            }

            if ($pass !== $passR) {
                throw new ValidationException(__u('Passwords do not match'));
            }

            $hash = $this->request->analyzeString('hash');

            $userPassRecoverService = $this->dic->get(UserPassRecoverService::class);
            $userId = $userPassRecoverService->getUserIdForHash($hash);
            $userPassRecoverService->toggleUsedByHash($hash);

            $userService = $this->dic->get(UserService::class);
            $userService->updatePass($userId, $pass);

            $user = $userService->getById($userId);

            $this->eventDispatcher->notifyEvent(
                'edit.user.password',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Password updated'))
                        ->addDetail(__u('User'), $user->getLogin())
                        ->addExtra('userId', $userId)
                        ->addExtra('email', $user->getEmail())
                )
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Password updated')
            );
        } catch (Exception $e) {
            processException($e);

            $this->addTracking();

            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws InvalidArgumentException
     */
    protected function initialize(): void
    {
        $this->trackService = $this->dic->get(TrackService::class);
        $this->trackRequest = $this->trackService->getTrackRequest($this->getViewBaseName());
    }
}