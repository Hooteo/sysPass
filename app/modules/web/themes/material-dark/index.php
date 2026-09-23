<?php
/**
 * sysPass fork
 *
 * @author    Infonet Solutions
 * @copyright 2026, Infonet Solutions
 *
 * New theme added in this fork, part of a modified version of sysPass.
 *
 * Material Dark: a dark-mode companion to the stock Material Blue theme.
 * Reuses Material Blue's views/inc unchanged (symlinked, not copied, so
 * template changes to Material Blue apply here automatically - these are
 * plain PHP includes, not subject to the check below).
 *
 * css/ and js/ are real copies, not symlinks: ResourceController's public
 * CSS/JS bundler (lib/SP/Html/Minify.php) resolves each requested file
 * with realpath() and requires it to stay under the requesting theme's
 * own directory (SP\Http\Request::getSecureAppPath()) - a symlinked
 * vendor file resolves outside material-dark/, fails that check, and is
 * silently served empty. Keep any future Material Blue vendor CSS/JS
 * update copied into both theme directories. dark-theme.min.css is this
 * theme's only real addition - it loads after everything else so plain
 * cascade order is enough to win. It is named *.min.css so sysPass's own
 * Minify class treats it as pre-minified: CSS minification, unlike JS,
 * isn't actually implemented there - a non-".min.css" name gets flagged
 * for minifying and its content silently dropped instead.
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

return [
    'name' => 'Material Dark',
    'creator' => 'Infonet Solutions',
    'version' => '1.0',
    'targetversion' => '3.0.0',
    'js' => [
        'bootstrap-material-datetimepicker.min.js',
        'material.min.js',
        'mdl-jquery-modal-dialog.min.js',
        'app-theme.min.js'
    ],
    'css' => [
        'fonts.min.css',
        'material.min.css',
        'material-custom.min.css',
        'mdl-datetimepicker.min.css',
        'mdl-jquery-modal-dialog.min.css',
        'selectize-custom.min.css',
        'toastr.min.css',
        'styles.min.css',
        'dark-theme.min.css'
    ]
];
