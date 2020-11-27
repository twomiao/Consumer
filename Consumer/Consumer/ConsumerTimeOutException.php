<?php
namespace Consumer\Consumer;

class ConsumerTimeOutException extends \RuntimeException
{
    protected $code =  ExitedCode::CONSUMER_BLOCKING;
}