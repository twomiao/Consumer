<?php

namespace Consumer\Consumer;

use Psr\Log\LoggerInterface;

class Log
{
    /**
     * @var LoggerInterface $logger
     */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param $e \Throwable
     */
    public function record($e)
    {
        // 异常类型记录日志
        if ($e instanceof \Error) {
            $this->logger->error($e->getMessage());
        } elseif ($e instanceof \RuntimeException) {
            $this->logger->debug($e->getMessage());
        } else {
            $this->logger->warning($e->getMessage());
        }
    }
}