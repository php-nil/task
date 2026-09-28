<?php

namespace NilTask\Plan;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Nil\Kernel\Kernel;
use NilTask\TaskClassInterface;

/**
 * 计划任务管理服务
 *
 * 提供计划任务的增、删、改、查、启停等管理接口，供调用方操作。
 * 实例由 TaskManager::getPlanTask() 创建，计划表名与数据库连接由其传入。
 */
final class PlanTask
{
    /**
     * @var Connection 数据库连接实例
     */
    private Connection $database;

    /**
     * @param string $table 计划表名
     * @param string $database 数据库连接名
     */
    public function __construct(public readonly string $table, string $database)
    {
        $this->database = Kernel::dbal($database);
    }

    /**
     * 获取数据库连接实例
     */
    public function getDatabase(): Connection
    {
        return $this->database;
    }

    /**
     * 新增计划任务
     *
     * @param string $name 计划名称（同名校验唯一）
     * @param string $taskClass 调用的任务类（须实现 TaskClassInterface）
     * @param string $runtime 任务执行时间，如 10:23
     * @param bool $enabled 是否启用
     * @param array<string,mixed> $cycle 周期，如 {"week":1,"day":2,"month":3}，空数组表示每天
     * @return int|false 新计划ID，失败返回 false
     */
    public function add(string $name, string $taskClass, string $runtime, bool $enabled = true, array $cycle = []): int|false
    {
        self::assertTaskClass($taskClass);
        $runtime = self::normalizeRuntime($runtime);
        $cycle = Cycle::fromArray($cycle);

        try {
            $i = $this->database->insert(
                $this->table,
                $this->buildData($name, $taskClass, $runtime, $enabled, $cycle)
            );
        } catch (TableNotFoundException) {
            PlanTableInit::init($this);
            $i = $this->database->insert(
                $this->table,
                $this->buildData($name, $taskClass, $runtime, $enabled, $cycle)
            );
        }

        return (1 === $i) ? (int) $this->database->lastInsertId() : false;
    }

    /**
     * 修改计划任务（仅更新 $data 中提供的字段）
     *
     * @param int $id 计划ID
     * @param array<string,mixed> $data 可含 name/taskclass/runtime/enabled/cycle
     * @return int 受影响行数
     */
    public function update(int $id, array $data): int
    {
        $save = [];

        if (array_key_exists('name', $data)) {
            $save['name'] = (string) $data['name'];
        }

        if (array_key_exists('taskclass', $data)) {
            self::assertTaskClass((string) $data['taskclass']);
            $save['taskclass'] = (string) $data['taskclass'];
        }

        if (array_key_exists('runtime', $data)) {
            $save['runtime'] = self::normalizeRuntime((string) $data['runtime']);
        }

        if (array_key_exists('enabled', $data)) {
            $save['enabled'] = $data['enabled'] ? 1 : 0;
        }

        if (array_key_exists('cycle', $data)) {
            $cycle = Cycle::fromArray(is_array($data['cycle']) ? $data['cycle'] : []);
            $save['cycle'] = json_encode($cycle->toArray(), JSON_UNESCAPED_UNICODE);
        }

        if ([] === $save) {
            return 0;
        }

        return $this->database->update($this->table, $save, ['id' => $id]);
    }

    /**
     * 启用 / 停用计划任务
     *
     * @param int $id 计划ID
     * @param bool $enabled 是否启用
     */
    public function setEnabled(int $id, bool $enabled): int
    {
        return $this->update($id, ['enabled' => $enabled]);
    }

    /**
     * 删除计划任务
     *
     * @param int $id 计划ID
     */
    public function delete(int $id): int
    {
        try {
            return $this->database->delete($this->table, ['id' => $id]);
        } catch (TableNotFoundException) {
            return 0;
        }
    }

    /**
     * 按ID获取计划任务
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $row = $this->fetch('*', 'id = ?', [$id]);

        return false === $row ? null : $this->map($row);
    }

    /**
     * 按名称获取计划任务
     *
     * @return array<string,mixed>|null
     */
    public function getByName(string $name): ?array
    {
        $row = $this->fetch('*', 'name = ?', [$name]);

        return false === $row ? null : $this->map($row);
    }

    /**
     * 获取计划任务列表
     *
     * @param bool $enabledOnly 是否仅取启用的计划
     * @return array<int,array<string,mixed>>
     */
    public function getList(bool $enabledOnly = false): array
    {
        if ($enabledOnly) {
            $rows = $this->fetchAll('*', 'enabled = ?', [1]);
        } else {
            $rows = $this->fetchAll('*');
        }

        return array_map(fn(array $row) => $this->map($row), $rows);
    }

    /**
     * 获取所有已启用的计划任务
     *
     * @return array<int,array<string,mixed>>
     */
    public function getEnabledList(): array
    {
        return $this->getList(true);
    }

    /**
     * 构造插入数据
     *
     * @return array<string,mixed>
     */
    private function buildData(string $name, string $taskClass, string $runtime, bool $enabled, Cycle $cycle): array
    {
        return [
            'name' => $name,
            'taskclass' => $taskClass,
            'runtime' => $runtime,
            'enabled' => $enabled ? 1 : 0,
            'cycle' => json_encode($cycle->toArray(), JSON_UNESCAPED_UNICODE)
        ];
    }

    /**
     * 读取单条记录（表不存在时自动建表并返回 false）
     *
     * @return array<string,mixed>|false
     */
    private function fetch(string $field, string $where, array $params): array|false
    {
        $sql = $this->database->getDatabasePlatform()
            ->modifyLimitQuery(
                'SELECT ' . $field . ' FROM ' . $this->table
                . ('' !== $where ? ' WHERE ' . $where : ''),
                1
            );

        try {
            return $this->database->fetchAssociative($sql, $params);
        } catch (TableNotFoundException) {
            PlanTableInit::init($this);

            return false;
        }
    }

    /**
     * 读取多条记录（表不存在时自动建表并返回空数组）
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchAll(string $field, string $where = '', array $params = []): array
    {
        $sql = 'SELECT ' . $field . ' FROM ' . $this->table
            . ('' !== $where ? ' WHERE ' . $where : '')
            . ' ORDER BY id ASC';

        try {
            return $this->database->fetchAllAssociative($sql, $params);
        } catch (TableNotFoundException) {
            PlanTableInit::init($this);

            return [];
        }
    }

    /**
     * 记录输出归一化
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function map(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['enabled'] = (bool) (int) $row['enabled'];
        $cycle = json_decode((string) $row['cycle'], true);
        $row['cycle'] = is_array($cycle) ? $cycle : [];

        return $row;
    }

    /**
     * 校验任务类：必须存在且实现 TaskClassInterface
     *
     * @throws \InvalidArgumentException
     */
    public static function assertTaskClass(string $taskClass): void
    {
        if (!class_exists($taskClass) || !is_subclass_of($taskClass, TaskClassInterface::class)) {
            throw new \InvalidArgumentException(\sprintf(
                'plan task class "%s" not exists or not implements %s',
                $taskClass,
                TaskClassInterface::class
            ));
        }
    }

    /**
     * 校验并归一化执行时间（HH:MM，00:00-23:59）
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeRuntime(string $runtime): string
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $runtime)) {
            throw new \InvalidArgumentException(\sprintf(
                'runtime "%s" must be HH:MM between 00:00 and 23:59',
                $runtime
            ));
        }

        return $runtime;
    }
}
