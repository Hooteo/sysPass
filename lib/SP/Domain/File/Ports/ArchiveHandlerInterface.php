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

namespace SP\Domain\File\Ports;

use SP\Infrastructure\File\FileException;

/**
 * Class ArchiveHandler
 */
interface ArchiveHandlerInterface
{
    /**
     * Realizar un backup de la aplicación y comprimirlo.
     *
     * @throws FileException
     */
    public function compressDirectory(string $directory, ?string $regex = null): void;

    /**
     * Realizar un backup de la aplicación y comprimirlo.
     *
     * @return string The path to the file
     * @throws FileException
     */
    public function compressFile(string $file): string;
}
