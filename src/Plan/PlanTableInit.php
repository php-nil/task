<?php

namespace NilTask\Plan;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Nil\Kernel\Kernel;

/**
 * 计划任务表初始化器
 *
 * 负责自动创建计划任务表（任务表名 + TaskManager::PLAN_TABLE_NAME 后缀）
 */
final class PlanTableInit
{
    /**
     * 初始化计划任务表
     *
     * @param PlanTask $plan 计划任务管理实例
     */
    public static function init(PlanTask $plan): void
    {
        Kernel::log('task')->info('init plan Table', [$plan->table]);

        $schema = new Schema();
        $myTable = $schema->createTable($plan->table);

        $myTable->addColumn('id', 'bigint', ['unsigned' => true, 'autoincrement' => true]);
        $myTable->addColumn('name', 'string', ['length' => 85]);
        $myTable->addColumn('taskclass', 'string', ['length' => 190]);
        $myTable->addColumn('runtime', 'string', ['length' => 5]);
        $myTable->addColumn('enabled', 'smallint', ['unsigned' => true, 'default' => 1]);
        $myTable->addColumn('cycle', 'json', ['platformOptions' => ['jsonb' => true]]);

        $myTable->addIndex(['enabled']);
        $myTable->addUniqueIndex(['name']);

        $myTable->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create()
        );

        $myTable->setComment('plan tasks');

        $database = $plan->getDatabase();
        $queries = $schema->toSql($database->getDatabasePlatform());

        if (str_starts_with($queries[0], 'CREATE SCHEMA ')) {
            $queries[0] = 'CREATE SCHEMA IF NOT EXISTS ' . substr($queries[0], 14);
        }

        foreach ($queries as $q) {
            $database->executeStatement($q);
        }
    }
}
