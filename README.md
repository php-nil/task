# Nil Task

Nil Framework 的定时任务组件，基于 Symfony Console 和 Doctrine DBAL 构建。

## 特性

- 基于数据库的任务队列管理（Doctrine DBAL，兼容 MySQL / MariaDB、PostgreSQL、SQL Server、SQLite 等平台）
- 支持任务延迟执行（按秒指定计划执行时间）
- 任务状态追踪（未执行、执行中、成功、失败）
- 并发安全的原子去重入队 `addIfNotRunning()`（数据库命名锁 + 事务内加锁复查）
- 多 worker 并发抢占安全：事务内 `FOR UPDATE`（MySQL/PostgreSQL）/ `UPDLOCK, READPAST`（SQL Server）行级锁选取任务，配合 `WHERE runtype = 0` 条件更新并校验受影响行数，同一任务不会被两个执行器重复执行；SQLite 等平台由条件更新兜底
- 批量认领执行 `BatchQueue`：单事务一次原子抢占多条到期任务后逐条执行，降低大量积压时的事务开销；与单条 `Queue::run()` 链路并存，互不影响
- 任务执行失败后可通过 `Queue::reRun()` 重新入队
- 支持按命名空间自动解析任务类（`ClassCollecter`）
- 任务类完整类名（FQCN）兜底解析（`FqcnCollecter`，自动注入，无需装配）
- 控制台命令行轮询执行，支持运行时长、轮询间隔、时间偏移、空闲退出
- 计划任务子模块（Plan）：按周期（每周 / 每隔几天 / 每月 / 月末 / 间隔相位）自动生成当日任务

## 环境要求

- PHP >= 8.2
- symfony/console ^8.0
- doctrine/dbal ^4.4
- Nil Framework（`Nil\Kernel\Kernel` 提供数据库连接与日志，`Nil\Result` 作为任务返回值）

## 安装

```bash
composer require php-nil/task
```

## 使用

### 1. 配置任务表与数据库连接（可选）

任务表名与数据库连接名通过 `TaskManager` 静态配置，均有默认值：

```php
use NilTask\TaskManager;

// 第一个参数：任务表名（默认 'task'）；第二个参数：数据库连接名（默认 Kernel 的 DEFAULT_NAME）
// 注意：必须在首次调用 TaskManager::getTask() 之前调用，单例创建后不再生效
TaskManager::setTable('task', 'default');
```

### 2. 创建任务类

实现 `TaskClassInterface` 接口或继承 `TaskClassAbstract` 抽象类：

```php
use Nil\Result;
use NilTask\TaskClassAbstract;

class MyTask extends TaskClassAbstract
{
    protected function task(): Result
    {
        // 通过 $this->queue 读取任务上下文（参数、内容、计划执行时间等）
        $value = $this->queue->getParam('param1');

        // 任务逻辑
        return Result::ok('success');
    }
}
```

### 3. 添加任务

通过 `TaskManager::getTask()` 获取任务实例：

```php
use NilTask\TaskManager;

$task = TaskManager::getTask();

// 添加立即执行的任务
$task->add('MyTask');

// 添加延迟执行的任务（30 秒后）
$task->add('MyTask', 0, 30);

// 添加带参数的任务
$task->add('MyTask', 0, 0, ['param1' => 'value']);

// 完整签名：add(string $name, int $doid = 0, int $timetorun = 0, array $params = [], string $content = '')
// 并发安全的去重入队：同 name + doid 已存在「未执行/执行中」任务时返回 false，不重复入队
$task->addIfNotRunning('MyTask', 0, 0, ['param1' => 'value']);
```

> 任务表在首次访问（添加 / 查询 / 执行）时由 `TableInit` 自动创建，无需手动建表。
> 建表使用 `CREATE TABLE IF NOT EXISTS` 并对并发建索引冲突做了容错，多个 worker 冷启动可安全竞争。
> 组件只负责创建自身的表，不会隐式创建 schema / database：若通过限定名（如 PostgreSQL 的 `schema.table`）
> 指定表名，schema 必须由部署方预先创建；缺失时建表会直接抛出明确的错误。

### 4. 注册并执行命令

组件只提供一个名为 `task` 的 Symfony Console 命令，命令本身不接收表名 / 连接名参数
（表名与连接名统一由 `TaskManager::setTable()` 配置）。

在 Nil 应用启动时把 `TaskManager` 注册为事件收集器即可挂载命令：

```php
use Nil\Kernel\Kernel;
use NilTask\TaskManager;

Kernel::boot(TaskManager::class /* , 其他收集器 */);
```

然后通过命令行执行：

```bash
# 持续轮询 60 秒（默认）
php bin/console task

# 指定运行时长（秒）
php bin/console task --duration=120

# 指定轮询间隔（毫秒，默认 100，最小强制 10）与取任务时间偏移（秒）
php bin/console task --interval=200 --offset=0

# 空闲（无待执行任务）时立即退出
php bin/console task --idle-exit

# 批量模式：每轮单事务认领并执行 20 条，适合大量积压；不指定 --batch 则单条逐条执行
php bin/console task --batch=20

# 常驻守护场景：延长单次运行时长，由系统进程守护（如 supervisor）保活
php bin/console task --duration=3600
```

> 可以同时启动多个 `task` 进程（多 worker）并行消费：取任务为数据库行锁原子抢占，
> 同一任务只会被一个执行器领取执行，未抢到的进程会自动顺延到下一条任务。

也可不经过 Nil Kernel，直接把 `TaskCommand` 加入自己的 Symfony Console Application：

```php
$application->add(new \NilTask\TaskCommand(TaskManager::getTask()));
```

### 5. 注册任务（收集器）

在应用中实现 `TaskCollectInterface` 接口来注册任务。接口方法为**静态方法** `collect()`：

```php
use NilTask\Collecter;
use NilTask\TaskCollectInterface;

class MyApp implements TaskCollectInterface
{
    public static function collect(Collecter $collecter): void
    {
        // 短任务名 => 回调（任务类统一通过 [类名, 'run'] 静态入口调用）
        $collecter->add('MyTask', [MyTask::class, 'run']);
    }
}
```

注册收集器（支持类名或闭包，可一次传多个）：

```php
TaskManager::collect(MyApp::class);

// 或直接传闭包
TaskManager::collect(function (Collecter $collecter): void {
    $collecter->add('MyTask', [MyTask::class, 'run']);
});
```

> 同名任务重复 `add()` 会抛出 `RuntimeException`；需要覆盖时可用 `set()`。

### 6. 使用类收集器（按命名空间自动解析）

`ClassCollecter` 把任务名直接映射为「命名空间 + 任务名」的类，按需懒加载：

```php
use NilTask\ClassCollecter;
use NilTask\TaskManager;

// 任务名 'MyTask' => 类 App\Task\MyTask（须实现 TaskClassInterface）
TaskManager::getCollecter()->addCollecter(new ClassCollecter('App\\Task'));

// 可带名称前缀：任务名 'taskMyTask' => 类 App\Task\MyTask
TaskManager::getCollecter()->addCollecter(new ClassCollecter('App\\Task', 'task'));
```

### 7. 任务运行时 API（Queue）

任务执行时，`TaskClassAbstract` 子类可通过 `$this->queue`（回调的入参也是 `Queue`）访问：

| 方法 | 说明 |
|------|------|
| `getId()` | 任务记录 ID |
| `getName()` / `getDoid()` | 任务名 / doid |
| `getParams()` / `getParam($name)` | 全部参数 / 单个参数（不存在返回 null） |
| `getContent()` | 任务内容描述 |
| `getTimeadd()` / `getTimetorun()` | 添加时间 / 计划执行时间 |
| `getIntervalms()` | 执行耗时（毫秒） |
| `reRun($time = 0, ?array $param = null, $content = 're run')` | 把本任务重新入队，可指定延迟秒数与新参数 |
| `count($time, ?bool $status = null)` | 统计同名同 doid 在最近 $time 秒内的任务数（可按成功/失败过滤） |

`Task` 实例另外提供查询与管理方法：`isRunning($name, $doid)`、`getTimeToRun()`、
`countRun()`、`count()`、`fetchOne()`、`fetch()`、`delete($where)`、`run($offset)`。

### 8. 批量认领执行（BatchQueue）

`BatchQueue` 在**单个事务**内一次原子抢占多条到期任务（`runtype` 0→9），提交后逐条执行并写回结果，适合任务积压时减少逐条开事务的开销。它不改变 `Queue::run()` / `Task::run()` 的现有行为，两种执行器可安全混用，同一任务仍只会被执行一次。

```php
use NilTask\BatchQueue;
use NilTask\TaskManager;

$task = TaskManager::getTask();

// 一次认领并执行最多 20 条到期任务；无可用任务时返回 false
$batch = BatchQueue::run($task, 20);

if (false !== $batch) {
    echo $batch->count();       // 本批次执行数量
    echo $batch->getResult();  // 逐任务结果汇总（每任务一行）
}
```

签名：`BatchQueue::run(Task $task, int $limit = 10, int $offset = 0)`。`getQueues()` 返回本批次的 `Queue[]`，可逐个读取 `getId()`、`getResult()`、`getIntervalms()` 等。并发抢占策略与单条认领一致（MySQL/PostgreSQL `FOR UPDATE`、SQL Server `UPDLOCK, READPAST`、SQLite 等由条件更新兜底）。

命令行也可直接启用：`php bin/console task --batch=20`（见上文「命令行参数」），不指定 `--batch` 时仍走原单条链路。

## 计划任务子模块（Plan）

计划任务子模块位于 `src/Plan`，用于定义一组按周期重复执行的任务。每天凌晨由队列中的扫描任务自动读取这些计划，分析后把当日需要执行的任务加入任务队列。子模块不提供命令行管理；按任务类完整类名（FQCN）解析执行的兜底收集器在 TaskManager 首次实例化 Task 时自动注入，无需显式装配。

### 1. 基本说明

- 队列按任务类完整类名（FQCN）执行的能力由默认兜底收集器 `FqcnCollecter` 提供：`TaskManager::getTask()` 首次实例化 Task 时自动把它注入收集器链末尾，短任务名优先解析、均未命中才按 FQCN 兜底，调用方无需任何显式装配；
- 计划表在首次访问时自动创建（同样不会隐式创建 schema，限定名场景的 schema 预置要求与任务表一致）。表名为「任务表名 + `_plan`」（`TaskManager::PLAN_TABLE_NAME`），例如任务表为 `task` 时计划表为 `task_plan`；
- 每日自动扫描默认不开启，需要时显式安装一次扫描任务（见第 4 节）。

### 2. 周期（cycle）说明

周期为 JSON 数组（关联结构），每个键均可省略，值支持数字或数字数组：

| 键 | 含义 | 示例 |
|----|------|------|
| week | 每周第几天（ISO-8601，1=周一 ... 7=周日） | `{"week":1}`、`{"week":[1,3,5]}` |
| day | 每隔几天执行一次（正整数，自固定基准日 1970-01-01 取模） | `{"day":2}` |
| month | 每月第几天（1-31，31 仅在有 31 天的月份生效） | `{"month":15}` |
| month_end | 当月倒数第几天（1=当月最后一天，1-31），各月天数不同时很有用 | `{"month_end":1}`、`{"month_end":[1,2]}` |
| day_offset | [间隔, 偏移]，每隔 N 天偏移 M 天（间隔正整数，0 ≤ 偏移 < 间隔）；多组用数组 | `{"day_offset":[3,1]}`、`{"day_offset":[[3,0],[3,1]]}` |

- 多个维度之间为**「或」**关系，任一命中即创建当日任务；
- 周期为空（`{}` 或未设置）表示**每天执行**；
- `{"day_offset":[N,0]}` 等价于 `{"day":N}`；偏移用于区分同一间隔下的不同相位，如 `[3,0]` 与 `[3,1]` 错开一天；
- 越界取值（如 `week:8`、`month:0`、`day_offset:[3,5]`）会在写入时抛出 `InvalidArgumentException`。

### 3. 管理接口

通过 TaskManager 获取 `PlanTask` 实例（表名由 TaskManager 传入）进行增删改查与启停：

```php
use NilTask\TaskManager;

$plan = TaskManager::getPlanTask();

// 新增：名称（同名校验唯一）、任务类(须存在且实现 TaskClassInterface)、
// 执行时间(HH:MM, 00:00-23:59)、是否启用、周期（空数组表示每天）
$plan->add('每日报表', App\Task\DailyReport::class, '10:23', true, ['week' => [1, 3, 5]]);

// 查询（读取结果中 enabled 被转为 bool，cycle 被解码为数组）
$plan->get(1);               // 按 ID，无记录返回 null
$plan->getByName('每日报表'); // 按名称，无记录返回 null
$plan->getList();            // 全部（可传 true 仅取启用的）
$plan->getEnabledList();     // 仅启用

// 修改（仅更新提供的字段：name/taskclass/runtime/enabled/cycle）
$plan->update(1, ['runtime' => '11:00', 'enabled' => false]);

// 启用 / 停用 / 删除
$plan->setEnabled(1, true);
$plan->delete(1);            // 表不存在时返回 0，不报错
```

### 4. 启用每日自动读取（安装扫描任务）

默认不会创建扫描任务。每日扫描本身是任务队列中的一个 task（`PlanScanTask`，继承 `TaskClassAbstract`），需要在代码中显式安装一次：

```php
NilTask\Plan\PlanScanTask::ensureScheduled();
```

安装逻辑为「检测当日扫描任务，不存在则创建」——**扫描任务被创建即代表启用了自动调度**；重复安装安全（已存在则跳过）。

启用后的运行方式（由持续轮询的 `task` 执行器驱动，无需额外扫描进程或系统计划任务）：

1. **当日补扫**：安装时创建的扫描任务执行时间为当日 00:00（通常已过点），会被立即拉起，`PlanScanner` 读取已启用计划，把命中周期的任务按当日 `HH:MM` 加入队列；
2. **凌晨执行**：此后扫描任务每天 00:00 到期，被轮询中的 `task` 执行器拉起；
3. **自我延续**：扫描任务执行后**先**把次日 00:00 的扫描任务加入队列（即使当日扫描失败也不中断调度链），再执行当日扫描，日复一日；
4. **幂等去重**：扫描任务与业务任务均以日期序号作为 `doid`——每个任务每天至多一条，同日重复扫描自动跳过；不同日期为独立记录，前一天的任务滞留（如执行器停机、执行中断）不会阻塞后续日期任务；执行器恢复后会按 `timetorun` 先后依次补执行各日任务。

已过执行时间的计划会以过去时间入队，由任务执行器立即补执行。因此只需保证 `task` 命令常驻轮询（如 `php bin/console task --duration=3600`，由系统进程守护），计划任务即可每天自动生成。

## 数据库表结构

组件会自动创建任务表，包含以下字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint unsigned | 主键，自增 |
| name | string(85) | 任务名称 |
| doid | bigint unsigned | 任务业务 ID（默认 0，常用于去重分组） |
| runtype | smallint unsigned | 运行状态（0未执行、9执行中、1成功、2失败） |
| params | json | 参数（JSON，PostgreSQL 等平台使用 jsonb） |
| content | text | 内容描述 |
| result | text | 执行结果（初始为 `not run`） |
| timeadd | datetime | 添加时间 |
| timetorun | datetime | 计划执行时间 |
| timerun | datetime | 实际执行时间 |
| intervalms | integer unsigned | 执行耗时（毫秒） |

索引：主键 `id`；普通索引 `(timeadd, name)`、`(runtype, name)`、`(timetorun, runtype)`。

计划任务表（`<任务表名>_plan`）字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint unsigned | 主键，自增 |
| name | string(85) | 计划名称（**唯一索引**） |
| taskclass | string(190) | 调用的任务类（须存在且实现 TaskClassInterface） |
| runtime | string(5) | 任务执行时间，如 `10:23`（HH:MM） |
| enabled | smallint unsigned | 是否启用（1是 / 0否，默认 1） |
| cycle | json | 周期，如 `{"week":1,"day":2,"month":3,"month_end":1,"day_offset":[3,1]}`，空表示每天 |

索引：主键 `id`；普通索引 `(enabled)`；唯一索引 `(name)`。

## 命令行参数

命令名：`task`（无位置参数；任务表名与数据库连接名通过 `TaskManager::setTable()` 配置）。

| 选项 | 简写 | 类型 | 默认值 | 说明 |
|------|------|------|--------|------|
| --duration | -D | optional | 60 | 运行时长（秒） |
| --interval | -I | optional | 100 | 多任务执行间隔（毫秒，最小强制 10） |
| --offset | -O | optional | 0 | 取任务的时间偏移（秒） |
| --idle-exit | - | flag | - | 空闲（无待执行任务）时立即退出 |
| --batch | -B | required | - | 批量认领执行的任务数量（如 `--batch=20`）；不指定则单条逐条执行 |

`--batch=N` 时每轮由 `BatchQueue` 在单事务内一次认领最多 N 条到期任务并逐条执行，
`--interval` 作用于批次之间（按批内最慢任务的耗时计算补偿等待）；其余选项语义不变。

未开启 `--idle-exit` 时，空闲等待采用指数退避：实际休眠 ≈ interval × 2^n（n 为连续空闲次数，上限 4）。

## 许可证

MIT License
