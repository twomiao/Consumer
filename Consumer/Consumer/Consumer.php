<?php
namespace Consumer\Consumer;

use Consumer\Consumer\Exception\AbnormalWorkloadException;
use Consumer\Consumer\Exception\ConsumerTimeOutException;
use Consumer\Consumer\Exception\ForkFailError;
use Consumer\Consumer\Exception\IdleTimeoutException;
use Katzgrau\KLogger\Logger;
use Psr\Log\LoggerInterface;

pcntl_async_signals(true);

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
     * 消费者状态 [pid=>status]
     * @var array $consumerStatus
     */
    protected static $consumerStatus = [];

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
     * @var $logger Log
     */
    protected $logger;

    /**
     * @var $consumerType
     */
    protected static $consumerType;

    /**
     * 创建指定常驻进程数量
     * @var int
     */
    protected $forkNumber = 2;

    /**
     * 子类运行逻辑的方法
     * @var array $method
     */
    protected $method = 'execute';

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
        'sock_file'    => '', // 可自定义
        'logger'       => '' // 日志设置
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
        $this->config['sock_file']    = $this->getSockFile();
        $this->config['logger']       = $this->getLogger();
    }

    public function start()
    {
        $this->checkSapiEnv();
        $this->init();
        $this->daemonize();
        $this->forkWorkerForLinux(self::RESIDENT_PROCESS, $this->forkNumber);
        $this->saveMasterPid();
        $this->resetStd();
        $this->monitorWorkers();
    }

    /**
     * 分配合适的内存
     * @return float|int
     */
    protected function getIdealMemorySize()
    {
        $idealMemory  = ceil($this->config['memory_limit']);

        $memory       = strtolower(ini_get('memory_limit'));
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
    public function onException(\Throwable $e)
    {
        // write log ....
        switch ($e->getCode()) {

            case ExitedCode::CONSUMER_BLOCKING:  // 任务阻塞
                echo $e->getMessage() . PHP_EOL;
                break;

            case ExitedCode::RELEASE_MEMORY:    // 内存超出预期值;
                echo $e->getMessage() . PHP_EOL;
                break;

            case ExitedCode::TASKS_TOTAL:      // 完成累计任务自动退出
                echo $e->getMessage() . PHP_EOL;
                break;

            case ExitedCode::SUCCESS:
                echo $e->getMessage() . PHP_EOL; // 正常退出
                break;
        }
        // write log ...
        $this->logger->record($e);
        // 设置退出状态代码
        exit($e->getCode());
    }

    /**
     * 创建消费者子进程
     *
     * @param $consumerType
     * @param int $forkWorkerNum
     * @throws ForkFailError
     */
    protected function forkWorkerForLinux($consumerType, $forkWorkerNum = 1)
    {
        while ($forkWorkerNum > 0 && count(self::$pidMap) < $this->forkMaxWorker) {
            $retry = 0;
            do {
                $pid = \pcntl_fork();
                try
                {
                    if ($pid === 0) {
                        $processTitle = $this->getConsumerName($consumerType);
                        self::setProcessTitle($processTitle);

                        // 消费者类型
                        self::$consumerType   = $consumerType;

                        usleep(500000);
                        // 清理父进程数据
                        self::$consumerStatus = self::$pidMap = self::$consumer = [];
                        // install signal
                        pcntl_signal(SIGINT, [$this, 'installSignal']);

                        if (self::$status === self::STATUS_STARTING) {
                            $this->resetStd();
                        }

                        try
                        {
                            // 执行任务
                            $this->processingWork($consumerType);
                        } catch (\Throwable $e) {
                            // write log
                            $this->onException($e);
                        }
                        return;
                    } elseif ($pid) {
                        self::$pidMap[$pid] = $pid;
                        self::$consumer[$pid] = $consumerType;
                        self::$consumerStatus[$pid] = 'idle';
                        self::$masterPid = getmypid();
                        pcntl_signal(SIGINT, [$this, 'installSignal']);
                    } else {
                        $retry++;
                        throw new ForkFailError("Fork({$retry}) process failed.", ExitedCode::FORK_ERROR);
                    }
                } catch (ForkFailError $error) {
                    $this->logger->record($error);  // write log ....
                }

                $forkWorkerNum--;
            } while ($pid < 0 && $retry <= 3);
        }
    }

    /**
     * @param $data
     * @return mixed
     */
    abstract public function execute($data);

    /**
     * 1. 如果抛出异常发生任务阻塞自动退出
     * 2. 记录异常对象到日志，具体可自定义处理
     * @param $e
     * @return mixed
     */
    abstract public function blocking($e);

    /**
     * @param $consumerType
     */
    protected function processingWork($consumerType)
    {
        // 仅用于演示
        $client = new \Redis();
        $client->pconnect('127.0.0.1', '6379');
        // 记录上次时间
        $lastTime     = time();
        // 内存初始化
        $initMemory   = memory_get_usage();
        // 任务累计初始化
        $tasks        = 0;
        // 当前worker 设置为运行中
        self::$status = self::STATUS_RUNNING;
        while (1) {
            if (self::$status === self::STATUS_STOP) {
                echo "进程收到退出命令，自动退出." . PHP_EOL;
               $this->stop();
            }

            $data = $client->brPop('task:data', 2);

            if (!$data) {
                $this->sendMsgToMaster('idle');

                echo '没有数据消费' . PHP_EOL;
                if ($consumerType === Consumer::TEMP_PROCESS)
                {
                    if (($remaining = time() - $lastTime) > $this->config['idle_time']) {
                        if ($client->isConnected()) {
                            $client->close();
                            unset($client);
                        }
                        echo "临时进程超过{$remaining}秒没有任务，自动退出." . PHP_EOL;
                        $type = $this->getConsumerName($consumerType);
                        $pid  = getmypid();
                        throw new IdleTimeoutException(
                            "(worker #{$pid} Types of {$type}), Auto exit when idle for {$remaining} seconds."
                        );
                    }
                }
                continue;
            }

            $this->sendMsgToMaster('busy');

            $this->checkConsumerTimeout($data);

            // 标记任务处理完成时间
            $lastTime = time();

            // 内存占用超过配置文件退出当前消费者
            $this->checkMaxMemorySize($initMemory);

            $tasks++;
            // 累计完成任务量自动退出
            $this->checkNumberOfTasks($tasks, $client);
        }
    }

    /**
     * 创建日志目录
     * @throws \InvalidArgumentException
     * @return Log
     */
    protected function getLogger()
    {
        $loggerDir  = $this->config['logger'] ?: __DIR__.'/../logs';

        if (!is_string($loggerDir)) {
            throw new \InvalidArgumentException("Incorrect log directory type.");
        }

        $withStart   = strpos($loggerDir, DIRECTORY_SEPARATOR);
        if($withStart !== 0)
        {
            throw new \InvalidArgumentException("{$loggerDir} Invalid log directory.");
        }

        $logger = new Logger($loggerDir);

        if ($logger && $logger instanceof LoggerInterface) {
            return $this->logger = new Log($logger);
        }

        $logger = is_object($logger) ? get_class($logger) : 'null';
        throw new \InvalidArgumentException("[$logger] LoggerInterface is not implemented.");
    }

    /**
     * 获取消费者类型
     * @param $consumerType
     * @return string
     */
    protected function getConsumerName($consumerType)
    {
        $name = ($this->config['name'] ?: 'consumer');

        switch ($consumerType)
        {
            case Consumer::TEMP_PROCESS:
                return "{$name}:temp";
            case Consumer::RESIDENT_PROCESS:
                return "{$name}:worker";
        }
        return "{$name}:none";
    }

    /**
     * @param $current
     * @param $client \Redis
     * @throws AbnormalWorkloadException
     */
    protected function checkNumberOfTasks($current, $client)
    {
        if (intval($this->config['tasks']) > 0 && ($current === $this->config['tasks'])) {
            if ($client->isConnected()) {
                $client->close();
                unset($client);
            }
            $pid  = getmypid();
            $type = $this->getConsumerName(self::$consumerType);
            throw new AbnormalWorkloadException(
                "(worker #{$pid} Types of {$type}) auto exited, Total number of tasks: ({$current})."
            );
        }
    }

    protected function sendMsgToMaster(string $consumerStatus)
    {
        $client = new \Consumer\Consumer\UnixClient($this->config['sock_file']);
        try {
            if ($client->isConnected()) {
                $data = serialize(['pid' => getmypid(), 'status' => $consumerStatus]);
                $client->send($data);
            }
        } catch (\Exception $e) {
            echo $e->getMessage() . PHP_EOL;
        } finally {
            try {
                $client->closed();
            } catch (\Exception $e) {
                echo $e->getMessage() . PHP_EOL;
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
    protected function checkConsumerTimeout($data)
    {
        try
        {
            pcntl_signal(SIGALRM, function () use ($data) {
                // redis data
                $return       = var_export($data, true);
                // pid
                $pid          = getmypid();
                // 任务超时秒数
                $seconds      = $this->config['task_timeout'];

                $type = $this->getConsumerName(self::$consumerType);

                throw new ConsumerTimeOutException(
                    "(worker #{$pid} Types of {$type}), The child process blocks for {$seconds} seconds, data is:{$return}."
                );
            });

            pcntl_alarm($this->config['task_timeout']);

            call_user_func_array([$this, $this->method], [$data]);

            pcntl_alarm(0);

        } catch (ConsumerTimeOutException $e) {
            $e->setData($data);
            // 当前任务阻塞异常
            $this->blocking($e);
        }
    }

    protected function init()
    {
        self::setProcessTitle('Consumer:master');

        if (empty(static::$pidFile)) {
            static::$pidFile = __DIR__ . "/../Consumer.pid";
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

    protected function getSockFile() :string
    {
        return $this->config['sock_file'] ?: "/tmp/".md5(spl_object_hash($this)).'.sock';
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

    protected function monitorWorkers()
    {
        self::$status = self::STATUS_RUNNING;
        $unixServer  = new UnixServer( $this->config['sock_file']);

        while (count(self::$pidMap)) {
            $pid = \pcntl_wait($status, \WNOHANG);

            if ($pid > 0) {
                $status = pcntl_wexitstatus($status);
                echo "子进程退出: status:{$status}, pid:{$pid}, " . self::$consumer[$pid] . PHP_EOL;

                // 异常退出
                $this->rebootConsumer($status, $pid);

                // 清理子进程数据
                unset(self::$pidMap[$pid]);
                unset(self::$consumer[$pid]);
                unset(self::$consumerStatus[$pid]);
                continue;
            }

            // 寻找临时工帮忙
            $this->forkTemporaryConsumerForLinux();

            $unixServer->listen(function($pid, $status) {
                if (posix_kill($pid, 0)) {
                    self::$consumerStatus[$pid] = $status;
                }
            });
        }
        $this->clearSockFile();
    }

    protected function forkTemporaryConsumerForLinux()
    {
        if (static::$status !== static::STATUS_STOP)
        {
            if (in_array('idle', array_values(self::$consumerStatus), true) === false) {
                $this->forkWorkerForLinux(self::TEMP_PROCESS);
            }
        }
    }

    /**
     * 异常退出重启消费者,除任务超时退出或者超出内存限制退出
     * @param $status
     * @param $pid
     */
    protected function rebootConsumer($status, $pid)
    {
        if (self::$status !== self::STATUS_STOP && !array_key_exists($status, ExitedCode::$exitedCodeMap)) {
            // 重新创建拉取指定类型的消费者进程
            self::forkWorkerForLinux
            (
                $consumer = self::$consumer[$pid]
            );
            echo "退出代码:{$status}, pid:{$pid}, reboot.\n";
        }
    }


    /**
     * 释放内存
     * @param $initMemory
     */
    protected function checkMaxMemorySize($initMemory)
    {
        // 内存占用
        $usedMemory = memory_get_usage() - $initMemory;
        // 预警值
        $outOfMemory = $usedMemory > $this->config['memory_limit'];

        if ($outOfMemory) {
            $pid  = getmypid();
            $type = $this->getConsumerName(self::$consumerType);
            $limitMemory  = $this->config['memory_limit'];

            throw new OutOfMemoryException(
                "(worker #{$pid} Types of {$type}), Memory limit size: {$limitMemory} bytes, memory exceeding: {$usedMemory} bytes."
            );
        }
    }

    /**
     * 安装进程信号
     * @param $signal
     */
    public function installSignal($signal)
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
       if (self::$masterPid === getmypid()) {
           foreach (self::$pidMap as $key => $pid) {
               @posix_kill($pid, SIGINT);
           }
       }
    }

    protected function clearSockFile() :void
    {
        clearstatcache();
        if (file_exists(self::$pidFile))
        {
            unlink(self::$pidFile);
        }

        if (file_exists($this->config['sock_file']))
        {
            unlink($this->config['sock_file']);
        }
    }
}
