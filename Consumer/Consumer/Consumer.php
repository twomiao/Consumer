<?php


namespace Consumer\Consumer;

abstract class Consumer
{

    use ConnectionManager;

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
     * 标准重定向输入输出
     * @var string
     */
    protected static $stdoutFile = '/dev/null';

    /**
     * 创建指定常驻进程数量
     * @var int
     */
    protected $forkNumber = 2;

    /**
     * 队列积累量
     * @var int $addConsumers
     */
    private static $addConsumers = 0;

    /**
     * Master
     * @var string
     */
    protected $installSignal = "\\Consumer\\Consumer\\Consumer::installSignal";

    /**
     * 基础配置数据
     * @var array $config
     */
    protected $config = [
        'name'         => 'consumer',
        'tasks'        => 0, // < 1 永久不退出,否则按照指定任务数量退出
        'memory_limit' => 0, // 默认为配置文件内存的4/1
        'max_consumers'=> 8, // 临时+常驻消费者最多：8个
        'task_timeout' => 30, // 任务从队列消费超30秒，消费者退出并记录数据
        'idle_time'    => 30, // 临时消费者空闲30秒没任务，自动退出节约资源
        'user'         => '', // 用户
        'user_group'   => '',  // 用户组
        'daemonize'    => false, // 守护进程
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
        $this->forkNumber             = $forkNumber;
        $this->config                 = array_merge($this->config, $config);
        $this->forkMaxWorker          = $this->config['max_consumers'];
        $this->config['user']         = $this->config['user'] ?: get_current_user();
        $this->config['memory_limit'] = $this->getIdealMemorySize();
    }

    public function start()
    {
        // 环境检查
        $this->checkSapiEnv();
        pcntl_async_signals(true);
        $this->init();
        $this->daemonize();
        // 创建消费者
        $this->forkWorkerForLinux(
            self::RESIDENT_PROCESS,
            $this->forkNumber
        );
        $this->saveMasterPid();
        $this->resetStd();
        pcntl_signal(SIGINT, $this->installSignal);
        $this->monitorWorkers();
    }

    /**
     * 分配合适的内存
     * @return float|int
     */
    protected function getIdealMemorySize()
    {
        $idealMemory = ceil($this->config['memory_limit']);

        $memory = strtolower(ini_get('memory_limit'));
        $targetMemory = floor(
            str_replace(
                'mb', '', $memory
            ));
        return (
            $idealMemory > 0 && $idealMemory <= ini_get('memory_limit')
        ) ? $idealMemory * 1024 * 1024 :
            floor(($targetMemory / 4)) * 1024 * 1024;
    }

    /**
     * 配置最大进程数量
     * 优先级高于配置文件 max_consumers的值
     * @param $max
     */
    public function setMaxWorker($max)
    {
        $this->forkMaxWorker = $max;
    }

    /**
     * 用户不处理异常,自动保存到日志然后退出进程
     * @param \Throwable $e
     */
    public function globalException(\Throwable $e)
    {
        // write log ....
        switch ($e->getCode()) {
            case ExitedCode::CONSUMER_BLOCKING:
                // write log ...

                echo $e->getMessage() . PHP_EOL;
                // 阻塞任务;
                exit($e->getCode());
            case ExitedCode::RELEASE_MEMORY:
                // write log ...
                $code = $e->getCode();
                $pid = getmypid();
                echo $e->getMessage() . " pid ({$pid}), Exited code ($code)." . PHP_EOL;
                // 内存超出预期值;
                exit($code);
                break;
            case ExitedCode::SUCCESS:
                file_put_contents(self::$logFile, var_export($e,true).PHP_EOL,FILE_APPEND);
                break;
            default:
                file_put_contents(self::$logFile, $e->getMessage().PHP_EOL,FILE_APPEND);
                break;
        }
    }


    /**
     * 创建消费者子进程
     * @param string $consumerType
     * @param int $forkWorkerNum
     */
    protected function forkWorkerForLinux($consumerType, $forkWorkerNum = 1)
    {
        \set_exception_handler(
            [$this, 'globalException']
        );
        while ($forkWorkerNum > 0 && count(self::$pidMap) < $this->forkMaxWorker) {
            $retry = 0;
            do {
                $pid = \pcntl_fork();
                if ($pid === 0) {
                    self::$addConsumers = 0;
                    // 清理父进程数据
                    self::$pidMap = self::$consumer = [];
                    // install signal
                    pcntl_signal(SIGINT, $this->installSignal);

                    $name = (Consumer::RESIDENT_PROCESS === $consumerType) ? ':worker':':temp';
                    $processTitle = ($this->config['name'] ?: 'consumer').$name;
                    self::setProcessTitle($processTitle);

                    if (self::$status === self::STATUS_STARTING) {
                        $this->resetStd();
                    }
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
                ++$retry;
            } while ($pid < 0 && $retry < 3);
        }
        \restore_exception_handler();
    }

    abstract public function fire($data);

    /**
     * @param $consumerType
     */
    private function processingWork($consumerType)
    {
        if (!is_subclass_of($this, ConnectionInterface::class)) {
            return;
        }

        /**
         * @var $client ConnectionInterface
         */
        $client = $this->getClientConnection();

        // 记录上次时间
        $lastTime   = time();
        // 内存初始化
        $initMemory = memory_get_usage();
        // 任务累计初始化
        $tasks      = 0;
        while (1) {
            if (self::$status === self::STATUS_STOP) {
                echo "进程收到退出命令，自动退出." . PHP_EOL;
               $this->stop();
            }
            $data = $client->reserve();

            if (!$data) {
                echo '没有数据消费' . PHP_EOL;
                if (($remaining = time() - $lastTime) > $this->config['idle_time'] &&
                    $consumerType === Consumer::TEMP_PROCESS
                ) {
                    if ($client->isConnected()) {
                        $client->closed();
                    }
                    echo "临时进程超过{$remaining}秒没有任务，自动退出." . PHP_EOL;
                    $this->stop();
                }
                continue;
            }

            $this->consumerBlocking($data);

            // 标记任务处理完成时间
            $lastTime = time();

            // 检查内存
            $this->releaseMemory($initMemory);

            if (intval($this->config['tasks']) > 0 && (++$tasks === $this->config['tasks'])) {
                if ($client->isConnected()) {
                    $client->closed();
                }
                $pid = getmypid();
                echo "Pid: {$pid}, 进程完成{$tasks}个任务，自动退出释放内存." . PHP_EOL;
                file_put_contents("run.log", "Pid: {$pid}, 进程完成{$tasks}个任务，自动退出释放内存." . PHP_EOL, FILE_APPEND);
                $this->stop(ExitedCode::TASKS_TOTAL);
            }
        }
    }

    /**
     * 重定向标准输出
     */
    public function resetStd()
    {
        if ($this->config['daemonize']) {
            global $STDOUT, $STDERR;
            $handle = \fopen(static::$stdoutFile, "a");
            if ($handle) {
                unset($handle);
                \set_error_handler(function () {
                });
                \fclose(\STDOUT);
                \fclose(\STDERR);
                $STDOUT = \fopen(static::$stdoutFile, "a");
                $STDERR = \fopen(static::$stdoutFile, "a");
                \restore_error_handler();
                return;
            }

            exit('Can not open stdoutFile ' . static::$stdoutFile);
        }
    }

    public function stop($status = 0)
    {
        exit($status);
    }


    /**
     * 消费者阻塞抛出异常退出
     *
     * 队列数据
     * @param $data
     */
    protected function consumerBlocking($data)
    {
        pcntl_signal(SIGALRM, function () use ($data) {
            [$pid, $data] = [
                getmypid(),
                var_export($data, true)
            ];
            throw new ConsumerTimeOutException(
                "Consumer timed out for {$this->config['task_timeout']} seconds, the data is:{$data}, pid:{$pid}"
            );
        });

        pcntl_alarm($this->config['task_timeout']);

        $this->fire($data);

        pcntl_alarm(0);
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

        $this->setUserAndGroup();

        static::$status = static::STATUS_STARTING;
    }

    /**
     * 守护进程运行.
     *
     */
    protected function daemonize()
    {
        if ($this->config['daemonize']) {
            \umask(0);
            $pid = \pcntl_fork();
            if (-1 === $pid) {
                exit("Error: Fork fail.\n");
            } elseif ($pid > 0) {
                exit(0);
            }
            if (-1 === \posix_setsid()) {
                exit("Error: Setsid fail.\n");
            }
            // Fork again avoid SVR4 system regain the control of terminal.
            $pid = \pcntl_fork();
            if (-1 === $pid) {
                exit("Error: Fork fail.\n");
            } elseif (0 !== $pid) {
                exit(0);
            }
        }
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

        if (version_compare(PHP_VERSION, '7.1.0', '<')) {
            exit("php version requirement >= 7.1 \n");
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

    /**
     * 设置Unix用户组和用户
     *
     * @return void
     */
    public function setUserAndGroup()
    {
        if (extension_loaded('posix')) {
            // Get uid.
            $user_info = \posix_getpwnam($this->config['user']);
            if (!$user_info) {
                echo "Warning: User '{$this->config['user']}' not exsits.\n";
                return;
            }

            $uid = $user_info['uid'];

            if ($this->config['user_group']) {
                $group_info = \posix_getgrnam($this->config['user_group']);
                if (!$group_info) {
                    echo "Warning: Group '{$this->config['user_group']}' not exsits\n";
                    return;
                }
                $gid = $group_info['gid'];
            } else {
                $gid = $user_info['gid'];
            }

            // Set uid and gid.
            if ($uid !== \posix_getuid() || $gid !== \posix_getgid()) {
                if (!\posix_setgid($gid) || !\posix_initgroups($user_info['name'], $gid) || !\posix_setuid($uid)) {
                    echo "Warning: change gid or uid fail.\n";
                }
            }
        }
    }


    private function monitorWorkers()
    {
        // 运行中
        self::$status = self::STATUS_RUNNING;

        while (count(self::$pidMap)) {
            // 非阻塞信号
            $status = ExitedCode::TEMP_CONSUMER;

            $pid = \pcntl_wait($status, \WNOHANG);

            if ($pid > 0) {
                $status = pcntl_wexitstatus($status);
                echo "子进程退出: status:{$status}, pid:{$pid}, " . self::$consumer[$pid] . PHP_EOL;
                var_dump($status);
                // 异常退出
                $this->rebootConsumer($status, $pid);

                // 清理子进程数据
                unset(self::$pidMap[$pid]);
                unset(self::$consumer[$pid]);
                continue;
            }

            usleep(500000);
            // 寻找临时工帮忙
            $this->forkRelieveStressForLinux($status);
        }
    }

    /**
     * 异常退出重启消费者,除任务超时退出或者超出内存限制退出
     * @param $status
     * @param $pid
     */
    private function rebootConsumer($status, $pid)
    {
        if (self::$status !== self::STATUS_STOP && !isset(ExitedCode::$exitedCodeMap[$status])) {
            // 重新创建拉取指定类型的消费者进程
            self::forkWorkerForLinux
            (
                $consumer = self::$consumer[$pid],
                1
            );
            echo "退出代码:{$status}, pid:{$pid}, reboot.\n";
        }
    }

    protected function forkRelieveStressForLinux($status)
    {
        if (self::$addConsumers > 0 &&
            self::$status !== self::STATUS_STOP &&
            !isset(ExitedCode::$exitedCodeMap[$status])
        ) {
            $this->forkWorkerForLinux(self::TEMP_PROCESS,1);
        }

        \set_exception_handler(
            [$this, 'globalException']
        );
        // 消费者总数量
        $consumers = count(self::$pidMap);

        self::$addConsumers = ceil($this->length() / $consumers) - $consumers;
        \restore_exception_handler();
    }

    /**
     * 释放内存
     * @param $initMemory
     */
    protected function releaseMemory($initMemory)
    {
        // 内存占用
        $usedMemory = memory_get_usage() - $initMemory;
        // 预警值
        $outOfMemory = $usedMemory > $this->config['memory_limit'];

        if ($outOfMemory) {
            throw new OutOfMemoryException(
                "Allowed memory size of {$this->config['memory_limit']} bytes exhausted (tried to allocate {$usedMemory} bytes)."
            );
        }
    }

    /**
     * 安装进程信号
     * @param $signal
     */
    protected function installSignal($signal)
    {
        switch ($signal) {
            case SIGINT:
                $this->stopConsumer();
                break;
        }
    }

    protected function stopConsumer()
    {
        // for master
       self::$status = self::STATUS_STOP;
       if (self::$masterPid  === getmypid()) {
           foreach (self::$pidMap as $pid) {
               @posix_kill($pid, SIGINT);
           }
       }
    }
}
