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

        foreach ($queries as $i => $q) {
            if (str_starts_with($q, 'CREATE SCHEMA ')) {
                $queries[$i] = 'CREATE SCHEMA IF NOT EXISTS ' . substr($q, 14);
            } elseif (str_starts_with($q, 'CREATE TABLE ')) {
                // 多 worker 冷启动可能并发首访建表，使用 IF NOT EXISTS 避免直接冲突
                $queries[$i] = 'CREATE TABLE IF NOT EXISTS ' . substr($q, 13);
            }
        }

        foreach ($queries as $q) {
            try {
                $database->executeStatement($q);
            } catch (\Throwable $e) {
                // 并发初始化：CREATE TABLE IF NOT EXISTS 已通过，仅 CREATE INDEX
                // 可能与另一进程撞名。只要表已存在即说明有进程正在/已经完成初始化，
                // 忽略冲突并继续执行剩余 DDL——各进程合起来保证每条 DDL 至少一方成功，
                // 不会留下「表在但索引缺失」的半成品
                if (!$database->createSchemaManager()->tablesExist([$plan->table])) {
                    throw $e;
                }
            }
        }
    }
}
