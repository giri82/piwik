<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Impl;

use \Exception;

/**
 * TODO
 */
class ApiTestConfig
{
    /**
     * TODO
     */
    public $idSite;

    /**
     * TODO
     */
    public $date;

    /**
     * TODO
     */
    public $periods = array('day');

    /**
     * TODO
     */
    public $format = 'xml';

    /**
     * TODO
     */
    public $setDateLastN = false;

    /**
     * TODO
     */
    public $language = false;

    /**
     * TODO
     */
    public $segment = false;

    /**
     * TODO
     */
    public $visitorId = false;

    /**
     * TODO
     */
    public $abandonedCarts = false;

    /**
     * TODO
     */
    public $idGoal = false;

    /**
     * TODO
     */
    public $apiModule = false;

    /**
     * TODO
     */
    public $apiAction = false;

    /**
     * TODO
     */
    public $otherRequestParameters = array();

    /**
     * TODO
     */
    public $supertableApi = false;

    /**
     * TODO
     */
    public $fileExtension = false;

    /**
     * TODO
     */
    public $apiNotToCall = false;

    /**
     * TODO
     */
    public $disableArchiving = false;

    /**
     * TODO: remove this guy
     */
    public $hackDeleteRangeArchivesBefore = false;

    /**
     * TODO
     */
    public $testSuffix = '';

    /**
     * TODO
     */
    public $compareAgainst = false;

    /**
     * TODO
     */
    public $xmlFieldsToRemove = false;

    /**
     * TODO
     */
    public $keepLiveDates = false;

    /**
     * TODO
     */
    public function __construct($params)
    {
        foreach ($params as $key => $value) {
            if (!property_exists($this, $key)) {
                throw new Exception("Invalid API test property '$key'! Check your Integration tests.");
            }

            $this->$key = $value;
        }

        if (!is_array($this->periods)) {
            $this->periods = array($this->periods);
        }

        if ($this->setDateLastN === true) {
            $this->setDateLastN = 6;
        }
    }
}