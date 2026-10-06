<?php

namespace NilTask;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Nil\Kernel\Kernel;

/**
 * 任务表初始化器
 *
 * 负责自动创建任务表结构
 */
final class TableInit
{
    /**
     * 初始化任务表
     *
     * @param Task $task 任务管理器实例
     */
    public static function init(Task $task): void
    {
        Kernel::log('task')->info('init Table', [$task->table]);

        $schema = new Schema();
        $myTable = $schema->createTable($task->table);

        $myTable->addColumn("id", "bigint", ["unsigned" => true, 'autoincrement' => true]);
        $myTable->addColumn("name", "string", ["length" => 85]);
        $myTable->addColumn("doid", "bigint", ["unsigned" => true, "default" => 0]);
        $myTable->addColumn("runtype", "smallint", ["unsigned" => true, "default" => 0]);
        $myTable->addColumn("params", "json", ['platformOptions' => ['jsonb' => true]]);
        $myTable->addColumn("content", "text");
        $myTable->addColumn("result", "text");
        $myTable->addColumn("timeadd", "datetime");
        $myTable->addColumn("timetorun", "datetime", ["default" => '9999-01-01 01:01:01']);
        $myTable->addColumn("timerun", "datetime", ["default" => '1970-01-01 01:01:01']);
        $myTable->addColumn("intervalms", "integer", ["unsigned" => true, "default" => 0]);

        $myTable->addIndex(['timeadd', 'name']);
        $myTable->addIndex(['runtype', 'name']);
        $myTable->addIndex(['timetorun', 'runtype']);

        $myTable->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create()
        );

        $myTable->setComment('tasks');

        $database = $task->getDatabase();
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
                if (!$database->createSchemaManager()->tablesExist([$task->table])) {
                    throw $e;
                }
            }
        }
    }
}
