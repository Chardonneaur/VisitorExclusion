<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\VisitorExclusion;

use Piwik\Plugin;
use Piwik\Tracker\Request;

class VisitorExclusion extends Plugin
{
    public function registerEvents(): array
    {
        return [
            'Tracker.isExcludedVisit' => 'onIsExcludedVisit',
        ];
    }

    /**
     * Fired on every tracking request. Sets $excluded = true to silently drop the visit.
     *
     * @param bool    &$excluded Whether the visit is already excluded by another check.
     * @param Request $request   The current tracking request.
     */
    public function onIsExcludedVisit(bool &$excluded, Request $request): void
    {
        if ($excluded) {
            // Already excluded by another mechanism — no need to evaluate our rules.
            return;
        }

        $model = new Model();
        $rules = $model->getEnabledRules();

        if (empty($rules)) {
            return;
        }

        $evaluator = new RuleEvaluator();

        foreach ($rules as $rule) {
            if ($evaluator->evaluate($rule, $request)) {
                $excluded = true;
                return;
            }
        }
    }

    public function install(): void
    {
        Model::install();
    }

    public function uninstall(): void
    {
        Model::uninstall();
    }
}
