<?php
/**
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

declare(strict_types=1);

namespace SP\Tests\Modules\Web\Controllers\Account;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Domain\Account\Adapters\AccountPassItemWithIdAndName;
use SP\Domain\Core\Context\SessionContext;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Core\Crypt\VaultInterface;
use SP\Domain\Core\Exceptions\InvalidClassException;
use SP\Domain\User\Models\ProfileData;
use SP\Infrastructure\Database\QueryResult;
use SP\Infrastructure\File\FileException;
use SP\Mvc\View\OutputHandlerInterface;
use SP\Tests\Generators\AccountDataGenerator;
use SP\Tests\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class ViewPassControllerTest
 */
#[Group('integration')]
class ViewPassControllerTest extends IntegrationTestCase
{

    /**
     * @throws NotFoundExceptionInterface
     * @throws Exception
     * @throws FileException
     * @throws InvalidClassException
     * @throws ContainerExceptionInterface
     */
    public function testViewPassAction()
    {
        $this->addDatabaseMapperResolver(
            AccountPassItemWithIdAndName::class,
            new QueryResult([
                                AccountPassItemWithIdAndName::buildFromSimpleModel(
                                    AccountDataGenerator::factory()->buildAccountDataView()
                                )
                            ])
        );
        $crypt = $this->createStub(CryptInterface::class);
        $crypt->method('decrypt')->willReturn('some_data');
        $crypt->method('encrypt')->willReturn('some_data');

        $definitions = $this->getModuleDefinitions();
        $definitions[CryptInterface::class] = $crypt;

        $definitions[OutputHandlerInterface::class] = $this->setupOutputHandler(function (string $output): void {
            $crawler = new Crawler($output);
            $filter = $crawler->filterXPath(
                '//div[@id="box-popup" and @class="box-password-view"]//table//td[starts-with(@class,"dialog-text")]|//button'
            )->extract(['_name']);

            assert(!empty($output));
            assert(count($filter) === 4);

            $this->assertTrue(true);
        });

        $container = $this->buildContainer(
            $definitions,
            $this->buildRequest(
                'get',
                'index.php',
                [
                    'r' => sprintf(
                        "account/viewPass/id/%d",
                        self::$faker->randomNumber(3)
                    )
                ]
            )
        );

        $this->runApp($container);

        $this->expectOutputRegex(
            '/\{"status":0,"description":null,"data":\{"useimage":false,"html":".*"\},"messages":\[\]\}/'
        );
    }

    protected function getContext(): SessionContext|Stub
    {
        $vault = self::createStub(VaultInterface::class);

        $context = parent::getContext();
        $context->method('getVault')->willReturn($vault);

        return $context;
    }

    protected function getUserProfile(): ProfileData
    {
        return new ProfileData(['accViewPass' => true,]);
    }
}
