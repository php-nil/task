<?php

namespace NilTask;

use Nil\Result;

/**
 * 任务类接口
 *
 * 所有任务类必须实现此接口
 */
interface TaskClassInterface
{
    /**
     * 执行任务
     *
     * @param Queue $queue 任务队列实例
     * @return Result 执行结果
     */
    public static function run(Queue $queue): Result;
}
