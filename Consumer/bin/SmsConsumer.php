<?php
namespace Consumer\Command;

require_once dirname(__DIR__) . '/../vendor/autoload.php';

use Consumer\Consumer\ConnectionInterface;
use Consumer\Consumer\Consumer;

class SmsConsumer extends Consumer implements ConnectionInterface
{
    protected static $clients = [];

    public function fire($data)
    {
        // 处理业务
        sleep(mt_rand(1, 2));
        $pid = getmypid();
        echo '已完成处理数据: ' . $data[1] .", pid( {$pid} )". PHP_EOL;
    }

    public function reserve()
    {
        return self::$clients[getmypid()]->brPop('task:data', 2);
    }

    public function closed()
    {
        $client = self::$clients[getmypid()];
        if ($client->isConnected()) {
            $client->close();
            unset(self::$clients[getmypid()]);
        }
    }

    public function getQueueConnection(): Consumer
    {
        // 创建连接
        self::$clients[getmypid()] = new \Redis();
        self::$clients[getmypid()]->pconnect('127.0.0.1', '6379',0);
        return $this;
    }

    public function length(): int
    {
        self::$clients[getmypid()] = new \Redis();
        self::$clients[getmypid()]->pconnect('127.0.0.1', '6379',0);

        return self::$clients[getmypid()]->lLen('task:data');
    }

    /**
     * 判断是否连接
     * @return bool
     */
    public function isConnected(): bool
    {
        return self::$clients[getmypid()]->isConnected();
    }
}

$flag = 0;

if ($flag) {
    $redis = new \Redis();
    $redis->connect('127.0.0.1', 6379);
    for ($i = 1; $i <= 10000; $i++) {
        $redis->lPush('task:data', str_repeat('data:' . $i, 1));
    }
    echo 'push tasK:data success, len is:' . $redis->lLen('task:data') . PHP_EOL;
    $redis->close();
} else {
    $config = [
        'name'          => 'delay:order',
        'tasks'         => 100,   // < 1 永久不退出,否则按照指定任务数量退出
        'memory_limit'  => 1,      // 默认是配置文件4/1内存限制,单位:MB
        'max_consumers' => 200,    // 临时+常驻消费者最多：8个
        'task_timeout'  => 35,     // 任务从队列消费超30秒，消费者退出并记录数据
        'idle_time'     => 30,     // 临时消费者空闲30秒没任务，自动退出节约资源
        'user'          => 'root', // 用户
        'user_group'    => 'root', // 用户组
        'daemonize'     => false,
    ];

    $smsConsumer = new SmsConsumer(20, $config);
//    $smsConsumer->setMaxWorker(8);
    $smsConsumer->start();
}

