<?php
namespace Consumer\Consumer;

class ExitedCode
{
    // 1-9
    const FORK_ERROR        = 1; // fork 失败
    const CONSUMER_BLOCKING = 3; // 超时
    const RELEASE_MEMORY    = 2; // 内存使用超出限制
    const TEMP_CONSUMER     = 4; // 临时消费者退出
    const TASKS_TOTAL       = 5; // 内存自动释放，并重启

    const SUCCESS = 0 ;    // 正常退出


    // 消费者不重启代码
    public static $exitedCodeMap = array(
        self::CONSUMER_BLOCKING => self::CONSUMER_BLOCKING,
//        self::RELEASE_MEMORY,
        self::TEMP_CONSUMER => self::TEMP_CONSUMER,
        self::SUCCESS => self::SUCCESS
    );

}