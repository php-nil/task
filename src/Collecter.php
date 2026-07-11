<?php

namespace NilTask;

use RuntimeException;

/**
 * 任务收集器
 *
 * 用于收集和管理任务回调函数，支持多级收集器链
 */
class Collecter
{
    /** @var array<string, callable> 已注册的任务列表 */
    protected array $list = [];

    /** @var Collecter[] 子收集器列表 */
    protected array $collecter = [];

    /**
     * 注册一个任务
     *
     * @param string $name 任务名称
     * @param callable $action 任务回调函数
     * @return $this
     */
    public function set(string $name, callable $action): Collecter
    {
        $this->list[$name] = $action;

        return $this;
    }

    /**
     * 添加一个任务（不允许重复）
     *
     * @param string $name 任务名称
     * @param callable $action 任务回调函数
     * @return $this
     * @throws RuntimeException 当任务名称已存在时抛出
     */
    public function add(string $name, callable $action): Collecter
    {
        if (isset($this->list[$name])) {
            throw new RuntimeException(
                \sprintf('task name of \'%s\' is exist!', $name)
            );
        }

        return $this->set($name, $action);
    }

    /**
     * 获取任务回调函数
     *
     * @param string $name 任务名称
     * @return callable|null 任务回调函数，不存在返回 null
     */
    public function get(string $name): ?callable
    {
        if (isset($this->list[$name])) {
            return $this->list[$name];
        }

        foreach ($this->collecter as $collecter) {
            if (null !== ($action = $collecter->get($name))) {
                return $this->list[$name] = $action;
            }
        }

        return null;
    }

    /**
     * 检查任务是否存在
     *
     * @param string $name 任务名称
     * @return bool 存在返回 true，否则返回 false
     */
    public function has(string $name): bool
    {
        if (isset($this->list[$name])) {
            return true;
        }

        foreach ($this->collecter as $collecter) {
            if ($collecter->has($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 添加子收集器
     *
     * @param Collecter $collecter 子收集器实例
     * @param bool $prepend 是否添加到最前面
     * @return $this
     */
    public function addCollector(Collecter $collecter, bool $prepend = false): Collecter
    {
        if (false === $prepend) {
            $this->collecter[] = $collecter;
        } else {
            array_unshift($this->collecter, $collecter);
        }

        return $this;
    }

    /**
     * 添加子收集器（兼容旧方法名）
     *
     * @deprecated 请使用 addCollector() 方法
     * @param Collecter $collecter 子收集器实例
     * @param bool $prepend 是否添加到最前面
     * @return $this
     */
    public function addCollectr(Collecter $collecter, bool $prepend = false): Collecter
    {
        return $this->addCollector($collecter, $prepend);
    }
}
