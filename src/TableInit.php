<?php

namespace NilTask;

use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;

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

        if (str_starts_with($queries[0], 'CREATE SCHEMA ')) {
            $queries[0] = 'CREATE SCHEMA IF NOT EXISTS ' . substr($queries[0], 14);
        }

        foreach ($queries as $q) {
            $database->executeStatement($q);
        }
    }
}
