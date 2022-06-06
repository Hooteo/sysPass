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
use SP\Core\Acl\Acl;
use SP\Core\Acl\ActionsInterface;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Core\Exceptions\ConstraintException;
use SP\Core\Exceptions\InvalidArgumentException;
use SP\Core\Exceptions\NoSuchPropertyException;
use SP\Core\Exceptions\QueryException;
use SP\Core\Exceptions\SessionTimeout;
use SP\Core\Exceptions\SPException;
use SP\Core\Exceptions\ValidationException;
use SP\DataModel\ItemPresetData;
use SP\Domain\Auth\Services\AuthException;
use SP\Domain\ItemPreset\ItemPresetInterface;
use SP\Domain\ItemPreset\Services\ItemPresetService;
use SP\Html\DataGrid\DataGridInterface;
use SP\Http\JsonResponse;
use SP\Infrastructure\Common\Repositories\NoSuchItemException;
use SP\Modules\Web\Controllers\Helpers\Grid\ItemPresetGrid;
use SP\Modules\Web\Controllers\Helpers\ItemPresetHelper;
use SP\Modules\Web\Controllers\Traits\JsonTrait;
use SP\Modules\Web\Forms\ItemsPresetForm;
use SP\Mvc\Controller\CrudControllerInterface;
use SP\Mvc\Controller\ItemTrait;
use SP\Util\Filter;

/**
 * Class AccountDefaultPermissionController
 *
 * @package SP\Modules\Web\Controllers
 */
final class ItemPresetController extends ControllerBase implements CrudControllerInterface
{
    use JsonTrait, ItemTrait;

    protected ?ItemPresetService $itemPresetService = null;

    /**
     * View action
     *
     * @param int $id
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function viewAction(int $id): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::ITEMPRESET_VIEW)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $this->view->assign('header', __('Display Value'));
            $this->view->assign('isView', true);

            $this->setViewData($id);

            $this->eventDispatcher->notifyEvent(
                'show.itemPreset',
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
     * Sets view data for displaying permissions' data
     *
     * @param int|null    $id
     * @param string|null $type
     *
     * @throws ConstraintException
     * @throws DependencyException
     * @throws InvalidArgumentException
     * @throws NoSuchItemException
     * @throws NoSuchPropertyException
     * @throws NotFoundException
     * @throws QueryException
     */
    protected function setViewData(?int $id = null, ?string $type = null): void
    {
        $this->view->addTemplate('item_preset', 'itemshow');

        $itemPresetData = $id
            ? $this->itemPresetService->getById($id)
            : new ItemPresetData();

        $itemPresetHelper = $this->dic->get(ItemPresetHelper::class);
        $itemPresetHelper->setCommon($itemPresetData);

        if ($itemPresetData->getType() === null) {
            $itemPresetData->setType($type);
        }

        switch ($itemPresetData->getType()) {
            case ItemPresetInterface::ITEM_TYPE_ACCOUNT_PERMISSION:
                $itemPresetHelper->makeAccountPermissionView($itemPresetData);
                break;
            case ItemPresetInterface::ITEM_TYPE_ACCOUNT_PRIVATE:
                $itemPresetHelper->makeAccountPrivateView($itemPresetData);
                break;
            case ItemPresetInterface::ITEM_TYPE_SESSION_TIMEOUT:
                $itemPresetHelper->makeSessionTimeoutView($itemPresetData);
                break;
            case ItemPresetInterface::ITEM_TYPE_ACCOUNT_PASSWORD:
                $itemPresetHelper->makeAccountPasswordView($itemPresetData);
                break;
        }

        $this->view->assign('preset', $itemPresetData);
        $this->view->assign(
            'nextAction',
            Acl::getActionRoute(ActionsInterface::ACCESS_MANAGE)
        );

        if ($this->view->isView === true) {
            $this->view->assign('disabled', 'disabled');
            $this->view->assign('readonly', 'readonly');
        } else {
            $this->view->assign('disabled', false);
            $this->view->assign('readonly', false);
        }
    }

    /**
     * Search action
     *
     * @return bool
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ConstraintException
     * @throws QueryException
     * @throws SPException
     * @throws \JsonException
     */
    public function searchAction(): bool
    {
        if (!$this->acl->checkUserAccess(ActionsInterface::ITEMPRESET_SEARCH)) {
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

        $grid = $this->dic->get(ItemPresetGrid::class);

        return $grid->updatePager(
            $grid->getGrid($this->itemPresetService->search($itemSearchData)),
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
            if (!$this->acl->checkUserAccess(ActionsInterface::ITEMPRESET_CREATE)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $args = func_get_args();
            $type = null;

            if (count($args) > 0) {
                $type = Filter::getString($args[0]);
            }

            $this->view->assign('header', __('New Value'));
            $this->view->assign('isView', false);
            $this->view->assign('route', 'itemPreset/saveCreate');

            $this->setViewData(null, $type);

            $this->eventDispatcher->notifyEvent(
                'show.itemPreset.create',
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
     * Edit action
     *
     * @param int $id
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function editAction(int $id): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::ITEMPRESET_EDIT)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $this->view->assign('header', __('Edit Value'));
            $this->view->assign('isView', false);
            $this->view->assign('route', 'itemPreset/saveEdit/' . $id);

            $this->setViewData($id);

            $this->eventDispatcher->notifyEvent(
                'show.itemPreset.edit',
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
     * @param int|null $id
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function deleteAction(?int $id = null): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::ITEMPRESET_DELETE)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            if ($id === null) {
                $this->itemPresetService
                    ->deleteByIdBatch($this->getItemsIdFromRequest($this->request));

                $this->eventDispatcher->notifyEvent(
                    'delete.itemPreset',
                    new Event(
                        $this,
                        EventMessage::factory()
                            ->addDescription(__u('Values deleted'))
                    )
                );

                return $this->returnJsonResponse(
                    JsonResponse::JSON_SUCCESS,
                    __u('Values deleted')
                );
            }

            $this->itemPresetService->delete($id);

            $this->eventDispatcher->notifyEvent(
                'delete.itemPreset',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Value deleted'))
                        ->addDetail(__u('ID'), $id)
                )
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Value deleted')
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
            if (!$this->acl->checkUserAccess(ActionsInterface::ITEMPRESET_CREATE)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $form = new ItemsPresetForm($this->dic);
            $form->validate(ActionsInterface::ITEMPRESET_CREATE);

            $itemData = $form->getItemData();

            $id = $this->itemPresetService->create($itemData);

            $this->eventDispatcher->notifyEvent(
                'create.itemPreset',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Value created'))
                        ->addDetail(__u('Type'), $itemData->getItemPresetData()->getType())
                        ->addDetail(__u('ID'), $id)
                )
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Value created')
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
     * Saves edit action
     *
     * @param int $id
     *
     * @return bool
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     */
    public function saveEditAction(int $id): bool
    {
        try {
            if (!$this->acl->checkUserAccess(ActionsInterface::ITEMPRESET_EDIT)) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('You don\'t have permission to do this operation')
                );
            }

            $form = new ItemsPresetForm($this->dic, $id);
            $form->validate(Acl::ITEMPRESET_EDIT);

            $itemData = $form->getItemData();

            $this->itemPresetService->update($itemData);

            $this->eventDispatcher->notifyEvent(
                'edit.itemPreset',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Value updated'))
                        ->addDetail(__u('Type'), $itemData->getItemPresetData()->getType())
                        ->addDetail(__u('ID'), $id)
                )
            );

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('Value updated')
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

        $this->itemPresetService = $this->dic->get(ItemPresetService::class);
    }
}