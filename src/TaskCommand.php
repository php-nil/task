<?php

namespace NilTask;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 任务执行命令
 *
 * 通过 Symfony Console 命令行执行定时任务
 */
#[AsCommand(
    name: 'task',
    description: '执行定时任务.'
)]
class TaskCommand extends Command
{
    public function __construct(
        /** @var Task 任务管理器实例 */
        protected Task $task
    ) {
        parent::__construct();
    }


    /**
     * 配置命令参数和选项
     */
    protected function configure(): void
    {
        $this->setDescription('定时执行任务');

        $this->addOption(
            'duration',
            'D',
            InputOption::VALUE_OPTIONAL,
            '运行多长时间结束(秒)?',
            60
        );

        $this->addOption(
            'interval',
            'I',
            InputOption::VALUE_OPTIONAL,
            '多任务下间隔多少毫秒执行?',
            100
        );

        $this->addOption(
            'offset',
            'O',
            InputOption::VALUE_OPTIONAL,
            '取任务偏移秒数',
            0
        );

        $this->addOption(
            'idle-exit',
            null,
            InputOption::VALUE_NONE,
            '空闲就跳出'
        );
    }

    /**
     * 执行命令
     *
     * @param InputInterface $input 输入参数
     * @param OutputInterface $output 输出接口
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln([
            '--------------------',
            '执行定时任务',
            '--------------------',
            '',
        ]);

        $duration = (int) $input->getOption('duration');
        $interval = max(10, (int) $input->getOption('interval'));
        $offset = (int) $input->getOption('offset');
        $idleExit = $input->getOption('idle-exit');

        $numRun = 0;
        $numIdle = 0;

        $now = time();
        $end = $now + $duration;

        do {
            if ($queue = $this->task->run($offset)) {
                $output->writeln('运行' . $queue->getResult());
                $numRun++;
                $numIdle = 0;
                $intervalms = $queue->getIntervalms();
            } else {
                $numIdle++;
                $intervalms = 0;
            }

            if ($idleExit && $numIdle > 0) {
                break;
            }

            if (0 !== $intervalms) {
                if (($sed = $interval - $intervalms) > 0) {
                    usleep($sed * 1000);
                }
            } else {
                $sed = $interval * (2 ** min(4, $numIdle));

                if (time() + ($sed / 1000) >= $end) {
                    break;
                }

                usleep($sed * 1000);
            }

            $time = time();
        } while ($time < $end);

        $info = '任务运行。开始:' . date('Y-m-d H:i:s', $now) . ';次数:' . $numRun;
        $output->writeln($info);

        return 0;
    }
}
