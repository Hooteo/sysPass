<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2022, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Infrastructure\Account\Repositories;

use SP\Core\Exceptions\ConstraintException;
use SP\Core\Exceptions\QueryException;
use SP\Domain\Account\In\AccountToFavoriteRepositoryInterface;
use SP\Infrastructure\Common\Repositories\Repository;
use SP\Infrastructure\Database\QueryData;
use SP\Infrastructure\Database\QueryResult;

/**
 * Class AccountFavoriteRepository
 *
 * @package SP\Infrastructure\Account\Repositories
 */
final class AccountToFavoriteRepository extends Repository implements AccountToFavoriteRepositoryInterface
{
    /**
     * Obtener un array con los Ids de cuentas favoritas
     *
     * @param $id int El Id de usuario
     *
     * @return QueryResult
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getForUserId(int $id): QueryResult
    {
        $queryData = new QueryData();
        $queryData->setQuery('SELECT accountId, userId FROM AccountToFavorite WHERE userId = ?');
        $queryData->addParam($id);
        $queryData->setUseKeyPair(true);

        return $this->db->doQuery($queryData);
    }

    /**
     * Añadir una cuenta a la lista de favoritos
     *
     * @param $accountId int El Id de la cuenta
     * @param $userId    int El Id del usuario
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     */
    public function add(int $accountId, int $userId): int
    {
        $queryData = new QueryData();
        $queryData->setQuery('INSERT INTO AccountToFavorite SET accountId = ?, userId = ?');
        $queryData->setParams([$accountId, $userId]);
        $queryData->setOnErrorMessage(__u('Error while adding favorite'));

        return $this->db->doQuery($queryData)->getLastId();
    }

    /**
     * Eliminar una cuenta de la lista de favoritos
     *
     * @param $accountId int El Id de la cuenta
     * @param $userId    int El Id del usuario
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     */
    public function delete(int $accountId, int $userId): int
    {
        $queryData = new QueryData();
        $queryData->setQuery('DELETE FROM AccountToFavorite WHERE accountId = ? AND userId = ?');
        $queryData->setParams([$accountId, $userId]);
        $queryData->setOnErrorMessage(__u('Error while deleting favorite'));

        return $this->db->doQuery($queryData)->getAffectedNumRows();
    }
}