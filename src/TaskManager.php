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

    protected static ?string $dbtable = null;
    protected static ?string $dbname = null;

    public const string DEFAULT_TABLE_NAME = 'task';

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
     * @return Task
     */
    public static function getTask(): Task
    {
        return self::$task ??= new Task(self::getCollecter(), self::getTable(), self::getDbName());
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
