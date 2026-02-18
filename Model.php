<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\VisitorExclusion;

use Piwik\Cache as PiwikCache;
use Piwik\Common;
use Piwik\Date;
use Piwik\Db;

class Model
{
    const TABLE_NAME = 'visitor_exclusion_rules';
    const CACHE_KEY  = 'VisitorExclusion_enabled_rules';

    // -------------------------------------------------------------------------
    // Install / Uninstall
    // -------------------------------------------------------------------------

    public static function install(): void
    {
        $table = Common::prefixTable(self::TABLE_NAME);

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id`          INT(11)      NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(255) NOT NULL,
            `description` TEXT         NULL,
            `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
            `match_all`   TINYINT(1)   NOT NULL DEFAULT 1,
            `conditions`  TEXT         NOT NULL,
            `created_at`  DATETIME     NOT NULL,
            `updated_at`  DATETIME     NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        Db::exec($sql);
    }

    public static function uninstall(): void
    {
        $table = Common::prefixTable(self::TABLE_NAME);
        Db::exec("DROP TABLE IF EXISTS `{$table}`");
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function getAllRules(): array
    {
        $table = Common::prefixTable(self::TABLE_NAME);
        $rows  = Db::fetchAll("SELECT * FROM `{$table}` ORDER BY `id` ASC");

        return $rows ?: [];
    }

    /**
     * Returns only enabled rules. Result is cached in the transient cache
     * (per-request memory cache) so the DB is hit at most once per tracking request.
     */
    public function getEnabledRules(): array
    {
        $cache = PiwikCache::getTransientCache();

        if ($cache->contains(self::CACHE_KEY)) {
            return $cache->fetch(self::CACHE_KEY);
        }

        $table = Common::prefixTable(self::TABLE_NAME);
        $rows  = Db::fetchAll(
            "SELECT * FROM `{$table}` WHERE `enabled` = 1 ORDER BY `id` ASC"
        );

        $rules = $rows ?: [];
        $cache->save(self::CACHE_KEY, $rules);

        return $rules;
    }

    public function getRule(int $id): ?array
    {
        $table = Common::prefixTable(self::TABLE_NAME);
        $row   = Db::fetchRow(
            "SELECT * FROM `{$table}` WHERE `id` = ?",
            [$id]
        );

        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    public function addRule(
        string $name,
        string $description,
        int    $matchAll,
        string $conditions
    ): int {
        $table = Common::prefixTable(self::TABLE_NAME);
        $now   = Date::now()->getDatetime();

        Db::query(
            "INSERT INTO `{$table}`
                (`name`, `description`, `enabled`, `match_all`, `conditions`, `created_at`, `updated_at`)
             VALUES (?, ?, 1, ?, ?, ?, ?)",
            [$name, $description, $matchAll, $conditions, $now, $now]
        );

        $this->clearCache();

        return (int) Db::get()->lastInsertId();
    }

    public function updateRule(
        int    $id,
        string $name,
        string $description,
        int    $matchAll,
        string $conditions,
        int    $enabled
    ): void {
        $table = Common::prefixTable(self::TABLE_NAME);
        $now   = Date::now()->getDatetime();

        Db::query(
            "UPDATE `{$table}`
             SET `name` = ?, `description` = ?, `match_all` = ?, `conditions` = ?,
                 `enabled` = ?, `updated_at` = ?
             WHERE `id` = ?",
            [$name, $description, $matchAll, $conditions, $enabled, $now, $id]
        );

        $this->clearCache();
    }

    public function deleteRule(int $id): void
    {
        $table = Common::prefixTable(self::TABLE_NAME);
        Db::query("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
        $this->clearCache();
    }

    public function setEnabled(int $id, int $enabled): void
    {
        $table = Common::prefixTable(self::TABLE_NAME);
        $now   = Date::now()->getDatetime();

        Db::query(
            "UPDATE `{$table}` SET `enabled` = ?, `updated_at` = ? WHERE `id` = ?",
            [$enabled, $now, $id]
        );

        $this->clearCache();
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    private function clearCache(): void
    {
        PiwikCache::getTransientCache()->delete(self::CACHE_KEY);
    }
}
