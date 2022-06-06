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
use RuntimeException;
use SP\Core\Acl\Acl;
use SP\Core\Acl\ActionsInterface;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Core\Exceptions\ConstraintException;
use SP\Core\Exceptions\QueryException;
use SP\Core\Exceptions\SessionTimeout;
use SP\Core\Exceptions\SPException;
use SP\Core\Exceptions\ValidationException;
use SP\DataModel\PublicLinkData;
use SP\DataModel\PublicLinkListData;
use SP\Domain\Account\Services\AccountService;
use SP\Domain\Account\Services\PublicLinkService;
use SP\Domain\Auth\Services\AuthException;
use SP\Html\DataGrid\DataGridInterface;
use SP\Http\JsonResponse;
use SP\Modules\Web\Controllers\Helpers\Grid\PublicLinkGrid;
use SP\Modules\Web\Controllers\Traits\JsonTrait;
use SP\Modules\Web\Forms\PublicLinkForm;
use SP\Mvc\Controller\CrudControllerInterface;
use SP\Mvc\Controller\ItemTrait;
use SP\Mvc\View\Components\SelectItemAdapter;
use SP\Util\PasswordUtil;

/**
 * Class PublicLinkController
 *
 * @package SP\Modules\Web\Controllers
 */
final class PublicLinkController extends ControllerBase implements CrudControllerInterface
{
    use JsonTrait, ItemTrait;

    protected ?PublicLinkService $publicLinkService = null;

    /**
     * Search action
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     * @throws \SP\Core\Exceptions\ConstraintException
     * @throws \SP\Core\Exceptions\QueryException
     */
    public function searchAction(): bool
    {
        if (!$this->acl->checkUserAccess(ActionsInterface::PUBLICLINK_SEARCH)) {
            return $this->returnJsonResponse(
                JsonResponse::JSON_ERROR,
                __u('You don\'t have permission to do this operation')
            );
        }

        $this->view->addTemplate('datagrid-table', 'grid');
        $this->view->assign(
            'index',
            $this->request->analyzeInt('activetab', 0)
        );
        $this->view->assign('data', $this->getSearchGrid());

        return $this->returnJsonResponseData(['html' => $this->render()]);
    }

    /**
     * getSearchGrid
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ConstraintException
     * @throws QueryException
     */
    protected function getSearchGrid(): DataGridInterface
    {
        $itemSearchData = $this->getSearchData(
            $this->configData->getAccountCount(),
            $this->request
        );

        $publicLinkGrid = $this->dic->get(PublicLinkGrid::class);

        return $publicLinkGrid->updatePager(
            $publicLinkGrid->getGrid($this->publicLinkService->search($itemSearchData)),
            $itemSearchData
        );
    }

    /**
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function createAction(): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::PUBLICLINK_CREATE)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $this->view->assign('header', __('New Public Link'));
            $this->view->assign('isView', false);
            $this->view->assign('route', 'publicLink/saveCreate');

            $this->setViewData();

            $this->eventDispatcher->notifyEvent(
                'show.publicLink.create',
                new Event($this)
            );

            return $this->returnJsonResponseData(['html' => $this->render()]);
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
     * Sets view data for displaying public link's data
     *
     * @param  int|null  $publicLinkId
     *
     * @throws ContainerExceptionInterface
     * @throws SPException
     */
    protected function setViewData(?int $publicLinkId = null): void
    {
        $this->view->addTemplate('public_link', 'itemshow');

        $publicLink = $publicLinkId
            ? $this->publicLinkService->getById($publicLinkId)
            : new PublicLinkListData();

        $this->view->assign('publicLink', $publicLink);
        $this->view->assign(
            'usageInfo',
            unserialize($publicLink->getUseInfo())
        );
        $this->view->assign(
            'accounts',
            SelectItemAdapter::factory($this->dic->get(AccountService::class)->getForUser())
                ->getItemsFromModelSelected([$publicLink->getItemId()])
        );

        $this->view->assign(
            'nextAction',
            Acl::getActionRoute(ActionsInterface::ACCESS_MANAGE)
        );

        if ($this->view->isView === true) {
            $baseUrl = ($this->configData->getApplicationUrl() ?: Bootstrap::$WEBURI).Bootstrap::$SUBURI;

            $this->view->assign(
                'publicLinkURL',
                PublicLinkService::getLinkForHash($baseUrl, $publicLink->getHash())
            );
            $this->view->assign('disabled', 'disabled');
            $this->view->assign('readonly', 'readonly');
        } else {
            $this->view->assign('disabled', false);
            $this->view->assign('readonly', false);
        }
    }

    /**
     * Create action
     *
     * @param  int  $id
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function refreshAction(int $id): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::PUBLICLINK_REFRESH)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $this->publicLinkService->refresh($id);

            $this->eventDispatcher->notifyEvent(
                'edit.publicLink.refresh',
                new Event($this)
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Link updated')
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
     * Edit action
     *
     * @param  int  $id
     *
     * @return bool
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \JsonException
     */
    public function editAction(int $id): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::PUBLICLINK_EDIT)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $this->view->assign('header', __('Edit Public Link'));
            $this->view->assign('isView', false);
            $this->view->assign('route', 'publicLink/saveEdit/'.$id);

            $this->setViewData($id);

            $this->eventDispatcher->notifyEvent(
                'show.publicLink.edit',
                new Event($this)
            );

            return $this->returnJsonResponseData(['html' => $this->render()]);
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
     * Delete action
     *
     * @param  int|null  $id
     *
     * @return bool
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \JsonException
     */
    public function deleteAction(?int $id = null): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::PUBLICLINK_DELETE)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            if ($id === null) {
                $this->publicLinkService
                    ->deleteByIdBatch($this->getItemsIdFromRequest($this->request));

                $this->deleteCustomFieldsForItem(
                    ActionsInterface::PUBLICLINK,
                    $id
                );

                $this->eventDispatcher->notifyEvent(
                    'delete.publicLink.selection',
                    new Event(
                        $this,
                        EventMessage::factory()
                            ->addDescription(__u('Links deleted'))
                    )
                );

                return $this->returnJsonResponse(
                    JsonResponse::JSON_SUCCESS,
                    __u('Links deleted')
                );
            }

            $this->publicLinkService->delete($id);

            $this->deleteCustomFieldsForItem(
                ActionsInterface::PUBLICLINK,
                $id
            );

            $this->eventDispatcher->notifyEvent(
                'delete.publicLink',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Link deleted'))
                        ->addDetail(__u('Link'), $id)
                )
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Link deleted')
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
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function saveCreateAction(): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::PUBLICLINK_CREATE)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $form = new PublicLinkForm($this->dic);
            $form->validate(ActionsInterface::PUBLICLINK_CREATE);

            $this->publicLinkService->create($form->getItemData());

            $this->eventDispatcher->notifyEvent(
                'create.publicLink',
                new Event($this)
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Link created')
            );
        } catch (ValidationException $e) {
            return $this->returnJsonResponseException($e);
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
     * Saves create action
     *
     * @param  int  $accountId
     * @param  int  $notify
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function saveCreateFromAccountAction(int $accountId, int $notify): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::PUBLICLINK_CREATE)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $publicLinkData = new PublicLinkData();
            $publicLinkData->setTypeId(PublicLinkService::TYPE_ACCOUNT);
            $publicLinkData->setItemId($accountId);
            $publicLinkData->setNotify((bool)$notify);
            $publicLinkData->setHash(PasswordUtil::generateRandomBytes());

            $this->publicLinkService->create($publicLinkData);

            $this->eventDispatcher->notifyEvent(
                'create.publicLink.account',
                new Event($this)
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Link created')
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
     * Saves edit action
     *
     * @param  int  $id
     */
    public function saveEditAction(int $id): void
    {
        throw new RuntimeException('Not implemented');
    }

    /**
     * View action
     *
     * @param  int  $id
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function viewAction(int $id): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::PUBLICLINK_VIEW)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $this->view->assign('header', __('View Link'));
            $this->view->assign('isView', true);

            $this->setViewData($id);

            $this->eventDispatcher->notifyEvent(
                'show.publicLink',
                new Event($this)
            );

            return $this->returnJsonResponseData(['html' => $this->render()]);
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
     * Initialize class
     *
     * @throws AuthException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws SessionTimeout
     */
    protected function initialize(): void
    {
        $this->checkLoggedIn();

        $this->publicLinkService = $this->dic->get(PublicLinkService::class);
    }
}