<?php

namespace Consumer\Consumer;

trait ConnectionManager
{
    protected static $connections = [];

    /**
     * 存储队列连接
     * @param $connection
     * @return mixed
     */
    protected function setQueueConnection($connection)
    {
        self::$connections[$this->getQueueConnectId()] = $connection;
        return self::$connections[$this->getQueueConnectId()];
    }

    /**
     * 获取队列连接
     * @return mixed|null
     */
    protected function getQueueConnection()
    {
        if (isset(self::$connections[$this->getQueueConnectId()])) {
            return self::$connections[$this->getQueueConnectId()];
        }
        return null;
    }

    /**
     * 存储数据库连接
     * @return mixed|null
     * @var $connection
     */
    protected function setDatabaseConnection($connection)
    {
        self::$connections[$this->getDbConnectionId()] = $connection;
        return self::$connections[$this->getQueueConnectId()];
    }

    /**
     * 获取数据库连接
     * @return mixed|null
     * @var $connection ConnectionInterface
     */
    protected function getDatabaseConnection($connection)
    {
        if (isset(self::$connections[$this->getQueueConnectId()])) {
            return self::$connections[$this->getQueueConnectId()];
        }
        return null;
    }

    protected function getQueueConnectId()
    {
        return "queue:" . getmypid();
    }

    protected function getDbConnectionId()
    {
        return "db:" . getmypid();
    }
}