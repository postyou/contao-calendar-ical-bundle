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

namespace Contao;

use Exception;
use Kigkonsult\Icalcreator\IcalInterface;
use Kigkonsult\Icalcreator\Pc;
use Kigkonsult\Icalcreator\Util\DateTimeFactory;
use Kigkonsult\Icalcreator\Util\DateTimeZoneFactory;
use Kigkonsult\Icalcreator\Util\UtilDateTime;
use Kigkonsult\Icalcreator\Util\RecurFactory;
use Kigkonsult\Icalcreator\Util\RecurFactory2;
use Kigkonsult\Icalcreator\Vcalendar;
use Kigkonsult\Icalcreator\Vevent;

/**
 * Class CalendarImport
 *
 * @package Contao
 * @property CalendarImport $CalendarImport
 */
class CalendarImport extends \Backend {
    protected $blnSave = true;
    /** @var Vcalendar */
    protected $cal;

    /** @var string */
    protected $filterEventTitle = '';

    /** @var string */
    protected $patternEventTitle = '';

    /** @var string */
    protected $replacementEventTitle = '';

    public function getAllEvents($arrEvents, $arrCalendars, $intStart, $intEnd) {
        $arrCalendars = $this->Database->prepare("SELECT id FROM tl_calendar WHERE id IN (" .
                                                 join(',', $arrCalendars) . ") AND ical_source = ?")
                                       ->execute('1')
                                       ->fetchAllAssoc();

        foreach ($arrCalendars as $calendar) {
            $this->importCalendarWithID($calendar['id']);
        }

        return $arrEvents;
    }

    public function importFromURL(DataContainer $dc) {
        $this->importCalendarWithID($dc->id);
    }

    public function importAllCalendarsWithICalSource() {
        $arrCalendars = $this->Database->prepare("SELECT * FROM tl_calendar")
                                       ->executeUncached()
                                       ->fetchAllAssoc();

        if (is_array($arrCalendars)) {
            foreach ($arrCalendars as $arrCalendar) {
                $this->importCalendarWithData($arrCalendar, true);
            }
        }
    }

    protected function importCalendarWithID($id) {
        $arrCalendar = $this->Database->prepare("SELECT * FROM tl_calendar WHERE id = ?")
                                      ->executeUncached($id)
                                      ->fetchAssoc();
        $this->importCalendarWithData($arrCalendar);
    }

    protected function importCalendarWithData($arrCalendar, $force_import = false) {
        if ($arrCalendar['ical_source']) {
            $arrLastchange = $this->Database->prepare("SELECT MAX(tstamp) lastchange FROM tl_calendar_events WHERE pid = ?")
                                            ->executeUncached($arrCalendar['id'])
                                            ->fetchAssoc();
            $last_change = $arrLastchange['lastchange'];

            if ($last_change == 0) {
                $last_change = $arrCalendar['tstamp'];
            }

            if (((time() - $last_change > $arrCalendar['ical_cache']) && ($arrCalendar['ical_importing'] != 1 || (time() - $arrCalendar['tstamp']) > 120)) || $force_import) {
                $this->Database->prepare("UPDATE tl_calendar SET tstamp = ?, ical_importing = ? WHERE id = ?")
                               ->execute(time(), '1', $arrCalendar['id']);
                \System::log('reading cal', __METHOD__, TL_GENERAL);
                // create new from ical file
                \System::log(
                    'Reload iCal Web Calendar ' . $arrCalendar['title'] . ' (' . $arrCalendar['id'] . ')' . ': Triggered by ' . time() . " - " . $last_change . " = " . (time() - $arrLastchange['lastchange']) . " > " . $arrCalendar['ical_cache'],
                    __METHOD__,
                    TL_GENERAL
                );
                $this->import('CalendarImport');
                $startDate = (strlen($arrCalendar['ical_source_start'])) ? new Date($arrCalendar['ical_source_start'],
                                                                                    $GLOBALS['TL_CONFIG']['dateFormat']) : new Date(time(),
                                                                                                                                    $GLOBALS['TL_CONFIG']['dateFormat']);
                $endDate = (strlen($arrCalendar['ical_source_end'])) ? new Date($arrCalendar['ical_source_end'],
                                                                                $GLOBALS['TL_CONFIG']['dateFormat']) : new Date(time() + $GLOBALS['calendar_ical']['endDateTimeDifferenceInDays'] * 24 * 3600,
                                                                                                                                $GLOBALS['TL_CONFIG']['dateFormat']);
                $tz = array($arrCalendar['ical_timezone'], $arrCalendar['ical_timezone']);
                $this->CalendarImport->filterEventTitle = $arrCalendar['ical_filter_event_title'];
                $this->CalendarImport->patternEventTitle = $arrCalendar['ical_pattern_event_title'];
                $this->CalendarImport->replacementEventTitle = $arrCalendar['ical_replacement_event_title'];
                $this->CalendarImport->importFromWebICS($arrCalendar['id'], $arrCalendar['ical_url'], $startDate,
                                                        $endDate, $tz, $arrCalendar['ical_proxy'], $arrCalendar['ical_bnpw'],
                                                        $arrCalendar['ical_port']);
                $this->Database->prepare("UPDATE tl_calendar SET tstamp = ?, ical_importing = ? WHERE id = ?")
                               ->execute(time(), '', $arrCalendar['id']);
            }
        }
    }

    public function importFromWebICS($pid, $url, $startDate, $endDate, $timezone, $proxy, $benutzerpw, $port) {
        $this->cal = new Vcalendar();
        $this->cal->setMethod(Vcalendar::PUBLISH);
        $this->cal->setXprop(Vcalendar::X_WR_CALNAME, $this->strTitle);
        $this->cal->setXprop(Vcalendar::X_WR_CALDESC, $this->strTitle);

        /* start parse of local file */
        $file = $this->downloadURLToTempFile($url, $proxy, $benutzerpw, $port);
        if ($file === null) {
            return;
        }
        try {
            $this->cal->parse($file->getContent());
        } catch (Exception $e) {
            \System::log($e->getMessage(), __METHOD__, TL_ERROR);
            return;
        }
        $tz = $this->cal->getProperty(Vcalendar::X_WR_TIMEZONE);

        if (!is_array($tz) || strlen($tz[1]) == 0) {
            $tz = $timezone;
        }

        $this->importFromICS($pid, $startDate, $endDate, true, $tz, true);
    }

    /**
     * @return File|null
     */
    protected function downloadURLToTempFile($url, $proxy, $benutzerpw, $port) {
        $url = html_entity_decode($url);

        if ($this->isCurlInstalled()) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            if (!empty($proxy)) {

                curl_setopt($ch, CURLOPT_PROXY, "$proxy");
                if (!empty($benutzerpw)) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, "$benutzerpw");
                }
                curl_setopt($ch, CURLOPT_PROXYPORT, "$port");
            }

            if (preg_match("/^https/", $url)) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }

            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            $content = curl_exec($ch);
            if ($content === false) {
                $content = null;
            } else {
                $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($responseCode >= 400) {
                    $content = null;
                }
            }
            curl_close($ch);
        } else {
            $content = file_get_contents($url);
        }

        if (empty($content)) {
            return null;
        }

        $filename = md5(time());
        $objFile = new \Contao\File('system/tmp/' . $filename);
        $objFile->write($content);
        $objFile->close();

        return $objFile;
    }

    private function isCurlInstalled() {
        return (in_array('curl', get_loaded_extensions()));
    }

    protected function importFromCSVFile($prepare = true) {
        $this->loadDataContainer('tl_calendar_events');
        $dbfields = $this->Database->listFields('tl_calendar_events');
        $fieldnames = array();

        foreach ($dbfields as $dbdata) {
            array_push($fieldnames, $dbdata['name']);
        }

        $calfields = array
        (
            array('title', $GLOBALS['TL_LANG']['tl_calendar_events']['title'][0]),
            array('startTime', $GLOBALS['TL_LANG']['tl_calendar_events']['startTime'][0]),
            array('endTime', $GLOBALS['TL_LANG']['tl_calendar_events']['endTime'][0]),
            array('startDate', $GLOBALS['TL_LANG']['tl_calendar_events']['startDate'][0]),
            array('endDate', $GLOBALS['TL_LANG']['tl_calendar_events']['endDate'][0]),
            array('details', $GLOBALS['TL_LANG']['tl_calendar_events']['details'][0]),
            array('teaser', $GLOBALS['TL_LANG']['tl_calendar_events']['teaser'][0]),
        );

        if (in_array('location', $fieldnames, true)) {
            $calfields[] = array('location', $GLOBALS['TL_LANG']['tl_calendar_events']['location'][0]);
        }
        if (in_array('cep_participants', $fieldnames, true)) {
            $calfields[] = array('cep_participants', $GLOBALS['TL_LANG']['tl_calendar_events']['cep_participants'][0]);
        }
        if (in_array('location_contact', $fieldnames, true)) {
            $calfields[] = array('location_contact', $GLOBALS['TL_LANG']['tl_calendar_events']['location_contact'][0]);
        }

        $dateFormat = \Input::post('dateFormat');
        $timeFormat = \Input::post('timeFormat');
        $fields = array();
        $csvvalues = \Input::post('csvfield');
        $calvalues = \Input::post('calfield');
        $encoding = \Input::post('encoding');

        if (!is_array($csvvalues)) {
            $sessiondata = deserialize($GLOBALS['TL_CONFIG']['calendar_ical']['csvimport'], true);
            if (is_array($sessiondata) && count($sessiondata) == 5) {
                $csvvalues = $sessiondata[0];
                $calvalues = $sessiondata[1];
                $dateFormat = $sessiondata[2];
                $timeFormat = $sessiondata[3];
                $encoding = $sessiondata[4];
            }
        }

        $data = TL_ROOT . '/' . $this->Session->get('csv_filename');
        $parser = new CsvParser($data, (strlen($encoding) > 0) ? $encoding : 'utf8');
        $header = $parser->extractHeader();

        for ($i = 0; $i < count($header); $i++) {
            $objCSV = $this->getFieldSelector($i, 'csvfield', $header,
                                              (is_array($csvvalues)) ? $csvvalues[$i] : $header[$i]);
            $objCal = $this->getFieldSelector($i, 'calfield', $calfields, $calvalues[$i]);
            array_push($fields, array($objCSV, $objCal));
        }

        if ($prepare) {
            $count = 5;
            $preview = $parser->getDataArray(5);
            $this->Template = new \BackendTemplate('be_import_calendar_csv_headers');
            $this->Template->lngFields = $GLOBALS['TL_LANG']['tl_calendar_events']['fields'];
            $this->Template->lngPreview = $GLOBALS['TL_LANG']['tl_calendar_events']['preview'];
            $this->Template->check = $GLOBALS['TL_LANG']['tl_calendar_events']['check'];
            $this->Template->header = $header;

            if (count($preview)) {
                foreach ($preview as $idx => $line) {
                    if (is_array($line)) {
                        foreach ($line as $key => $value) {
                            $preview[$idx][$key] = specialchars($value);
                        }
                    }
                }
            }

            $this->Template->preview = $preview;
            $this->Template->encoding = $this->getEncodingWidget($encoding);

            if (function_exists('date_parse_from_format')) {
                $this->Template->dateFormat = $this->getDateFormatWidget($dateFormat);
                $this->Template->timeFormat = $this->getTimeFormatWidget($timeFormat);
            }

            $this->Template->hrefBack = ampersand(str_replace('&key=import', '', \Environment::get('request')));
            $this->Template->goBack = $GLOBALS['TL_LANG']['MSC']['goBack'];
            $this->Template->headline = $GLOBALS['TL_LANG']['MSC']['import_calendar'][0];
            $this->Template->request = ampersand(\Environment::get('request'), 'ENCODE_AMPERSANDS');
            $this->Template->submit = specialchars($GLOBALS['TL_LANG']['tl_calendar_events']['proceed'][0]);
            $this->Template->fields = $fields;

            return $this->Template->parse();
        } else {
            // save config
            $this->Config->update("\$GLOBALS['TL_CONFIG']['calendar_ical']['csvimport']", serialize(array(
                                                                                                        $csvvalues,
                                                                                                        $calvalues,
                                                                                                        \Input::post('dateFormat'),
                                                                                                        \Input::post('timeFormat'),
                                                                                                        \Input::post('encoding'),
                                                                                                    )));

            if ($this->Session->get('csv_deletecalendar') && $this->Session->get('csv_pid')) {
                $event = \CalendarEventsModel::findByPid($this->Session->get('csv_pid'));
                if ($event) {
                    while ($event->next()) {
                        $arrColumns = array("ptable=? AND pid=?");
                        $arrValues = array('tl_calendar_events', $event->id);
                        $content = \ContentModel::findBy($arrColumns, $arrValues);
                        if ($content) {
                            while ($content->next()) {
                                $content->delete();
                            }
                        }
                        $event->delete();
                    }
                }
            }

            $this->import('BackendUser', 'User');
            $done = false;

            while (!$done) {
                $data = $parser->getDataArray();

                if ($data !== false) {
                    $eventcontent = array();
                    $arrFields = array();
                    $arrFields['tstamp'] = time();
                    $arrFields['pid'] = $this->Session->get('csv_pid');
                    $arrFields['published'] = 1;
                    $arrFields['author'] = ($this->User->id) ? $this->User->id : 0;

                    foreach ($calvalues as $idx => $value) {
                        if (strlen($value)) {
                            $indexfield = $csvvalues[$idx];
                            $foundindex = array_search($indexfield, $header);

                            if ($foundindex !== false) {
                                switch ($value) {
                                    case 'startDate':
                                        if (function_exists('date_parse_from_format')) {
                                            $res = date_parse_from_format(\Input::post('dateFormat'), $data[$foundindex]);

                                            if ($res !== false) {
                                                $arrFields[$value] = mktime($res['hour'], $res['minute'],
                                                                            $res['second'], $res['month'], $res['day'],
                                                                            $res['year']);
                                            }
                                        } else {
                                            $arrFields[$value] = $this->getTimestampFromDefaultDatetime($data[$foundindex]);
                                        }

                                        $arrFields['startTime'] = $arrFields[$value];

                                        if (!array_key_exists('endDate', $arrFields)) {
                                            $arrFields['endDate'] = $arrFields[$value];
                                            $arrFields['endTime'] = $arrFields[$value];
                                        }
                                        break;
                                    case 'endDate':
                                        if (function_exists('date_parse_from_format')) {
                                            $res = date_parse_from_format(\Input::post('dateFormat'), $data[$foundindex]);

                                            if ($res !== false) {
                                                $arrFields[$value] = mktime($res['hour'], $res['minute'],
                                                                            $res['second'], $res['month'], $res['day'],
                                                                            $res['year']);
                                            }
                                        } else {
                                            $arrFields[$value] = $this->getTimestampFromDefaultDatetime($data[$foundindex]);
                                        }

                                        $arrFields['endTime'] = $arrFields['endDate'];
                                        break;
                                    case 'details':
                                        array_push($eventcontent, specialchars($data[$foundindex]));
                                        break;
                                    default:
                                        if (strlen($data[$foundindex])) {
                                            $arrFields[$value] = specialchars($data[$foundindex]);
                                        }
                                        break;
                                }

                                if ($value === 'title') {
                                    $this->filterEventTitle = $this->Session->get('csv_filterEventTitle');
                                    if (!empty($this->filterEventTitle) && strpos(specialchars($data[$foundindex]),
                                                                                  $this->filterEventTitle) === false) {
                                        continue;
                                    }
                                }
                            }
                        }
                    }

                    foreach ($calvalues as $idx => $value) {
                        if (strlen($value)) {
                            $indexfield = $csvvalues[$idx];
                            $foundindex = array_search($indexfield, $header);

                            if ($foundindex !== false) {
                                switch ($value) {
                                    case 'startTime':
                                        if (function_exists('date_parse_from_format')) {
                                            $res = date_parse_from_format(\Input::post('timeFormat'), $data[$foundindex]);

                                            if ($res !== false) {
                                                $arrFields[$value] = $arrFields['startDate'] + $res['hour'] * 60 * 60 + $res['minute'] * 60 + $res['second'];
                                                $arrFields['endTime'] = $arrFields[$value];
                                            }
                                        } else {
                                            if (preg_match("/(\\d+):(\\d+)/", $data[$foundindex], $matches)) {
                                                $arrFields[$value] = $arrFields['startDate'] + (int)$matches[1] * 60 * 60 + (int)$matches[2] * 60;
                                            }
                                        }
                                        break;
                                    case 'endTime':
                                        if (function_exists('date_parse_from_format')) {
                                            $res = date_parse_from_format(\Input::post('timeFormat'), $data[$foundindex]);

                                            if ($res !== false) {
                                                $arrFields[$value] = $arrFields['endDate'] + $res['hour'] * 60 * 60 + $res['minute'] * 60 + $res['second'];
                                            }
                                        } else {
                                            if (preg_match("/(\\d+):(\\d+)/", $data[$foundindex], $matches)) {
                                                $arrFields[$value] = $arrFields['startDate'] + (int)$matches[1] * 60 * 60 + (int)$matches[2] * 60;
                                            }
                                        }
                                        break;
                                }
                            }
                        }
                    }

                    if (!array_key_exists('startDate', $arrFields)) {
                        $arrFields['startDate'] = time();
                        $arrFields['startTime'] = time();
                    }

                    if (!array_key_exists('endDate', $arrFields)) {
                        $arrFields['endDate'] = time();
                        $arrFields['endTime'] = time();
                    }

                    if ($arrFields['startDate'] != $arrFields['startTime']) {
                        $arrFields['addTime'] = 1;
                    }

                    if ($arrFields['endDate'] != $arrFields['endTime']) {
                        $arrFields['addTime'] = 1;
                    }

                    if (!array_key_exists('title', $arrFields)) {
                        $arrFields['title'] = $GLOBALS['TL_LANG']['tl_calendar_events']['untitled'];
                    }

                    $timeshift = $this->Session->get('csv_timeshift');

                    if ($timeshift != 0) {
                        $arrFields['startDate'] += $timeshift * 3600;
                        $arrFields['endDate'] += $timeshift * 3600;
                        $arrFields['startTime'] += $timeshift * 3600;
                        $arrFields['endTime'] += $timeshift * 3600;
                    }

                    $startDate = new Date($this->Session->get('csv_startdate'), $GLOBALS['TL_CONFIG']['dateFormat']);
                    $endDate = new Date($this->Session->get('csv_enddate'), $GLOBALS['TL_CONFIG']['dateFormat']);

                    if (!array_key_exists('source', $arrFields)) {
                        $arrFields['source'] = 'default';
                    }

                    if ($arrFields['endDate'] < $startDate->tstamp || (strlen($this->Session->get('csv_enddate')) && ($arrFields['startDate'] > $endDate->tstamp))) {
                        // date is not in range
                    } else {
                        $objInsertStmt = $this->Database->prepare("INSERT INTO tl_calendar_events %s")
                                                        ->set($arrFields)
                                                        ->execute();

                        if ($objInsertStmt->affectedRows) {
                            $insertID = $objInsertStmt->insertId;

                            if (count($eventcontent)) {
                                $step = 128;

                                foreach ($eventcontent as $content) {
                                    $cm = new \ContentModel();
                                    $cm->tstamp = time();
                                    $cm->pid = $insertID;
                                    $cm->ptable = 'tl_calendar_events';
                                    $cm->sorting = $step;
                                    $step = $step * 2;
                                    $cm->type = 'text';
                                    $cm->text = $content;
                                    $cm->save();
                                }
                            }

                            $alias = $this->generateAlias($arrFields['title'], $insertID, $this->Session->get('csv_pid'));
                            $this->Database->prepare("UPDATE tl_calendar_events SET alias = ? WHERE id = ?")
                                           ->execute($alias, $insertID);
                        }
                    }
                } else {
                    $done = true;
                }
            }

            $this->redirect(str_replace('&key=import', '', \Environment::get('request')));
        }
    }

    protected function getTimestampFromDefaultDatetime($datestring) {
        $tstamp = time();

        if (preg_match("/(\\d{4})-(\\d{2})-(\\d{2})\\s+(\\d{2}):(\\d{2}):(\\d{2})/", $datestring, $matches)) {
            $tstamp = mktime((int)$matches[4], (int)$matches[5], (int)$matches[6], (int)$matches[2], (int)$matches[3],
                             (int)$matches[1]);
        } else {
            if (preg_match("/(\\d{4})-(\\d{2})-(\\d{2})\\s+(\\d{2}):(\\d{2})/", $datestring, $matches)) {
                $tstamp = mktime((int)$matches[4], (int)$matches[5], 0, (int)$matches[2], (int)$matches[3],
                                 (int)$matches[1]);
            } else {
                if (preg_match("/(\\d{4})-(\\d{2})-(\\d{2})/", $datestring, $matches)) {
                    $tstamp = mktime(0, 0, 0, (int)$matches[2], (int)$matches[3], (int)$matches[1]);
                } else {
                    if (strtotime($datestring) !== false) {
                        $tstamp = strtotime($datestring);
                    }
                }
            }
        }

        return $tstamp;
    }

    protected function getDateFormatWidget($value = null) {
        $widget = new TextField();

        $widget->id = 'dateFormat';
        $widget->name = 'dateFormat';
        $widget->mandatory = true;
        $widget->required = true;
        $widget->maxlength = 20;
        $widget->value = (strlen($value)) ? $value : $GLOBALS['TL_LANG']['tl_calendar_events']['dateFormat'];

        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['importDateFormat'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['importDateFormat'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['importDateFormat'][1];
        }

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_csv_headers') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    protected function getTimeFormatWidget($value = null) {
        $widget = new TextField();

        $widget->id = 'timeFormat';
        $widget->name = 'timeFormat';
        $widget->mandatory = true;
        $widget->required = true;
        $widget->maxlength = 20;
        $widget->value = (strlen($value)) ? $value : $GLOBALS['TL_LANG']['tl_calendar_events']['timeFormat'];

        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['importTimeFormat'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['importTimeFormat'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['importTimeFormat'][1];
        }

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_csv_headers') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    protected function getEncodingWidget($value = null) {
        $widget = new SelectMenu();

        $widget->id = 'encoding';
        $widget->name = 'encoding';
        $widget->mandatory = true;
        $widget->value = $value;
        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['encoding'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['encoding'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['encoding'][1];
        }

        $arrOptions = array(
            array('value' => 'utf8', 'label' => 'UTF-8'),
            array('value' => 'latin1', 'label' => 'ISO-8859-1 (Windows)'),
        );
        $widget->options = $arrOptions;

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_csv_headers') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    protected function getFieldSelector($index, $name, $fieldvalues, $value = null) {
        $widget = new SelectMenu();

        $widget->id = $name . '[' . $index . ']';
        $widget->name = $name . '[' . $index . ']';
        $widget->mandatory = false;
        $widget->value = $value;
        $widget->label = 'csvfield';

        $arrOptions = array();

        $arrOptions[] = array('value' => '', 'label' => '-');

        foreach ($fieldvalues as $fieldvalue) {
            if (is_array($fieldvalue)) {
                $arrOptions[] = array('value' => $fieldvalue[0], 'label' => $fieldvalue[1]);
            } else {
                $arrOptions[] = array('value' => $fieldvalue, 'label' => $fieldvalue);
            }
        }

        $widget->options = $arrOptions;

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_csv_headers') {
            $widget->validate();

            if ($widget->hasErrors()) {
                echo "field";
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    protected function importFromICSFile(
        $filename,
        DataContainer $dc,
        $startDate,
        $endDate,
        $correctTimezone = null,
        $manualTZ = null,
        $deleteCalendar = false,
        $timeshift = 0
    ) {
        $pid = $dc->id;
        $this->cal = new Vcalendar();
        $this->cal->setMethod(Vcalendar::PUBLISH);
        $this->cal->setXprop(Vcalendar::X_WR_CALNAME, $this->strTitle);
        $this->cal->setXprop(Vcalendar::X_WR_CALDESC, $this->strTitle);

        try {
            $file = new \Contao\File($filename);
            $content = $file->exists() ? $file->getContent() : '';
            if (empty($content)) {
                throw new \InvalidArgumentException('Ical content empty');
            }
            $this->cal->parse($content);
        } catch (Exception $e) {
            \Message::addError($e->getMessage());
            $this->redirect(str_replace('&key=import', '', \Environment::get('request')));
        }
        $tz = $this->cal->getProperty(Vcalendar::X_WR_TIMEZONE);

        if ($timeshift == 0) {
            if (is_array($tz) && strlen($tz[1]) && strcmp($tz[1], $GLOBALS['TL_CONFIG']['timeZone']) != 0) {
                if ($correctTimezone === null) {
                    return $this->getConfirmationForm($dc, $filename, $startDate->date, $endDate->date, $tz[1],
                                                      $GLOBALS['TL_CONFIG']['timeZone'], $deleteCalendar);
                }
            } else {
                if (!is_array($tz) || strlen($tz[1]) == 0) {
                    if ($manualTZ === null) {
                        return $this->getConfirmationForm($dc, $filename, $startDate->date, $endDate->date, $tz[1],
                                                          $GLOBALS['TL_CONFIG']['timeZone'], $deleteCalendar);
                    }
                }
            }
            if (strlen($manualTZ)) {
                $tz[1] = $manualTZ;
            }
        }
        $this->importFromICS($pid, $startDate, $endDate, $correctTimezone, $tz, $deleteCalendar, $timeshift);
        $this->redirect(str_replace('&key=import', '', \Environment::get('request')));
    }

    protected function importFromICS(
        $pid,
        $startDate,
        $endDate,
        $correctTimezone = null,
        $tz,
        $deleteCalendar = false,
        $timeshift = 0
    ) {
        $this->cal->sort();
        $this->loadDataContainer('tl_calendar_events');
        $fields = $this->Database->listFields('tl_calendar_events');
        $fieldNames = array();
        $arrFields = array();
        $defaultFields = array();
        foreach ($fields as $fieldarr) {
            if (strcmp($fieldarr['name'], 'id') != 0 && strcmp($fieldarr['type'], 'index') != 0) {
                $fieldNames[] = $fieldarr['name'];
            }
        }

        // Get all default values for new entries
        foreach ($GLOBALS['TL_DCA']['tl_calendar_events']['fields'] as $k => $v) {
            if (isset($v['default'])) {
                $defaultFields[$k] = is_array($v['default']) ? serialize($v['default']) : $v['default'];
            }
        }

        $this->import('BackendUser', 'User');
        $foundevents = array();

        if ($deleteCalendar && $pid) {
            $event = \CalendarEventsModel::findByPid($pid);
            if ($event) {
                while ($event->next()) {
                    $arrColumns = array("ptable=? AND pid=?");
                    $arrValues = array('tl_calendar_events', $event->id);
                    $content = \ContentModel::findBy($arrColumns, $arrValues);

                    if ($content) {
                        while ($content->next()) {
                            $content->delete();
                        }
                    }

                    $event->delete();
                }
            }
        }

        $eventArray = $this->cal->selectComponents(date('Y', $startDate->tstamp), date('m', $startDate->tstamp),
                                                   date('d', $startDate->tstamp), date('Y', $endDate->tstamp), date('m', $endDate->tstamp),
                                                   date('d', $endDate->tstamp), 'vevent', true);

        if (is_array($eventArray)) {
            foreach ($eventArray as $vevent) {
                /** @var Vevent $vevent */
                $arrFields = $defaultFields;
                $dtstart = $vevent->getDtstart();
                $dtstartRow = $vevent->getDtstart(true);
                $dtend = $vevent->getDtend();
                $dtendRow = $vevent->getDtend(true);
                $rrule = $vevent->getRrule();
                $summary = $vevent->getSummary() ?? '';
                if (!empty($this->filterEventTitle) && strpos($summary, $this->filterEventTitle) === false) {
                    continue;
                }
                $description = $vevent->getDescription() ?? '';
                $location = trim($vevent->getLocation() ?? '');
                $uid = $vevent->getUid();

                $arrFields['tstamp'] = time();
                $arrFields['pid'] = $pid;
                $arrFields['published'] = 1;
                $arrFields['author'] = ($this->User->id) ? $this->User->id : 0;

                $title = $summary;
                if (!empty($this->patternEventTitle) && !empty($this->replacementEventTitle)) {
                    $title = preg_replace($this->patternEventTitle, $this->replacementEventTitle, $title);
                }

                // set values from vevent
                $arrFields['title'] = !empty($title) ? $title : $summary;
                $cleanedup = (strlen($description)) ? $description : $summary;
                $cleanedup = preg_replace("/[\\r](\\\\)n(\\t){0,1}/ims", "", $cleanedup);
                $cleanedup = preg_replace("/[\\r\\n]/ims", "", $cleanedup);
                $cleanedup = str_replace("\\n", "<br />", $cleanedup);
                $eventcontent = array();

                if (strlen($cleanedup)) {
                    array_push($eventcontent, '<p>' . $cleanedup . '</p>');
                }

                // calendar_events_plus fields
                if (!empty($location)) {
                    if (array_key_exists('location', $fieldNames)) {
                        $location = preg_replace("/(\\\\r)|(\\\\n)/im", "\n", $location);
                        $arrFields['location'] = $location;
                    } else {
                        $location = preg_replace("/(\\\\r)|(\\\\n)/im", "<br />", $location);
                        $eventcontent[] = '<p><strong>' . $GLOBALS['TL_LANG']['MSC']['location'] . ':</strong> ' . $location . "</p>";
                    }
                }

                if (array_key_exists('cep_participants', $fieldNames) && is_array($vevent->attendee)) {
                    $attendees = array();
                    foreach ($vevent->attendee as $attendee) {
                        if (!empty($attendee['params']['CN'])) {
                            $attendees[] = $attendee['params']['CN'];
                        }
                    }

                    if (count($attendees)) {
                        $arrFields['cep_participants'] = implode(',', $attendees);
                    }
                }

                if (array_key_exists('location_contact', $fieldNames)) {
                    $contact = $vevent->getContact();
                    if (is_array($contact)) {
                        $contacts = array();
                        foreach ($contact as $data) {
                            if (!empty($data['value'])) {
                                $contacts[] = $data['value'];
                            }
                        }
                        if (count($contacts)) {
                            $arrFields['location_contact'] = implode(',', $contacts);
                        }
                    }
                }

                $arrFields['startDate'] = 0;
                $arrFields['startTime'] = 0;
                $arrFields['addTime'] = '';
                $arrFields['endDate'] = 0;
                $arrFields['endTime'] = 0;
                $timezone = $tz[1];

                if ($dtstart instanceof \DateTime) {
                    if ($dtstartRow instanceof Pc) {
                        if ($dtstartRow->hasParamKey(IcalInterface::TZID)) {
                            $timezone = $dtstartRow->getParams(IcalInterface::TZID);
                        } else {
                            if ($dtstart->getTimezone() && $dtstart->getTimezone()->getName() == $tz[1]) {
                                $timezone = $dtstart->getTimezone()->getName();
                                $dtstart = new \DateTime(
                                    $dtstart->format(DateTimeFactory::$YmdHis),
                                    $dtstart->getTimezone()
                                );
                            } else {
                                $dtstart = new \DateTime(
                                    $dtstart->format(DateTimeFactory::$YmdHis),
                                    DateTimeZoneFactory::factory($tz[1])
                                );
                            }
                        }

                        if (!$dtstartRow->hasParamValue(IcalInterface::DATE)) {
                            $arrFields['addTime'] = 1;
                        } else {
                            $arrFields['addTime'] = 0;
                        }
                    } else {
                        if ($dtstart->getTimezone() && $dtstart->getTimezone()->getName() == $tz[1]) {
                            $timezone = $dtstart->getTimezone()->getName();
                            $dtstart = new \DateTime(
                                $dtstart->format(DateTimeFactory::$YmdHis),
                                $dtstart->getTimezone()
                            );
                        } else {
                            $dtstart = new \DateTime(
                                $dtstart->format(DateTimeFactory::$YmdHis),
                                DateTimeZoneFactory::factory($tz[1])
                            );
                        }

                        if (array_key_exists('params', $dtstartRow) && array_key_exists('VALUE',
                                                                                        $dtstartRow['params']) && strcmp(strtoupper($dtstartRow['params']['VALUE']),
                                                                                                                         'DATE') == 0) {
                            $arrFields['addTime'] = 0;
                        } else {
                            $arrFields['addTime'] = 1;
                        }
                    }
                    $arrFields['startDate'] = $dtstart->getTimestamp();
                    $arrFields['startTime'] = $dtstart->getTimestamp();
                }
                if ($dtend instanceof \DateTime) {
                    if ($dtendRow instanceof Pc) {
                        if ($dtendRow->hasParamKey(IcalInterface::TZID)) {
                            $timezone = $dtendRow->getParams(IcalInterface::TZID);
                        } else {
                            if ($dtend->getTimezone() && $dtend->getTimezone()->getName() == $tz[1]) {
                                $timezone = $dtend->getTimezone()->getName();
                                $dtend = new \DateTime(
                                    $dtend->format(DateTimeFactory::$YmdHis),
                                    $dtend->getTimezone()
                                );
                            } else {
                                $dtend = new \DateTime(
                                    $dtend->format(DateTimeFactory::$YmdHis),
                                    DateTimeZoneFactory::factory($tz[1])
                                );
                            }
                        }

                        if ($arrFields['addTime'] == 1) {
                            $arrFields['endDate'] = $dtend->getTimestamp();
                            $arrFields['endTime'] = $dtend->getTimestamp();
                        } else {
                            $endDate = (clone $dtend)->modify('- 1 day')->getTimestamp();
                            $endTime = (clone $dtend)->modify('- 1 second')->getTimestamp();

                            $arrFields['endDate'] = $endDate;
                            $arrFields['endTime'] = $endTime <= $endDate ? $endTime : $endDate;
                        }
                    } else {
                        if ($dtend->getTimezone() && $dtend->getTimezone()->getName() == $tz[1]) {
                            $timezone = $dtend->getTimezone()->getName();
                            $dtend = new \DateTime(
                                $dtend->format(DateTimeFactory::$YmdHis),
                                $dtend->getTimezone()
                            );
                        } else {
                            $dtend = new \DateTime(
                                $dtend->format(DateTimeFactory::$YmdHis),
                                DateTimeZoneFactory::factory($tz[1])
                            );
                        }

                        if ($arrFields['addTime'] == 1) {
                            $arrFields['endDate'] = $dtend->getTimestamp();
                            $arrFields['endTime'] = $dtend->getTimestamp();
                        } else {
                            $endDate = (clone $dtend)->modify('- 1 day')->getTimestamp();
                            $endTime = (clone $dtend)->modify('- 1 second')->getTimestamp();

                            $arrFields['endDate'] = $endDate;
                            $arrFields['endTime'] = $endTime <= $endDate ? $endTime : $endDate;
                        }
                    }
                }

                if ($timeshift != 0) {
                    $arrFields['startDate'] += $timeshift * 3600;
                    $arrFields['endDate'] += $timeshift * 3600;
                    $arrFields['startTime'] += $timeshift * 3600;
                    $arrFields['endTime'] += $timeshift * 3600;
                }

                if (is_array($rrule)) {
                    $arrFields['recurring'] = 1;
                    $arrFields['recurrences'] = (array_key_exists('COUNT', $rrule)) ? $rrule['COUNT'] : 0;
                    $repeatEach = array();

                    switch ($rrule['FREQ']) {
                        case 'DAILY':
                            $repeatEach['unit'] = 'days';
                            break;
                        case 'WEEKLY':
                            $repeatEach['unit'] = 'weeks';
                            break;
                        case 'MONTHLY':
                            $repeatEach['unit'] = 'months';
                            break;
                        case 'YEARLY':
                            $repeatEach['unit'] = 'years';
                            break;
                    }

                    $repeatEach['value'] = $rrule['INTERVAL'] ?? 1;
                    $arrFields['repeatEach'] = serialize($repeatEach);
                    $arrFields['repeatEnd'] = $this->getRepeatEnd($arrFields, $rrule, $repeatEach, $timezone, $timeshift);

                    if (isset($rrule['WKST']) && is_array($rrule['WKST'])) {
                        $weekdays = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 0];
                        $mapWeekdays = static function (string $value) use ($weekdays): ?int {
                            return $weekdays[$value] ?? null;
                        };
                        $arrFields['repeatWeekday'] = serialize(array_map($mapWeekdays, $rrule['WKST']));
                    }
                }
                $this->handleRecurringExceptions($arrFields, $vevent, $timezone, $timeshift);

                if (!isset($foundevents[$uid])) {
                    $foundevents[$uid] = 0;
                }
                $foundevents[$uid]++;

                if ($foundevents[$uid] <= 1) {
                    if (array_key_exists('singleSRC', $arrFields) && $arrFields['singleSRC'] == "") {
                        $arrFields['singleSRC'] = null;
                    }

                    $objInsertStmt = $this->Database->prepare("INSERT INTO tl_calendar_events %s")
                                                    ->set($arrFields)
                                                    ->execute();

                    if ($objInsertStmt->affectedRows) {
                        $insertID = $objInsertStmt->insertId;

                        if (count($eventcontent)) {
                            $step = 128;
                            foreach ($eventcontent as $content) {
                                $cm = new ContentModel();
                                $cm->tstamp = time();
                                $cm->pid = $insertID;
                                $cm->ptable = 'tl_calendar_events';
                                $cm->sorting = $step;
                                $step = $step * 2;
                                $cm->type = 'text';
                                $cm->text = $content;
                                $cm->save();
                            }
                        }

                        $alias = $this->generateAlias($arrFields['title'], $insertID, $pid);
                        $this->Database->prepare("UPDATE tl_calendar_events SET alias = ? WHERE id = ?")
                                       ->execute($alias, $insertID);
                    }
                }
            }
        }
    }

    /**
     * Auto-generate the event alias if it has not been set yet
     *
     * @param mixed   $varValue
     * @param integer $id
     * @param integer $pid
     *
     * @return mixed
     *
     * @throws Exception
     */
    private function generateAlias($varValue, $id, $pid) {
        $aliasExists = function (string $alias) use ($id): bool {
            return $this->Database->prepare("SELECT id FROM tl_calendar_events WHERE alias=? AND id!=?")->execute($alias, $id)->numRows > 0;
        };

        // Generate the alias if there is none
        return \System::getContainer()->get('contao.slug')->generate($varValue, \CalendarModel::findByPk($pid)->jumpTo, $aliasExists);
    }

    public function getConfirmationForm(
        DataContainer $dc,
                      $icssource,
                      $startDate,
                      $endDate,
                      $tzimport,
                      $tzsystem,
                      $deleteCalendar
    ) {
        $this->Template = new BackendTemplate('be_import_calendar_confirmation');

        if (strlen($tzimport)) {
            $this->Template->confirmationText = sprintf(
                $GLOBALS['TL_LANG']['tl_calendar_events']['confirmationTimezone'],
                $tzsystem,
                $tzimport
            );
            $this->Template->correctTimezone = $this->getCorrectTimezoneWidget();
        } else {
            $this->Template->confirmationText = sprintf(
                $GLOBALS['TL_LANG']['tl_calendar_events']['confirmationMissingTZ'],
                $tzsystem
            );
            $this->Template->timezone = $this->getTimezoneWidget($tzsystem);
        }

        $this->Template->startDate = $startDate;
        $this->Template->endDate = $endDate;
        $this->Template->icssource = $icssource;
        $this->Template->deleteCalendar = $deleteCalendar;
        $this->Template->filterEventTitle = $this->filterEventTitle;
        $this->Template->hrefBack = ampersand(str_replace('&key=import', '', \Environment::get('request')));
        $this->Template->goBack = $GLOBALS['TL_LANG']['MSC']['goBack'];
        $this->Template->headline = $GLOBALS['TL_LANG']['MSC']['import_calendar'][0];
        $this->Template->request = ampersand(\Environment::get('request'));
        $this->Template->submit = specialchars($GLOBALS['TL_LANG']['tl_calendar_events']['proceed'][0]);

        return $this->Template->parse();
    }

    public function importCalendar(DataContainer $dc) {
        if (\Input::get('key') != 'import') {
            return '';
        }

        $this->import('BackendUser', 'User');
        $class = $this->User->uploader;

        // See #4086
        if (!class_exists($class)) {
            $class = 'FileUpload';
        }

        $objUploader = new $class();

        $this->loadLanguageFile("tl_calendar_events");
        $this->Template = new BackendTemplate('be_import_calendar');

        $class = $this->User->uploader;

        // See #4086
        if (!class_exists($class)) {
            $class = 'FileUpload';
        }

        $objUploader = new $class();
        $this->Template->markup = $objUploader->generateMarkup();
        $this->Template->icssource = $this->getFileTreeWidget();
        $year = date('Y', time());
        $defaultTimeShift = 0;
        $tstamp = mktime(0, 0, 0, 1, 1, $year);
        $defaultStartDate = date($GLOBALS['TL_CONFIG']['dateFormat'], $tstamp);
        $tstamp = mktime(0, 0, 0, 12, 31, $year);
        $defaultEndDate = date($GLOBALS['TL_CONFIG']['dateFormat'], $tstamp);
        $this->Template->startDate = $this->getStartDateWidget($defaultStartDate);
        $this->Template->endDate = $this->getEndDateWidget($defaultEndDate);
        $this->Template->timeshift = $this->getTimeShiftWidget($defaultTimeShift);
        $this->Template->deleteCalendar = $this->getDeleteWidget();
        $this->Template->filterEventTitle = $this->getFilterWidget();
        $this->Template->max_file_size = $GLOBALS['TL_CONFIG']['maxFileSize'];
        $this->Template->message = \Message::generate();

        $this->Template->hrefBack = ampersand(str_replace('&key=import', '', \Environment::get('request')));
        $this->Template->goBack = $GLOBALS['TL_LANG']['MSC']['goBack'];
        $this->Template->headline = $GLOBALS['TL_LANG']['MSC']['import_calendar'][0];
        $this->Template->request = ampersand(\Environment::get('request'), 'ENCODE_AMPERSANDS');
        $this->Template->submit = specialchars($GLOBALS['TL_LANG']['tl_calendar_events']['import'][0]);

        // Create import form
        if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar' && $this->blnSave) {
            $arrUploaded = $objUploader->uploadTo('system/tmp');

            if (empty($arrUploaded)) {
                \Message::addError($GLOBALS['TL_LANG']['ERR']['all_fields']);
                $this->reload();
            }

            $arrFiles = array();

            foreach ($arrUploaded as $strFile) {
                // Skip folders
                if (is_dir(TL_ROOT . '/' . $strFile)) {
                    \Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['importFolder'], basename($strFile)));
                    continue;
                }

                $objFile = new \File($strFile, true);

                if ($objFile->extension != 'ics' && $objFile->extension != 'csv') {
                    \Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['filetype'], $objFile->extension));
                    continue;
                }

                $arrFiles[] = $strFile;
            }

            if (empty($arrFiles)) {
                \Message::addError($GLOBALS['TL_LANG']['ERR']['all_fields']);
                $this->reload();
            } else {
                if (count($arrFiles) > 1) {
                    \Message::addError($GLOBALS['TL_LANG']['ERR']['only_one_file']);
                    $this->reload();
                } else {
                    $startDate = new Date($this->Template->startDate->value, $GLOBALS['TL_CONFIG']['dateFormat']);
                    $endDate = new Date($this->Template->endDate->value, $GLOBALS['TL_CONFIG']['dateFormat']);
                    $deleteCalendar = $this->Template->deleteCalendar->value;
                    $this->filterEventTitle = $this->Template->filterEventTitle->value;
                    $timeshift = $this->Template->timeshift->value;
                    $file = new \File($arrFiles[0], true);
                    if (strcmp(strtolower($file->extension), 'ics') == 0) {
                        $this->importFromICSFile($file->path, $dc, $startDate, $endDate, null, null, $deleteCalendar,
                                                 $timeshift);
                    } else {
                        if (strcmp(strtolower($file->extension), 'csv') == 0) {
                            $this->Session->set('csv_pid', $dc->id);
                            $this->Session->set('csv_timeshift', $this->Template->timeshift->value);
                            $this->Session->set('csv_startdate', $this->Template->startDate->value);
                            $this->Session->set('csv_enddate', $this->Template->endDate->value);
                            $this->Session->set('csv_deletecalendar', $deleteCalendar);
                            $this->Session->set('csv_filterEventTitle', $this->filterEventTitle);
                            $this->Session->set('csv_filename', $file->path);
                            $this->importFromCSVFile();
                        }
                    }
                }
            }
        } else {
            if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar_confirmation' && $this->blnSave) {
                $startDate = new Date(\Input::post('startDate'), $GLOBALS['TL_CONFIG']['dateFormat']);
                $endDate = new Date(\Input::post('endDate'), $GLOBALS['TL_CONFIG']['dateFormat']);
                $filename = \Input::post('icssource');
                $deleteCalendar = \Input::post('deleteCalendar');
                $this->filterEventTitle = \Input::post('filterEventTitle');
                $timeshift = \Input::post('timeshift');

                if (strlen(\Input::post('timezone'))) {
                    $timezone = \Input::post('timezone');
                    $correctTimezone = null;
                } else {
                    $timezone = null;
                    $correctTimezone = (\Input::post('correctTimezone')) ? true : false;
                }

                $this->importFromICSFile($filename, $dc, $startDate, $endDate, $correctTimezone, $timezone,
                                         $deleteCalendar, $timeshift);
            } else {
                if (\Input::post('FORM_SUBMIT') == 'tl_csv_headers') {
                    if ($this->blnSave && (strlen(\Input::post('import')))) {
                        $this->importFromCSVFile(false);
                    } else {
                        $this->importFromCSVFile();
                    }
                }
            }
        }

        return $this->Template->parse();
    }

    /**
     * Return the file tree widget as object
     *
     * @param mixed
     * @return object
     */
    protected function getFileTreeWidget($value = null) {
        $widget = new FileTree();

        $widget->id = 'icssource';
        $widget->name = 'icssource';
        $widget->strTable = 'tl_calendar_events';
        $widget->strField = 'icssource';
        $GLOBALS['TL_DCA']['tl_calendar_events']['fields']['icssource']['eval']['fieldType'] = 'radio';
        $GLOBALS['TL_DCA']['tl_calendar_events']['fields']['icssource']['eval']['files'] = true;
        $GLOBALS['TL_DCA']['tl_calendar_events']['fields']['icssource']['eval']['filesOnly'] = true;
        $GLOBALS['TL_DCA']['tl_calendar_events']['fields']['icssource']['eval']['extensions'] = 'ics,csv';
        $widget->value = $value;

        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['icssource'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['icssource'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['icssource'][1];
        }

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    /**
     * Return the start date widget as object
     *
     * @param mixed
     * @return object
     */
    protected function getStartDateWidget($value = null) {
        $widget = new TextField();

        $widget->id = 'startDate';
        $widget->name = 'startDate';
        $widget->mandatory = true;
        $widget->required = true;
        $widget->maxlength = 10;
        $widget->rgxp = 'date';
        $widget->datepicker = $this->getDatePickerString();
        $widget->value = $value;

        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['importStartDate'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['importStartDate'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['importStartDate'][1];
        }

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    /**
     * Return the end date widget as object
     *
     * @param mixed
     * @return object
     */
    protected function getEndDateWidget($value = null) {
        $widget = new TextField();

        $widget->id = 'endDate';
        $widget->name = 'endDate';
        $widget->mandatory = false;
        $widget->maxlength = 10;
        $widget->rgxp = 'date';
        $widget->datepicker = $this->getDatePickerString();
        $widget->value = $value;

        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['importEndDate'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['importEndDate'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['importEndDate'][1];
        }

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    /**
     * Return the time shift widget as object
     *
     * @param mixed
     * @return object
     */
    protected function getTimeShiftWidget($value = 0) {
        $widget = new TextField();

        $widget->id = 'timeshift';
        $widget->name = 'timeshift';
        $widget->mandatory = false;
        $widget->maxlength = 4;
        $widget->rgxp = 'digit';
        $widget->value = $value;

        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['importTimeShift'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['importTimeShift'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['importTimeShift'][1];
        }

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    /**
     * Return the delete calendar widget as object
     *
     * @param mixed
     * @return object
     */
    protected function getDeleteWidget($value = null) {
        $widget = new CheckBox();

        $widget->id = 'deleteCalendar';
        $widget->name = 'deleteCalendar';
        $widget->mandatory = false;
        $widget->options = array(
            array(
                'value' => '1',
                'label' => $GLOBALS['TL_LANG']['tl_calendar_events']['importDeleteCalendar'][0],
            ),
        );
        $widget->value = $value;

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['importDeleteCalendar'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['importDeleteCalendar'][1];
        }

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    /**
     * Return the correct timezone widget as object
     *
     * @param mixed
     * @return object
     */
    protected function getCorrectTimezoneWidget($value = null) {
        $widget = new CheckBox();

        $widget->id = 'correctTimezone';
        $widget->name = 'correctTimezone';
        $widget->value = $value;
        $widget->options = array(
            array(
                'value' => 1,
                'label' => $GLOBALS['TL_LANG']['tl_calendar_events']['correctTimezone'][0],
            ),
        );

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['correctTimezone'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['correctTimezone'][1];
        }

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar_confirmation') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    /**
     * Return the status widget as object
     *
     * @param mixed
     * @return object
     */
    protected function getTimezoneWidget($value = null) {
        $widget = new SelectMenu();

        $widget->id = 'timezone';
        $widget->name = 'timezone';
        $widget->mandatory = true;
        $widget->value = $value;

        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['timezone'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_calendar_events']['timezone'][1])) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['timezone'][1];
        }

        $arrOptions = array();

        foreach ($this->getTimezones() as $name => $zone) {
            if (!array_key_exists($name, $arrOptions)) {
                $arrOptions[$name] = array();
            }

            foreach ($zone as $tz) {
                $arrOptions[$name][] = array('value' => $tz, 'label' => $tz);
            }
        }

        $widget->options = $arrOptions;

        // Valiate input
        if (\Input::post('FORM_SUBMIT') == 'tl_import_calendar_confirmation') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    /**
     * Return the filter widget as object
     *
     * @param mixed
     * @return object
     */
    protected function getFilterWidget($value = "") {
        $widget = new TextField();

        $widget->id = 'filterEventTitle';
        $widget->name = 'filterEventTitle';
        $widget->mandatory = false;
        $widget->maxlength = 50;
        $widget->rgxp = 'text';
        $widget->value = $value;

        $widget->label = $GLOBALS['TL_LANG']['tl_calendar_events']['importFilterEventTitle'][0];

        if ($GLOBALS['TL_CONFIG']['showHelp'] && strlen(
                $GLOBALS['TL_LANG']['tl_calendar_events']['importFilterEventTitle'][1]
            )) {
            $widget->help = $GLOBALS['TL_LANG']['tl_calendar_events']['importFilterEventTitle'][1];
        }

        // Valiate input
        if ($this->Input->post('FORM_SUBMIT') == 'tl_import_calendar') {
            $widget->validate();

            if ($widget->hasErrors()) {
                $this->blnSave = false;
            }
        }

        return $widget;
    }

    /**
     * @param array  $arrFields
     * @param array  $rrule
     * @param array  $repeatEach
     * @param string $timezone
     * @param int    $timeshift
     * @return int
     * @throws Exception
     */
    private function getRepeatEnd($arrFields, $rrule, $repeatEach, $timezone, $timeshift = 0) {
        if (($until = $rrule[IcalInterface::UNTIL] ?? null) instanceof \DateTime) {
            // convert UNTIL date to current timezone
            $until = new \DateTime(
                $until->format(DateTimeFactory::$YmdHis),
                DateTimeZoneFactory::factory($timezone)
            );

            $timestamp = $until->getTimestamp();
            if ($timeshift != 0) {
                $timestamp += $timeshift * 3600;
            }
            return $timestamp;
        }

        if ((int)$arrFields['recurrences'] === 0) {
            return (int)min(4294967295, PHP_INT_MAX);
        }

        if (isset($repeatEach['unit'], $repeatEach['value'])) {
            $arg = $repeatEach['value'] * $arrFields['recurrences'];
            $unit = $repeatEach['unit'];

            $strtotime = '+ ' . $arg . ' ' . $unit;

            return (int)strtotime($strtotime, $arrFields['endTime']);
        }

        return 0;
    }

    /**
     * @param array  $arrFields
     * @param Vevent $vevent
     * @param string $timezone
     * @param int    $timeshift
     */
    private function handleRecurringExceptions(&$arrFields, $vevent, $timezone, $timeshift) {
        if (!array_key_exists('useExceptions', $arrFields)
            && !array_key_exists('repeatExceptions', $arrFields)
            && !array_key_exists('exceptionList', $arrFields)) {
            return;
        }

        $arrFields['useExceptions'] = 0;
        $arrFields['repeatExceptions'] = null;
        $arrFields['exceptionList'] = null;

        $exDates = [];
        while (false !== ($exDateRow = $vevent->getExdate())) {
            foreach ($exDateRow as $exDate) {
                if ($exDate instanceof \DateTime) {
                    // convert UNTIL date to current timezone
                    $exDate = new \DateTime(
                        $exDate->format(DateTimeFactory::$YmdHis),
                        DateTimeZoneFactory::factory($timezone)
                    );
                    $timestamp = $exDate->getTimestamp();
                    if ($timeshift != 0) {
                        $timestamp += $timeshift * 3600;
                    }
                    $exDates[$timestamp] = [
                        'exception' => $timestamp,
                        'action' => 'hide',
                    ];
                }
            }
        }

        if (empty($exDates)) {
            return;
        }

        $arrFields['useExceptions'] = 1;
        ksort($exDates);
        $arrFields['exceptionList'] = $exDates;
        $arrFields['repeatExceptions'] = array_values($exDates);
    }
}
