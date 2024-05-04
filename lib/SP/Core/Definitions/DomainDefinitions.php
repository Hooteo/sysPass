<?php
declare(strict_types=1);
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

namespace SP\Core\Definitions;

use Psr\Container\ContainerInterface;
use SP\Core\Application;
use SP\Core\Crypt\RequestBasedPassword;
use SP\Core\Crypt\UuidCookie;
use SP\Domain\Account\Ports\AccountSearchDataBuilder;
use SP\Domain\Account\Services\Builders\AccountSearchData;
use SP\Domain\Config\Ports\ConfigDataInterface;
use SP\Domain\Core\Crypt\CryptInterface;
use SP\Domain\Crypt\Ports\SecureSessionService;
use SP\Domain\Crypt\Services\SecureSession;
use SP\Infrastructure\File\FileCache;

use function DI\autowire;
use function DI\factory;

/**
 * Class DomainDefinitions
 */
final class DomainDefinitions
{
    private const DOMAINS = [
        'Account',
        'Api',
        'Auth',
        'Category',
        'Client',
        'Config',
        'Crypt',
        'CustomField',
        'Export',
        'Import',
        'Install',
        'ItemPreset',
        'Notification',
        'Plugins',
        'Security',
        'Tag',
        'User',
    ];

    private const PORTS = [
        'Service' => 'SP\Domain\%s\Services',
        'Repository' => 'SP\Infrastructure\%s\Repositories',
        'Adapter' => 'SP\Domain\%s\Adapters'
    ];

    public static function getDefinitions(): array
    {
        $sources = [];

        foreach (self::DOMAINS as $domain) {
            foreach (self::PORTS as $suffix => $target) {
                $key = sprintf('SP\Domain\%s\Ports\*%s', $domain, $suffix);
                $sources[$key] = autowire(sprintf($target, $domain));
            }
        }

        return [
            ...$sources,
            AccountSearchDataBuilder::class => autowire(AccountSearchData::class),
            SecureSessionService::class => factory(
                static function (ContainerInterface $c) {
                    $fileCache = new FileCache(
                        SecureSession::getFileNameFrom(
                            $c->get(UuidCookie::class),
                            $c->get(ConfigDataInterface::class)->getPasswordSalt()
                        )
                    );

                    return new SecureSession(
                        $c->get(Application::class),
                        $c->get(CryptInterface::class),
                        $fileCache,
                        $c->get(RequestBasedPassword::class)
                    );
                }
            ),
        ];
    }
}
