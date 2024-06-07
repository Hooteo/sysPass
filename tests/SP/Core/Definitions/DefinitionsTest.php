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

namespace SP\Tests\Core\Definitions;

use DI\ContainerBuilder;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SP\Core\Application;
use SP\Core\Definitions\CoreDefinitions;
use SP\Core\Definitions\DomainDefinitions;
use SP\Domain\Auth\Ports\LdapConnectionInterface;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Http\Ports\RequestService;
use SP\Infrastructure\File\ArchiveHandler;

/**
 * Class DefinitionsTest
 */
#[Group('unitary')]
class DefinitionsTest extends TestCase
{

    /**
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testGetDefinitions()
    {
        define('APP_MODULE', 'test');

        $configData = $this->createStub(ConfigDataInterface::class);
        $configData->method('getSiteTheme')->willReturn('theme');
        $configData->method('getLdapServer')->willReturn('a_server');
        $configData->method('getLdapType')->willReturn(1);
        $configData->method('getLdapBindUser')->willReturn('a_user');
        $configData->method('getLdapBindPass')->willReturn('a_password');
        $configData->method('getLdapBase')->willReturn('a_base');
        $configData->method('getLdapGroup')->willReturn('a_group');
        $configData->method('getMailServer')->willReturn('a_server');
        $configData->method('getMailUser')->willReturn('a_user');
        $configData->method('getMailPass')->willReturn('a_password');
        $configData->method('getMailSecurity')->willReturn('a_security');
        $configData->method('getMailFrom')->willReturn('an_email');
        $configData->method('isMailAuthenabled')->willReturn(false);
        $configData->method('getPasswordSalt')->willReturn('a_salt');

        $requestService = $this->createStub(RequestService::class);
        $requestService->method('analyzeString')
                       ->willReturnMap(
                           [
                               ['sitelang', 'a_lang'],
                               ['adminlogin', 'a_login'],
                               ['dbuser', 'a_user'],
                               ['dbname', 'a_name'],
                               ['dbhost', 'a_host'],
                           ]
                       );
        $requestService->method('analyzeEncrypted')
                       ->willReturnMap(
                           [
                               ['adminpass', 'a_password'],
                               ['masterpassword', 'a_password'],
                           ]
                       );
        $requestService->method('analyzeBool')
                       ->willReturnMap(
                           [
                               ['hostingmode', false]
                           ]
                       );

        $mockedDefinitions = [
            ConfigDataInterface::class => $configData,
            LdapConnectionInterface::class => $this->createStub(LdapConnectionInterface::class),
            'backup.dbArchiveHandler' => $this->createStub(ArchiveHandler::class),
            'backup.appArchiveHandler' => $this->createStub(ArchiveHandler::class),
        ];

        $definitions = CoreDefinitions::getDefinitions();

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(DomainDefinitions::getDefinitions(), $definitions, $mockedDefinitions);
        $out = $containerBuilder->build();

        $this->assertInstanceOf(Application::class, $out->get(Application::class));

        foreach (array_keys($definitions) as $definition) {
            if (class_exists($definition)) {
                $this->assertInstanceOf($definition, $out->get($definition));
            }
        }
    }
}
