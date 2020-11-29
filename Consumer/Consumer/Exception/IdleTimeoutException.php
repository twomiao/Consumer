<?php
namespace Consumer\Consumer\Exception;

use Consumer\Consumer\ExitedCode;

class IdleTimeoutException extends \RuntimeException
{
    protected $code = ExitedCode::TASKS_TOTAL;

}