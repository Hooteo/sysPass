<?php

declare(strict_types=1);
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

namespace SP\Domain\Auth\Providers\Ldap;

use Exception;
use SP\Core\Events\EventDispatcher;
use SP\Domain\Auth\Ports\LdapActionsService;
use SP\Domain\Auth\Ports\LdapConnectionInterface;
use SP\Domain\Auth\Ports\LdapService;
use SP\Domain\Core\Events\EventDispatcherInterface;

use function SP\__u;

/**
 * Class LdapBase
 *
 * @package SP\Domain\Auth\Providers\Ldap
 */
abstract class LdapBase implements LdapService
{
    protected string $server;

    /**
     * LdapBase constructor.
     *
     * @param LdapConnectionInterface $ldapConnection
     * @param LdapActionsService $ldapActions
     * @param LdapParams $ldapParams
     * @param EventDispatcher $eventDispatcher
     */
    public function __construct(
        protected readonly LdapConnectionInterface  $ldapConnection,
        protected readonly LdapActionsService $ldapActions,
        protected readonly LdapParams               $ldapParams,
        protected readonly EventDispatcherInterface $eventDispatcher
    ) {
        $this->server = $this->pickServer();
    }

    abstract protected function pickServer(): string;

    /**
     * @param EventDispatcher $eventDispatcher
     * @param LdapConnectionInterface $ldapConnection
     * @param LdapActionsService $ldapActions
     * @param LdapParams|null $ldapParams
     * @return LdapService
     * @throws LdapException
     * @throws Exception
     */
    public static function factory(
        EventDispatcherInterface $eventDispatcher,
        LdapConnectionInterface  $ldapConnection,
        LdapActionsService $ldapActions,
        ?LdapParams              $ldapParams = null
    ): LdapService {
        if (null !== $ldapParams) {
            $ldapConnection = $ldapConnection->mutate($ldapParams);
            $ldapActions = $ldapActions->mutate($ldapParams);
        }

        $ldapConnection->checkConnection();

        switch ($ldapParams->getType()) {
            case LdapTypeEnum::STD:
                return new LdapStd($ldapConnection, $ldapActions, $ldapParams, $eventDispatcher);
            case LdapTypeEnum::ADS:
                return new LdapMsAds($ldapConnection, $ldapActions, $ldapParams, $eventDispatcher);
            case LdapTypeEnum::AZURE:
                throw new LdapException(__u('To be implemented'));
        }

        throw LdapException::error(__u('LDAP type not set'));
    }

    public function actions(): LdapActionsService
    {
        return $this->ldapActions;
    }

    /**
     * @throws LdapException
     */
    public function connect(?string $bindDn = null, ?string $bindPass = null): void
    {
        $this->ldapConnection->connect($bindDn, $bindPass);
    }

    public function getServer(): string
    {
        return $this->server;
    }

    /**
     * @return string
     */
    protected function getGroupFromParams(): string
    {
        $group = $this->ldapParams->getGroup();

        if ($group === null) {
            return '';
        }

        if (stripos($group, 'cn') === 0) {
            return LdapUtil::getGroupName($group) ?: '';
        }

        return $group;
    }

    /**
     * @return string
     * @throws LdapException
     */
    protected function getGroupDn(): string
    {
        if (stripos($this->ldapParams->getGroup(), 'cn') === 0) {
            return $this->ldapParams->getGroup();
        }

        return $this->ldapActions->searchGroupsDn($this->getGroupObjectFilter())[0] ?? '';
    }
}
