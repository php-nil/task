# Nil Task

Nil Framework 的定时任务组件，基于 Symfony Console 和 Doctrine DBAL 构建。

## 特性

- 基于数据库的任务队列管理
- 支持任务延迟执行
- 任务状态追踪（未执行、执行中、成功、失败）
- 任务自动重试机制
- 支持按命名空间自动收集任务类
- 控制台命令行执行

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
