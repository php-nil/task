# Nil Task

Nil Framework 的定时任务组件，基于 Symfony Console 和 Doctrine DBAL 构建。

## 特性

- 基于数据库的任务队列管理
- 支持任务延迟执行
- 任务状态追踪（未执行、执行中、成功、失败）
- 任务自动重试机制
- 支持按命名空间自动收集任务类
- 控制台命令行执行
- 计划任务子模块（Plan）：按周期（每周 / 每隔几天 / 每月）自动生成当日任务

## 安装

```bash
composer require php-nil/task
```

## 使用

### 1. 创建任务类

实现 `TaskClassInterface` 接口或继承 `TaskClassAbstract` 抽象类：

```php
use NilTask\Queue;
use NilTask\TaskClassAbstract;
use Nil\Result;

class MyTask extends TaskClassAbstract
{
    protected function task(): Result
    {
        // 任务逻辑
        return Result::ok('success');
    }
}
```

### 2. 添加任务

```php
$task = NilTask\Task::get('task');

// 添加立即执行的任务
$task->add('MyTask');

// 添加延迟执行的任务（30秒后）
$task->add('MyTask', 0, 30);

// 添加带参数的任务
$task->add('MyTask', 0, 0, ['param1' => 'value']);
```

### 3. 执行任务

通过 Symfony Console 命令执行：

```bash
php bin/console task task_table

# 指定运行时长（默认60秒）
php bin/console task task_table --duration=120

# 指定数据库连接
php bin/console task task_table --dbname=default

# 空闲时自动退出
php bin/console task task_table --idle-exit
```

### 4. 收集任务

在应用中实现 `TaskCollectInterface` 接口来注册任务：

```php
use NilTask\Collecter;
use NilTask\TaskCollectInterface;

class MyApp implements TaskCollectInterface
{
    public function taskCollect(Collecter $collecter): void
    {
        $collecter->add('MyTask', [MyTask::class, 'run']);
    }
}
```

### 5. 使用类收集器

自动按命名空间收集任务类：

```php
$collecter = new NilTask\ClassCollecter('App\\Task');
$task->getCollecter()->addCollector($collecter);
```

## 计划任务子模块（Plan）

计划任务子模块位于 `src/Plan`，用于定义一组按周期重复执行的任务。每天凌晨由队列中的扫描任务自动读取这些计划，分析后把当日需要执行的任务加入任务队列。子模块不提供命令行管理；按任务类完整类名（FQCN）解析执行的兜底收集器在 TaskManager 首次实例化 Task 时自动注入，无需显式装配。

### 1. 基本说明

- 队列按任务类完整类名（FQCN）执行的能力由默认兜底收集器 `FqcnCollecter` 提供：`TaskManager::getTask()` 首次实例化 Task 时自动把它注入收集器链末尾，短任务名优先解析、均未命中才按 FQCN 兜底，调用方无需任何显式装配；
- 计划表在首次访问时自动创建。表名为「任务表名 + `_plan`」（`TaskManager::PLAN_TABLE_NAME`），例如任务表为 `task` 时计划表为 `task_plan`；
- 每日自动扫描默认不开启，需要时显式安装一次扫描任务（见第 4 节）。

### 2. 周期（cycle）说明

周期为 JSON 数组，每个键均可省略，值支持数字或数字数组：

| 键 | 含义 | 示例 |
|----|------|------|
| week | 每周第几天（ISO-8601，1=周一 ... 7=周日） | `{"week":1}`、`{"week":[1,3,5]}` |
| day | 每隔几天执行一次（正整数，自固定基准日 1970-01-01 取模） | `{"day":2}` |
| month | 每月第几天（1-31，31 仅在有 31 天的月份生效） | `{"month":15}` |
| month_end | 当月倒数第几天（1=当月最后一天，1-31），各月天数不同时很有用 | `{"month_end":1}`、`{"month_end":[1,2]}` |
| day_offset | [间隔, 偏移]，每隔 N 天偏移 M 天（间隔正整数，0 ≤ 偏移 < 间隔）；多组用数组 | `{"day_offset":[3,1]}`、`{"day_offset":[[3,0],[3,1]]}` |

- 多个维度之间为**「或」**关系，任一命中即创建当日任务；
- 周期为空（`{}` 或未设置）表示**每天执行**；
- `{"day_offset":[N,0]}` 等价于 `{"day":N}`；偏移用于区分同一间隔下的不同相位，如 `[3,0]` 与 `[3,1]` 错开一天。

### 3. 管理接口

通过 TaskManager 获取 `PlanTask` 实例（表名由 TaskManager 传入）进行增删改查与启停：

```php
use NilTask\TaskManager;

$plan = TaskManager::getPlanTask();

// 新增：名称、任务类(须实现 TaskClassInterface)、执行时间、是否启用、周期
$plan->add('每日报表', App\Task\DailyReport::class, '10:23', true, ['week' => [1, 3, 5]]);

// 查询
$plan->get(1);              // 按 ID
$plan->getByName('每日报表'); // 按名称
$plan->getList();           // 全部
$plan->getEnabledList();    // 仅启用

// 修改（仅更新提供的字段）
$plan->update(1, ['runtime' => '11:00', 'enabled' => false]);

// 启用 / 停用 / 删除
$plan->setEnabled(1, true);
$plan->delete(1);
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
3. **自我延续**：扫描任务执行后自动把次日 00:00 的扫描任务加入队列，日复一日；
4. **幂等去重**：扫描任务与业务任务均以日期序号作为 `doid`——每个任务每天至多一条，同日重复扫描自动跳过；不同日期为独立记录，前一天的任务滞留（如执行器停机、执行中断）不会阻塞后续日期任务；执行器恢复后会按 `timetorun` 先后依次补执行各日任务。

已过执行时间的计划会以过去时间入队，由任务执行器立即补执行。因此只需保证 `task` 命令常驻轮询（如 `php bin/console task --duration=3600`，由系统进程守护），计划任务即可每天自动生成。

## 数据库表结构

组件会自动创建任务表，包含以下字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| name | string(85) | 任务名称 |
| doid | bigint | 任务ID |
| runtype | smallint | 运行状态（0未执行、9执行中、1成功、2失败） |
| params | json | 参数 |
| content | text | 内容 |
| result | text | 执行结果 |
| timeadd | datetime | 添加时间 |
| timetorun | datetime | 计划执行时间 |
| timerun | datetime | 实际执行时间 |
| intervalms | integer | 执行耗时（毫秒） |

计划任务表（`<任务表名>_plan`）字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| name | string(85) | 计划名称（唯一） |
| taskclass | string(190) | 调用的任务类（须实现 TaskClassInterface） |
| runtime | string(5) | 任务执行时间，如 10:23 |
| enabled | smallint | 是否启用（1是 / 0否） |
| cycle | json | 周期，如 {"week":1,"day":2,"month":3,"month_end":1,"day_offset":[3,1]}，空表示每天 |

## 命令行参数

| 参数 | 简写 | 类型 | 默认值 | 说明 |
|------|------|------|--------|------|
| dbtable | - | required | - | 任务表名 |
| dbname | - | optional | default | 数据库连接名 |
| --duration | -D | optional | 60 | 运行时长（秒） |
| --interval | -I | optional | 100 | 任务间隔（毫秒） |
| --offset | -O | optional | 0 | 时间偏移（秒） |
| --idle-exit | - | flag | - | 空闲时退出 |

## 许可证

MIT License
