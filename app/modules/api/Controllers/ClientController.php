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

namespace SP\Modules\Api\Controllers;

use Exception;
use League\Fractal\Resource\Item;
use SP\Adapters\ClientAdapter;
use SP\Core\Acl\ActionsInterface;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\DataModel\ClientData;
use SP\DataModel\ItemSearchData;
use SP\Domain\Api\Services\ApiResponse;
use SP\Domain\Client\Services\ClientService;
use SP\Modules\Api\Controllers\Help\ClientHelp;
use SP\Mvc\Controller\ItemTrait;
use SP\Util\Util;

/**
 * Class ClientController
 *
 * @package SP\Modules\Api\Controllers
 */
final class ClientController extends ControllerBase
{
    use ItemTrait;

    private ?ClientService $clientService = null;

    /**
     * viewAction
     */
    public function viewAction(): void
    {
        try {
            $this->setupApi(ActionsInterface::CLIENT_VIEW);

            $id = $this->apiService->getParamInt('id', true);

            $customFields = Util::boolval($this->apiService->getParamString('customFields'));

            $clientData = $this->clientService->getById($id);

            $this->eventDispatcher->notifyEvent(
                'show.client',
                new Event($this)
            );

            $this->eventDispatcher->notifyEvent(
                'show.client',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Client displayed'))
                        ->addDetail(__u('Name'), $clientData->getName())
                        ->addDetail('ID', $id)
                )
            );

            if ($customFields) {
                $this->apiService->requireMasterPass();
            }

            $out = $this->fractal
                ->createData(
                    new Item(
                        $clientData,
                        new ClientAdapter($this->configData)
                    ));

            if ($customFields) {
                $this->apiService->requireMasterPass();
                $this->fractal->parseIncludes(['customFields']);
            }

            $this->returnResponse(
                ApiResponse::makeSuccess($out->toArray(), $id)
            );
        } catch (Exception $e) {
            processException($e);

            $this->returnResponseException($e);
        }
    }

    /**
     * createAction
     */
    public function createAction(): void
    {
        try {
            $this->setupApi(ActionsInterface::CLIENT_CREATE);

            $clientData = new ClientData();
            $clientData->setName($this->apiService->getParamString('name', true));
            $clientData->setDescription($this->apiService->getParamString('description'));
            $clientData->setIsGlobal($this->apiService->getParamInt('global'));

            $id = $this->clientService->create($clientData);

            $clientData->setId($id);

            $this->eventDispatcher->notifyEvent(
                'create.client',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Client added'))
                        ->addDetail(__u('Name'), $clientData->getName())
                        ->addDetail('ID', $id)
                )
            );

            $this->returnResponse(
                ApiResponse::makeSuccess(
                    $clientData,
                    $id,
                    __('Client added')
                )
            );
        } catch (Exception $e) {
            processException($e);

            $this->returnResponseException($e);
        }
    }

    /**
     * editAction
     */
    public function editAction(): void
    {
        try {
            $this->setupApi(ActionsInterface::CLIENT_EDIT);

            $clientData = new ClientData();
            $clientData->setId($this->apiService->getParamInt('id', true));
            $clientData->setName($this->apiService->getParamString('name', true));
            $clientData->setDescription($this->apiService->getParamString('description'));
            $clientData->setIsGlobal($this->apiService->getParamInt('global'));

            $this->clientService->update($clientData);

            $this->eventDispatcher->notifyEvent(
                'edit.client',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Client updated'))
                        ->addDetail(__u('Name'), $clientData->getName())
                        ->addDetail('ID', $clientData->getId())
                )
            );

            $this->returnResponse(
                ApiResponse::makeSuccess(
                    $clientData,
                    $clientData->getId(),
                    __('Client updated')
                )
            );
        } catch (Exception $e) {
            processException($e);

            $this->returnResponseException($e);
        }
    }

    /**
     * deleteAction
     */
    public function deleteAction(): void
    {
        try {
            $this->setupApi(ActionsInterface::CLIENT_DELETE);

            $id = $this->apiService->getParamInt('id', true);

            $clientData = $this->clientService->getById($id);

            $this->clientService->delete($id);

            $this->eventDispatcher->notifyEvent(
                'delete.client',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Client deleted'))
                        ->addDetail(__u('Name'), $clientData->getName())
                        ->addDetail('ID', $id)
                )
            );

            $this->returnResponse(
                ApiResponse::makeSuccess(
                    $clientData,
                    $id,
                    __('Client deleted')
                )
            );
        } catch (Exception $e) {
            $this->returnResponseException($e);

            processException($e);
        }
    }

    /**
     * searchAction
     */
    public function searchAction(): void
    {
        try {
            $this->setupApi(ActionsInterface::CLIENT_SEARCH);

            $itemSearchData = new ItemSearchData();
            $itemSearchData->setSeachString($this->apiService->getParamString('text'));
            $itemSearchData->setLimitCount($this->apiService->getParamInt('count', false, self::SEARCH_COUNT_ITEMS));

            $this->eventDispatcher->notifyEvent(
                'search.client',
                new Event($this)
            );

            $this->returnResponse(
                ApiResponse::makeSuccess(
                    $this->clientService->search($itemSearchData)->getDataAsArray()
                )
            );
        } catch (Exception $e) {
            processException($e);

            $this->returnResponseException($e);
        }
    }

    /**
     * @throws \SP\Core\Exceptions\InvalidClassException
     */
    protected function initialize(): void
    {
        $this->clientService = $this->dic->get(ClientService::class);
        $this->apiService->setHelpClass(ClientHelp::class);
    }
}