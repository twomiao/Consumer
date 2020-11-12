<?php

namespace Consumer\Consumer;

interface ConnectionInterface
{
    /**
     * 创建连接
     */
    public function getClientConnection(): Consumer;

    /**
     * 连接是否成功
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * 消费者读取队列数据
     * @return mixed
     */
    public function reserve();

    /**
     * 消费者关闭连接
     * @return mixed
     */
    public function closed();

    /**
     * 当前队列积累任务总数
     * @return int
     */
    public function length(): int;

}