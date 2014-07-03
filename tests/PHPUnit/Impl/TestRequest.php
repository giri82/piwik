<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Impl;

use Piwik\API\Request;

/**
 * TODO
 */
class TestRequest extends Request
{
    /**
     * TODO
     */
    public function __construct($requestUrl)
    {
        parent::__construct($requestUrl);
    }

    /**
     * TODO
     */
    public function process()
    {
        // Cast as string is important. For example when calling
        // with format=original, objects or php arrays can be returned.
        // we also hide errors to prevent the 'headers already sent' in the ResponseBuilder (which sends Excel headers multiple times eg.)
        return (string)parent::process();
    }
}