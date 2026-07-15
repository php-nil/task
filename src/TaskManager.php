<?php

namespace NilTask;

use Nil\Kernel\EventCollectorInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use const Nil\Kernel\DEFAULT_NAME;

/**
 * 管理
 *
 */
final class TaskManager implements EventCollectorInterface
{
    /**
     * 事件收集器
     *
     * @var Collecter
     */
    protected static Collecter $collecter;

    protected static ?string $dbtable = null;
    protected static ?string $dbname = null;

    public const string DEFAULT_TABLE_NAME = 'task';

    public static function collect(string|\Closure ...$events): void
    {
        self::$collecter = new Collecter();

        foreach ($events as $event) {
            if ($event instanceof \Closure) {
                $event(self::$collecter);
                continue;
            }

            // 事件类
            if (class_exists($event) && is_subclass_of($event, TaskCollectInterface::class)) {
                $event::collect(self::$collecter);
            } else {
                throw new \Exception("event{$event} class not found or not implements TaskCollectInterface!");
            }
        }
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
     * 注册到内核启动事件
     *
     * @return void
     */
    public static function kernelEvent(EventDispatcher $dispatcher)
    {
        $task = Task::get(self::$dbtable ?? self::DEFAULT_TABLE_NAME, self::$dbname ?? DEFAULT_NAME);

        $dispatcher->addListener(
            'kernel.console',
            fn($event) => $event->add(new Command(
                self::$collecter,
                $task
            ))
        );
    }
}
