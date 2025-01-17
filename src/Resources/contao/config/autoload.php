<?php

/*
 * This file is part of the Contao Calendar iCal Bundle.
 *
 * (c) Helmut Schottmüller 2009-2013 <https://github.com/hschottm>
 * (c) Daniel Kiesel 2017 <https://github.com/iCodr8>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Register the classes
 */
ClassLoader::addClasses(array
                        (
                            // Classes
                            'Contao\CalendarExport' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/classes/CalendarExport.php',
                            'Contao\CalendarImport' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/classes/CalendarImport.php',
                            'Contao\ContentICal' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/classes/ContentICal.php',
                            'Contao\Csv' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/classes/Csv.php',
                            'Contao\CsvParser' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/classes/CsvParser.php',
                            'Contao\CsvReader' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/classes/CsvReader.php',
                        ));

/**
 * Register the templates
 */
TemplateLoader::addFiles(array
                         (
                             'be_import_calendar' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/templates',
                             'be_import_calendar_confirmation' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/templates',
                             'be_import_calendar_csv_headers' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/templates',
                             'ce_ical' => 'vendor/cgoit/contao-calendar-ical-php8-bundle/src/Resources/contao/templates',
                         ));
