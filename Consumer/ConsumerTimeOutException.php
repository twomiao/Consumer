<?php


namespace Consumer;


class ConsumerTimeOutException extends \RuntimeException
{
    protected $code =  ExitedCode::CONSUMER_BLOCKING;
}