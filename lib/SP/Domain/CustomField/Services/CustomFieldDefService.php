<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2024, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Domain\CustomField\Services;

use SP\Core\Application;
use SP\DataModel\ItemSearchData;
use SP\Domain\Common\Services\Service;
use SP\Domain\Common\Services\ServiceException;
use SP\Domain\Common\Services\ServiceItemTrait;
use SP\Domain\Core\Acl\AclActionsInterface;
use SP\Domain\Core\Exceptions\ConstraintException;
use SP\Domain\Core\Exceptions\QueryException;
use SP\Domain\Core\Exceptions\SPException;
use SP\Domain\CustomField\Models\CustomFieldDefinition;
use SP\Domain\CustomField\Ports\CustomFieldDataRepository;
use SP\Domain\CustomField\Ports\CustomFieldDefinitionRepository;
use SP\Domain\CustomField\Ports\CustomFieldDefServiceInterface;
use SP\Infrastructure\Common\Repositories\NoSuchItemException;
use SP\Infrastructure\Database\DatabaseInterface;
use SP\Infrastructure\Database\QueryResult;

/**
 * Class CustomFieldDefService
 *
 * @package SP\Domain\CustomField\Services
 */
final class CustomFieldDefService extends Service implements CustomFieldDefServiceInterface
{
    use ServiceItemTrait;

    protected CustomFieldDefinitionRepository $customFieldDefRepository;
    protected CustomFieldDataRepository       $customFieldRepository;
    private DatabaseInterface                 $database;

    public function __construct(
        Application                     $application,
        CustomFieldDefinitionRepository $customFieldDefRepository,
        CustomFieldDataRepository       $customFieldRepository,
        DatabaseInterface               $database
    ) {
        parent::__construct($application);

        $this->customFieldDefRepository = $customFieldDefRepository;
        $this->customFieldRepository = $customFieldRepository;
        $this->database = $database;
    }


    /**
     * @param $id
     *
     * @return mixed
     */
    public static function getFieldModuleById($id)
    {
        $modules = self::getFieldModules();

        return $modules[$id] ?? $id;
    }

    /**
     * Devuelve los módulos disponibles para los campos personalizados
     */
    public static function getFieldModules(): array
    {
        return [
            AclActionsInterface::ACCOUNT  => __('Accounts'),
            AclActionsInterface::CATEGORY => __('Categories'),
            AclActionsInterface::CLIENT   => __('Clients'),
            AclActionsInterface::USER     => __('Users'),
            AclActionsInterface::GROUP    => __('Groups'),
        ];
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function search(ItemSearchData $itemSearchData): QueryResult
    {
        return $this->customFieldDefRepository->search($itemSearchData);
    }

    /**
     * @throws ServiceException
     */
    public function delete(int $id): CustomFieldDefService
    {
        $this->transactionAware(
            function () use ($id) {
                if ($this->customFieldDefRepository->delete($id) === 0) {
                    throw new NoSuchItemException(__u('Field not found'), SPException::INFO);
                }
            },
            $this->database
        );

        return $this;
    }

    /**
     * Deletes all the items for given ids
     *
     * @param  int[]  $ids
     *
     * @throws ServiceException
     */
    public function deleteByIdBatch(array $ids): void
    {
        $this->transactionAware(
            function () use ($ids) {
                if ($this->customFieldDefRepository->deleteByIdBatch($ids) !== count($ids)) {
                    throw new ServiceException(
                        __u('Error while deleting the fields'),
                        SPException::WARNING
                    );
                }
            },
            $this->database
        );
    }

    /**
     * @param CustomFieldDefinition $itemData
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     */
    public function create(CustomFieldDefinition $itemData): int
    {
        return $this->customFieldDefRepository->create($itemData);
    }

    /**
     * @throws ServiceException
     * @throws ConstraintException
     * @throws QueryException
     */
    public function updateRaw(CustomFieldDefinition $itemData): void
    {
        if ($this->customFieldDefRepository->update($itemData) !== 1) {
            throw new ServiceException(__u('Error while updating the custom field'));
        }
    }

    /**
     * @throws ServiceException
     */
    public function update(CustomFieldDefinition $itemData)
    {
        return $this->transactionAware(
            function () use ($itemData) {
                $customFieldDefinitionData = $this->getById($itemData->getId());

                // Delete the data used by the items using the previous definition
                if ($customFieldDefinitionData->getModuleId() !== $itemData->moduleId) {
                    $this->customFieldRepository->deleteByDefinition($customFieldDefinitionData->getId());
                }

                if ($this->customFieldDefRepository->update($itemData) !== 1) {
                    throw new ServiceException(__u('Error while updating the custom field'));
                }
            },
            $this->database
        );
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     * @throws NoSuchItemException
     */
    public function getById(int $id): CustomFieldDefinition
    {
        return $this->customFieldDefRepository->getById($id);
    }

    /**
     * Get all items from the service's repository
     *
     * @return CustomFieldDefinition[]
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getAll(): array
    {
        return $this->customFieldDefRepository->getAll()->getDataAsArray();
    }
}
