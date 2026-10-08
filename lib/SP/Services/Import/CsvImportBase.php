<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      https://syspass.org
 * @copyright 2012-2019, Rubén Domínguez nuxsmin@$syspass.org
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
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Services\Import;

use Exception;
use Psr\Container\ContainerInterface;
use SP\Core\Acl\ActionsInterface;
use SP\Core\Events\Event;
use SP\Core\Events\EventDispatcher;
use SP\Core\Events\EventMessage;
use SP\DataModel\CategoryData;
use SP\DataModel\ClientData;
use SP\DataModel\CustomFieldData;
use SP\Services\Account\AccountRequest;
use SP\Services\Account\AccountService;
use SP\Services\Category\CategoryService;
use SP\Services\Client\ClientService;
use SP\Services\CustomField\CustomFieldDefService;
use SP\Services\CustomField\CustomFieldService;
use SP\Services\CustomField\CustomFieldTypeService;
use SP\Services\Tag\TagService;
use SP\Storage\File\FileException;

defined('APP_ROOT') || die();

/**
 * Clase CsvImportBase para base de clases de importación desde archivos CSV
 *
 * @package SP
 */
abstract class CsvImportBase
{
    use ImportTrait;

    /**
     * @var int
     */
    protected $numFields = 7;
    /**
     * @var array
     */
    protected $mapFields = [];
    /**
     * @var FileImport
     */
    protected $fileImport;
    /**
     * @var EventDispatcher
     */
    protected $eventDispatcher;
    /**
     * @var array
     */
    protected $categories = [];
    /**
     * @var array
     */
    protected $clients = [];
    /**
     * @var CustomFieldService
     */
    protected $customFieldService;
    /**
     * @var int|null The OTP custom field's definition id for accounts,
     * resolved once in the constructor - null if this instance has no
     * such field defined (fork removed, or a stock sysPass without it).
     */
    protected $otpFieldDefinitionId;

    /**
     * ImportBase constructor.
     *
     * @param ContainerInterface $dic
     * @param FileImport         $fileImport
     * @param ImportParams       $importParams
     *
     */
    public function __construct(ContainerInterface $dic, FileImport $fileImport, ImportParams $importParams)
    {
        $this->fileImport = $fileImport;
        $this->importParams = $importParams;

        $this->accountService = $dic->get(AccountService::class);
        $this->categoryService = $dic->get(CategoryService::class);
        $this->clientService = $dic->get(ClientService::class);
        $this->tagService = $dic->get(TagService::class);
        $this->eventDispatcher = $dic->get(EventDispatcher::class);
        $this->customFieldService = $dic->get(CustomFieldService::class);
        $this->otpFieldDefinitionId = $this->findOtpFieldDefinitionId($dic);
    }

    /**
     * Resolves the account OTP custom field's definition id by joining
     * through CustomFieldType.name = 'otp' - the same way
     * AccountOtpHelper identifies it - rather than assuming a fixed id,
     * since CustomFieldDefinition rows are autoincrement.
     *
     * @param ContainerInterface $dic
     *
     * @return int|null
     */
    private function findOtpFieldDefinitionId(ContainerInterface $dic)
    {
        $otpType = null;

        foreach ($dic->get(CustomFieldTypeService::class)->getAll() as $type) {
            if ($type->getName() === 'otp') {
                $otpType = $type;
                break;
            }
        }

        if ($otpType === null) {
            return null;
        }

        foreach ($dic->get(CustomFieldDefService::class)->getAllBasic() as $definition) {
            if ($definition->getModuleId() === ActionsInterface::ACCOUNT
                && $definition->getTypeId() === $otpType->getId()
            ) {
                return $definition->getId();
            }
        }

        return null;
    }

    /**
     * @param int $numFields
     */
    public function setNumFields($numFields)
    {
        $this->numFields = $numFields;
    }

    /**
     * @param array $mapFields
     */
    public function setMapFields($mapFields)
    {
        $this->mapFields = $mapFields;
    }

    /**
     * Obtener los datos de las entradas de sysPass y crearlas
     *
     * @throws ImportException
     * @throws FileException
     */
    protected function processAccounts()
    {
        $line = 0;

        $handler = $this->fileImport->getFileHandler()->open();

        while (($fields = fgetcsv($handler, 0, $this->importParams->getCsvDelimiter())) !== false) {
            $line++;
            $numfields = count($fields);

            // Fork note: an 8th, optional column carries the account's
            // OTP/TOTP secret (set on the custom field also used by the
            // "is:otp"/"not:otp" search filters and the OTP dropdown -
            // see README.md) - a plain 7-field line still works exactly
            // as before.
            if ($numfields !== $this->numFields && $numfields !== $this->numFields + 1) {
                throw new ImportException(
                    sprintf(__('Wrong number of fields (%d)'), $numfields),
                    ImportException::ERROR,
                    sprintf(__('Please, check the CSV file format in line %s'), $line)
                );
            }

            // Asignar los valores del array a variables
            list($accountName, $clientName, $categoryName, $url, $login, $password, $notes) = $fields;
            $otp = isset($fields[7]) ? trim($fields[7]) : '';

            try {
                if (empty($clientName) || empty($categoryName)) {
                    throw new ImportException('Either client or category name not set');
                }

                // Obtener los ids de cliente y categoría
                $clientId = $this->addClient(new ClientData(null, $clientName));
                $categoryId = $this->addCategory(new CategoryData(null, $categoryName));

                // Crear la nueva cuenta
                $accountRequest = new AccountRequest();
                $accountRequest->name = $accountName;
                $accountRequest->login = $login;
                $accountRequest->clientId = $clientId;
                $accountRequest->categoryId = $categoryId;
                $accountRequest->notes = $notes;
                $accountRequest->url = $url;
                $accountRequest->pass = $password;

                $accountId = $this->addAccount($accountRequest);

                if ($otp !== '') {
                    if ($this->otpFieldDefinitionId === null) {
                        $this->eventDispatcher->notifyEvent('run.import.csv.process.account',
                            new Event($this, EventMessage::factory()
                                ->addDescription(__u('OTP column set but no OTP custom field is defined - skipped'))
                                ->addDetail(__u('Account'), $accountName))
                        );
                    } else {
                        $customFieldData = new CustomFieldData();
                        $customFieldData->setItemId($accountId);
                        $customFieldData->setModuleId(ActionsInterface::ACCOUNT);
                        $customFieldData->setDefinitionId($this->otpFieldDefinitionId);
                        $customFieldData->setData($otp);

                        $this->customFieldService->create($customFieldData);
                    }
                }

                $this->eventDispatcher->notifyEvent('run.import.csv.process.account',
                    new Event($this, EventMessage::factory()
                        ->addDetail(__u('Account imported'), $accountName)
                        ->addDetail(__u('Client'), $clientName))
                );
            } catch (Exception $e) {
                processException($e);

                $this->eventDispatcher->notifyEvent('exception',
                    new Event($e, EventMessage::factory()
                        ->addDetail(__u('Error while importing the account'), $accountName)
                        ->addDetail(__u('Error while processing line'), $line))
                );
            }
        }

        $this->fileImport->getFileHandler()->close();

        if ($line === 0) {
            throw new ImportException(
                sprintf(__('Wrong number of fields (%d)'), 0),
                ImportException::ERROR,
                sprintf(__('Please, check the CSV file format in line %s'), 0)
            );
        }
    }
}