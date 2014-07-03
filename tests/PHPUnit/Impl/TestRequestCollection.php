<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Impl;

use Piwik\API\DocumentationGenerator;
use Piwik\API\Proxy;
use Piwik\API\Request;
use \Exception;
use \PHPUnit_Framework_Assert;
use Piwik\UrlHelper;

/**
 * TODO
 */
class TestRequestCollection
{
    /**
     * TODO
     */
    private $requestUrls;

    /**
     * TODO
     */
    private $processedPath;

    /**
     * TODO
     */
    private $expectedPath;

    /**
     * TODO
     */
    private $apiToCall;

    /**
     * TODO
     */
    private $apiNotToCall;

    /**
     * TODO
     */
    public function __construct($api, $params, $apiToCall, $apiNotToCall)
    {
        $this->apiToCall = $apiToCall;
        $this->apiNotToCall = $apiNotToCall;

        $this->requestUrls = $this->_generateApiUrls(
            isset($params['format']) ? $params['format'] : 'xml',
            isset($params['idSite']) ? $params['idSite'] : false,
            isset($params['date']) ? $params['date'] : false,
            isset($params['periods']) ? $params['periods'] : (isset($params['period']) ? $params['period'] : false),
            isset($params['setDateLastN']) ? $params['setDateLastN'] : false,
            isset($params['language']) ? $params['language'] : false,
            isset($params['segment']) ? $params['segment'] : false,
            isset($params['visitorId']) ? $params['visitorId'] : false,
            isset($params['abandonedCarts']) ? $params['abandonedCarts'] : false,
            isset($params['idGoal']) ? $params['idGoal'] : false,
            isset($params['apiModule']) ? $params['apiModule'] : false,
            isset($params['apiAction']) ? $params['apiAction'] : false,
            isset($params['otherRequestParameters']) ? $params['otherRequestParameters'] : array(),
            isset($params['supertableApi']) ? $params['supertableApi'] : false,
            isset($params['fileExtension']) ? $params['fileExtension'] : false);

        if (!empty($params['apiNotToCall'])) {
            $this->apiNotToCall = array_merge($this->apiNotToCall, $params['apiNotToCall']);
        }
    }

    public function getRequestUrls()
    {
        return $this->requestUrls;
    }

    /**
     * Will return all api urls for the given data
     *
     * @param string|array $formats        String or array of formats to fetch from API
     * @param int|bool $idSite         Id site
     * @param string|bool $dateTime       Date time string of reports to request
     * @param array|bool|string $periods        String or array of strings of periods (day, week, month, year)
     * @param bool $setDateLastN   When set to true, 'date' parameter passed to API request will be rewritten to query a range of dates rather than 1 date only
     * @param string|bool $language       2 letter language code to request data in
     * @param string|bool $segment        Custom Segment to query the data  for
     * @param string|bool $visitorId      Only used for Live! API testing
     * @param bool $abandonedCarts Only used in Goals API testing
     * @param bool $idGoal
     * @param bool $apiModule
     * @param bool $apiAction
     * @param array $otherRequestParameters
     * @param array|bool $supertableApi
     * @param array|bool $fileExtension
     *
     * @return array
     */
    protected function _generateApiUrls($formats = 'xml', $idSite = false, $dateTime = false, $periods = false,
                                        $setDateLastN = false, $language = false, $segment = false, $visitorId = false,
                                        $abandonedCarts = false, $idGoal = false, $apiModule = false, $apiAction = false,
                                        $otherRequestParameters = array(), $supertableApi = false, $fileExtension = false)
    {
        if ($periods === false) {
            $periods = 'day';
        }
        if (!is_array($periods)) {
            $periods = array($periods);
        }
        if (!is_array($formats)) {
            $formats = array($formats);
        }
        $parametersToSet = array(
            'idSite'         => $idSite,
            'date'           => ($periods == array('range') || strpos($dateTime, ',') !== false) ?
                                    $dateTime : date('Y-m-d', strtotime($dateTime)),
            'expanded'       => '1',
            'piwikUrl'       => 'http://example.org/piwik/',
            // Used in getKeywordsForPageUrl
            'url'            => 'http://example.org/store/purchase.htm',

            // Used in Actions.getPageUrl, .getDownload, etc.
            // tied to Main.test.php doTest_oneVisitorTwoVisits
            // will need refactoring when these same API functions are tested in a new function
            'downloadUrl'    => 'http://piwik.org/path/again/latest.zip?phpsessid=this is ignored when searching',
            'outlinkUrl'     => 'http://dev.piwik.org/svn',
            'pageUrl'        => 'http://example.org/index.htm?sessionid=this is also ignored by default',
            'pageName'       => ' Checkout / Purchasing... ',

            // do not show the millisec timer in response or tests would always fail as value is changing
            'showTimer'      => 0,

            'language'       => $language ? $language : 'en',
            'abandonedCarts' => $abandonedCarts ? 1 : 0,
            'idSites'        => $idSite,
        );
        $parametersToSet = array_merge($parametersToSet, $otherRequestParameters);
        if (!empty($visitorId)) {
            $parametersToSet['visitorId'] = $visitorId;
        }
        if (!empty($apiModule)) {
            $parametersToSet['apiModule'] = $apiModule;
        }
        if (!empty($apiAction)) {
            $parametersToSet['apiAction'] = $apiAction;
        }
        if (!empty($segment)) {
            $parametersToSet['segment'] = urlencode($segment);
        }
        if ($idGoal !== false) {
            $parametersToSet['idGoal'] = $idGoal;
        }

        $requestUrls = $this->generateUrlsApi($parametersToSet, $formats, $periods, $supertableApi, $setDateLastN, $language, $fileExtension);

        $this->checkEnoughUrlsAreTested($requestUrls);

        return $requestUrls;
    }

    protected function checkEnoughUrlsAreTested($requestUrls)
    {
        $countUrls = count($requestUrls);
        $approximateCountApiToCall = count($this->apiToCall);
        if (empty($requestUrls)
            || $approximateCountApiToCall > $countUrls
        ) {
            throw new Exception("Only generated $countUrls API calls to test but was expecting more for this test.\n" .
                    "Want to test APIs: " . implode(", ", $this->apiToCall) . ")\n" .
                    "But only generated these URLs: \n" . implode("\n", $requestUrls) . ")\n"
            );
        }
    }

    /**
     * Given a list of default parameters to set, returns the URLs of APIs to call
     * If any API was specified in $this->apiNotToCall we ensure only these are tested.
     * If any API is set as excluded (see list below) then it will be ignored.
     *
     * @param array $parametersToSet Parameters to set in api call
     * @param array $formats         Array of 'format' to fetch from API
     * @param array $periods         Array of 'period' to query API
     * @param bool  $supertableApi
     * @param bool  $setDateLastN    If set to true, the 'date' parameter will be rewritten to query instead a range of dates, rather than one period only.
     * @param bool|string $language        2 letter language code, defaults to default piwik language
     * @param bool|string $fileExtension
     *
     * @throws Exception
     *
     * @return array of API URLs query strings
     */
    protected function generateUrlsApi($parametersToSet, $formats, $periods, $supertableApi = false, $setDateLastN = false, $language = false, $fileExtension = false)
    {
        // Get the URLs to query against the API for all functions starting with get*
        $skipped = $requestUrls = array();
        $apiMetadata = new DocumentationGenerator;
        foreach (Proxy::getInstance()->getMetadata() as $class => $info) {
            $moduleName = Proxy::getInstance()->getModuleNameFromClassName($class);
            foreach ($info as $methodName => $infoMethod) {
                $apiId = $moduleName . '.' . $methodName;

                // If Api to test were set, we only test these
                if (!empty($this->apiToCall)
                    && in_array($moduleName, $this->apiToCall) === false
                    && in_array($apiId, $this->apiToCall) === false
                ) {
                    $skipped[] = $apiId;
                    continue;
                } elseif (
                    ((strpos($methodName, 'get') !== 0 && $methodName != 'generateReport')
                        || in_array($moduleName, $this->apiNotToCall) === true
                        || in_array($apiId, $this->apiNotToCall) === true
                        || $methodName == 'getLogoUrl'
                        || $methodName == 'getSVGLogoUrl'
                        || $methodName == 'hasSVGLogo'
                        || $methodName == 'getHeaderLogoUrl'
                    )
                ) { // Excluded modules from test
                    $skipped[] = $apiId;
                    continue;
                }

                foreach ($periods as $period) {
                    $parametersToSet['period'] = $period;

                    // If date must be a date range, we process this date range by adding 6 periods to it
                    if ($setDateLastN) {
                        if (!isset($parametersToSet['dateRewriteBackup'])) {
                            $parametersToSet['dateRewriteBackup'] = $parametersToSet['date'];
                        }

                        $lastCount = (int)$setDateLastN;
                        if ($setDateLastN === true) {
                            $lastCount = 6;
                        }
                        $firstDate = $parametersToSet['dateRewriteBackup'];
                        $secondDate = date('Y-m-d', strtotime("+$lastCount " . $period . "s", strtotime($firstDate)));
                        $parametersToSet['date'] = $firstDate . ',' . $secondDate;
                    }

                    // Set response language
                    if ($language !== false) {
                        $parametersToSet['language'] = $language;
                    }

                    // set idSubtable if subtable API is set
                    if ($supertableApi !== false) {
                        $request = new Request(array(
                                                              'module'    => 'API',
                                                              'method'    => $supertableApi,
                                                              'idSite'    => $parametersToSet['idSite'],
                                                              'period'    => $parametersToSet['period'],
                                                              'date'      => $parametersToSet['date'],
                                                              'format'    => 'php',
                                                              'serialize' => 0,
                                                         ));

                        // find first row w/ subtable
                        $content = $request->process();

                        $this->checkRequestResponse($content);
                        foreach ($content as $row) {
                            if (isset($row['idsubdatatable'])) {
                                $parametersToSet['idSubtable'] = $row['idsubdatatable'];
                                break;
                            }
                        }

                        // if no subtable found, throw
                        if (!isset($parametersToSet['idSubtable'])) {
                            throw new Exception(
                                "Cannot find subtable to load for $apiId in $supertableApi.");
                        }
                    }

                    // Generate for each specified format
                    foreach ($formats as $format) {
                        $parametersToSet['format'] = $format;
                        $parametersToSet['hideIdSubDatable'] = 1;
                        $parametersToSet['serialize'] = 1;

                        $exampleUrl = $apiMetadata->getExampleUrl($class, $methodName, $parametersToSet);
                        
                        if ($exampleUrl === false) {
                            $skipped[] = $apiId;
                            continue;
                        }

                        // Remove the first ? in the query string
                        $exampleUrl = substr($exampleUrl, 1);
                        $apiRequestId = $apiId;
                        if (strpos($exampleUrl, 'period=') !== false) {
                            $apiRequestId .= '_' . $period;
                        }

                        $apiRequestId .= '.' . $format;

                        if ($fileExtension) {
                            $apiRequestId .= '.' . $fileExtension;
                        }

                        $requestUrls[$apiRequestId] = UrlHelper::getArrayFromQueryString($exampleUrl);
                    }
                }
            }
        }
        return $requestUrls;
    }

    // TODO: duplicated code (also in IntegrationTestCase)
    protected function checkRequestResponse($response)
    {
        if(!is_string($response)) {
            $response = json_encode($response);
        }

        PHPUnit_Framework_Assert::assertTrue(stripos($response, 'error') === false, "error in $response");
        PHPUnit_Framework_Assert::assertTrue(stripos($response, 'exception') === false, "exception in $response");
    }
}