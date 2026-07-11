<?php

namespace NilTask;

use Nil\Result;

/**
 * 任务类抽象基类
 *
 * 提供任务执行的默认实现，子类只需实现 task() 方法
 */
abstract class TaskClassAbstract implements TaskClassInterface
{
    /**
     * 执行任务（静态入口）
     *
     * @param Queue $queue 任务队列实例
     * @return Result 执行结果
     */
    public static function run(Queue $queue): Result
    {
        return new static($queue)->task();
    }

    /**
     * 构造函数
     *
     * @param Queue $queue 任务队列实例
     */
    protected function __construct(public readonly Queue $queue)
    {
    }

    /**
     * 在此定义具体的任务运行逻辑
     *
     * @return Result 执行结果
     */
    abstract protected function task(): Result;
}
