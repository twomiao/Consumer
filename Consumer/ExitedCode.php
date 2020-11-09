<?php
namespace Consumer;


class ExitedCode
{
    // 1-9
    const FORK_ERROR        = 1; // fork 失败
    const CONSUMER_BLOCKING = 3; // 超时
    const RELEASE_MEMORY    = 2; // 内存使用超出限制
    const TEMP_CONSUMER     = 4; // 临时消费者退出

    const SUCCESS = 0 ;    // 正常退出


    // 消费者不重启代码
    static $exitedCodeMap  = array(
        self::CONSUMER_BLOCKING,
//        self::RELEASE_MEMORY,
        self::TEMP_CONSUMER,
        self::SUCCESS
    );

}