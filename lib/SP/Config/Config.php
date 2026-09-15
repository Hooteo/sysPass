<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      https://syspass.org
 * @copyright 2012-2019, Rubén Domínguez nuxsmin@$syspass.org
 * @copyright 2026, Infonet Solutions - Modified for this fork
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

namespace SP\Config;

use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Exception;
use Psr\Container\ContainerInterface;
use ReflectionObject;
use SP\Core\Context\ContextInterface;
use SP\Core\Exceptions\ConfigException;
use SP\Services\Config\ConfigBackupService;
use SP\Storage\File\FileCacheInterface;
use SP\Storage\File\FileException;
use SP\Storage\File\XmlFileStorageInterface;
use SP\Util\PasswordUtil;

defined('APP_ROOT') || die();

/**
 * Esta clase es responsable de leer y escribir la configuración del archivo config.php
 */
final class Config
{
    /**
     * Cache file name
     */
    const CONFIG_CACHE_FILE = CACHE_PATH . DIRECTORY_SEPARATOR . 'config.cache';
    /**
     * @var int
     */
    private static $timeUpdated;
    /**
     * @var ContextInterface
     */
    private $context;
    /**
     * @var bool
     */
    private $configLoaded = false;
    /**
     * @var ConfigData
     */
    private $configData;
    /**
     * @var XmlFileStorageInterface
     */
    private $fileStorage;
    /**
     * @var FileCacheInterface
     */
    private $fileCache;
    /**
     * @var ContainerInterface
     */
    private $dic;

    /**
     * Config constructor.
     *
     * @param XmlFileStorageInterface $fileStorage
     * @param FileCacheInterface      $fileCache
     * @param ContainerInterface      $dic
     *
     * @throws ConfigException
     */
    public function __construct(XmlFileStorageInterface $fileStorage, FileCacheInterface $fileCache, ContainerInterface $dic)
    {
        $this->fileCache = $fileCache;
        $this->fileStorage = $fileStorage;
        $this->context = $dic->get(ContextInterface::class);
        $this->dic = $dic;

        $this->initialize();
    }

    /**
     * @throws ConfigException
     */
    private function initialize()
    {
        if (!$this->configLoaded) {
            try {
                if ($this->fileCache->exists()
                    && !$this->isCacheExpired()
                ) {
                    $this->configData = $this->fileCache->load();

                    logger('Config cache loaded');
                } else {
                    if (file_exists($this->fileStorage->getFileHandler()->getFile())) {
                        $this->configData = $this->loadConfigFromFile();
                        $this->fileCache->save($this->configData);
                    } else {
                        $configData = new ConfigData();

                        $this->applyEnvironmentOverrides($configData);

                        $this->saveConfig($configData, false);

                        logger('Config file created', 'INFO');
                    }

                    logger('Config loaded');
                }

                self::$timeUpdated = $this->configData->getConfigDate();

                $this->configLoaded = true;
            } catch (Exception $e) {
                processException($e);

                throw new ConfigException($e->getMessage(),
                    ConfigException::CRITICAL,
                    null,
                    $e->getCode(),
                    $e);
            }
        }
    }

    /**
     * Provisions a brand new config from the environment (eg. Docker secrets)
     * so a migrated deployment doesn't need a hand-edited config.xml.
     *
     * Only ever called when app/config has no config.xml yet, so this never
     * touches an already-running instance's configuration.
     */
    private function applyEnvironmentOverrides(ConfigData $configData)
    {
        $passwordSalt = getenv('SYSPASS_PASSWORD_SALT');

        $configData->setPasswordSalt(
            $passwordSalt !== false && $passwordSalt !== ''
                ? $passwordSalt
                : PasswordUtil::generateRandomBytes(30)
        );

        $dbHost = getenv('SYSPASS_DB_HOST');

        if ($dbHost === false || $dbHost === '') {
            // No DB connection provided - fall through to the install
            // wizard, same as if no env vars were set at all.
            return;
        }

        $configData->setDbHost($dbHost);
        $configData->setDbName((string)getenv('SYSPASS_DB_NAME'));
        $configData->setDbUser((string)getenv('SYSPASS_DB_USER'));
        $configData->setDbPass((string)getenv('SYSPASS_DB_PASS'));

        $dbPort = getenv('SYSPASS_DB_PORT');

        if ($dbPort !== false && $dbPort !== '') {
            $configData->setDbPort((int)$dbPort);
        }

        // Providing DB connection details means "this is an already
        // installed database", not "run the install wizard against it".
        $configData->setInstalled(true);

        // These must match what the OLD installation's config.xml last had,
        // or sysPass will either re-show the install wizard against a
        // non-empty database, or replay every upgrade script from scratch
        // against a database that already has them applied - neither value
        // can be derived from the database itself, they only ever lived in
        // the old config.xml.
        $dbVersion = getenv('SYSPASS_DB_VERSION');

        if ($dbVersion !== false && $dbVersion !== '') {
            $configData->setDatabaseVersion($dbVersion);
        }

        $appVersion = getenv('SYSPASS_APP_VERSION');

        if ($appVersion !== false && $appVersion !== '') {
            $configData->setAppVersion($appVersion);
        }
    }

    /**
     * @return bool
     */
    private function isCacheExpired()
    {
        try {
            return $this->fileCache->isExpiredDate($this->fileStorage->getFileHandler()->getFileTime());
        } catch (FileException $e) {
            return true;
        }
    }

    /**
     * Cargar el archivo de configuración
     *
     * @return ConfigData
     * @throws FileException
     */
    public function loadConfigFromFile()
    {
        $configData = new ConfigData();

        // Mapear el array de elementos de configuración con las propiedades de la clase configData
        $items = $this->fileStorage->load('config')->getItems();
        $reflectionObject = new ReflectionObject($configData);

        foreach ($reflectionObject->getProperties() as $property) {
            $property->setAccessible(true);

            if (isset($items[$property->getName()])) {
                $property->setValue($configData, $items[$property->getName()]);
            }

            $property->setAccessible(false);
        }

        return $configData;
    }

    /**
     * Guardar la configuración
     *
     * @param ConfigData $configData
     * @param bool       $backup
     *
     * @return Config
     * @throws FileException
     */
    public function saveConfig(ConfigData $configData, $backup = true)
    {
        if ($backup) {
            $this->dic->get(ConfigBackupService::class)
                ->backup($configData);
        }

        $configData->setConfigDate(time());
        $configData->setConfigSaver($this->context->getUserData()->getLogin() ?: 'sysPass');
        $configData->setConfigHash();

        $this->fileStorage->save($configData, 'config');
        $this->fileCache->save($configData);

        $this->configData = $configData;

        return $this;
    }

    /**
     * @return int
     */
    public static function getTimeUpdated()
    {
        return self::$timeUpdated;
    }

    /**
     * Commits a config data
     *
     * @param ConfigData $configData
     *
     * @return Config
     */
    public function updateConfig(ConfigData $configData)
    {
        $configData->setConfigDate(time());
        $configData->setConfigSaver($this->context->getUserData()->getLogin());
        $configData->setConfigHash();

        $this->configData = $configData;

        self::$timeUpdated = $configData->getConfigDate();

        return $this;
    }

    /**
     * Cargar la configuración desde el contexto
     *
     * @param bool $reload
     *
     * @return ConfigData
     */
    public function loadConfig($reload = false)
    {
        try {
            $configData = $this->fileCache->load();

            if ($reload === true
                || $configData === null
                || $this->isCacheExpired()
            ) {
                $this->configData = $this->loadConfigFromFile();
                $this->fileCache->save($this->configData);

                return $this->configData;
            }

            return $configData;
        } catch (FileException $e) {
            processException($e);
        }

        return $this->configData;
    }

    /**
     * @return ConfigData
     */
    public function getConfigData()
    {
        return clone $this->configData;
    }

    /**
     * @return Config
     * @throws FileException
     * @throws EnvironmentIsBrokenException
     */
    public function generateUpgradeKey()
    {
        if (empty($this->configData->getUpgradeKey())) {
            logger('Generating upgrade key');

            return $this->saveConfig($this->configData->setUpgradeKey(PasswordUtil::generateRandomBytes(16)), false);
        }

        return $this;
    }
}
