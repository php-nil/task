<?php

namespace NilTask;

use Nil\Kernel\EventCollectorInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use const Nil\Kernel\DEFAULT_NAME;

/**
 * 任务管理器
 * 全局单例模式
 */
final class TaskManager implements EventCollectorInterface
{
    /**
     * 事件收集器
     *
     * @var Collecter
     */
    protected static Collecter $collecter;

    /**
     * 任务实例
     *
     * @var Task
     */
    protected static Task $task;

    /**
     * 计划任务实例
     *
     * @var Plan\PlanTask
     */
    protected static Plan\PlanTask $planTask;

    protected static ?string $dbtable = null;
    protected static ?string $dbname = null;

    /**
     * 默认任务表名
     */
    public const string DEFAULT_TABLE_NAME = 'task';

    /**
     * 自动计划任务表名
     * 实际表名在 任务表名后面添加 PLAN_TABLE_NAME 后缀
     */
    public const string PLAN_TABLE_NAME = '_plan';

    /**
     * 收集事件
     *
     * @param string|\Closure ...$events 事件名称或闭包
     * @return void
     * @throws \Exception 当事件类不存在或未实现 TaskCollectInterface 接口时抛出
     */
    public static function collect(string|\Closure ...$events): void
    {
        $collecter = self::getCollecter();

        foreach ($events as $event) {
            if ($event instanceof \Closure) {
                $event($collecter);
                continue;
            }

            // 事件类
            if (class_exists($event) && is_subclass_of($event, TaskCollectInterface::class)) {
                $event::collect($collecter);
            } else {
                throw new \Exception("event{$event} class not found or not implements TaskCollectInterface!");
            }
        }
    }

    /**
     * 获取事件收集器
     *
     * @return Collecter
     */
    public static function getCollecter(): Collecter
    {
        return self::$collecter ??= new Collecter();
    }

    /**
     * 设置任务表名和数据库连接名
     *
     * @param string|null $dbtable 任务表名
     * @param string|null $dbname 数据库连接名
     */
    public static function setTable(?string $dbtable = null, ?string $dbname = null)
    {
        self::$dbtable = $dbtable;
        self::$dbname = $dbname;
    }

    /**
     * 获取任务表名
     *
     * @return string
     */
    public static function getTable(): string
    {
        return self::$dbtable ?? self::DEFAULT_TABLE_NAME;
    }

    /**
     * 获取计划任务表名
     *
     * @return string
     */
    public static function getPlanTable(): string
    {
        return self::getTable() . self::PLAN_TABLE_NAME;
    }

    /**
     * 获取数据库连接名
     *
     * @return string
     */
    public static function getDbName(): string
    {
        return self::$dbname ?? DEFAULT_NAME;
    }

    /**
     * 获取任务实例
     *
     * 首次实例化时自动把 FqcnCollecter 作为默认兜底收集器注入收集器链
     * （短任务名均未命中时，按任务类完整类名解析执行）。
     *
     * @return Task
     */
    public static function getTask(): Task
    {
        return self::$task ??= self::createTask();
    }

    /**
     * 创建任务实例（注入默认兜底收集器，仅执行一次）
     */
    private static function createTask(): Task
    {
        $collecter = self::getCollecter();
        $collecter->addCollecter(new FqcnCollecter());

        return new Task($collecter, self::getTable(), self::getDbName());
    }

    /**
     * 获取计划任务实例
     *
     * 计划表名由任务表名追加 PLAN_TABLE_NAME 后缀得到，
     * 与 Task 一样由本管理器统一创建并传入表名与连接名。
     *
     * @return Plan\PlanTask
     */
    public static function getPlanTask(): Plan\PlanTask
    {
        return self::$planTask ??= new Plan\PlanTask(
            self::getPlanTable(),
            self::getDbName()
        );
    }

    /**
     * 注册到内核启动事件中
     *
     * @return void
     */
    public static function kernelEvent(EventDispatcher $dispatcher): void
    {
        $task = self::getTask();

        $dispatcher->addListener(
            'kernel.console',
            fn($event) => $event->add(new TaskCommand($task))
        );
    }
}
