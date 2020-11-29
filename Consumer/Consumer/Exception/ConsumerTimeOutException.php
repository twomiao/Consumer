<?php
namespace Consumer\Consumer\Exception;

use Consumer\Consumer\ExitedCode;

class ConsumerTimeOutException extends \RuntimeException
{
    protected $code =  ExitedCode::CONSUMER_BLOCKING;

    protected $data;

    public function setData($data) :void
    {
        $this->data = $data;
    }

    public function getData()
    {
        return $this->data;
    }
}