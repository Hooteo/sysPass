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

namespace SP\Providers\Acl;

use Exception;
use SP\Config\Config;
use SP\Core\Context\ContextInterface;
use SP\Core\Events\Event;
use SP\Core\Events\EventDispatcher;
use SP\Core\Events\EventReceiver;
use SP\Core\Exceptions\SPException;
use SP\Providers\EventsTrait;
use SP\Providers\Provider;
use SP\Services\Account\AccountAclService;
use SP\Services\UserGroup\UserGroupService;
use SP\Services\UserProfile\UserProfileService;
use SplSubject;

/**
 * Class AclHandler
 *
 * @package SP\Providers\Acl
 */
final class AclHandler extends Provider implements EventReceiver
{
    use EventsTrait;

    public const EVENTS = [
        'edit.userProfile',
        'edit.user',
        'edit.userGroup',
        'delete.user',
        'delete.user.selection',
    ];

    private string             $events;
    private UserProfileService $userProfileService;
    private UserGroupService   $userGroupService;

    public function __construct(
        Config $config,
        ContextInterface $context,
        EventDispatcher $eventDispatcher,
        UserProfileService $userProfileService,
        UserGroupService $userGroupService
    ) {
        $this->userProfileService = $userProfileService;
        $this->userGroupService = $userGroupService;

        parent::__construct($config, $context, $eventDispatcher);
    }


    /**
     * Devuelve los eventos que implementa el observador
     *
     * @return array
     */
    public function getEvents(): array
    {
        return self::EVENTS;
    }

    /**
     * Devuelve los eventos que implementa el observador en formato cadena
     *
     * @return string
     */
    public function getEventsString(): string
    {
        return $this->events;
    }

    /**
     * Receive update from subject
     *
     * @link  https://php.net/manual/en/splobserver.update.php
     *
     * @param  SplSubject  $subject  <p>
     *                            The <b>SplSubject</b> notifying the observer of an update.
     *                            </p>
     *
     * @return void
     * @since 5.1.0
     * @throws \SP\Core\Exceptions\SPException
     */
    public function update(SplSubject $subject): void
    {
        $this->updateEvent('update', new Event($subject));
    }

    /**
     * Evento de actualización
     *
     * @param  string  $eventType  Nombre del evento
     * @param  Event  $event  Objeto del evento
     *
     * @throws \SP\Core\Exceptions\SPException
     */
    public function updateEvent(string $eventType, Event $event): void
    {
        switch ($eventType) {
            case 'edit.userProfile':
                $this->processUserProfile($event);
                break;
            case 'edit.user':
            case 'delete.user':
            case 'delete.user.selection':
                $this->processUser($event);
                break;
            case 'edit.userGroup':
                $this->processUserGroup($event);
                break;
        }
    }

    private function processUserProfile(Event $event): void
    {
        try {
            $eventMessage = $event->getEventMessage();

            if (null === $eventMessage) {
                throw new SPException(__u('Unable to process event for user profile'));
            }

            $extra = $eventMessage->getExtra();

            if (isset($extra['userProfileId'])) {
                foreach ($this->userProfileService->getUsersForProfile($extra['userProfileId'][0]) as $user) {
                    AccountAclService::clearAcl($user->id);
                }
            }
        } catch (Exception $e) {
            processException($e);
        }
    }

    /**
     * @throws \SP\Core\Exceptions\SPException
     */
    private function processUser(Event $event): void
    {
        $eventMessage = $event->getEventMessage();

        if (null === $eventMessage) {
            throw new SPException(__u('Unable to process event for user'));
        }

        $extra = $eventMessage->getExtra();

        if (isset($extra['userId'])) {
            foreach ($extra['userId'] as $id) {
                AccountAclService::clearAcl($id);
            }
        }
    }

    private function processUserGroup(Event $event): void
    {
        try {
            $eventMessage = $event->getEventMessage();

            if (null === $eventMessage) {
                throw new SPException(__u('Unable to process event for user group'));
            }

            $extra = $eventMessage->getExtra();

            if (isset($extra['userGroupId'])) {
                foreach ($this->userGroupService->getUsageByUsers($extra['userGroupId'][0]) as $user) {
                    AccountAclService::clearAcl($user->id);
                }
            }
        } catch (Exception $e) {
            processException($e);
        }
    }

    public function initialize(): void
    {
        $this->events = $this->parseEventsToRegex(self::EVENTS);
    }
}