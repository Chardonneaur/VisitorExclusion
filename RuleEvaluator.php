<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\VisitorExclusion;

use DeviceDetector\DeviceDetector;
use Matomo\Network\IP;
use Piwik\Container\StaticContainer;
use Piwik\DeviceDetector\DeviceDetectorFactory;
use Piwik\Tracker\Request;

/**
 * Evaluates exclusion rules against a live tracker Request object.
 *
 * Each rule is a DB row with:
 *   - conditions: JSON array of {field, operator, value}
 *   - match_all:  1 = AND logic, 0 = OR logic
 *
 * Returns true when the rule matches (visit should be excluded).
 */
class RuleEvaluator
{
    /** @var DeviceDetector|null  Lazily initialised — only when a device field is needed. */
    private ?DeviceDetector $deviceDetector = null;

    private bool $deviceDetectorInitialised = false;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * @param array   $rule    Row from matomo_visitor_exclusion_rules.
     * @param Request $request Current tracker request.
     * @return bool True = rule matched, visit should be excluded.
     */
    public function evaluate(array $rule, Request $request): bool
    {
        $conditions = json_decode($rule['conditions'], true);

        if (empty($conditions) || !is_array($conditions)) {
            return false;
        }

        $matchAll = !empty($rule['match_all']);

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $matches = $this->evaluateCondition($condition, $request);

            if ($matchAll && !$matches) {
                return false; // AND: one failure → rule does not match
            }

            if (!$matchAll && $matches) {
                return true; // OR: one match → rule matches
            }
        }

        // AND: all conditions passed → match
        // OR:  no condition matched  → no match
        return $matchAll;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function evaluateCondition(array $condition, Request $request): bool
    {
        $field    = (string) ($condition['field']    ?? '');
        $operator = (string) ($condition['operator'] ?? '');
        $expected = (string) ($condition['value']    ?? '');

        $actual = $this->extractFieldValue($field, $request);

        return $this->applyOperator($operator, $actual, $expected, $field, $request);
    }

    private function extractFieldValue(string $field, Request $request): string
    {
        switch ($field) {
            case 'ip':
                $binary = $request->getIp();
                if (empty($binary)) {
                    return '';
                }
                return IP::fromBinaryIP($binary)->toString();

            case 'userAgent':
                return (string) $request->getUserAgent();

            case 'pageUrl':
                return (string) $request->getParam('url');

            case 'referrerUrl':
                return (string) $request->getParam('urlref');

            case 'browserLanguage':
                // Normalise: "fr-FR,fr;q=0.9,en;q=0.8" → "fr"
                $lang = (string) $request->getParam('lang');
                return strtolower(substr($lang, 0, 2));

            case 'screenResolution':
                // Sent as "WIDTHxHEIGHT" (e.g. "1920x1080") or "unknown"
                $res = (string) $request->getParam('res');
                return $res === 'unknown' ? '' : $res;

            case 'deviceType':
                $dd = $this->getDeviceDetector($request);
                if ($dd === null) {
                    return '';
                }
                if ($dd->isBot()) {
                    return 'bot';
                }
                return strtolower((string) $dd->getDeviceName());

            case 'browserName':
                $dd = $this->getDeviceDetector($request);
                if ($dd === null) {
                    return '';
                }
                $client = $dd->getClient('name');
                return strtolower(is_string($client) ? $client : '');

            case 'operatingSystem':
                $dd = $this->getDeviceDetector($request);
                if ($dd === null) {
                    return '';
                }
                $os = $dd->getOs('name');
                return strtolower(is_string($os) ? $os : '');

            default:
                // Custom dimensions: dimension1 … dimension20
                if (preg_match('/^dimension([1-9]|1\d|20)$/', $field, $m)) {
                    return (string) $request->getParam('dimension' . $m[1]);
                }
                return '';
        }
    }

    private function applyOperator(
        string  $operator,
        string  $actual,
        string  $expected,
        string  $field,
        Request $request
    ): bool {
        switch ($operator) {
            case 'equals':
                return strcasecmp($actual, $expected) === 0;

            case 'not_equals':
                return strcasecmp($actual, $expected) !== 0;

            case 'contains':
                return mb_stripos($actual, $expected) !== false;

            case 'not_contains':
                return mb_stripos($actual, $expected) === false;

            case 'starts_with':
                return mb_stripos($actual, $expected) === 0;

            case 'ends_with':
                $actualLen   = mb_strlen($actual);
                $expectedLen = mb_strlen($expected);
                if ($expectedLen === 0) {
                    return true;
                }
                if ($expectedLen > $actualLen) {
                    return false;
                }
                return mb_stripos($actual, $expected, $actualLen - $expectedLen) !== false;

            case 'matches_regex':
                if ($expected === '') {
                    return false;
                }
                return @preg_match('~' . $expected . '~i', $actual) === 1;

            case 'not_matches_regex':
                if ($expected === '') {
                    return true;
                }
                return @preg_match('~' . $expected . '~i', $actual) !== 1;

            case 'in_ip_range':
                return $this->isIpInRange($request, $expected);

            case 'not_in_ip_range':
                return !$this->isIpInRange($request, $expected);

            default:
                return false;
        }
    }

    /**
     * Evaluates whether the request IP is within a given range expression.
     * $rangeExpression may be a single IP, a CIDR range, or a comma-separated
     * list of either (e.g. "192.168.1.0/24, 10.0.0.1").
     */
    private function isIpInRange(Request $request, string $rangeExpression): bool
    {
        $binary = $request->getIp();
        if (empty($binary)) {
            return false;
        }

        $ip     = IP::fromBinaryIP($binary);
        $ranges = array_values(array_filter(array_map('trim', explode(',', $rangeExpression))));

        if (empty($ranges)) {
            return false;
        }

        return $ip->isInRanges($ranges);
    }

    /**
     * Lazily initialises the DeviceDetector instance (only when a device/browser/OS
     * field is actually evaluated, to avoid unnecessary processing).
     */
    private function getDeviceDetector(Request $request): ?DeviceDetector
    {
        if ($this->deviceDetectorInitialised) {
            return $this->deviceDetector;
        }

        $this->deviceDetectorInitialised = true;

        try {
            /** @var DeviceDetectorFactory $factory */
            $factory = StaticContainer::get(DeviceDetectorFactory::class);
            $ua      = (string) $request->getUserAgent();
            $hints   = $request->getClientHints();

            $this->deviceDetector = $factory->makeInstance($ua, $hints);
        } catch (\Exception $e) {
            $this->deviceDetector = null;
        }

        return $this->deviceDetector;
    }
}
