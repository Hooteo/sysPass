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

namespace SP\Core\Acl;

use SP\Domain\Core\Acl\ActionNotFoundException;
use SP\Domain\Core\Acl\ActionsInterface;
use SP\Domain\Core\Models\Action;
use SP\Domain\Storage\Ports\FileCacheService;
use SP\Domain\Storage\Ports\XmlFileStorageService;
use SP\Infrastructure\File\FileException;

use function SP\__u;
use function SP\logger;
use function SP\processException;

/**
 * Class Actions
 */
class Actions implements ActionsInterface
{
    /**
     * Cache file name
     */
    public const ACTIONS_CACHE_FILE = CACHE_PATH . DIRECTORY_SEPARATOR . 'actions.cache';
    /**
     * Cache expire time
     */
    public const CACHE_EXPIRE = 86400;
    /**
     * @var  Action[]|null
     */
    protected ?array $actions = null;

    /**
     * Action constructor.
     *
     * @param FileCacheService $fileCache
     * @param XmlFileStorageService $xmlFileStorage
     *
     * @throws FileException
     */
    public function __construct(
        private readonly FileCacheService      $fileCache,
        private readonly XmlFileStorageService $xmlFileStorage
    ) {
        $this->loadCache();
    }

    /**
     * Loads actions from cache file
     *
     * @throws FileException
     */
    protected function loadCache(): void
    {
        try {
            if ($this->fileCache->isExpired(self::CACHE_EXPIRE)
                || $this->fileCache->isExpiredDate($this->xmlFileStorage->getFileTime())
            ) {
                $this->mapAndSave();
            } else {
                $this->actions = $this->fileCache->load();

                logger('Loaded actions cache', 'INFO');
            }
        } catch (FileException $e) {
            processException($e);

            $this->mapAndSave();
        }
    }

    /**
     * @throws FileException
     */
    protected function mapAndSave(): void
    {
        logger('ACTION CACHE MISS', 'INFO');

        $this->map();
        $this->saveCache();
    }

    /**
     * Sets an array of actions using id as key
     *
     * @throws FileException
     */
    protected function map(): void
    {
        $this->actions = [];

        foreach ($this->load() as $a) {
            $this->actions[$a['id']] = new Action($a['id'], $a['name'], $a['text'], $a['route']);
        }
    }

    /**
     * Loads actions from DB
     *
     * @return Action[]
     * @throws FileException
     */
    protected function load(): array
    {
        return $this->xmlFileStorage->load('actions');
    }

    /**
     * Saves actions into cache file
     */
    protected function saveCache(): void
    {
        try {
            $this->fileCache->save($this->actions);

            logger('Saved actions cache', 'INFO');
        } catch (FileException $e) {
            processException($e);
        }
    }

    /**
     * Returns an action by id
     *
     * @throws ActionNotFoundException
     */
    public function getActionById(int $id): Action
    {
        if (!isset($this->actions[$id])) {
            throw new ActionNotFoundException(__u('Action not found'));
        }

        return $this->actions[$id];
    }

    /**
     * @throws FileException
     */
    public function reset(): void
    {
        @unlink(self::ACTIONS_CACHE_FILE);

        $this->loadCache();
    }
}
