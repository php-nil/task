<?php

namespace NilTask;

use RuntimeException;

/**
 * 类任务收集器
 *
 * 根据命名空间和前缀自动发现并收集任务类
 */
class ClassCollecter extends Collecter
{
    /** @var int 前缀长度 */
    protected int $len;

    /**
     * 构造函数
     *
     * @param string $namespace 任务类所在的命名空间
     * @param string $prefix 任务名称前缀（可选）
     */
    public function __construct(public readonly string $namespace, public readonly string $prefix = '')
    {
        $this->len = \strlen($prefix);
    }

    /**
     * 根据任务名称获取对应的类方法回调
     *
     * @param string $name 任务名称
     * @return callable|null 任务回调函数，不匹配返回 null
     * @throws RuntimeException 当类存在但未实现 TaskClassInterface 时抛出
     */
    protected function getAction(string $name): ?callable
    {
        if (0 !== $this->len && substr($name, 0, $this->len) !== $this->prefix) {
            return null;
        }

        $class = "{$this->namespace}\\";
        if (0 !== $this->len) {
            $class .= substr($name, $this->len);
        }

        if (!class_exists($class)) {
            return null;
        }

        if (is_subclass_of($class, TaskClassInterface::class)) {
            $action = [$class, 'run'];
            $this->set($name, $action);

            return $action;
        }

        throw new RuntimeException(
            \sprintf('Task action \'%s\' must instanceof TaskClassInterface', $class)
        );
    }

    /**
     * 获取任务回调函数
     *
     * @param string $name 任务名称
     * @return callable|null 任务回调函数，不存在返回 null
     */
    public function get(string $name): ?callable
    {
        if (null !== ($action = parent::get($name))) {
            return $action;
        }

        return $this->getAction($name);
    }

    /**
     * 检查任务是否存在
     *
     * @param string $name 任务名称
     * @return bool 存在返回 true，否则返回 false
     */
    public function has(string $name): bool
    {
        if (parent::has($name)) {
            return true;
        }

        return (null !== $this->getAction($name));
    }
}
