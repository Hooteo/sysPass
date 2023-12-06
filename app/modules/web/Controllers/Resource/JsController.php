<?php
/*
 * sysPass
 *
 * @author nuxsmin
 * @link https://syspass.org
 * @copyright 2012-2023, Rubén Domínguez nuxsmin@$syspass.org
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

namespace SP\Modules\Web\Controllers\Resource;

use SP\Infrastructure\File\FileHandler;
use SP\Util\FileUtil;

/**
 * Class JsController
 */
final class JsController extends ResourceBase
{

    private const JS_MIN_FILES     = [
        'jquery-3.3.1.min.js',
        'jquery.fileDownload.min.js',
        'clipboard.min.js',
        'selectize.min.js',
        'selectize-plugins.min.js',
        'zxcvbn-async.min.js',
        'jsencrypt.min.js',
        'spark-md5.min.js',
        'moment.min.js',
        'moment-timezone.min.js',
        'toastr.min.js',
        'jquery.magnific-popup.min.js',
        'eventsource.min.js',
    ];
    private const JS_APP_MIN_FILES = [
        'app.min.js',
        'app-config.min.js',
        'app-triggers.min.js',
        'app-actions.min.js',
        'app-requests.min.js',
        'app-util.min.js',
        'app-main.min.js',
    ];

    /**
     * Return JS resources
     */
    public function jsAction(): void
    {
        $file = $this->request->analyzeString('f');
        $base = $this->request->analyzeString('b');

        if ($file && $base) {
            $files = $this->buildFiles(urldecode($base), explode(',', urldecode($file)));

            $this->minify->builder(true)
                         ->addFiles($files)
                         ->getMinified();
        } else {
            $group = $this->request->analyzeInt('g', 0);

            if ($group === 0) {
                $this->minify
                    ->builder()
                    ->addFiles(
                        $this->buildFiles(FileUtil::buildPath(PUBLIC_PATH, 'vendor', 'js'), self::JS_MIN_FILES),
                        false
                    )
                    ->getMinified();
            } elseif ($group === 1) {
                $this->minify
                    ->builder()
                    ->addFiles($this->buildFiles(FileUtil::buildPath(PUBLIC_PATH, 'js'), self::JS_APP_MIN_FILES), false)
                    ->getMinified();
            }
        }
    }

    /**
     * @param string $base
     * @param array $files
     * @return FileHandler[]
     */
    private function buildFiles(string $base, array $files): array
    {
        return array_map(
            fn(string $file) => new FileHandler(FileUtil::buildPath($base, $file)),
            $files
        );
    }
}
