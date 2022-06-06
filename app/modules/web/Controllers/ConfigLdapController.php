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

namespace SP\Modules\Web\Controllers;

use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use SP\Core\Acl\ActionsInterface;
use SP\Core\Acl\UnauthorizedPageException;
use SP\Core\Events\Event;
use SP\Core\Events\EventMessage;
use SP\Core\Exceptions\CheckException;
use SP\Core\Exceptions\SPException;
use SP\Core\Exceptions\ValidationException;
use SP\Domain\Auth\Services\LdapCheckService;
use SP\Domain\Import\Services\LdapImportParams;
use SP\Domain\Import\Services\LdapImportService;
use SP\Http\JsonResponse;
use SP\Modules\Web\Controllers\Traits\ConfigTrait;
use SP\Mvc\View\Template;
use SP\Providers\Auth\Ldap\LdapParams;
use SP\Providers\Auth\Ldap\LdapTypeInterface;

/**
 * Class ConfigLdapController
 *
 * @package SP\Modules\Web\Controllers
 */
final class ConfigLdapController extends SimpleControllerBase
{
    use ConfigTrait;

    /**
     * @return bool
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \JsonException
     */
    public function saveAction(): bool
    {
        try {
            $eventMessage = EventMessage::factory();
            $configData = $this->config->getConfigData();

            // LDAP
            $ldapEnabled = $this->request->analyzeBool('ldap_enabled', false);
            $ldapDefaultGroup = $this->request->analyzeInt('ldap_defaultgroup');
            $ldapDefaultProfile = $this->request->analyzeInt('ldap_defaultprofile');

            $ldapParams = $this->getLdapParamsFromRequest();

            // Valores para la configuración de LDAP
            if ($ldapEnabled
                && !($ldapParams->getServer()
                    || $ldapParams->getSearchBase()
                    || $ldapParams->getBindDn())) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('Missing LDAP parameters')
                );
            }

            if ($ldapEnabled) {
                $configData->setLdapEnabled(true);
                $configData->setLdapType($ldapParams->getType());
                $configData->setLdapTlsEnabled($ldapParams->isTlsEnabled());
                $configData->setLdapServer($this->request->analyzeString('ldap_server'));
                $configData->setLdapBase($ldapParams->getSearchBase());
                $configData->setLdapGroup($ldapParams->getGroup());
                $configData->setLdapDefaultGroup($ldapDefaultGroup);
                $configData->setLdapDefaultProfile($ldapDefaultProfile);
                $configData->setLdapBindUser($ldapParams->getBindDn());
                $configData->setLdapFilterUserObject($ldapParams->getFilterUserObject());
                $configData->setLdapFilterGroupObject($ldapParams->getFilterGroupObject());
                $configData->setLdapFilterUserAttributes($ldapParams->getFilterUserAttributes());
                $configData->setLdapFilterGroupAttributes($ldapParams->getFilterGroupAttributes());

                $databaseEnabled = $this->request->analyzeBool('ldap_database_enabled', false);
                $configData->setLdapDatabaseEnabled($databaseEnabled);

                if ($ldapParams->getBindPass() !== '***') {
                    $configData->setLdapBindPass($ldapParams->getBindPass());
                }

                if ($configData->isLdapEnabled() === false) {
                    $eventMessage->addDescription(__u('LDAP enabled'));
                }
            } elseif ($configData->isLdapEnabled()) {
                $configData->setLdapEnabled(false);

                $eventMessage->addDescription(__u('LDAP disabled'));
            } else {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_SUCCESS,
                    __u('No changes')
                );
            }

            return $this->saveConfig(
                $configData,
                $this->config,
                function () use ($eventMessage) {
                    $this->eventDispatcher->notifyEvent(
                        'save.config.ldap',
                        new Event($this, $eventMessage)
                    );
                });
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * @return LdapParams
     * @throws ValidationException
     */
    protected function getLdapParamsFromRequest(): LdapParams
    {
        $data = LdapParams::getServerAndPort($this->request->analyzeString('ldap_server'));

        if (count($data) === 0) {
            throw new ValidationException(__u('Wrong LDAP parameters'));
        }

        $params = new LdapParams();
        $params->setServer($data['server']);
        $params->setPort($data['port'] ?? 389);
        $params->setSearchBase($this->request->analyzeString('ldap_base'));
        $params->setGroup($this->request->analyzeString('ldap_group'));
        $params->setBindDn($this->request->analyzeString('ldap_binduser'));
        $params->setBindPass($this->request->analyzeEncrypted('ldap_bindpass'));
        $params->setType($this->request->analyzeInt('ldap_server_type', LdapTypeInterface::LDAP_STD));
        $params->setTlsEnabled($this->request->analyzeBool('ldap_tls_enabled', false));
        $params->setFilterUserObject($this->request->analyzeString('ldap_filter_user_object', null));
        $params->setFilterGroupObject($this->request->analyzeString('ldap_filter_group_object', null));
        $params->setFilterUserAttributes($this->request->analyzeArray('ldap_filter_user_attributes'));
        $params->setFilterGroupAttributes($this->request->analyzeArray('ldap_filter_group_attributes'));

        return $params;
    }

    /**
     * @return bool
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \JsonException
     */
    public function checkAction(): bool
    {
        try {
            $ldapParams = $this->getLdapParamsFromRequest();

            // Valores para la configuración de LDAP
            if (!($ldapParams->getServer()
                || $ldapParams->getSearchBase()
                || $ldapParams->getBindDn())
            ) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('Missing LDAP parameters')
                );
            }

            $ldapCheckService = $this->dic->get(LdapCheckService::class);
            $ldapCheckService->checkConnection($ldapParams);

            $data = $ldapCheckService->getObjects(false);

            $template = $this->dic->get(Template::class);
            $template->addTemplate('results', 'itemshow');
            $template->assign('header', __('Results'));

            return $this->returnJsonResponseData(
                ['template' => $template->render(), 'items' => $data['results']],
                JsonResponse::JSON_SUCCESS,
                __u('LDAP connection OK'),
                [sprintf(__('Objects found: %d'), $data['count'])]
            );
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * @return bool
     * @throws DependencyException
     * @throws NotFoundException
     * @throws \JsonException
     */
    public function checkImportAction(): bool
    {
        try {
            $ldapParams = $this->getLdapParamsFromRequest();

            // Valores para la configuración de LDAP
            if (!($ldapParams->getServer()
                || $ldapParams->getSearchBase()
                || $ldapParams->getBindDn())
            ) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_ERROR,
                    __u('Missing LDAP parameters')
                );
            }

            $ldapCheckService = $this->dic->get(LdapCheckService::class);
            $ldapCheckService->checkConnection($ldapParams);

            $filter = $this->request->analyzeString('ldap_import_filter');

            if (empty($filter)) {
                $data = $ldapCheckService->getObjects($this->request->analyzeBool('ldap_import_groups', false));
            } else {
                $data = $ldapCheckService->getObjectsByFilter($filter);
            }

            $template = $this->dic->get(Template::class);
            $template->addTemplate('results', 'itemshow');
            $template->assign('header', __('Results'));
            $template->assign('results', $data);

            return $this->returnJsonResponseData(
                ['template' => $template->render(), 'items' => $data['results']],
                JsonResponse::JSON_SUCCESS,
                __u('LDAP connection OK'),
                [sprintf(__('Objects found: %d'), $data['count'])]
            );
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * importAction
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \JsonException
     */
    public function importAction(): bool
    {
        try {
            if ($this->configData->isDemoEnabled()) {
                return $this->returnJsonResponse(
                    JsonResponse::JSON_WARNING,
                    __u('Ey, this is a DEMO!!')
                );
            }

            $ldapImportParams = new LdapImportParams();

            $ldapImportParams->filter = $this->request->analyzeString('ldap_import_filter');
            $ldapImportParams->loginAttribute = $this->request->analyzeString('ldap_login_attribute');
            $ldapImportParams->userNameAttribute = $this->request->analyzeString('ldap_username_attribute');
            $ldapImportParams->userGroupNameAttribute = $this->request->analyzeString('ldap_groupname_attribute');
            $ldapImportParams->defaultUserGroup = $this->request->analyzeInt('ldap_defaultgroup');
            $ldapImportParams->defaultUserProfile = $this->request->analyzeInt('ldap_defaultprofile');

            $checkImportGroups = $this->request->analyzeBool('ldap_import_groups', false);

            if ((empty($ldapImportParams->loginAttribute)
                    || empty($ldapImportParams->userNameAttribute)
                    || empty($ldapImportParams->defaultUserGroup)
                    || empty($ldapImportParams->defaultUserProfile))
                && ($checkImportGroups === true && empty($ldapImportParams->userGroupNameAttribute))
            ) {
                throw new ValidationException(__u('Wrong LDAP parameters'));
            }

            $ldapParams = $this->getLdapParamsFromRequest();

            $this->eventDispatcher->notifyEvent(
                'import.ldap.start',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('LDAP Import'))
                )
            );

            $userLdapService = $this->dic->get(LdapImportService::class);
            $userLdapService->importUsers($ldapParams, $ldapImportParams);

            if ($checkImportGroups === true) {
                $userLdapService->importGroups($ldapParams, $ldapImportParams);
            }

            $this->eventDispatcher->notifyEvent(
                'import.ldap.end',
                new Event(
                    $this,
                    EventMessage::factory()
                        ->addDescription(__u('Import finished'))
                )
            );

            if ($userLdapService->getTotalObjects() === 0) {
                throw new SPException(__u('There aren\'t any objects to synchronize'));
            }

            return $this->returnJsonResponse(
                JsonResponse::JSON_SUCCESS,
                __u('LDAP users import finished'),
                [
                    sprintf(__('Imported users: %d / %d'), $userLdapService->getSyncedObjects(), $userLdapService->getTotalObjects()),
                    sprintf(__('Errors: %d'), $userLdapService->getErrorObjects())

                ]
            );
        } catch (Exception $e) {
            processException($e);

            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            return $this->returnJsonResponseException($e);
        }
    }

    /**
     * @return void
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \JsonException
     * @throws \SP\Core\Exceptions\SessionTimeout
     */
    protected function initialize(): void
    {
        try {
            $this->checks();
            $this->checkAccess(ActionsInterface::CONFIG_LDAP);

            $this->extensionChecker->checkLdapAvailable(true);
        } catch (UnauthorizedPageException | CheckException $e) {
            $this->eventDispatcher->notifyEvent(
                'exception',
                new Event($e)
            );

            $this->returnJsonResponseException($e);
        }
    }
}
