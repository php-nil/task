<?php

namespace NilTask;

/**
 * 任务收集接口
 *
 * 应用通过实现此接口注册任务
 */
interface TaskCollectInterface
{
    /**
     * 收集任务
     *
     * @param Collecter $collecter 任务收集器实例
     */
    public static function collect(Collecter $collecter):void;
}
