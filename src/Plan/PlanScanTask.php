<?php

namespace NilTask\Plan;

use Nil\Kernel\Kernel;
use Nil\Result;
use NilTask\TaskClassAbstract;
use NilTask\TaskManager;

/**
 * 每日计划扫描任务
 *
 * 它本身就是任务队列中的一个任务（继承 TaskClassAbstract），
 * 由持续轮询的任务执行器每天 00:00 拉起：
 *
 *   读取已启用计划 → 命中周期的任务按当日 HH:MM 入队 → 次日扫描任务入队
 *
 * 扫描任务的 doid 为目标日期序号（距 1970-01-01 的天数），每天至多一条。
 * 不会自动创建：需调用方在代码中手动调用 ensureScheduled()，
 * 扫描任务被创建即代表启用每日自动调度。
 */
final class PlanScanTask extends TaskClassAbstract
{
    /**
     * 执行每日扫描
     *
     * @return Result 值为字符串摘要
     */
    protected function task(): Result
    {
        $scanner = new PlanScanner(TaskManager::getPlanTask(), TaskManager::getTask());

        // 先保证扫描链不断（即使当日扫描失败也会延续到次日）
        try {
            self::enqueueDate(new \DateTimeImmutable('tomorrow'));
        } catch (\Throwable $th) {
            Kernel::log('task')->warning('plan scanner selfSchedule failed', [$th->getMessage()]);
        }

        try {
            $report = $scanner->scan();

            return Result::ok(PlanScanner::summary($report));
        } catch (\Throwable $th) {
            return Result::throwableTrace($th);
        }
    }

    /**
     * 安装扫描任务：检测当日扫描任务是否存在，不存在则创建（立即补扫）
     *
     * 供调用方在代码中手动显式调用，扫描任务被创建即代表启用了每日自动调度。
     * 重复调用安全。
     *
     * @return int|false 扫描任务ID，已存在待执行/执行中任务时返回 false
     */
    public static function ensureScheduled(): int|false
    {
        return self::enqueueDate(new \DateTimeImmutable('today'));
    }

    /**
     * 把指定日期凌晨的扫描任务加入队列
     *
     * @param \DateTimeInterface $date 目标日期
     * @return int|false 扫描任务ID，已存在时返回 false
     */
    private static function enqueueDate(\DateTimeInterface $date): int|false
    {
        $date = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        return TaskManager::getTask()->addIfNotRunning(
            self::class,
            Cycle::dayOrdinal($date),
            $date->getTimestamp() - time(),
            [],
            'plan scanner daily'
        );
    }
}
