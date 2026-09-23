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

namespace SP\Repositories\User;

use SP\Core\Exceptions\ConstraintException;
use SP\Core\Exceptions\QueryException;
use SP\Repositories\Repository;
use SP\Storage\Database\QueryData;
use SP\Storage\Database\QueryResult;

/**
 * Class UserMfaRepository
 *
 * One row per user (userId is unique) holding their login-time TOTP secret,
 * encrypted with a key derived from that user's own login password.
 *
 * @package SP\Repositories\User
 */
final class UserMfaRepository extends Repository
{
    /**
     * @param int $userId
     *
     * @return QueryResult
     * @throws ConstraintException
     * @throws QueryException
     */
    public function getByUserId(int $userId)
    {
        $queryData = new QueryData();
        $queryData->setQuery('SELECT userId, secret, `key`, dateAdd FROM UserMfa WHERE userId = ? LIMIT 1');
        $queryData->addParam($userId);

        return $this->db->doSelect($queryData);
    }

    /**
     * Creates or replaces the MFA secret for a user
     *
     * @param int    $userId
     * @param string $secret
     * @param string $key
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     */
    public function save(int $userId, string $secret, string $key)
    {
        $query = /** @lang SQL */
            'INSERT INTO UserMfa (userId, secret, `key`, dateAdd)
            VALUES (?, ?, ?, UNIX_TIMESTAMP())
            ON DUPLICATE KEY UPDATE secret = VALUES(secret), `key` = VALUES(`key`)';

        $queryData = new QueryData();
        $queryData->setQuery($query);
        $queryData->setParams([$userId, $secret, $key]);
        $queryData->setOnErrorMessage(__u('Internal error'));

        return $this->db->doQuery($queryData)->getAffectedNumRows();
    }

    /**
     * @param int $userId
     *
     * @return int
     * @throws ConstraintException
     * @throws QueryException
     */
    public function deleteByUserId(int $userId)
    {
        $queryData = new QueryData();
        $queryData->setQuery('DELETE FROM UserMfa WHERE userId = ? LIMIT 1');
        $queryData->addParam($userId);
        $queryData->setOnErrorMessage(__u('Internal error'));

        return $this->db->doQuery($queryData)->getAffectedNumRows();
    }
}
