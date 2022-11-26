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

namespace SP\Tests\Services\Account;

use DI\DependencyException;
use DI\NotFoundException;
use SP\Core\Context\ContextException;
use SP\Core\Exceptions\ConstraintException;
use SP\Core\Exceptions\QueryException;
use SP\Domain\Account\Ports\AccountToTagServiceInterface;
use SP\Domain\Account\Services\AccountToTagService;
use SP\Tests\DatabaseTestCase;
use function SP\Tests\setupContext;

/**
 * Class AccountToTagServiceTest
 *
 * @package SP\Tests\Services
 */
class AccountToTagServiceTest extends DatabaseTestCase
{
    /**
     * @var \SP\Domain\Account\Ports\AccountToTagServiceInterface
     */
    private static $service;

    /**
     * @throws NotFoundException
     * @throws ContextException
     * @throws DependencyException
     */
    public static function setUpBeforeClass(): void
    {
        $dic = setupContext();

        self::$loadFixtures = true;

        // Inicializar el servicio
        self::$service = $dic->get(AccountToTagService::class);
    }

    /**
     * @throws ConstraintException
     * @throws QueryException
     */
    public function testGetTagsByAccountId()
    {
        $this->assertCount(2, self::$service->getTagsByAccountId(1));
        $this->assertCount(0, self::$service->getTagsByAccountId(10));
    }
}
