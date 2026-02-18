<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\VisitorExclusion;

use Piwik\Updater;
use Piwik\Updates as PiwikUpdates;
use Piwik\Updater\Migration\Factory as MigrationFactory;

class Updates_1_0_0 extends PiwikUpdates
{
    /** @var MigrationFactory */
    private $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function getMigrations(Updater $updater): array
    {
        return [
            $this->migration->db->createTable(
                'visitor_exclusion_rules',
                [
                    'id'          => 'INT(11) NOT NULL AUTO_INCREMENT',
                    'name'        => 'VARCHAR(255) NOT NULL',
                    'description' => 'TEXT NULL',
                    'enabled'     => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'match_all'   => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'conditions'  => 'TEXT NOT NULL',
                    'created_at'  => 'DATETIME NOT NULL',
                    'updated_at'  => 'DATETIME NOT NULL',
                ],
                'id'
            ),
        ];
    }

    public function doUpdate(Updater $updater): void
    {
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));
    }
}
