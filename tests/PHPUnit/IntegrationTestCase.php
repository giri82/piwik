<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
use Piwik\API\DocumentationGenerator;
use Piwik\API\Proxy;
use Piwik\API\Request;
use Piwik\ArchiveProcessor\Rules;
use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\ReportRenderer;
use Piwik\Translate;
use Piwik\UrlHelper;
use Piwik\Tests\Impl\TestRequestCollection;
use Piwik\Tests\Impl\TestRequest;

require_once PIWIK_INCLUDE_PATH . '/libs/PiwikTracker/PiwikTracker.php';

/**
 * Base class for Integration tests.
 *
 * Provides helpers to track data and then call API get* methods to check outputs automatically.
 *
 */
abstract class IntegrationTestCase extends PHPUnit_Framework_TestCase
{
    public $defaultApiNotToCall = array(
        'LanguagesManager',
        'DBStats',
        'Dashboard',
        'UsersManager',
        'SitesManager',
        'ExampleUI',
        'Overlay',
        'Live',
        'SEO',
        'ExampleAPI',
        'ScheduledReports',
        'MobileMessaging',
        'Transitions',
        'API',
        'ImageGraph',
        'Annotations',
        'SegmentEditor',
        'UserCountry.getLocationFromIP',
        'Dashboard',
        'ExamplePluginTemplate',
        'CustomAlerts',
        'Insights'
    );

    /**
     * List of Modules, or Module.Method that should not be called as part of the XML output compare
     * Usually these modules either return random changing data, or are already tested in specific unit tests.
     */
    public $apiNotToCall = array();
    public $apiToCall = array();

    /**
     * Identifies the last language used in an API/Controller call.
     *
     * @var string
     */
    protected $lastLanguage;

    protected $missingExpectedFiles = array();
    protected $comparisonFailures = array();

    public static function setUpBeforeClass()
    {
        if (!isset(static::$fixture)) {
            $fixture = new Fixture();
        } else {
            $fixture = static::$fixture;
        }

        $fixture->testCaseClass = get_called_class();

        try {
            $fixture->performSetUp();
        } catch (Exception $e) {
            static::fail("Failed to setup fixture: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    public static function tearDownAfterClass()
    {
        if (!isset(static::$fixture)) {
            $fixture = new Fixture();
        } else {
            $fixture = static::$fixture;
        }

        $fixture->performTearDown();
    }

    public function setUp()
    {
        parent::setUp();

        // Make sure the browser running the test does not influence the Country detection code
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        $this->changeLanguage('en');
    }

    /**
     * Returns true if continuous integration running this request
     * Useful to exclude tests which may fail only on this setup
     */
    static public function isTravisCI()
    {
        $travis = getenv('TRAVIS');
        return !empty($travis);
    }

    static public function isPhpVersion53()
    {
        return strpos(PHP_VERSION, '5.3') === 0;
    }

    static public function isMysqli()
    {
        return getenv('MYSQL_ADAPTER') == 'MYSQLI';
    }

    protected function alertWhenImagesExcludedFromTests()
    {
        if (!Fixture::canImagesBeIncludedInScheduledReports()) {
            $this->markTestSkipped(
                'Scheduled reports generated during integration tests will not contain the image graphs. ' .
                    'For tests to generate images, use a machine with the following specifications : ' .
                    'OS = '.Fixture::IMAGES_GENERATED_ONLY_FOR_OS.', PHP = '.Fixture::IMAGES_GENERATED_FOR_PHP .
                    ' and GD = ' . Fixture::IMAGES_GENERATED_FOR_GD
            );
        }
    }

    /**
     * Return 4 Api Urls for testing scheduled reports :
     * - one in HTML format with all available reports
     * - one in PDF format with all available reports
     * - two in SMS (one for each available report: MultiSites.getOne & MultiSites.getAll)
     *
     * @param string $dateTime eg '2010-01-01 12:34:56'
     * @param string $period eg 'day', 'week', 'month', 'year'
     * @return array
     */
    protected static function getApiForTestingScheduledReports($dateTime, $period)
    {
        $apiCalls = array();

        // HTML Scheduled Report
        array_push(
            $apiCalls,
            array(
                'ScheduledReports.generateReport',
                array(
                    'testSuffix'             => '_scheduled_report_in_html_tables_only',
                    'date'                   => $dateTime,
                    'periods'                => array($period),
                    'format'                 => 'original',
                    'fileExtension'          => 'html',
                    'otherRequestParameters' => array(
                        'idReport'     => 1,
                        'reportFormat' => ReportRenderer::HTML_FORMAT,
                        'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                    )
                )
            )
        );


        // CSV Scheduled Report
        array_push(
            $apiCalls,
            array(
                'ScheduledReports.generateReport',
                array(
                    'testSuffix'             => '_scheduled_report_in_csv',
                    'date'                   => $dateTime,
                    'periods'                => array($period),
                    'format'                 => 'original',
                    'fileExtension'          => 'csv',
                    'otherRequestParameters' => array(
                        'idReport'     => 1,
                        'reportFormat' => ReportRenderer::CSV_FORMAT,
                        'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                    )
                )
            )
        );

        if(Fixture::canImagesBeIncludedInScheduledReports()) {
            // PDF Scheduled Report
            // tests/PHPUnit/Integration/processed/test_ecommerceOrderWithItems_scheduled_report_in_pdf_tables_only__ScheduledReports.generateReport_week.original.pdf
            array_push(
                $apiCalls,
                array(
                     'ScheduledReports.generateReport',
                     array(
                         'testSuffix'             => '_scheduled_report_in_pdf_tables_only',
                         'date'                   => $dateTime,
                         'periods'                => array($period),
                         'format'                 => 'original',
                         'fileExtension'          => 'pdf',
                         'otherRequestParameters' => array(
                             'idReport'     => 1,
                             'reportFormat' => ReportRenderer::PDF_FORMAT,
                             'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                         )
                     )
                )
            );
        }

        // SMS Scheduled Report, one site
        array_push(
            $apiCalls,
            array(
                 'ScheduledReports.generateReport',
                 array(
                     'testSuffix'             => '_scheduled_report_via_sms_one_site',
                     'date'                   => $dateTime,
                     'periods'                => array($period),
                     'format'                 => 'original',
                     'fileExtension'          => 'sms.txt',
                     'otherRequestParameters' => array(
                         'idReport'   => 2,
                         'outputType' => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                     )
                 )
            )
        );

        // SMS Scheduled Report, all sites
        array_push(
            $apiCalls,
            array(
                 'ScheduledReports.generateReport',
                 array(
                     'testSuffix'             => '_scheduled_report_via_sms_all_sites',
                     'date'                   => $dateTime,
                     'periods'                => array($period),
                     'format'                 => 'original',
                     'fileExtension'          => 'sms.txt',
                     'otherRequestParameters' => array(
                         'idReport'   => 3,
                         'outputType' => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                     )
                 )
            )
        );

        if (Fixture::canImagesBeIncludedInScheduledReports()) {
            // HTML Scheduled Report with images
            array_push(
                $apiCalls,
                array(
                     'ScheduledReports.generateReport',
                     array(
                         'testSuffix'             => '_scheduled_report_in_html_tables_and_graph',
                         'date'                   => $dateTime,
                         'periods'                => array($period),
                         'format'                 => 'original',
                         'fileExtension'          => 'html',
                         'otherRequestParameters' => array(
                             'idReport'     => 4,
                             'reportFormat' => ReportRenderer::HTML_FORMAT,
                             'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                         )
                     )
                )
            );

            // mail report with one row evolution based png graph
            array_push(
                $apiCalls,
                array(
                     'ScheduledReports.generateReport',
                     array(
                         'testSuffix'             => '_scheduled_report_in_html_row_evolution_graph',
                         'date'                   => $dateTime,
                         'periods'                => array($period),
                         'format'                 => 'original',
                         'fileExtension'          => 'html',
                         'otherRequestParameters' => array(
                             'idReport'     => 5,
                             'outputType'   => \Piwik\Plugins\ScheduledReports\API::OUTPUT_RETURN
                         )
                     )
                )
            );
        }

        return $apiCalls;
    }

    protected function _testApiUrl($testName, $apiId, $requestUrl, $compareAgainst, $xmlFieldsToRemove = array())
    {
        $testRequest = new TestRequest($requestUrl);
        $response = $testRequest->process();

        list($processedFilePath, $expectedFilePath) =
            $this->getProcessedAndExpectedPaths($testName, $apiId, $format = null, $compareAgainst);

        $dateTime = $requestUrl['date'];

        $isTestLogImportReverseChronological = strpos($testName, 'ImportedInRandomOrderTest') === false;
        $isLiveMustDeleteDates = ($requestUrl['method'] == 'Live.getLastVisits'
                                  || $requestUrl['method'] == 'Live.getVisitorProfile')
                                // except for that particular test that we care about dates!
                                && $isTestLogImportReverseChronological;

        if ($isLiveMustDeleteDates) {
            $response = $this->removeAllLiveDatesFromXml($response);
        }
        $response = $this->normalizePdfContent($response);

        if (!empty($xmlFieldsToRemove)) {
            $response = $this->removeXmlFields($response, $xmlFieldsToRemove);
        }

        $expected = $this->loadExpectedFile($expectedFilePath);
        $expectedContent = $expected;
        $expected = $this->normalizePdfContent($expected);

        if (empty($expected)) {
            if (empty($compareAgainst)) {
                file_put_contents($processedFilePath, $response);
            }

            print("The expected file is not found at '$expectedFilePath'. The Processed response was:");
            print("\n----------------------------\n\n");
            var_dump($response);
            print("\n----------------------------\n");
            return;
        }

        $expected = $this->removeXmlElement($expected, 'idsubdatatable', $testNotSmallAfter = false);
        $response = $this->removeXmlElement($response, 'idsubdatatable', $testNotSmallAfter = false);

        if ($isLiveMustDeleteDates) {
            $expected = $this->removeAllLiveDatesFromXml($expected);
        } // If date=lastN the <prettyDate> element will change each day, we remove XML element before comparison
        elseif (strpos($dateTime, 'last') !== false
            || strpos($dateTime, 'today') !== false
            || strpos($dateTime, 'now') !== false
        ) {
            if ($requestUrl['method'] == 'API.getProcessedReport') {
                $expected = $this->removePrettyDateFromXml($expected);
                $response = $this->removePrettyDateFromXml($response);
            }

            $expected = $this->removeXmlElement($expected, 'visitServerHour');
            $response = $this->removeXmlElement($response, 'visitServerHour');

            if (!empty($requestUrl['date'])) {
                $regex = "/date=[-0-9,%Ca-z]+/"; // need to remove %2C which is encoded ,
                $expected = preg_replace($regex, 'date=', $expected);
                $response = preg_replace($regex, 'date=', $response);
            }
        }

        // if idSubtable is in request URL, make sure idSubtable values are not in any urls
        if (!empty($requestUrl['idSubtable'])) {
            $regex = "/idSubtable=[0-9]+/";
            $expected = preg_replace($regex, 'idSubtable=', $expected);
            $response = preg_replace($regex, 'idSubtable=', $response);
        }

        // Do not test for TRUNCATE(SUM()) returning .00 on mysqli since this is not working
        // http://bugs.php.net/bug.php?id=54508
        $expected = str_replace('.000000</l', '</l', $expected); //lat/long
        $response = str_replace('.000000</l', '</l', $response); //lat/long
        $expected = str_replace('.00</revenue>', '</revenue>', $expected);
        $response = str_replace('.00</revenue>', '</revenue>', $response);
        $response = str_replace('.1</revenue>', '</revenue>', $response);
        $expected = str_replace('.1</revenue>', '</revenue>', $expected);
        $expected = str_replace('.11</revenue>', '</revenue>', $expected);
        $response = str_replace('.11</revenue>', '</revenue>', $response);

        if (empty($compareAgainst)) {
            file_put_contents($processedFilePath, $response);
        }

        try {
            if ($requestUrl['format'] == 'xml') {
                $this->assertXmlStringEqualsXmlString($expected, $response, "Differences with expected in: $processedFilePath");
            } else {
                $this->assertEquals(strlen($expected), strlen($response), "Differences with expected in: $processedFilePath");
                $this->assertEquals($expected, $response, "Differences with expected in: $processedFilePath");
            }

            if (trim($response) == trim($expected)
                && empty($compareAgainst)
            ) {
                if(trim($expectedContent) != trim($expected)) {
                    file_put_contents($expectedFilePath, $expected);
                }
            }
        } catch (Exception $ex) {
            $this->comparisonFailures[] = $ex;
        }
    }

    protected function checkRequestResponse($response)
    {
        if(!is_string($response)) {
            $response = json_encode($response);
        }
        $this->assertTrue(stripos($response, 'error') === false, "error in $response");
        $this->assertTrue(stripos($response, 'exception') === false, "exception in $response");
    }

    protected function removeAllLiveDatesFromXml($input)
    {
        $toRemove = array(
            'serverDate',
            'firstActionTimestamp',
            'lastActionTimestamp',
            'lastActionDateTime',
            'serverTimestamp',
            'serverTimePretty',
            'serverDatePretty',
            'serverDatePrettyFirstAction',
            'serverTimePrettyFirstAction',
            'goalTimePretty',
            'serverTimePretty',
            'visitorId',
            'nextVisitorId',
            'previousVisitorId',
            'visitServerHour',
            'date',
            'prettyDate',
            'serverDateTimePrettyFirstAction'
        );
        return $this->removeXmlFields($input, $toRemove);
    }

    protected function removeXmlFields($input, $toRemove)
    {
        foreach ($toRemove as $xml) {
            $input = $this->removeXmlElement($input, $xml);
        }
        return $input;
    }

    protected function removePrettyDateFromXml($input)
    {
        return $this->removeXmlElement($input, 'prettyDate');
    }

    protected function removeXmlElement($input, $xmlElement, $testNotSmallAfter = true)
    {
        // Only raise error if there was some data before
        $testNotSmallAfter = strlen($input > 100) && $testNotSmallAfter;

        $oldInput = $input;
        $input = preg_replace('/(<' . $xmlElement . '>.+?<\/' . $xmlElement . '>)/', '', $input);

        //check we didn't delete the whole string
        if ($testNotSmallAfter && $input != $oldInput) {
            $this->assertTrue(strlen($input) > 100);
        }
        return $input;
    }

    protected static function getProcessedAndExpectedDirs()
    {
        $path = static::getPathToTestDirectory();
        $processedPath = $path . '/processed/';

        if (!is_writable($processedPath)) {
            $this->fail('To run the tests, you need to give write permissions to the following directory (create it if '
                      . 'it doesn\'t exist).<code><br/>mkdir ' . $processedPath . '<br/>chmod 777 ' . $processedPath
                      . '</code><br/>');
        }

        return array($processedPath, $path . '/expected/');
    }

    private function getProcessedAndExpectedPaths($testName, $testId, $format = null, $compareAgainst = false)
    {
        $filenameSuffix = '__' . $testId;
        if ($format) {
            $filenameSuffix .= ".$format";
        }

        $processedFilename = $testName . $filenameSuffix;
        $expectedFilename = ($compareAgainst ?: $testName) . $filenameSuffix;

        list($processedDir, $expectedDir) = static::getProcessedAndExpectedDirs();

        return array($processedDir . $processedFilename, $expectedDir . $expectedFilename);
    }

    private function loadExpectedFile($filePath)
    {
        $result = @file_get_contents($filePath);
        if (empty($result)) {
            $expectedDir = dirname($filePath);
            $this->missingExpectedFiles[] = $filePath;
            return null;
        }
        return $result;
    }

    /**
     * Returns an array describing the API methods to call & compare with
     * expected output.
     *
     * The returned array must be of the following format:
     * <code>
     * array(
     *     array('SomeAPI.method', array('testOption1' => 'value1', 'testOption2' => 'value2'),
     *     array(array('SomeAPI.method', 'SomeOtherAPI.method'), array(...)),
     *     .
     *     .
     *     .
     * )
     * </code>
     *
     * Valid test options:
     * <ul>
     *   <li><b>testSuffix</b> The suffix added to the test name. Helps determine
     *   the filename of the expected output.</li>
     *   <li><b>format</b> The desired format of the output. Defaults to 'xml'.</li>
     *   <li><b>idSite</b> The id of the website to get data for.</li>
     *   <li><b>date</b> The date to get data for.</li>
     *   <li><b>periods</b> The period or periods to get data for. Can be an array.</li>
     *   <li><b>setDateLastN</b> Flag describing whether to query for a set of
     *   dates or not.</li>
     *   <li><b>language</b> The language to use.</li>
     *   <li><b>segment</b> The segment to use.</li>
     *   <li><b>visitorId</b> The visitor ID to use.</li>
     *   <li><b>abandonedCarts</b> Whether to look for abandoned carts or not.</li>
     *   <li><b>idGoal</b> The goal ID to use.</li>
     *   <li><b>apiModule</b> The value to use in the apiModule request parameter.</li>
     *   <li><b>apiAction</b> The value to use in the apiAction request parameter.</li>
     *   <li><b>otherRequestParameters</b> An array of extra request parameters to use.</li>
     *   <li><b>disableArchiving</b> Disable archiving before running tests.</li>
     * </ul>
     *
     * All test options are optional, except 'idSite' & 'date'.
     */
    public function getApiForTesting()
    {
        return array();
    }

    /**
     * Gets the string prefix used in the name of the expected/processed output files.
     */
    public static function getOutputPrefix()
    {
        $parts = explode("\\", get_called_class());
        $result = end($parts);
        $result = str_replace('Test_Piwik_Integration_', '', $result);
        return $result;
    }

    protected function _setCallableApi($api)
    {
        if ($api == 'all') {
            $this->apiToCall = array();
            $this->apiNotToCall = $this->defaultApiNotToCall;
        } else {
            if (!is_array($api)) {
                $api = array($api);
            }

            $this->apiToCall = $api;

            if (!in_array('UserCountry.getLocationFromIP', $api)) {
                $this->apiNotToCall = array('API.getPiwikVersion',
                                            'UserCountry.getLocationFromIP');
            } else {
                $this->apiNotToCall = array();
            }
        }
    }

    /**
     * Runs API tests.
     */
    protected function runApiTests($api, $params)
    {
        // make sure that the reports we process here are not directly deleted in ArchiveProcessor/PluginsArchiver
        // (because we process reports in the past, they would sometimes be invalid, and would have been deleted)
        \Piwik\ArchiveProcessor\Rules::disablePurgeOutdatedArchives();

        $testName = 'test_' . static::getOutputPrefix();
        $this->missingExpectedFiles = array();
        $this->comparisonFailures = array();

        $this->_setCallableApi($api);

        if (isset($params['disableArchiving']) && $params['disableArchiving'] === true) {
            Rules::$archivingDisabledByTests = true;
            Config::getInstance()->General['browser_archiving_disabled_enforce'] = 1;
        } else {
            Rules::$archivingDisabledByTests = false;
            Config::getInstance()->General['browser_archiving_disabled_enforce'] = 0;
        }

        if(!empty($params['hackDeleteRangeArchivesBefore'])) {
            Db::query('delete from '. Common::prefixTable('archive_numeric_2009_12') . ' where period = 5');
            Db::query('delete from '. Common::prefixTable('archive_blob_2009_12') . ' where period = 5');
        }

        if (isset($params['language'])) {
            $this->changeLanguage($params['language']);
        }

        $testSuffix = isset($params['testSuffix']) ? $params['testSuffix'] : '';

        $testRequests = new TestRequestCollection($api, $params, $this->apiToCall, $this->apiNotToCall);

        $compareAgainst = isset($params['compareAgainst']) ? ('test_' . $params['compareAgainst']) : false;
        $xmlFieldsToRemove = @$params['xmlFieldsToRemove'];

        foreach ($testRequests->getRequestUrls() as $apiId => $requestUrl) {
            $this->_testApiUrl($testName . $testSuffix, $apiId, $requestUrl, $compareAgainst, $xmlFieldsToRemove);
        }

        // Restore normal purge behavior
        \Piwik\ArchiveProcessor\Rules::enablePurgeOutdatedArchives();

        // change the language back to en
        if ($this->lastLanguage != 'en') {
            $this->changeLanguage('en');
        }

        if (!empty($this->missingExpectedFiles)) {
            $expectedDir = dirname(reset($this->missingExpectedFiles));
            $this->fail(" ERROR: Could not find expected API output '"
                . implode("', '", $this->missingExpectedFiles)
                . "'. For new tests, to pass the test, you can copy files from the processed/ directory into"
                . " $expectedDir  after checking that the output is valid. %s ");
        }

        // Display as one error all sub-failures
        if (!empty($this->comparisonFailures)) {
            $messages = '';
            $i = 1;
            foreach ($this->comparisonFailures as $failure) {
                $msg = $failure->getMessage();
                $msg = strtok($msg, "\n");
                $messages .= "\n#" . $i++ . ": " . $msg;
            }
            $messages .= " \n ";
            print($messages);
            $first = reset($this->comparisonFailures);
            throw $first;
        }

        return count($this->comparisonFailures) == 0;
    }

    /**
     * changing the language within one request is a bit fancy
     * in order to keep the core clean, we need a little hack here
     *
     * @param string $langId
     */
    protected function changeLanguage($langId)
    {
        if ($this->lastLanguage != $langId) {
            $_GET['language'] = $langId;
            Translate::reset();
            Translate::reloadLanguage($langId);
        }

        $this->lastLanguage = $langId;
    }

    /**
     * Path where expected/processed output files are stored.
     */
    public static function getPathToTestDirectory()
    {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Integration';
    }

    /**
     * Returns an array associating table names w/ lists of row data.
     *
     * @return array
     */
    protected static function getDbTablesWithData()
    {
        $result = array();
        foreach (DbHelper::getTablesInstalled() as $tableName) {
            $result[$tableName] = Db::fetchAll("SELECT * FROM $tableName");
        }
        return $result;
    }

    /**
     * Truncates all tables then inserts the data in $tables into each
     * mapped table.
     *
     * @param array $tables Array mapping table names with arrays of row data.
     */
    protected static function restoreDbTables($tables)
    {
        // truncate existing tables
        DbHelper::truncateAllTables();

        // insert data
        $existingTables = DbHelper::getTablesInstalled();
        foreach ($tables as $table => $rows) {
            // create table if it's an archive table
            if (strpos($table, 'archive_') !== false && !in_array($table, $existingTables)) {
                $tableType = strpos($table, 'archive_numeric') !== false ? 'archive_numeric' : 'archive_blob';

                $createSql = DbHelper::getTableCreateSql($tableType);
                $createSql = str_replace(Common::prefixTable($tableType), $table, $createSql);
                Db::query($createSql);
            }

            if (empty($rows)) {
                continue;
            }

            $rowsSql = array();
            $bind = array();
            foreach ($rows as $row) {
                $values = array();
                foreach ($row as $name => $value) {
                    if (is_null($value)) {
                        $values[] = 'NULL';
                    } else if (is_numeric($value)) {
                        $values[] = $value;
                    } else if (!ctype_print($value)) {
                        $values[] = "x'" . bin2hex(substr($value, 1)) . "'";
                    } else {
                        $values[] = "?";
                        $bind[] = $value;
                    }
                }

                $rowsSql[] = "(" . implode(',', $values) . ")";
            }

            $sql = "INSERT INTO $table VALUES " . implode(',', $rowsSql);
            Db::query($sql, $bind);
        }
    }

    /**
     * Drops all archive tables.
     */
    public static function deleteArchiveTables()
    {
        foreach (ArchiveTableCreator::getTablesArchivesInstalled() as $table) {
            Db::query("DROP TABLE IF EXISTS $table");
        }

        ArchiveTableCreator::refreshTableList($forceReload = true);
    }

    /**
     * Removes content from PDF binary the content that changes with the datetime or other random Ids
     */
    protected function normalizePdfContent($response)
    {
        // normalize date markups and document ID in pdf files :
        // - /LastModified (D:20120820204023+00'00')
        // - /CreationDate (D:20120820202226+00'00')
        // - /ModDate (D:20120820202226+00'00')
        // - /M (D:20120820202226+00'00')
        // - /ID [ <0f5cc387dc28c0e13e682197f485fe65> <0f5cc387dc28c0e13e682197f485fe65> ]
        $response = preg_replace('/\(D:[0-9]{14}/', '(D:19700101000000', $response);
        $response = preg_replace('/\/ID \[ <.*> ]/', '', $response);
        $response = preg_replace('/\/id:\[ <.*> ]/', '', $response);
        $response = $this->removeXmlElement($response, "xmp:CreateDate");
        $response = $this->removeXmlElement($response, "xmp:ModifyDate");
        $response = $this->removeXmlElement($response, "xmp:MetadataDate");
        $response = $this->removeXmlElement($response, "xmpMM:DocumentID");
        $response = $this->removeXmlElement($response, "xmpMM:InstanceID");
        return $response;
    }
}
