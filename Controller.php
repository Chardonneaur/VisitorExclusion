<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\VisitorExclusion;

use Piwik\Common;
use Piwik\Nonce;
use Piwik\Notification;
use Piwik\Piwik;
use Piwik\View;

class Controller extends \Piwik\Plugin\ControllerAdmin
{
    const NONCE_NAME = 'VisitorExclusion.action';

    // -------------------------------------------------------------------------
    // Main action
    // -------------------------------------------------------------------------

    public function index(): string
    {
        Piwik::checkUserHasSuperUserAccess();

        // editId to pre-populate the edit form (from GET or preserved after a POST error)
        $editId = 0;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $editId = $this->handlePost();
        } else {
            $editId = Common::getRequestVar('editId', 0, 'int');
        }

        // Load all rules fresh from the DB (after any write operation above)
        $model = new Model();
        $rules = $model->getAllRules();

        foreach ($rules as &$rule) {
            $rule['conditions_decoded'] = json_decode($rule['conditions'], true) ?? [];
        }
        unset($rule);

        // Resolve the rule to edit (if any)
        $editRule = null;
        if ($editId > 0) {
            foreach ($rules as $r) {
                if ((int) $r['id'] === $editId) {
                    $editRule = $r;
                    break;
                }
            }
        }

        $view = new View('@VisitorExclusion/index');
        $this->setGeneralVariablesView($view);

        $view->rules            = $rules;
        $view->editRule         = $editRule;
        $view->nonce            = Nonce::getNonce(self::NONCE_NAME);
        $view->availableFields  = $this->getAvailableFields();
        $view->availableOperators = $this->getAvailableOperators();
        $view->fieldHints       = $this->getFieldHints();

        return $view->render();
    }

    // -------------------------------------------------------------------------
    // POST dispatch
    // -------------------------------------------------------------------------

    /**
     * Handles a POST request. Returns the editId to show in the form after the
     * operation (0 = show empty add form; positive = show edit form for that ID).
     */
    private function handlePost(): int
    {
        $nonce = Common::getRequestVar('nonce', '', 'string', $_POST);
        Nonce::checkNonce(self::NONCE_NAME, $nonce);

        $action = Common::getRequestVar('action', '', 'string', $_POST);

        switch ($action) {
            case 'add':
                return $this->handleAdd();
            case 'update':
                return $this->handleUpdate();
            case 'delete':
                $this->handleDelete();
                return 0;
            case 'toggle':
                $this->handleToggle();
                return 0;
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // Individual handlers
    // -------------------------------------------------------------------------

    /**
     * @return int editId (0 on success so form resets, >0 on error to keep form populated)
     */
    private function handleAdd(): int
    {
        $name        = Common::getRequestVar('name', '', 'string', $_POST);
        $description = Common::getRequestVar('description', '', 'string', $_POST);
        $matchAll    = Common::getRequestVar('match_all', 1, 'int', $_POST);
        $conditions  = $this->buildConditionsJson();

        if (trim($name) === '') {
            $this->showError(Piwik::translate('VisitorExclusion_ErrorNameRequired'));
            return 0;
        }

        if ($conditions === null) {
            $this->showError(Piwik::translate('VisitorExclusion_ErrorNoConditions'));
            return 0;
        }

        $validationError = $this->validateConditions($conditions);
        if ($validationError !== null) {
            $this->showError($validationError);
            return 0;
        }

        $model = new Model();
        $model->addRule($name, $description, $matchAll ? 1 : 0, $conditions);

        $this->showSuccess(Piwik::translate('VisitorExclusion_RuleAdded'));

        return 0;
    }

    /**
     * @return int editId (0 on success, rule ID on error)
     */
    private function handleUpdate(): int
    {
        $id          = Common::getRequestVar('id', 0, 'int', $_POST);
        $name        = Common::getRequestVar('name', '', 'string', $_POST);
        $description = Common::getRequestVar('description', '', 'string', $_POST);
        $matchAll    = Common::getRequestVar('match_all', 1, 'int', $_POST);
        $conditions  = $this->buildConditionsJson();

        if ($id <= 0) {
            return 0;
        }

        if (trim($name) === '') {
            $this->showError(Piwik::translate('VisitorExclusion_ErrorNameRequired'));
            return $id;
        }

        if ($conditions === null) {
            $this->showError(Piwik::translate('VisitorExclusion_ErrorNoConditions'));
            return $id;
        }

        $validationError = $this->validateConditions($conditions);
        if ($validationError !== null) {
            $this->showError($validationError);
            return $id;
        }

        // Preserve the current enabled status — toggling is done via the toggle action.
        $model       = new Model();
        $existingRule = $model->getRule($id);
        $enabled     = $existingRule ? (int) $existingRule['enabled'] : 1;

        $model->updateRule($id, $name, $description, $matchAll ? 1 : 0, $conditions, $enabled);

        $this->showSuccess(Piwik::translate('VisitorExclusion_RuleUpdated'));

        return 0; // Reset to add form after successful save
    }

    private function handleDelete(): void
    {
        $id = Common::getRequestVar('id', 0, 'int', $_POST);

        if ($id <= 0) {
            return;
        }

        $model = new Model();
        $model->deleteRule($id);

        $this->showSuccess(Piwik::translate('VisitorExclusion_RuleDeleted'));
    }

    private function handleToggle(): void
    {
        $id      = Common::getRequestVar('id', 0, 'int', $_POST);
        $enabled = Common::getRequestVar('enabled', 0, 'int', $_POST);

        if ($id <= 0) {
            return;
        }

        $model = new Model();
        $model->setEnabled($id, $enabled ? 1 : 0);

        $key = $enabled ? 'VisitorExclusion_RuleEnabled' : 'VisitorExclusion_RuleDisabled';
        $this->showSuccess(Piwik::translate($key));
    }

    // -------------------------------------------------------------------------
    // Condition helpers
    // -------------------------------------------------------------------------

    /**
     * Reads condition_field[], condition_operator[], condition_value[] from $_POST
     * and builds a JSON string. Returns null if no valid conditions found.
     */
    private function buildConditionsJson(): ?string
    {
        $fields    = $_POST['condition_field']    ?? [];
        $operators = $_POST['condition_operator'] ?? [];
        $values    = $_POST['condition_value']    ?? [];

        if (!is_array($fields) || empty($fields)) {
            return null;
        }

        $conditions = [];
        $count      = min(count($fields), count($operators), count($values));

        for ($i = 0; $i < $count; $i++) {
            $field    = Common::sanitizeInputValue((string) $fields[$i]);
            $operator = Common::sanitizeInputValue((string) $operators[$i]);
            $value    = Common::sanitizeInputValue((string) $values[$i]);

            if ($field === '' || $operator === '') {
                continue;
            }

            $conditions[] = [
                'field'    => $field,
                'operator' => $operator,
                'value'    => $value,
            ];
        }

        if (empty($conditions)) {
            return null;
        }

        return json_encode($conditions);
    }

    /**
     * Validates decoded conditions. Returns an error string or null if valid.
     */
    private function validateConditions(string $conditionsJson): ?string
    {
        $conditions = json_decode($conditionsJson, true);

        if (!is_array($conditions) || empty($conditions)) {
            return Piwik::translate('VisitorExclusion_ErrorNoConditions');
        }

        $allowedOperators = array_keys($this->getAvailableOperators());

        foreach ($conditions as $condition) {
            $operator = (string) ($condition['operator'] ?? '');
            $value    = (string) ($condition['value']    ?? '');

            if (!in_array($operator, $allowedOperators, true)) {
                continue; // Unknown operators are silently skipped
            }

            if (in_array($operator, ['matches_regex', 'not_matches_regex'], true) && $value !== '') {
                if (strlen($value) > 512) {
                    return Piwik::translate('VisitorExclusion_ErrorRegexTooLong');
                }

                if (preg_match('/[+*]\)[+*?]/', $value)) {
                    return Piwik::translate('VisitorExclusion_ErrorRegexReDoS');
                }

                if (@preg_match('~' . $value . '~', '') === false) {
                    return Piwik::translate('VisitorExclusion_ErrorInvalidRegex', [$value]);
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Field / operator definitions (shared with template)
    // -------------------------------------------------------------------------

    private function getAvailableFields(): array
    {
        $fields = [
            'ip'              => Piwik::translate('VisitorExclusion_FieldIp'),
            'userAgent'       => Piwik::translate('VisitorExclusion_FieldUserAgent'),
            'pageUrl'         => Piwik::translate('VisitorExclusion_FieldPageUrl'),
            'referrerUrl'     => Piwik::translate('VisitorExclusion_FieldReferrerUrl'),
            'deviceType'      => Piwik::translate('VisitorExclusion_FieldDeviceType'),
            'browserName'     => Piwik::translate('VisitorExclusion_FieldBrowserName'),
            'operatingSystem' => Piwik::translate('VisitorExclusion_FieldOperatingSystem'),
            'browserLanguage'   => Piwik::translate('VisitorExclusion_FieldBrowserLanguage'),
            'screenResolution'  => Piwik::translate('VisitorExclusion_FieldScreenResolution'),
        ];

        // Custom dimensions 1–20
        for ($i = 1; $i <= 20; $i++) {
            $fields['dimension' . $i] = Piwik::translate('VisitorExclusion_FieldCustomDimension', [$i]);
        }

        return $fields;
    }

    private function getAvailableOperators(): array
    {
        return [
            'equals'            => Piwik::translate('VisitorExclusion_OpEquals'),
            'not_equals'        => Piwik::translate('VisitorExclusion_OpNotEquals'),
            'contains'          => Piwik::translate('VisitorExclusion_OpContains'),
            'not_contains'      => Piwik::translate('VisitorExclusion_OpNotContains'),
            'starts_with'       => Piwik::translate('VisitorExclusion_OpStartsWith'),
            'ends_with'         => Piwik::translate('VisitorExclusion_OpEndsWith'),
            'matches_regex'     => Piwik::translate('VisitorExclusion_OpMatchesRegex'),
            'not_matches_regex' => Piwik::translate('VisitorExclusion_OpNotMatchesRegex'),
            'in_ip_range'       => Piwik::translate('VisitorExclusion_OpInIpRange'),
            'not_in_ip_range'   => Piwik::translate('VisitorExclusion_OpNotInIpRange'),
        ];
    }

    private function getFieldHints(): array
    {
        return [
            'ip'              => Piwik::translate('VisitorExclusion_HintIpRange'),
            'browserLanguage' => Piwik::translate('VisitorExclusion_HintBrowserLanguage'),
            'deviceType'      => Piwik::translate('VisitorExclusion_HintDeviceType'),
            'matches_regex'   => Piwik::translate('VisitorExclusion_HintRegex'),
        ];
    }

    // -------------------------------------------------------------------------
    // Notification helpers
    // -------------------------------------------------------------------------

    private function showSuccess(string $message): void
    {
        $notification          = new Notification($message);
        $notification->context = Notification::CONTEXT_SUCCESS;
        Notification\Manager::notify('VisitorExclusion_result', $notification);
    }

    private function showError(string $message): void
    {
        $notification          = new Notification($message);
        $notification->context = Notification::CONTEXT_ERROR;
        Notification\Manager::notify('VisitorExclusion_error', $notification);
    }
}
