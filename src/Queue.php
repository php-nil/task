<?php

namespace NilTask;

use Doctrine\DBAL\TransactionIsolationLevel as Transaction;
use Nil\Result;

/**
 * 任务队列执行器
 *
 * 负责任务的执行、状态更新和结果处理
 */
final class Queue
{
    /**
     * 执行一个待处理的任务
     *
     * @param Task $task 任务管理器实例
     * @param int $offset 时间偏移（秒）
     * @return false|self 执行结果或 false
     */
    public static function run(Task $task, int $offset): false|self
    {
        $time = date('Y-m-d H:i:s');
        $dbal = $task->getDatabase();

        $oldTransaction = $dbal->getTransactionIsolation();
        if ($oldTransaction != Transaction::REPEATABLE_READ) {
            $dbal->setTransactionIsolation(Transaction::REPEATABLE_READ);
        }

        $dbal->beginTransaction();

        try {
            $row = $task->fetchRunByOffset($offset);

            if (false !== $row) {
                $dbal->update(
                    $task->table,
                    ['runtype' => 9, 'timerun' => $time],
                    ['id' => $row['id']]
                );
            }

            $dbal->commit();
        } catch (\Throwable $e) {
            $dbal->rollBack();

            return false;
        }

        if ($oldTransaction != Transaction::REPEATABLE_READ) {
            $dbal->setTransactionIsolation($oldTransaction);
        }

        if (false === $row) {
            return false;
        }

        $row['doid'] = (int) $row['doid'];
        $row['params'] = json_decode($row['params'], true);
        $queue = new self($task, $row);

        $t1 = microtime(true);

        try {
            $action = $task->getCollecter()->get($queue->getName());

            if (null === $action) {
                $return = Result::err(
                    \sprintf('TASK action \'%s\' not exist', $queue->getName())
                );
            } else {
                $return = \call_user_func_array($action, [$queue]);

                if (!$return instanceof Result) {
                    $return = Result::err(
                        \sprintf('TASK return is %s, must Result, ', \gettype($return))
                    );
                }
            }
        } catch (\Throwable $th) {
            $return = Result::throwableTrace($th);
        }

        $jg = (int) ((microtime(true) - $t1) * 1000);

        $queue->setResult(
            $return->isOk(),
            (string) $return->unwrapAny(),
            max(0, $jg)
        );

        return $queue;
    }

    /**
     * 构造函数
     *
     * @param Task $task 任务管理器实例
     * @param array $info 任务信息数组
     */
    private function __construct(public readonly Task $task, private array $info)
    {
    }

    /**
     * 重新添加该任务
     *
     * @param int $time 延迟执行时间（秒，默认0）
     * @param array|null $param 任务参数，null 使用原参数
     * @param string $content 任务内容描述
     * @return int|false 新任务ID或失败返回 false
     */
    public function reRun(int $time = 0, ?array $param = null, string $content = 're run'): int|false
    {
        $param = (null === $param) ? $this->info['params'] : $param;

        return $this->task->add($this->info['name'], $this->info['doid'], $time, $param, $content);
    }

    /**
     * 统计指定时间内创建的任务数量
     *
     * @param int $time 时间范围（秒）
     * @param bool|null $status 状态过滤（true=成功，false=失败，null=全部）
     * @return mixed 任务数量
     */
    public function count(int $time, ?bool $status = null): mixed
    {
        return $this->task->count(
            $this->info['name'],
            $this->info['doid'],
            $time,
            $status
        );
    }

    /** @var string 执行结果字符串 */
    protected string $result;

    /**
     * 设置任务执行结果
     *
     * @param bool $status 执行状态（true=成功，false=失败）
     * @param string $result 执行结果描述
     * @param int $intervalms 执行耗时（毫秒）
     * @return int 受影响行数
     */
    protected function setResult(bool $status, string $result, int $intervalms = 0): int
    {
        $this->info['result'] = $result;
        $this->info['intervalms'] = $intervalms;
        $this->info['runtype'] = $status ? 1 : 2;

        $this->result = $this->info['id'] . ':' . $this->info['name']
            . '(' . $this->info['runtype'] . ',' . $intervalms . 'ms):' . $result;

        return $this->task->getDatabase()->update(
            $this->task->getDatabaseTable(),
            [
                'runtype' => $this->info['runtype'],
                'result' => $result,
                'intervalms' => $intervalms
            ],
            [
                'id' => $this->info['id']
            ]
        );
    }

    /**
     * 获取执行结果字符串
     *
     * @return string 执行结果
     */
    public function getResult(): string
    {
        return $this->result ?? 'empty';
    }

    /**
     * 获取任务ID
     *
     * @return int 任务ID
     */
    public function getId(): int
    {
        return (int) $this->info['id'];
    }

    /**
     * 获取任务名称
     *
     * @return string 任务名称
     */
    public function getName(): string
    {
        return $this->info['name'];
    }

    /**
     * 获取任务DOID
     *
     * @return int DOID
     */
    public function getDoid(): int
    {
        return $this->info['doid'];
    }

    /**
     * 获取任务参数
     *
     * @return array 参数数组
     */
    public function getParams(): array
    {
        return $this->info['params'];
    }

    /**
     * 获取单个任务参数
     *
     * @param string $name 参数名称
     * @return mixed 参数值，不存在返回 null
     */
    public function getParam(string $name): mixed
    {
        return $this->info['params'][$name] ?? null;
    }

    /**
     * 获取任务内容描述
     *
     * @return string 内容描述
     */
    public function getContent(): string
    {
        return $this->info['content'];
    }

    /**
     * 获取任务执行耗时（毫秒）
     *
     * @return int 耗时（毫秒）
     */
    public function getIntervalms(): int
    {
        return (int) $this->info['intervalms'];
    }

    /**
     * 获取任务添加时间
     *
     * @return string 添加时间
     */
    public function getTimeadd(): string
    {
        return $this->info['timeadd'];
    }

    /**
     * 获取任务计划执行时间
     *
     * @return string 计划执行时间
     */
    public function getTimetorun(): string
    {
        return $this->info['timetorun'];
    }
}
