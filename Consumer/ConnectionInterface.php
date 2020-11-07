<?php

namespace Consumer;

interface ConnectionInterface
{
    /**
     * 创建连接
     * @return Consumer
     */
    public function getQueueConnection(): Consumer;

    /**
     * 判断是否连接
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * 获取数据
     * @return mixed
     */
    public function reserve();

    /**
     * 关闭连接
     * @return mixed
     */
    public function closed();

    /**
     * 当前任务总数量
     * @return int
     */
    public function length(): int;

}