### Consumer 简介：
> Pcntl 扩展实现高效率动态进程池消费，因此只支持Linux。进程模型类似于PHP-FPM 动态进程池。<br/>
> 进程模型：主进程 + 临时进程 + 常驻进程。
> <br /><img src="https://img-blog.csdnimg.cn/20201201225915997.gif" width="700" height="300" alt="动态进程池"/>

* Consumer 优点: 
   * 多进程运用多核CPU  √
   * 处理任务发生阻塞子进程自动退出或者记录日志：可自定义  √
   * 自动扩充子进程数量，默认空闲30秒退出临时子进程：可自定义  √
   * 常驻进程异常退出自动拉取同类型子进程  √
   * 允许设置进程组、运行用户、守护进程 √
   * 子进程占用内存过大，自动退出释放：可自定义  √
   * 子进程处理完成指定任务数量，自动退出防止内存泄漏：可自定义  √
   * SIGINT 通知全部子进程退出  √
   * 记录日志文件：可自定义 √

* 编写目的: 
   * 主要学习并发编程，多进程实现原理。切勿用于实际生产环境，可以借鉴类似思想完成进程池编写。
   * 其中还有很多细节未实现，只实现了最核心的部分。
  
### 环境要求

* PHP >= 7.1.0
* Pcntl

#### 自定义动态池：
```
<?php
namespace Consumer\Command;

require_once dirname(__DIR__,2) . '/vendor/autoload.php';

use Consumer\Consumer\Consumer;

class SmsConsumer extends Consumer
{
    public function execute($data)
    {
        // 处理业务
        sleep(mt_rand(1, 3));
        $pid = getmypid();
        echo '已完成处理数据: ' . $data[1] . ", pid( {$pid} )" . PHP_EOL;
    }

    /**
     * 1. 如果抛出异常发生任务阻塞自动退出
     * 2. 记录异常对象到日志，具体可自定义处理
     * @param $e
     * @return mixed
     */
    public function blocking($e)
    {
        // 1.
        throw $e;
    }
}

$flag = 0;

if ($flag) {
    $redis = new \Redis();
    $redis->connect('127.0.0.1', 6379);
    for ($i = 1; $i <= 1000; $i++) {
        $redis->lPush('task:data', str_repeat('data:' . $i, 1));
    }
    echo 'push tasK:data success, len is:' . $redis->lLen('task:data') . PHP_EOL;
    $redis->close();
} else {

    /**
     * 提醒：
     * $config 配置可以使用 php.ini 或者 .env 等等配置形式加载.
     * 这里使用数组作为配置文件载体
     */
    $config = [
        'name'          => 'delay-order',
        'tasks'         => 5000,   // < 1 永久不退出,否则按照指定任务数量退出
        'memory_limit'  => 1,      // 默认是配置文件4/1内存限制,单位:MB
        'max_consumers' => 50,     // 临时+常驻消费者最多：8个
        'task_timeout'  => 35,     // 任务从队列消费超30秒，消费者退出并记录数据
        'idle_time'     => 30,     // 临时消费者空闲30秒没任务，自动退出节约资源
        'user'          => 'root', // 用户
        'user_group'    => 'root', // 用户组
        'daemonize'     => false,  // 守护进程
        'logger'        => __DIR__ . '/../logs', // 日志目录
        'intervals'     => 3  // 全部workers(临时+常驻),默认间隔10秒钟汇报一次当前状态给Master.
                               // 提醒：不要输入 < 1的值，因为我这里没做判断。有心情的可以帮忙做个参数验证。
    ];

    $smsConsumer = new SmsConsumer(4, $config);
    $smsConsumer->start();
}
```

### License

Apache License Version 2.0, http://www.apache.org/licenses/
