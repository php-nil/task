<?php

namespace NilTask;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\TransactionIsolationLevel as Transaction;

/**
 * 批量任务队列执行器
 *
 * 在单个事务内一次原子抢占多条到期任务（runtype 未执行(0) → 执行中(9)），
 * 提交后再逐条执行并写回结果。相比 Queue::run() 每条任务「开事务 + 抢占 +
 * 提交」一轮，大量积压时可显著减少事务与锁开销。
 *
 * 独立实现，不改动 Queue::run() / Task::run() 的单条认领链路，原有 task
 * 命令与单 worker / 多 worker 行为完全不变；同一任务在两种执行器混用下
 * 仍只会被执行一次（抢占条件均带 runtype = 0）。
 */
final class BatchQueue
{
    /**
     * 批量认领并执行多个到期任务
     *
     * @param Task $task 任务管理器实例
     * @param int $limit 一次认领执行的任务数量（最小 1）
     * @param int $offset 取任务时间偏移（秒）
     * @return false|self 未认领成功任何任务时返回 false
     */
    public static function run(Task $task, int $limit = 10, int $offset = 0): false|self
    {
        $rows = self::claim($task, max(1, $limit), $offset);

        if (empty($rows)) {
            return false;
        }

        // 抢占已提交，逐条在事务外执行，长任务不持锁（与 Queue::run 一致）
        $queues = [];

        foreach ($rows as $row) {
            $queues[] = Queue::executeClaimed($task, $row);
        }

        return new self($queues);
    }

    /**
     * 单事务批量抢占到期任务
     *
     * 并发安全（与 Task::fetchRunByOffset() 同策略）：
     * - MySQL/MariaDB、PostgreSQL：SELECT ... FOR UPDATE 当前读锁定候选行，
     *   再以 runtype = 0 为条件批量 UPDATE，并发 worker 顺延/跳过，不会重领；
     * - SQL Server：WITH (UPDLOCK, ROWLOCK, READPAST) 直接跳过被锁行；
     * - SQLite 等无行锁平台：退化为普通读 + 条件 UPDATE，竞态时以 busy/
     *   锁冲突抛异常，整批回滚。
     *
     * UPDATE 后重查候选集中实际被本事务置为 9 的行作为执行集，保证抢占
     * 成功的行一定被执行，不会假死在 runtype = 9。
     *
     * @param int $limit 抢占条数
     * @param int $offset 时间偏移（秒）
     * @return array<int,array<string,mixed>> 实际抢占成功的任务记录
     */
    private static function claim(Task $task, int $limit, int $offset): array
    {
        $time = date('Y-m-d H:i:s');
        $dbal = $task->getDatabase();
        $table = $task->getDatabaseTable();
        $platform = $dbal->getDatabasePlatform();

        $oldTransaction = $dbal->getTransactionIsolation();
        if ($oldTransaction != Transaction::REPEATABLE_READ) {
            $dbal->setTransactionIsolation(Transaction::REPEATABLE_READ);
        }

        $rows = [];

        try {
            $dbal->beginTransaction();

            $fromSuffix = '';
            $querySuffix = '';

            if ($platform instanceof SQLServerPlatform) {
                $fromSuffix = ' WITH (UPDLOCK, ROWLOCK, READPAST)';
            } elseif ($platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform) {
                $querySuffix = ' FOR UPDATE';
            }

            $sql = $platform->modifyLimitQuery(
                'SELECT * FROM ' . $table . $fromSuffix
                . ' WHERE timetorun <= ? AND runtype = ? ORDER BY timetorun ASC',
                $limit
            ) . $querySuffix;

            $rows = $dbal->fetchAllAssociative($sql, [
                date('Y-m-d H:i:s', time() + $offset),
                0
            ]);

            if (!empty($rows)) {
                $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                // 条件批量抢占：仅 runtype 仍为 0 的行才会置为 9
                $dbal->executeStatement(
                    'UPDATE ' . $table . ' SET runtype = 9, timerun = ?'
                    . ' WHERE runtype = 0 AND id IN (' . $placeholders . ')',
                    array_merge([$time], $ids)
                );

                // 以「实际抢占结果」为执行集：重查候选集中被本事务置为 9
                // （timerun = 本批次时间）的行。保证被本事务抢占的行一定会
                // 被执行，不会出现 runtype=9 无人处理的假死；未抢到的行保持
                // runtype=0，后续可再被领取。
                // 行锁平台上候选行被本事务锁定，命中行必为本事务所更新；
                // 无行锁平台发生竞态时 UPDATE 会以 busy/锁冲突抛异常，
                // 整批回滚，不会误把其他 worker 同秒抢占的行当作自己的。
                $rows = $dbal->fetchAllAssociative(
                    'SELECT * FROM ' . $table
                    . ' WHERE runtype = 9 AND timerun = ? AND id IN (' . $placeholders . ')'
                    . ' ORDER BY timetorun ASC',
                    array_merge([$time], $ids)
                );
            }

            $dbal->commit();
        } catch (TableNotFoundException $e) {
            if ($dbal->isTransactionActive()) {
                $dbal->rollBack();
            }

            TableInit::init($task);

            return [];
        } catch (\Throwable $e) {
            if ($dbal->isTransactionActive()) {
                $dbal->rollBack();
            }

            return [];
        } finally {
            // setTransactionIsolation 改的是会话级隔离，常驻 worker 复用共享连接，
            // 必须保证所有路径（含异常 return）都还原，否则会污染后续所有查询。
            if ($oldTransaction != Transaction::REPEATABLE_READ) {
                $dbal->setTransactionIsolation($oldTransaction);
            }
        }

        return $rows;
    }

    /**
     * @param Queue[] $queues 本批次已执行的任务
     */
    private function __construct(private readonly array $queues)
    {
    }

    /**
     * 获取本批次执行的任务实例
     *
     * @return Queue[]
     */
    public function getQueues(): array
    {
        return $this->queues;
    }

    /**
     * 本批次实际执行的任务数量
     */
    public function count(): int
    {
        return count($this->queues);
    }

    /**
     * 获取本批次所有任务的执行结果汇总（每任务一行）
     *
     * @return string
     */
    public function getResult(): string
    {
        return implode(
            "\n",
            array_map(static fn(Queue $q): string => $q->getResult(), $this->queues)
        );
    }
}
