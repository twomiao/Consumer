<?php

namespace Consumer;

abstract class Consumer
{
    // 进程数量扩展限制
    public $forkMaxWorker = 15;

    // 临时进程,常驻进程
    const TEMP_PROCESS = 'temp';
    const RESIDENT_PROCESS = 'resident';

    // 运行状态
    const STATUS_STARTING = 1;
    const STATUS_STOP = 2;
    const STATUS_RUNNING = 3;

    /**
     * 进程PID
     * @var array $pidMap
     */
    protected static $pidMap = [];

    /**
     * 消费者类型
     * @var array $consumer
     */
    protected static $consumer = [];

    /**
     * 日志文件
     * @var $logFile
     */
    protected static $logFile;

    /**
     * 当前运行状态
     * @var $status
     */
    protected static $status;

    /**
     * 主进程PID
     * @var $masterPid
     */
    protected static $masterPid;

    /**
     * pid文件
     * @var $pidFile
     */
    protected static $pidFile;

    /**
     * 创建指定常驻进程数量
     * @var int
     */
    protected $forkNumber = 2;

    /**
     * 基础配置数据
     * @var array $config
     */
    protected $config = [
        'memory_limit'  => 128,// 128mb
        'max_consumers' => 8, // 临时+常驻消费者最多：8个
        'task_timeout'  => 30, // 任务从队列消费超30秒，消费者退出并记录数据
        'idle_time'     => 30, // 临时消费者空闲30秒没任务，自动退出节约资源
        ''
    ];

    /**
     * Consumer constructor.
     * 常驻消费者数量
     * @param $forkNumber
     * 配置文件数据
     * @param array $config
     */
    public function __construct($forkNumber, $config = [])
    {
        $this->forkNumber    = $forkNumber;
        $this->config        = array_merge($this->config, $config);
        $this->forkMaxWorker = $this->config['max_consumers'];
    }

    public function start()
    {
        // 环境监察
        $this->checkSapiEnv();
        $this->init();
        // 注册异步信号
        pcntl_async_signals(true);

        // 创建消费者
        $this->forkWorkerForLinux(self::RESIDENT_PROCESS, $this->forkNumber);

        $this->saveMasterPid();
        $this->monitorWorkers();
    }

    public function setMaxWorker($max)
    {
        $this->forkMaxWorker = $max;
    }

    /**
     * 创建消费者子进程
     * @param string $consumerType
     * @param int $forkWorkerNum
     */
    protected function forkWorkerForLinux($consumerType, $forkWorkerNum = 1)
    {
        while ($forkWorkerNum > 0 && count(self::$pidMap) < $this->forkMaxWorker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // 清理父进程数据
                self::$pidMap = self::$consumer = [];
                $processTitle = (Consumer::RESIDENT_PROCESS === $consumerType) ? 'Consumer:worker' : 'Consumer:temp';
                self::setProcessTitle($processTitle);

                // 执行任务
                $this->processingWork($consumerType);

                $this->stop();
            } elseif ($pid) {
                self::$pidMap[$pid] = $pid;
                self::$consumer[$pid] = $consumerType;
                self::$masterPid = getmypid();
            } else {
                $this->stop(ExitedCode::FORK_ERROR);
            }
            $forkWorkerNum--;
        }
    }

    abstract public function fire($data);

    /**
     * @param $consumerType
     */
    final private function processingWork($consumerType)
    {
        if (!is_subclass_of($this, ConnectionInterface::class)) {
            return;
        }

        /**
         * @var $client ConnectionInterface
         */
        $client = $this->getQueueConnection();

        // 记录上次时间
        $lastTime = time();
        while (1) {
            $data = $client->reserve();

            if (!$data) {
                echo '没有数据消费' . PHP_EOL;
                if (($remaining = time() - $lastTime) > $this->config['idle_time'] &&
                    $consumerType === Consumer::TEMP_PROCESS
                ) {
                    $client->closed();
                    echo "临时进程超过{$remaining}秒没有任务，自动退出." . PHP_EOL;
                    $this->stop();
                }
                continue;
            }

            $this->registerTimeoutHandler($data);

            $this->fire($data);

            pcntl_alarm(0);

            // 标记任务处理完成时间
            $lastTime = time();

            // mb 为单位
            $this->memorySizeLimit($this->config['memory_limit']);

        }
    }

    public function stop($status = 0)
    {
        exit($status);
    }

    protected function registerTimeoutHandler($data)
    {
        pcntl_signal(SIGALRM, function () use ($data) {
            $pid = getmypid();
            @file_put_contents(self::$logFile, "pid:{$pid}, 任务超时:" . json_encode($data, 256) . PHP_EOL, FILE_APPEND);
            exit(ExitedCode::JOB_EXIT_OUT);
        });

        pcntl_alarm($this->config['task_timeout']);
    }

    protected function init()
    {
        self::setProcessTitle('Consumer:master');
        // Start file.
        $backtrace = \debug_backtrace();
        $_startFile = $backtrace[\count($backtrace) - 1]['file'];

        // 启动文件路径
        $unique_prefix = \str_replace('/', '_', $_startFile);

        // Pid file.
        // 主进程进程号保存路径
        if (empty(static::$pidFile)) {
            static::$pidFile = __DIR__ . "/../$unique_prefix.pid";
        }

        // Log file.
        // 创建日志文件 赋予权限 0622
        if (empty(static::$logFile)) {
            static::$logFile = __DIR__ . '/../consumer.log';
        }
        $log_file = (string)static::$logFile;
        if (!\is_file($log_file)) {
            \touch($log_file);
            \chmod($log_file, 0622);
        }

        static::$status = static::STATUS_STARTING;
    }

    // Save pid.
    protected static function saveMasterPid()
    {
        if (false === \file_put_contents(static::$pidFile, static::$masterPid)) {
            throw new \RuntimeException('can not save pid to ' . static::$pidFile);
        }
    }

    /**
     * Check sapi.
     *
     * @return void
     */
    protected function checkSapiEnv()
    {
        // Only for cli.
        // 参考：https://www.php.net/manual/zh/function.php-sapi-name.php
        if (\PHP_SAPI !== 'cli') {
            exit("Only run in command line mode \n");
        }
        //  特殊路径得知是Windows 系统
        if (\DIRECTORY_SEPARATOR === '\\') {
            throw new \RuntimeException('pcntl extension does not support Windows system.');
        }
    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        \set_error_handler(function () {
        });
        // >=php 5.5
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (\extension_loaded('proctitle') && \function_exists('setproctitle')) {
            \setproctitle($title);
        }
        \restore_error_handler();
    }


    private function monitorWorkers()
    {
        // 运行中
        self::$status = self::STATUS_RUNNING;

        while (1) {
            // 非阻塞信号
            $status = 8;
            $pid = \pcntl_wait($status, \WNOHANG);

            if ($pid > 0) {
                $status = pcntl_wexitstatus($status);
                echo "子进程退出: status:{$status}, pid:{$pid}, " . self::$consumer[$pid] . PHP_EOL;
                // 异常退出
                $this->rebootByExitedStatus($status, $pid);

                // 清理子进程数据
                unset(self::$pidMap[$pid]);
                unset(self::$consumer[$pid]);
                continue;
            }

            // 子进程退出,主进程正常退出
            if (count(self::$pidMap) < 1) {
                break;
            }

            // 创建临时进程
            $this->forkTempForLinux($status);
            usleep(500000);
        }
    }

    protected function rebootByExitedStatus($status, $pid)
    {
        switch ($status) {
            case ExitedCode::MEMORY_SIZE:
                // 内存超出限制
//                var_dump("pid:{$pid},内存超出限制大小.\n");
//                @file_put_contents(self::$logFile, "pid:{$pid},内存超出限制大小.\n", FILE_APPEND);
                break;
            case ExitedCode::JOB_EXIT_OUT:
                // 任务超时运行
//                var_dump("pid:{$pid},任务超时.\n");
//                @file_put_contents(self::$logFile, "pid:{$pid},任务超时.\n", FILE_APPEND);
                break;
            case ExitedCode::SUCCESS:
                break;
            default:
                // 除上面的情况，均重新创建退出消费者的类型
                $this->reboot($status, $pid);
                break;
        }
    }

    /**
     * 异常退出重启消费者,除任务超时退出或者超出内存限制退出
     * @param $status
     * @param $pid
     */
    private function reboot($status, $pid)
    {
        if (self::$status !== self::STATUS_STOP && $this->checkRebootStatus($status)) {
            // 重新创建拉取指定类型的消费者进程
            self::forkWorkerForLinux
            (
                $consumer = self::$consumer[$pid],
                1
            );
            echo "退出代码:{$status}, pid:{$pid}, reboot.\n";
        }
    }

    /**
     * 以下消费者退出状态码不会重启
     * @param $status
     * @return bool
     */
    private function checkRebootStatus($status)
    {
        $exitedStatus = in_array(
            $status,
            [ExitedCode::JOB_EXIT_OUT, ExitedCode::MEMORY_SIZE, ExitedCode::SUCCESS],
            true
        );
        return !$exitedStatus;
    }

    protected function forkTempForLinux($status)
    {
        // 消费者总数量
        $consumers = count(self::$pidMap);

        $addConsumers = ceil($this->length() / $consumers) - $consumers;
        if ($addConsumers > 0 && $this->checkRebootStatus($status)) {
            $this->forkWorkerForLinux(self::TEMP_PROCESS);
        }
    }

    protected function memorySizeLimit($memoryLimit)
    {
        if ((memory_get_usage(true) / 1024 / 1024) >= $memoryLimit) {
            $this->stop(ExitedCode::MEMORY_SIZE);
        }
    }
}


