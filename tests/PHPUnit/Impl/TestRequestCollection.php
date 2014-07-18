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
use Piwik\UrlHelper;
use \Exception;
use \PHPUnit_Framework_Assert;

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
    private $testConfig;

    /**
     * TODO
     */
    private $apiToCall;

    /**
     * TODO
     */
    private $apiNotToCall;
// TODO: put in type hinting (ie for testConfig
    /**
     * TODO
     */
    public function __construct($api, $testConfig, $apiToCall, $apiNotToCall)
    {
        $this->apiToCall = $apiToCall;
        $this->apiNotToCall = $apiNotToCall;
        $this->testConfig = $testConfig;

        if (!empty($testConfig->apiNotToCall)) {
            $this->apiNotToCall = array_merge($this->apiNotToCall, $testConfig->apiNotToCall);
        }

        $this->requestUrls = $this->_generateApiUrls();
    }

    public function getRequestUrls()
    {
        return $this->requestUrls;
    }

    /**
     * Will return all api urls for the given data
     *
     * @return array
     */
    protected function _generateApiUrls()
    {
        $parametersToSet = array(
            'idSite'         => $this->testConfig->idSite,
            'date'           => ($this->testConfig->periods == array('range') || strpos($this->testConfig->date, ',') !== false) ?
                                    $this->testConfig->date : date('Y-m-d', strtotime($this->testConfig->date)),
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

            'language'       => $this->testConfig->language ?: 'en',
            'abandonedCarts' => $this->testConfig->abandonedCarts ? 1 : 0,
            'idSites'        => $this->testConfig->idSite,
        );
        $parametersToSet = array_merge($parametersToSet, $this->testConfig->otherRequestParameters);
        if (!empty($this->testConfig->visitorId)) {
            $parametersToSet['visitorId'] = $this->testConfig->visitorId;
        }
        if (!empty($this->testConfig->apiModule)) {
            $parametersToSet['apiModule'] = $this->testConfig->apiModule;
        }
        if (!empty($this->testConfig->apiAction)) {
            $parametersToSet['apiAction'] = $this->testConfig->apiAction;
        }
        if (!empty($this->testConfig->segment)) {
            $parametersToSet['segment'] = urlencode($this->testConfig->segment);
        }
        if ($this->testConfig->idGoal !== false) {
            $parametersToSet['idGoal'] = $this->testConfig->idGoal;
        }

        $requestUrls = $this->generateUrlsApi($parametersToSet);

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
    protected function generateUrlsApi($parametersToSet)
    {
        $formats = array($this->testConfig->format);

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

                foreach ($this->testConfig->periods as $period) {
                    $parametersToSet['period'] = $period;

                    // If date must be a date range, we process this date range by adding 6 periods to it
                    if ($this->testConfig->setDateLastN) {
                        if (!isset($parametersToSet['dateRewriteBackup'])) {
                            $parametersToSet['dateRewriteBackup'] = $parametersToSet['date'];
                        }

                        $lastCount = (int)$this->testConfig->setDateLastN;
                        if ($this->testConfig->setDateLastN === true) {
                            $lastCount = 6;
                        }
                        $firstDate = $parametersToSet['dateRewriteBackup'];
                        $secondDate = date('Y-m-d', strtotime("+$lastCount " . $period . "s", strtotime($firstDate)));
                        $parametersToSet['date'] = $firstDate . ',' . $secondDate;
                    }

                    // Set response language
                    if ($this->testConfig->language !== false) {
                        $parametersToSet['language'] = $this->testConfig->language;
                    }

                    // set idSubtable if subtable API is set
                    if ($this->testConfig->supertableApi !== false) {
                        $request = new Request(array(
                                                              'module'    => 'API',
                                                              'method'    => $this->testConfig->supertableApi,
                                                              'idSite'    => $parametersToSet['idSite'],
                                                              'period'    => $parametersToSet['period'],
                                                              'date'      => $parametersToSet['date'],
                                                              'format'    => 'php',
                                                              'serialize' => 0,
                                                         ));

                        // find first row w/ subtable
                        $content = $request->process();

                        IntegrationTestCase::assertApiResponseHasNoError($content);
                        foreach ($content as $row) {
                            if (isset($row['idsubdatatable'])) {
                                $parametersToSet['idSubtable'] = $row['idsubdatatable'];
                                break;
                            }
                        }

                        // if no subtable found, throw
                        if (!isset($parametersToSet['idSubtable'])) {
                            throw new Exception(
                                "Cannot find subtable to load for $apiId in {$this->testConfig->supertableApi}.");
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

                        if ($this->testConfig->fileExtension) {
                            $apiRequestId .= '.' . $this->testConfig->fileExtension;
                        }

                        $requestUrls[$apiRequestId] = UrlHelper::getArrayFromQueryString($exampleUrl);
                    }
                }
            }
        }
        return $requestUrls;
    }
}