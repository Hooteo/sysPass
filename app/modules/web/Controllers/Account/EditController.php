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

namespace SP\Modules\Web\Controllers\Account;


use Exception;
use SP\Core\Acl\ActionsInterface;
use SP\Core\Application;
use SP\Core\Events\Event;
use SP\Core\UI\ThemeIcons;
use SP\Domain\Account\AccountServiceInterface;
use SP\Modules\Web\Controllers\Helpers\Account\AccountHelper;
use SP\Mvc\Controller\WebControllerHelper;
use SP\Util\ErrorUtil;

final class EditController extends AccountControllerBase
{
    private AccountHelper $accountHelper;
    private ThemeIcons $icons;
    private AccountServiceInterface $accountService;

    public function __construct(
        Application $application,
        WebControllerHelper $webControllerHelper,
        AccountHelper $accountHelper,
        \SP\Domain\Account\AccountServiceInterface $accountService
    ) {
        parent::__construct(
            $application,
            $webControllerHelper
        );

        $this->accountHelper = $accountHelper;
        $this->accountService = $accountService;
        $this->icons = $this->theme->getIcons();
    }

    /**
     * Edit action
     *
     * @param  int  $id  Account's ID
     */
    public function editAction(int $id): void
    {
        try {
            $accountDetailsResponse = $this->accountService->getById($id);
            $this->accountService
                ->withUsersById($accountDetailsResponse)
                ->withUserGroupsById($accountDetailsResponse)
                ->withTagsById($accountDetailsResponse);

            $this->accountHelper->setViewForAccount($accountDetailsResponse, ActionsInterface::ACCOUNT_EDIT);

            $this->view->addTemplate('account');
            $this->view->assign(
                'title',
                [
                    'class' => 'titleOrange',
                    'name'  => __('Edit Account'),
                    'icon'  => $this->icons->getIconEdit()->getIcon(),
                ]
            );
            $this->view->assign('formRoute', 'account/saveEdit');

            $this->accountService->incrementViewCounter($id);

            $this->eventDispatcher->notifyEvent('show.account.edit', new Event($this));

            if ($this->isAjax === false) {
                $this->upgradeView();
            }

            $this->view();
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            if ($this->isAjax === false && !$this->view->isUpgraded()) {
                $this->upgradeView();
            }

            ErrorUtil::showExceptionInView($this->view, $e, 'account');
        }
    }
}