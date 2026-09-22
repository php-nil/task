<?php

namespace NilTask;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Nil\Kernel\Kernel;

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

    /** @var int 并发命名锁等待超时（秒） */
    public const int RUNNING_LOCK_TIMEOUT = 10;

    /**
     * 构造函数
     * @param Collecter $collecter 事件收集器
     * @param string $table 任务表名
     * @param string $database 数据库连接名
     */
    public function __construct(public readonly Collecter $collecter, public readonly string $table, string $database)
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
        try {
            $i = $this->database->insert($this->table, $this->buildAddData($name, $doid, $timetorun, $params, $content));
        } catch (TableNotFoundException $th) {
            TableInit::init($this);
            $i = $this->database->insert($this->table, $this->buildAddData($name, $doid, $timetorun, $params, $content));
        }

        return ($i == 1) ? $this->database->lastInsertId() : false;
    }

    /**
     * 构造任务插入数据
     *
     * @param string $name 任务名称
     * @param int $doid 任务ID
     * @param int $timetorun 延迟执行时间（秒）
     * @param array $params 任务参数
     * @param string $content 任务内容描述
     * @return array<string,mixed>
     */
    private function buildAddData(string $name, int $doid, int $timetorun, array $params, string $content): array
    {
        return [
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
    }

    /**
     * 条件添加任务：同 name + doid 存在未完成任务时跳过
     *
     * 是 add() 与 isRunning() 的原子组合：仅当不存在 runtype 为
     * 未执行(0)/执行中(9) 的同名同 doid 任务时才新增；已成功(1)/失败(2)
     * 的历史任务不影响再次添加。
     *
     * 并发安全：
     * - 事务内先获取数据库命名锁（MySQL GET_LOCK / PostgreSQL
     *   pg_advisory_xact_lock / SQL Server sp_getapplock），串行化同一
     *   (name,doid) 的并发请求（即使记录尚不存在），再加锁复查后插入；
     * - 唯一键冲突视为抢占失败返回 false，死锁/锁等待超时自动重试一次；
     * - SQLite 等无命名锁的平台依赖库级写互斥 + 锁冲突重试兜底。
     *
     * 注意：应在无外层事务时直接调用（方法自行管理事务）。PostgreSQL /
     * SQL Server 的命名锁为事务级，外层事务包裹时仍有效；MySQL 的
     * GET_LOCK 为会话级，外层事务包裹时需依赖 InnoDB 在 REPEATABLE READ
     * 下的间隙锁防幻插。
     *
     * @param string $name 任务名称
     * @param int $doid 任务ID（默认0）
     * @param int $timetorun 延迟执行时间（秒，默认0表示立即执行）
     * @param array $params 任务参数（默认空数组）
     * @param string $content 任务内容描述（默认空字符串）
     * @return int|false 新任务ID；已存在未完成任务（或抢占失败）时返回 false
     */
    public function addIfNotRunning(string $name, int $doid = 0, int $timetorun = 0, array $params = [], string $content = ''): int|false
    {
        // 快速路径：已存在未完成任务直接跳过（表不存在时此处会自动建表）
        if ($this->isRunning($name, $doid)) {
            return false;
        }

        $platform = $this->database->getDatabasePlatform();
        $lockKey = 'nil_task:' . md5($this->table . '|' . $name . '|' . $doid);

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $needRelease = false;
            $this->database->beginTransaction();

            try {
                // 1. 命名锁：串行化同一 (name,doid) 的并发
                $needRelease = $this->acquireRunningLock($lockKey);

                // 2. 锁内加锁复查，避免「先查后插」竞态
                $from = $this->table;
                $tail = '';

                if ($platform instanceof SQLServerPlatform) {
                    $from = $platform->appendLockHint($from, LockMode::PESSIMISTIC_WRITE);
                } elseif ($platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform) {
                    $tail = ' FOR UPDATE';
                }

                $sql = $platform->modifyLimitQuery(
                    'SELECT id FROM ' . $from
                    . ' WHERE runtype IN (0, 9) AND name = ? AND doid = ?',
                    1
                ) . $tail;

                if (false !== $this->database->fetchOne($sql, [$name, $doid])) {
                    $this->database->commit();

                    return false;
                }

                // 3. 确认不存在，插入新任务
                $affected = $this->database->insert(
                    $this->table,
                    $this->buildAddData($name, $doid, $timetorun, $params, $content)
                );

                $this->database->commit();

                return (1 === $affected) ? (int) $this->database->lastInsertId() : false;
            } catch (UniqueConstraintViolationException) {
                // 并发下被其他请求抢先插入（仅在存在额外唯一索引时触发）
                $this->rollbackTransaction();

                return false;
            } catch (TableNotFoundException $e) {
                $this->rollbackTransaction();
                TableInit::init($this);

                if (2 === $attempt) {
                    throw $e;
                }
            } catch (DeadlockException | LockWaitTimeoutException | \RuntimeException $e) {
                // 死锁、锁等待超时（含 SQLite "database is locked"）、
                // 命名锁获取失败（GET_LOCK/sp_getapplock）均可重试一次
                $this->rollbackTransaction();

                if (2 === $attempt) {
                    throw $e;
                }
            } catch (\Throwable $e) {
                // 其余错误也要保证事务被回滚，避免连接上残留事务
                $this->rollbackTransaction();

                throw $e;
            } finally {
                if ($needRelease) {
                    $this->releaseRunningLock($lockKey);
                }
            }
        }

        return false;
    }

    /**
     * 回滚当前事务（容错：回滚本身失败时仅记录日志，不掩盖原异常）
     */
    private function rollbackTransaction(): void
    {
        try {
            $this->database->rollBack();
        } catch (\Throwable $th) {
            Kernel::log('task')->warning('addIfNotRunning rollback failed', [$th->getMessage()]);
        }
    }

    /**
     * 获取并发命名锁
     *
     * 各数据库实现：
     * - MySQL/MariaDB：GET_LOCK（会话级，需在事务结束后显式 RELEASE_LOCK）
     * - PostgreSQL：pg_advisory_xact_lock（事务结束自动释放）
     * - SQL Server：sp_getapplock（事务结束自动释放）
     * - SQLite 等：不支持命名锁，返回 false，依赖库级写互斥 + 重试
     *
     * @param string $key 锁键
     * @return bool 是否需要调用 releaseRunningLock() 显式释放
     * @throws \RuntimeException 锁等待超时或获取失败时抛出
     */
    private function acquireRunningLock(string $key): bool
    {
        $platform = $this->database->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $ret = $this->database->fetchOne(
                'SELECT GET_LOCK(?, ?)',
                [$key, self::RUNNING_LOCK_TIMEOUT]
            );

            if (1 !== (int) $ret) {
                throw new \RuntimeException(\sprintf(
                    'TASK acquire running lock failed, key:%s, ret:%s',
                    $key,
                    (string) $ret
                ));
            }

            return true;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $this->database->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                [$key]
            );

            return false;
        }

        if ($platform instanceof SQLServerPlatform) {
            $ret = $this->database->fetchOne(
                "EXEC sp_getapplock @Resource = ?, @LockMode = 'Exclusive',"
                . " @LockOwner = 'Transaction', @LockTimeout = ?, @DbPrincipal = 'public'",
                [$key, self::RUNNING_LOCK_TIMEOUT * 1000]
            );

            if (0 > (int) $ret) {
                throw new \RuntimeException(\sprintf(
                    'TASK acquire running lock failed, key:%s, ret:%s',
                    $key,
                    (string) $ret
                ));
            }

            return false;
        }

        return false;
    }

    /**
     * 释放并发命名锁（仅 MySQL/MariaDB 的会话锁需要显式释放）
     *
     * @param string $key 锁键
     */
    private function releaseRunningLock(string $key): void
    {
        try {
            $this->database->executeStatement('SELECT RELEASE_LOCK(?)', [$key]);
        } catch (\Throwable $th) {
            Kernel::log('task')->warning('releaseRunningLock failed', [$key, $th->getMessage()]);
        }
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

        $isT = $this->database->isTransactionActive();

        $isT && $this->database->createSavepoint(self::POINT_NAME);

        try {
            $ret = $this->database->$func($sql, $params);
            $isT && $this->database->releaseSavepoint(self::POINT_NAME);
        } catch (TableNotFoundException $th) {
            Kernel::log('task')->info('fetchHandel TableNotFound', [$th->getMessage()]);
            $isT && $this->database->rollbackSavepoint(self::POINT_NAME);
            TableInit::init($this);
            return false;
        } catch (\Throwable $th) {
            $isT && $this->database->rollbackSavepoint(self::POINT_NAME);
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
