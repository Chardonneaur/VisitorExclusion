<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\VisitorExclusion;

use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugin\API as PluginAPI;

/**
 * Public API for managing visitor exclusion rules.
 * All methods require super-admin access.
 */
class API extends PluginAPI
{
    private Model $model;

    public function __construct()
    {
        $this->model = new Model();
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Returns all exclusion rules.
     *
     * @return array[]
     */
    public function getRules(): array
    {
        Piwik::checkUserHasSuperUserAccess();

        return $this->model->getAllRules();
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Creates a new exclusion rule.
     *
     * @param string $name        Human-readable rule name (required).
     * @param string $conditions  JSON-encoded array of {field, operator, value} objects.
     * @param int    $matchAll    1 = AND logic (all conditions must match), 0 = OR logic.
     * @param string $description Optional description.
     * @return int The ID of the newly created rule.
     */
    public function addRule(
        string $name,
        string $conditions,
        int    $matchAll    = 1,
        string $description = ''
    ): int {
        Piwik::checkUserHasSuperUserAccess();

        $name        = Common::unsanitizeInputValue($name);
        $description = Common::unsanitizeInputValue($description);

        $this->validateRule($name, $conditions);

        return $this->model->addRule($name, $description, $matchAll ? 1 : 0, $conditions);
    }

    /**
     * Updates an existing exclusion rule.
     *
     * @param int    $id
     * @param string $name
     * @param string $conditions  JSON-encoded array of {field, operator, value} objects.
     * @param int    $matchAll
     * @param string $description
     * @param int    $enabled     1 = active, 0 = inactive.
     * @return bool
     */
    public function updateRule(
        int    $id,
        string $name,
        string $conditions,
        int    $matchAll    = 1,
        string $description = '',
        int    $enabled     = 1
    ): bool {
        Piwik::checkUserHasSuperUserAccess();

        $name        = Common::unsanitizeInputValue($name);
        $description = Common::unsanitizeInputValue($description);

        $this->validateRule($name, $conditions);

        $this->model->updateRule(
            $id,
            $name,
            $description,
            $matchAll ? 1 : 0,
            $conditions,
            $enabled ? 1 : 0
        );

        return true;
    }

    /**
     * Deletes an exclusion rule.
     *
     * @param int $id
     * @return bool
     */
    public function deleteRule(int $id): bool
    {
        Piwik::checkUserHasSuperUserAccess();

        $this->model->deleteRule($id);

        return true;
    }

    /**
     * Enables or disables an exclusion rule.
     *
     * @param int $id
     * @param int $enabled 1 to enable, 0 to disable.
     * @return bool
     */
    public function setRuleEnabled(int $id, int $enabled): bool
    {
        Piwik::checkUserHasSuperUserAccess();

        $this->model->setEnabled($id, $enabled ? 1 : 0);

        return true;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    private function validateRule(string $name, string $conditions): void
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException(
                Piwik::translate('VisitorExclusion_ErrorNameRequired')
            );
        }

        $decoded = json_decode($conditions, true);

        if (!is_array($decoded) || empty($decoded)) {
            throw new \InvalidArgumentException(
                Piwik::translate('VisitorExclusion_ErrorNoConditions')
            );
        }

        foreach ($decoded as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $operator = (string) ($condition['operator'] ?? '');
            $value    = (string) ($condition['value']    ?? '');

            if (in_array($operator, ['matches_regex', 'not_matches_regex'], true) && $value !== '') {
                if (@preg_match('~' . $value . '~', '') === false) {
                    throw new \InvalidArgumentException(
                        Piwik::translate('VisitorExclusion_ErrorInvalidRegex', [$value])
                    );
                }
            }
        }
    }
}
