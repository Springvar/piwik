<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package ScheduledReports
 */
namespace Piwik\Plugins\ScheduledReports;

use Exception;
use Piwik\Common;
use Piwik\Config;
use Piwik\Date;
use Piwik\Db;
use Piwik\Mail;
use Piwik\Menu\MenuTop;
use Piwik\Piwik;
use Piwik\Plugins\MobileMessaging\API as APIMobileMessaging;
use Piwik\Plugins\MobileMessaging\MobileMessaging;
use Piwik\Plugins\SegmentEditor\API as APISegmentEditor;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\ReportRenderer;
use Piwik\ScheduledTask;
use Piwik\ScheduledTime;
use Piwik\Site;
use Piwik\View;
use Zend_Mime;

/**
 *
 * @package ScheduledReports
 */
class ScheduledReports extends \Piwik\Plugin
{
    const MOBILE_MESSAGING_TOP_MENU_TRANSLATION_KEY = 'MobileMessaging_TopMenu';
    const PDF_REPORTS_TOP_MENU_TRANSLATION_KEY = 'ScheduledReports_EmailReports';

    const DISPLAY_FORMAT_GRAPHS_ONLY_FOR_KEY_METRICS = 1; // Display Tables Only (Graphs only for key metrics)
    const DISPLAY_FORMAT_GRAPHS_ONLY = 2; // Display Graphs Only for all reports
    const DISPLAY_FORMAT_TABLES_AND_GRAPHS = 3; // Display Tables and Graphs for all reports
    const DISPLAY_FORMAT_TABLES_ONLY = 4; // Display only tables for all reports
    const DEFAULT_DISPLAY_FORMAT = self::DISPLAY_FORMAT_GRAPHS_ONLY_FOR_KEY_METRICS;

    const DEFAULT_REPORT_FORMAT = ReportRenderer::HTML_FORMAT;
    const DEFAULT_PERIOD = 'week';
    const DEFAULT_HOUR = '0';

    const EMAIL_ME_PARAMETER = 'emailMe';
    const EVOLUTION_GRAPH_PARAMETER = 'evolutionGraph';
    const ADDITIONAL_EMAILS_PARAMETER = 'additionalEmails';
    const DISPLAY_FORMAT_PARAMETER = 'displayFormat';
    const EMAIL_ME_PARAMETER_DEFAULT_VALUE = true;
    const EVOLUTION_GRAPH_PARAMETER_DEFAULT_VALUE = false;

    const EMAIL_TYPE = 'email';

    static private $availableParameters = array(
        self::EMAIL_ME_PARAMETER          => false,
        self::EVOLUTION_GRAPH_PARAMETER   => false,
        self::ADDITIONAL_EMAILS_PARAMETER => false,
        self::DISPLAY_FORMAT_PARAMETER    => true,
    );

    static private $managedReportTypes = array(
        self::EMAIL_TYPE => 'plugins/Zeitgeist/images/email.png'
    );

    static private $managedReportFormats = array(
        ReportRenderer::HTML_FORMAT => 'plugins/Zeitgeist/images/html_icon.png',
        ReportRenderer::PDF_FORMAT  => 'plugins/UserSettings/images/plugins/pdf.gif'
    );

    /**
     * @see Piwik_Plugin::getListHooksRegistered
     */
    public function getListHooksRegistered()
    {
        return array(
            'Menu.Top.addItems'                         => 'addTopMenu',
            'TaskScheduler.getScheduledTasks'           => 'getScheduledTasks',
            'AssetManager.getJavaScriptFiles'           => 'getJsFiles',
            'ScheduledReports.getReportParameters'      => 'getReportParameters',
            'ScheduledReports.validateReportParameters' => 'validateReportParameters',
            'ScheduledReports.getReportMetadata'        => 'getReportMetadata',
            'ScheduledReports.getReportTypes'           => 'getReportTypes',
            'ScheduledReports.getReportFormats'         => 'getReportFormats',
            'ScheduledReports.getRendererInstance'      => 'getRendererInstance',
            'ScheduledReports.getReportRecipients'      => 'getReportRecipients',
            'ScheduledReports.processReports'           => 'processReports',
            'ScheduledReports.allowMultipleReports'     => 'allowMultipleReports',
            'ScheduledReports.sendReport'               => 'sendReport',
            'Template.reportParametersScheduledReports' => 'template_reportParametersScheduledReports',
            'UsersManager.deleteUser'                   => 'deleteUserReport',
            'SitesManager.deleteSite.end'               => 'deleteSiteReport',
            APISegmentEditor::DEACTIVATE_SEGMENT_EVENT  => 'segmentDeactivation',
        );
    }

    /**
     * Delete reports for the website
     */
    public function deleteSiteReport($idSite)
    {
        $idReports = API::getInstance()->getReports($idSite);

        foreach ($idReports as $report) {
            $idReport = $report['idreport'];
            API::getInstance()->deleteReport($idReport);
        }
    }

    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = "plugins/ScheduledReports/javascripts/pdf.js";
    }

    public function validateReportParameters(&$parameters, $reportType)
    {
        if (self::manageEvent($reportType)) {
            $reportFormat = $parameters[self::DISPLAY_FORMAT_PARAMETER];
            $availableDisplayFormats = array_keys(self::getDisplayFormats());
            if (!in_array($reportFormat, $availableDisplayFormats)) {
                throw new Exception(
                    Piwik::translate(
                    // General_ExceptionInvalidAggregateReportsFormat should be named General_ExceptionInvalidDisplayFormat
                        'General_ExceptionInvalidAggregateReportsFormat',
                        array($reportFormat, implode(', ', $availableDisplayFormats))
                    )
                );
            }

            // emailMe is an optional parameter
            if (!isset($parameters[self::EMAIL_ME_PARAMETER])) {
                $parameters[self::EMAIL_ME_PARAMETER] = self::EMAIL_ME_PARAMETER_DEFAULT_VALUE;
            } else {
                $parameters[self::EMAIL_ME_PARAMETER] = self::valueIsTrue($parameters[self::EMAIL_ME_PARAMETER]);
            }

            // evolutionGraph is an optional parameter
            if (!isset($parameters[self::EVOLUTION_GRAPH_PARAMETER])) {
                $parameters[self::EVOLUTION_GRAPH_PARAMETER] = self::EVOLUTION_GRAPH_PARAMETER_DEFAULT_VALUE;
            } else {
                $parameters[self::EVOLUTION_GRAPH_PARAMETER] = self::valueIsTrue($parameters[self::EVOLUTION_GRAPH_PARAMETER]);
            }

            // additionalEmails is an optional parameter
            if (isset($parameters[self::ADDITIONAL_EMAILS_PARAMETER])) {
                $parameters[self::ADDITIONAL_EMAILS_PARAMETER] = self::checkAdditionalEmails($parameters[self::ADDITIONAL_EMAILS_PARAMETER]);
            }
        }
    }

    // based on http://www.php.net/manual/en/filter.filters.validate.php -> FILTER_VALIDATE_BOOLEAN
    static private function valueIsTrue($value)
    {
        return $value == 'true' || $value == 1 || $value == '1' || $value === true;
    }

    public function getReportMetadata(&$reportMetadata, $reportType, $idSite)
    {
        if (self::manageEvent($reportType)) {
            $availableReportMetadata = \Piwik\Plugins\API\API::getInstance()->getReportMetadata($idSite);

            $filteredReportMetadata = array();
            foreach ($availableReportMetadata as $reportMetadata) {
                // removing reports from the API category and MultiSites.getOne
                if (
                    $reportMetadata['category'] == 'API' ||
                    $reportMetadata['category'] == Piwik::translate('General_MultiSitesSummary') && $reportMetadata['name'] == Piwik::translate('General_SingleWebsitesDashboard')
                ) continue;

                $filteredReportMetadata[] = $reportMetadata;
            }

            $reportMetadata = $filteredReportMetadata;
        }
    }

    public function getReportTypes(&$reportTypes)
    {
        $reportTypes = array_merge($reportTypes, self::$managedReportTypes);
    }

    public function getReportFormats(&$reportFormats, $reportType)
    {
        if (self::manageEvent($reportType)) {
            $reportFormats = self::$managedReportFormats;
        }
    }

    public function getReportParameters(&$availableParameters, $reportType)
    {
        if (self::manageEvent($reportType)) {
            $availableParameters = self::$availableParameters;
        }
    }

    public function processReports(&$processedReports, $reportType, $outputType, $report)
    {
        if (self::manageEvent($reportType)) {
            $displayFormat = $report['parameters'][self::DISPLAY_FORMAT_PARAMETER];
            $evolutionGraph = $report['parameters'][self::EVOLUTION_GRAPH_PARAMETER];

            foreach ($processedReports as &$processedReport) {
                $metadata = $processedReport['metadata'];

                $isAggregateReport = !empty($metadata['dimension']);

                $processedReport['displayTable'] = $displayFormat != self::DISPLAY_FORMAT_GRAPHS_ONLY;

                $processedReport['displayGraph'] =
                    ($isAggregateReport ?
                        $displayFormat == self::DISPLAY_FORMAT_GRAPHS_ONLY || $displayFormat == self::DISPLAY_FORMAT_TABLES_AND_GRAPHS
                        :
                        $displayFormat != self::DISPLAY_FORMAT_TABLES_ONLY)
                    && \Piwik\SettingsServer::isGdExtensionEnabled()
                    && \Piwik\Plugin\Manager::getInstance()->isPluginActivated('ImageGraph')
                    && !empty($metadata['imageGraphUrl']);

                $processedReport['evolutionGraph'] = $evolutionGraph;

                // remove evolution metrics from MultiSites.getAll
                if ($metadata['module'] == 'MultiSites') {
                    $columns = $processedReport['columns'];

                    foreach (\Piwik\Plugins\MultiSites\API::getApiMetrics($enhanced = true) as $metricSettings) {
                        unset($columns[$metricSettings[\Piwik\Plugins\MultiSites\API::METRIC_EVOLUTION_COL_NAME_KEY]]);
                    }

                    $processedReport['metadata'] = $metadata;
                    $processedReport['columns'] = $columns;
                }
            }
        }
    }

    public function getRendererInstance(&$reportRenderer, $reportType, $outputType, $report)
    {
        if (self::manageEvent($reportType)) {
            $reportFormat = $report['format'];

            $reportRenderer = ReportRenderer::factory($reportFormat);

            if ($reportFormat == ReportRenderer::HTML_FORMAT) {
                $reportRenderer->setRenderImageInline($outputType != API::OUTPUT_SAVE_ON_DISK);
            }
        }
    }

    public function allowMultipleReports(&$allowMultipleReports, $reportType)
    {
        if (self::manageEvent($reportType)) {
            $allowMultipleReports = true;
        }
    }

    public function sendReport($reportType, $report, $contents, $filename, $prettyDate, $reportSubject, $reportTitle,
                               $additionalFiles)
    {
        if (self::manageEvent($reportType)) {
            $periods = self::getPeriodToFrequencyAsAdjective();
            $message = Piwik::translate('ScheduledReports_EmailHello');
            $subject = Piwik::translate('General_Report') . ' ' . $reportTitle . " - " . $prettyDate;

            $mail = new Mail();
            $mail->setSubject($subject);
            $fromEmailName = Config::getInstance()->branding['use_custom_logo']
                ? Piwik::translate('CoreHome_WebAnalyticsReports')
                : Piwik::translate('ScheduledReports_PiwikReports');
            $fromEmailAddress = Config::getInstance()->General['noreply_email_address'];
            $attachmentName = $subject;
            $mail->setFrom($fromEmailAddress, $fromEmailName);

            $displaySegmentInfo = false;
            $segmentInfo = null;
            $segment = API::getSegment($report['idsegment']);
            if ($segment != null) {
                $displaySegmentInfo = true;
                $segmentInfo = Piwik::translate('ScheduledReports_SegmentAppliedToReports', $segment['name']);
            }

            switch ($report['format']) {
                case 'html':

                    // Needed when using images as attachment with cid
                    $mail->setType(Zend_Mime::MULTIPART_RELATED);
                    $message .= "<br/>" . Piwik::translate('ScheduledReports_PleaseFindBelow', array($periods[$report['period']], $reportTitle));

                    if ($displaySegmentInfo) {
                        $message .= " " . $segmentInfo;
                    }

                    $mail->setBodyHtml($message . "<br/><br/>" . $contents);
                    break;

                default:
                case 'pdf':
                    $message .= "\n" . Piwik::translate('ScheduledReports_PleaseFindAttachedFile', array($periods[$report['period']], $reportTitle));

                    if ($displaySegmentInfo) {
                        $message .= " " . $segmentInfo;
                    }

                    $mail->setBodyText($message);
                    $mail->createAttachment(
                        $contents,
                        'application/pdf',
                        Zend_Mime::DISPOSITION_INLINE,
                        Zend_Mime::ENCODING_BASE64,
                        $attachmentName . '.pdf'
                    );
                    break;
            }

            foreach ($additionalFiles as $additionalFile) {
                $fileContent = $additionalFile['content'];
                $at = $mail->createAttachment(
                    $fileContent,
                    $additionalFile['mimeType'],
                    Zend_Mime::DISPOSITION_INLINE,
                    $additionalFile['encoding'],
                    $additionalFile['filename']
                );
                $at->id = $additionalFile['cid'];

                unset($fileContent);
            }

            // Get user emails and languages
            $reportParameters = $report['parameters'];
            $emails = array();

            if (isset($reportParameters[self::ADDITIONAL_EMAILS_PARAMETER])) {
                $emails = $reportParameters[self::ADDITIONAL_EMAILS_PARAMETER];
            }

            if ($reportParameters[self::EMAIL_ME_PARAMETER] == 1) {
                if (Piwik::getCurrentUserLogin() == $report['login']) {
                    $emails[] = Piwik::getCurrentUserEmail();
                } elseif ($report['login'] == Piwik::getSuperUserLogin()) {
                    $emails[] = Piwik::getSuperUserEmail();
                } else {
                    try {
                        $user = APIUsersManager::getInstance()->getUser($report['login']);
                    } catch (Exception $e) {
                        return;
                    }
                    $emails[] = $user['email'];
                }
            }

            foreach ($emails as $email) {
                if (empty($email)) {
                    continue;
                }
                $mail->addTo($email);

                try {
                    $mail->send();
                } catch (Exception $e) {

                    // If running from piwik.php with debug, we ignore the 'email not sent' error
                    if (!isset($GLOBALS['PIWIK_TRACKER_DEBUG']) || !$GLOBALS['PIWIK_TRACKER_DEBUG']) {
                        throw new Exception("An error occured while sending '$filename' " .
                            " to " . implode(', ', $mail->getRecipients()) .
                            ". Error was '" . $e->getMessage() . "'");
                    }
                }
                $mail->clearRecipients();
            }
        }
    }

    public function getReportRecipients(&$recipients, $reportType, $report)
    {
        if (self::manageEvent($reportType)) {
            $parameters = $report['parameters'];
            $eMailMe = $parameters[self::EMAIL_ME_PARAMETER];

            if ($eMailMe) {
                $recipients[] = Piwik::getCurrentUserEmail();
            }

            if (isset($parameters[self::ADDITIONAL_EMAILS_PARAMETER])) {
                $additionalEMails = $parameters[self::ADDITIONAL_EMAILS_PARAMETER];
                $recipients = array_merge($recipients, $additionalEMails);
            }
            $recipients = array_filter($recipients);
        }
    }

    static public function template_reportParametersScheduledReports(&$out)
    {
        $view = new View('@ScheduledReports/reportParametersScheduledReports');
        $view->currentUserEmail = Piwik::getCurrentUserEmail();
        $view->displayFormats = self::getDisplayFormats();
        $view->reportType = self::EMAIL_TYPE;
        $view->defaultDisplayFormat = self::DEFAULT_DISPLAY_FORMAT;
        $view->defaultEmailMe = self::EMAIL_ME_PARAMETER_DEFAULT_VALUE ? 'true' : 'false';
        $view->defaultEvolutionGraph = self::EVOLUTION_GRAPH_PARAMETER_DEFAULT_VALUE ? 'true' : 'false';
        $out .= $view->render();
    }

    private static function manageEvent($reportType)
    {
        return in_array($reportType, array_keys(self::$managedReportTypes));
    }

    public function getScheduledTasks(&$tasks)
    {
        $arbitraryDateInUTC = Date::factory('2011-01-01');
        foreach (API::getInstance()->getReports() as $report) {
            if (!$report['deleted'] && $report['period'] != ScheduledTime::PERIOD_NEVER) {
                $midnightInSiteTimezone =
                    date(
                        'H',
                        Date::factory(
                            $arbitraryDateInUTC,
                            Site::getTimezoneFor($report['idsite'])
                        )->getTimestamp()
                    );

                $hourInUTC = (24 - $midnightInSiteTimezone + $report['hour']) % 24;

                $schedule = ScheduledTime::getScheduledTimeForPeriod($report['period']);
                $schedule->setHour($hourInUTC);
                $tasks[] = new ScheduledTask (
                    API::getInstance(),
                    'sendReport',
                    $report['idreport'], $schedule
                );
            }
        }
    }

    public function segmentDeactivation($idSegment)
    {
        $reportsUsingSegment = API::getInstance()->getReports(false, false, false, false, $idSegment);

        if (count($reportsUsingSegment) > 0) {

            $reportList = '';
            $reportNameJoinText = ' ' . Piwik::translate('General_And') . ' ';
            foreach ($reportsUsingSegment as $report) {
                $reportList .= '\'' . $report['description'] . '\'' . $reportNameJoinText;
            }
            $reportList = rtrim($reportList, $reportNameJoinText);

            $errorMessage = Piwik::translate('ScheduledReports_Segment_Deletion_Error', $reportList);
            throw new Exception($errorMessage);
        }
    }

    function addTopMenu()
    {
        MenuTop::addEntry(
            $this->getTopMenuTranslationKey(),
            array('module' => 'ScheduledReports', 'action' => 'index', 'segment' => false),
            true,
            13,
            $isHTML = false,
            $tooltip = Piwik::translate(
                \Piwik\Plugin\Manager::getInstance()->isPluginActivated('MobileMessaging')
                    ? 'MobileMessaging_TopLinkTooltip' : 'ScheduledReports_TopLinkTooltip'
            )
        );
    }

    function getTopMenuTranslationKey()
    {
        // if MobileMessaging is not activated, display 'Email reports'
        if (!\Piwik\Plugin\Manager::getInstance()->isPluginActivated('MobileMessaging'))
            return self::PDF_REPORTS_TOP_MENU_TRANSLATION_KEY;

        if (Piwik::isUserIsAnonymous()) {
            return self::MOBILE_MESSAGING_TOP_MENU_TRANSLATION_KEY;
        }

        $reports = API::getInstance()->getReports();
        $reportCount = count($reports);

        // if there are no reports and the mobile account is
        //  not configured, display 'Email reports'
        //  configured, display 'Email & SMS reports'
        if ($reportCount == 0)
            return APIMobileMessaging::getInstance()->areSMSAPICredentialProvided() ?
                self::MOBILE_MESSAGING_TOP_MENU_TRANSLATION_KEY : self::PDF_REPORTS_TOP_MENU_TRANSLATION_KEY;

        $anyMobileReport = false;
        foreach ($reports as $report) {
            if ($report['type'] == MobileMessaging::MOBILE_TYPE) {
                $anyMobileReport = true;
                break;
            }
        }

        // if there is at least one sms report, display 'Email & SMS reports'
        if ($anyMobileReport) {
            return self::MOBILE_MESSAGING_TOP_MENU_TRANSLATION_KEY;
        }

        return self::PDF_REPORTS_TOP_MENU_TRANSLATION_KEY;
    }

    public function deleteUserReport($userLogin)
    {
        Db::query('DELETE FROM ' . Common::prefixTable('report') . ' WHERE login = ?', $userLogin);
    }

    public function install()
    {
        $queries[] = '
                CREATE TABLE `' . Common::prefixTable('report') . '` (
					`idreport` INT(11) NOT NULL AUTO_INCREMENT,
					`idsite` INTEGER(11) NOT NULL,
					`login` VARCHAR(100) NOT NULL,
					`description` VARCHAR(255) NOT NULL,
					`idsegment` INT(11),
					`period` VARCHAR(10) NOT NULL,
					`hour` tinyint NOT NULL default 0,
					`type` VARCHAR(10) NOT NULL,
					`format` VARCHAR(10) NOT NULL,
					`reports` TEXT NOT NULL,
					`parameters` TEXT NULL,
					`ts_created` TIMESTAMP NULL,
					`ts_last_sent` TIMESTAMP NULL,
					`deleted` tinyint(4) NOT NULL default 0,
					PRIMARY KEY (`idreport`)
				) DEFAULT CHARSET=utf8';
        try {
            foreach ($queries as $query) {
                Db::exec($query);
            }
        } catch (Exception $e) {
            if (!Db::get()->isErrNo($e, '1050')) {
                throw $e;
            }
        }
    }

    private static function checkAdditionalEmails($additionalEmails)
    {
        foreach ($additionalEmails as &$email) {
            $email = trim($email);
            if (empty($email)) {
                $email = false;
            } elseif (!Piwik::isValidEmailString($email)) {
                throw new Exception(Piwik::translate('UsersManager_ExceptionInvalidEmail') . ' (' . $email . ')');
            }
        }
        $additionalEmails = array_filter($additionalEmails);
        return $additionalEmails;
    }

    private static function getDisplayFormats()
    {
        $displayFormats = array(
            // ScheduledReports_AggregateReportsFormat_TablesOnly should be named ScheduledReports_DisplayFormat_GraphsOnlyForKeyMetrics
            self::DISPLAY_FORMAT_GRAPHS_ONLY_FOR_KEY_METRICS => Piwik::translate('ScheduledReports_AggregateReportsFormat_TablesOnly'),
            // ScheduledReports_AggregateReportsFormat_GraphsOnly should be named ScheduledReports_DisplayFormat_GraphsOnly
            self::DISPLAY_FORMAT_GRAPHS_ONLY                 => Piwik::translate('ScheduledReports_AggregateReportsFormat_GraphsOnly'),
            // ScheduledReports_AggregateReportsFormat_TablesAndGraphs should be named ScheduledReports_DisplayFormat_TablesAndGraphs
            self::DISPLAY_FORMAT_TABLES_AND_GRAPHS           => Piwik::translate('ScheduledReports_AggregateReportsFormat_TablesAndGraphs'),
            self::DISPLAY_FORMAT_TABLES_ONLY                 => Piwik::translate('ScheduledReports_DisplayFormat_TablesOnly'),
        );
        return $displayFormats;
    }

    /**
     * Used in the Report Listing
     * @ignore
     */
    static public function getPeriodToFrequency()
    {
        return array(
            ScheduledTime::PERIOD_NEVER => Piwik::translate('General_Never'),
            ScheduledTime::PERIOD_DAY   => Piwik::translate('General_Daily'),
            ScheduledTime::PERIOD_WEEK  => Piwik::translate('General_Weekly'),
            ScheduledTime::PERIOD_MONTH => Piwik::translate('General_Monthly'),
        );
    }

    /**
     * Used in the Report's email content, ie "monthly report"
     * @ignore
     */
    static public function getPeriodToFrequencyAsAdjective()
    {
        return array(
            ScheduledTime::PERIOD_DAY   => Piwik::translate('General_DailyReport'),
            ScheduledTime::PERIOD_WEEK  => Piwik::translate('General_WeeklyReport'),
            ScheduledTime::PERIOD_MONTH => Piwik::translate('General_MonthlyReport'),
            ScheduledTime::PERIOD_YEAR  => Piwik::translate('General_YearlyReport'),
            ScheduledTime::PERIOD_RANGE => Piwik::translate('General_RangeReports'),
        );
    }
}
