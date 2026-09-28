<?php

namespace NilTask\Plan;

use NilTask\Task;

/**
 * 计划扫描器
 *
 * 纯扫描逻辑：读取已启用的计划任务，按周期分析后把当日需要执行的
 * 任务加入任务队列。调度（每日定时、自我延续、安装启用）由
 * PlanScanTask 负责，本类不关心何时被调用。
 */
final class PlanScanner
{
    /**
     * @param PlanTask $plan 计划任务管理服务（由 TaskManager 创建并传入表名）
     * @param Task $task 任务队列服务（命中的计划通过它入队）
     */
    public function __construct(
        private readonly PlanTask $plan,
        private readonly Task $task
    ) {
    }

    /**
     * 读取计划并生成当日任务
     *
     * @param \DateTimeInterface|null $date 目标日期（取日期部分），默认今天
     * @return array<string,mixed> 扫描报告
     */
    public function scan(?\DateTimeInterface $date = null): array
    {
        $date = \DateTimeImmutable::createFromInterface($date ?? new \DateTimeImmutable('today'))
            ->setTime(0, 0);

        $plans = $this->plan->getEnabledList();

        $report = [
            'date' => $date->format('Y-m-d'),
            'total' => count($plans),
            'matched' => 0,
            'added' => 0,
            'skipped' => 0,
            'items' => []
        ];

        foreach ($plans as $row) {
            $cycle = Cycle::fromArray($row['cycle']);

            if (!$cycle->matches($date)) {
                continue;
            }

            ++$report['matched'];

            // 当日执行时刻 HH:MM
            [$hour, $minute] = array_map('intval', explode(':', $row['runtime']));
            $when = $date->setTime($hour, $minute);

            // 相对当前时刻的偏移（已过点则为负数，入队后立即补执行）
            $offset = $when->getTimestamp() - time();
            $content = \sprintf('plan:%s@%s', $row['name'], $report['date']);

            // 以任务类 FQCN 作为队列任务名，由 FqcnCollecter 解析执行；
            // doid 用当日日期序号：每天一条独立记录，跨天互不阻塞；
            // addIfNotRunning 保证同日重复扫描 / 当日已有待执行任务时不重复入队
            $id = $this->task->addIfNotRunning(
                $row['taskclass'],
                Cycle::dayOrdinal($date),
                $offset,
                [],
                $content
            );

            if (false === $id) {
                ++$report['skipped'];
                $status = 'skipped';
            } else {
                ++$report['added'];
                $status = 'added';
            }

            $report['items'][] = [
                'plan' => $row['name'],
                'taskclass' => $row['taskclass'],
                'timetorun' => $when->format('Y-m-d H:i'),
                'id' => false === $id ? null : $id,
                'status' => $status
            ];
        }

        return $report;
    }

    /**
     * 生成字符串摘要
     *
     * @param array<string,mixed> $report 扫描报告
     */
    public static function summary(array $report): string
    {
        return \sprintf(
            'plan scan date:%s total:%d matched:%d added:%d skipped:%d',
            $report['date'],
            $report['total'],
            $report['matched'],
            $report['added'],
            $report['skipped']
        );
    }
}
