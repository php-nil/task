<?php

namespace NilTask;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Nil\Kernel\EventAppInterface;
use Nil\Kernel\Kernel;
use Nil\Nil;
use Symfony\Component\EventDispatcher\EventDispatcher;
use const Nil\Kernel\DEFAULT_NAME;

/**
 * 任务管理器
 *
 * 提供任务的添加、查询、删除、执行等核心功能，支持单例模式和数据库操作
 */
final class Task
{
    /** @var Connection 数据库连接实例 */
    protected Connection $database;

    /** @var string 事务保存点名称 */
    public const string POINT_NAME = 'try_query';

    /**
     * 构造函数
     * @param Collecter $collecter 事件收集器
     * @param string $table 任务表名
     * @param string $database 数据库连接名
     */
    protected function __construct(public readonly Collecter $collecter, public readonly string $table, string $database)
    {
        $this->database = Kernel::dbal($database);
    }

    /**
     * 获取数据库连接实例
     *
     * @return Connection 数据库连接
     */
    public function getDatabase(): Connection
    {
        return $this->database;
    }

    /**
     * 获取任务表名
     *
     * @return string 表名
     */
    public function getDatabaseTable(): string
    {
        return $this->table;
    }

    /**
     * 添加一个任务
     *
     * @param string $name 任务名称
     * @param int $doid 任务ID（默认0）
     * @param int $timetorun 延迟执行时间（秒，默认0表示立即执行）
     * @param array $params 任务参数（默认空数组）
     * @param string $content 任务内容描述（默认空字符串）
     * @return int|false 任务ID或失败返回 false
     */
    public function add(string $name, int $doid = 0, int $timetorun = 0, array $params = [], string $content = ''): int|false
    {
        $data = [
            'timeadd' => date('Y-m-d H:i:s'),
            'timetorun' => date('Y-m-d H:i:s', time() + $timetorun),
            'timerun' => '0001-01-01 00:00:00',
            'runtype' => 0,
            'name' => $name,
            'doid' => $doid,
            'params' => \json_encode($params),
            'content' => $content,
            'result' => 'not run',
            'intervalms' => 0
        ];

        try {
            $i = $this->database->insert($this->table, $data);
        } catch (TableNotFoundException $th) {
            TableInit::init($this);
            $i = $this->database->insert($this->table, $data);
        }

        return ($i == 1) ? $this->database->lastInsertId() : false;
    }

    /**
     * 查询数据的内部处理方法
     *
     * @param string $field 查询字段
     * @param string $where WHERE 条件
     * @param array $params 参数绑定
     * @param string $func 查询方法名
     * @return mixed 查询结果
     */
    private function fetchHandel(string $field, string $where, array $params, string $func): mixed
    {
        $sql = 'SELECT ' . $field . ' FROM ' . $this->table;

        if (!empty($where)) {
            $sql .= ' WHERE ' . $where;
        }

        $sql = $this->database->getDatabasePlatform()
            ->modifyLimitQuery($sql, 1);

        $this->database->createSavepoint(self::POINT_NAME);

        try {
            $ret = $this->database->$func($sql, $params);
            $this->database->releaseSavepoint(self::POINT_NAME);
        } catch (TableNotFoundException $th) {
            $this->database->rollbackSavepoint(self::POINT_NAME);
            TableInit::init($this);

            return false;
        } catch (\Throwable $th) {
            throw $th;
        }

        return $ret;
    }

    /**
     * 删除任务数据
     *
     * @param string $where WHERE 条件
     * @param bool $isProtect 是否保护（防止误删全部数据）
     * @return int 受影响行数
     * @throws \Error 当条件为空且保护开启时抛出
     */
    public function delete(string $where, bool $isProtect = true): int
    {
        if (empty($where)) {
            if ($isProtect) {
                throw new \Error("Delete All is Protected!");
            }

            $sql = 'TRUNCATE ' . $this->table;
        } else {
            $sql = 'DELETE FROM ' . $this->table . ' WHERE ' . $where;
        }

        return $this->database->executeStatement($sql);
    }

    /**
     * 获取单个字段值
     *
     * @param string $field 查询字段
     * @param string $where WHERE 条件（可选）
     * @param array $params 参数绑定（可选）
     * @return mixed 查询结果
     */
    public function fetchOne(string $field, string $where = '', array $params = []): mixed
    {
        return $this->fetchHandel($field, $where, $params, 'fetchOne');
    }

    /**
     * 获取一条完整记录
     *
     * @param string $field 查询字段
     * @param string $where WHERE 条件（可选）
     * @param array $params 参数绑定（可选）
     * @return mixed 查询结果
     */
    public function fetch(string $field, string $where = '', array $params = []): mixed
    {
        return $this->fetchHandel($field, $where, $params, 'fetchAssociative');
    }

    /**
     * 获取一条待执行的任务记录
     *
     * @param int $offset 时间偏移（秒）
     * @return mixed 任务记录或 false
     */
    public function fetchRunByOffset(int $offset): mixed
    {
        return $this->fetch(
            '*',
            'timetorun <= ? AND runtype = ? ORDER BY timetorun ASC',
            [date('Y-m-d H:i:s', time() + $offset), 0]
        );
    }

    /**
     * 统计待执行任务数量
     *
     * @param int|null $time 时间范围（秒），null 表示不限制
     * @return mixed 任务数量
     */
    public function countRun(?int $time = null): mixed
    {
        $where = 'runtype = 0';
        $param = [];

        if (null !== $time) {
            $where .= 'AND timetorun < ?';
            $param[] = date('Y-m-d H:i:s', time() + $time);
        }

        return $this->fetchOne('COUNT(*)', $where, $param);
    }

    /**
     * 判断任务是否正在运行
     *
     * @param string $name 任务名称
     * @param int $doId 任务ID（默认0）
     * @param int|null $timetorun 时间范围（秒），null 表示不限制
     * @return bool 是否正在运行
     */
    public function isRunning(string $name, int $doId = 0, ?int $timetorun = null): bool
    {
        $where = 'runtype IN (0,9) AND name = ? AND doid = ?';
        $param = [$name, $doId];

        if (null !== $timetorun) {
            $where .= 'AND timetorun < ?';
            $param[] = date('Y-m-d H:i:s', time() + $timetorun);
        }

        $id = $this->fetchOne('id', $where, $param);

        return is_numeric($id);
    }

    /**
     * 获取即将运行的任务时间
     *
     * @param string $name 任务名称
     * @param int $doId 任务ID（默认0）
     * @return mixed 计划执行时间或 false
     */
    public function getTimeToRun(string $name, int $doId = 0): mixed
    {
        $where = 'runtype IN (0,9) AND name = ? AND doid = ? ORDER BY timetorun ASC';
        $param = [$name, $doId];

        return $this->fetchOne('timetorun', $where, $param);
    }

    /**
     * 统计指定时间内创建的任务数量
     *
     * @param string $name 任务名称
     * @param int $doid 任务ID
     * @param int $time 时间范围（秒）
     * @param bool|null $status 状态过滤（true=成功，false=失败，null=全部）
     * @return mixed 任务数量
     */
    public function count(string $name, int $doid, int $time, ?bool $status = null): mixed
    {
        $where = 'timeadd >= ? AND name = ? AND doid = ?';
        $par = [date('Y-m-d H:i:s', time() - $time), $name, $doid];

        if (null !== $status) {
            $where .= ' AND runtype = ?';
            $par[] = $status ? 1 : 2;
        }

        return $this->fetchOne('COUNT(*)', $where, $par);
    }

    /**
     * 执行任务
     *
     * @param int $offset 时间偏移（秒）
     * @return false|Queue 执行结果或 false
     */
    public function run(int $offset = 0): false|Queue
    {
        return Queue::run($this, $offset);
    }
}
