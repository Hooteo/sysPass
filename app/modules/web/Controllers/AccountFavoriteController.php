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
use SP\Core\Events\Event;
use SP\Core\Exceptions\SessionTimeout;
use SP\Domain\Account\Services\AccountToFavoriteService;
use SP\Http\JsonResponse;
use SP\Modules\Web\Controllers\Traits\JsonTrait;

/**
 * Class AccountFavoriteController
 *
 * @package SP\Modules\Web\Controllers
 */
final class AccountFavoriteController extends SimpleControllerBase
{
    use JsonTrait;

    private ?AccountToFavoriteService $accountFavoriteService = null;

    /**
     * @param int $accountId
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function markAction(int $accountId): bool
    {
        try {
            $this->accountFavoriteService->add(
                $accountId,
                $this->session->getUserData()->getId()
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Favorite added')
            );
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * @param int $accountId
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function unmarkAction(int $accountId): bool
    {
        try {
            $this->accountFavoriteService->delete(
                $accountId,
                $this->session->getUserData()->getId()
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Favorite deleted')
            );
        } catch (Exception $e) {
            processException($e);

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
     * @throws SessionTimeout
     */
    protected function initialize(): void
    {
        $this->checks();

        $this->accountFavoriteService = $this->dic->get(AccountToFavoriteService::class);
    }

}