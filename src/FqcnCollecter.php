<?php

namespace NilTask;

/**
 * 完整类名（FQCN）任务解析器（默认兜底收集器）
 *
 * 当主收集器及其子收集器都无法按短任务名解析时，本收集器尝试把名称
 * 当作任务类的完整类名加载并执行（要求其实现 TaskClassInterface）。
 *
 * 仅对包含命名空间分隔符且可加载的合法任务类生效，普通短任务名不会被
 * 拦截。由 TaskManager 在首次实例化 Task 时自动注入收集器链末尾，
 * 无需调用方显式装配。
 */
final class FqcnCollecter extends Collecter
{
    /**
     * 按名称获取任务回调
     *
     * @param string $name 任务名称（此处为任务类 FQCN）
     * @return callable|null 任务回调，不匹配返回 null
     */
    public function get(string $name): ?callable
    {
        if (null !== ($action = parent::get($name))) {
            return $action;
        }

        if (!str_contains($name, '\\') || !class_exists($name)) {
            return null;
        }

        if (!is_subclass_of($name, TaskClassInterface::class)) {
            return null;
        }

        $action = [$name, 'run'];
        $this->set($name, $action);

        return $action;
    }

    /**
     * 检查任务是否存在
     *
     * @param string $name 任务名称
     */
    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }
}
